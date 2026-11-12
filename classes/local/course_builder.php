<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_aicoursebuilder\local;

/**
 * Stores editable plans and creates the approved course structure.
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_builder {
    /**
     * Save an AI-generated course plan and its source records.
     *
     * @param int $userid
     * @param string $name
     * @param array $program
     * @param array $delivery
     * @param array $sources
     * @param array $plan
     * @return int
     */
    public static function save_plan(int $userid, string $name, array $program, array $delivery, array $sources, array $plan): int {
        global $DB;
        if (empty($plan['outcomes']) || empty($plan['units']) || empty($plan['lessons'])) {
            throw new \moodle_exception('invalidplan', 'local_aicoursebuilder');
        }
        $plan = self::repair_plan($plan, $sources, $name, $delivery);
        $plan = self::normalise_plan(
            $plan,
            isset($delivery['allowedTools']) ? (array)$delivery['allowedTools'] : null,
            isset($delivery['toolModes']) ? (array)$delivery['toolModes'] : null
        );
        $missing = [];
        foreach ($plan['lessons'] as $lesson) {
            foreach (array_unique($lesson['tools'] ?? []) as $component) {
                if (!\local_aicoursebuilder\local\generator_runtime::component_available((string)$component)) {
                    $missing[] = (string)$component;
                }
            }
        }
        if ($missing) {
            throw new \moodle_exception('generatornotready', 'local_aicoursebuilder', '', implode(', ', array_unique($missing)));
        }
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $programrecord = (object)[
            'userid' => $userid,
            'name' => clean_param($name, PARAM_TEXT),
            'status' => 'planned',
            'categoryid' => null,
            'deliveryjson' => json_encode($delivery, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'planjson' => json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'error' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $programid = $DB->insert_record('local_aicoursebuilder_program', $programrecord);
        $framework = (object)[
            'programid' => $programid,
            'framework' => clean_param($program['framework'] ?? 'custom', PARAM_ALPHANUMEXT),
            'country' => clean_param($program['country'] ?? '', PARAM_TEXT),
            'state' => clean_param($program['state'] ?? '', PARAM_TEXT),
            'sector' => clean_param($program['sector'] ?? '', PARAM_TEXT),
            'level' => clean_param($program['level'] ?? '', PARAM_TEXT),
            'subject' => clean_param($program['subject'] ?? '', PARAM_TEXT),
            'language' => clean_param($program['language'] ?? 'English', PARAM_TEXT),
            'spelling' => clean_param($program['spelling'] ?? '', PARAM_TEXT),
            'learnerprofile' => clean_param($program['learnerProfile'] ?? '', PARAM_TEXT),
            'unitcode' => \core_text::substr(clean_param($program['unitCode'] ?? '', PARAM_ALPHANUMEXT), 0, 50),
            'unittitle' => \core_text::substr(clean_param($program['unitTitle'] ?? '', PARAM_TEXT), 0, 255),
            'nominalhours' => max(0, (int)($program['nominalHours'] ?? 0)),
            'timecreated' => $now,
        ];
        $DB->insert_record('local_aicoursebuilder_framework', $framework);
        foreach ($sources as $source) {
            $text = clean_param((string)($source['text'] ?? ''), PARAM_TEXT);
            if ($text === '') {
                continue;
            }
            $DB->insert_record('local_aicoursebuilder_source', (object)[
                'programid' => $programid,
                'name' => clean_param($source['name'] ?? 'Source', PARAM_TEXT),
                'sourcetext' => $text,
                'sourcehash' => hash('sha256', $text),
                'timecreated' => $now,
            ]);
        }
        foreach ($plan['outcomes'] as $index => $outcome) {
            $DB->insert_record('local_aicoursebuilder_outcome', (object)[
                'programid' => $programid,
                'code' => empty($outcome['code']) ? null : clean_param($outcome['code'], PARAM_TEXT),
                'outcometext' => clean_param($outcome['text'] ?? '', PARAM_TEXT),
                'strand' => clean_param($outcome['strand'] ?? 'General', PARAM_TEXT),
                'weight' => min(3, max(1, (int)($outcome['weight'] ?? 1))),
                'tagsjson' => json_encode(self::clean_outcome_tags($outcome['tags'] ?? []), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'sortorder' => $index,
            ]);
        }
        $unitids = [];
        foreach ($plan['units'] as $unit) {
            $number = (int)$unit['number'];
            $unitids[$number] = $DB->insert_record('local_aicoursebuilder_unit', (object)[
                'programid' => $programid,
                'unitnumber' => $number,
                'title' => clean_param($unit['title'] ?? 'Learning sequence', PARAM_TEXT),
                'courseid' => null,
                'sortorder' => $number,
            ]);
        }
        foreach ($plan['lessons'] as $lesson) {
            $lessonnumber = (int)$lesson['number'];
            $unitnumber = self::unit_for_lesson($plan['units'], $lessonnumber);
            if (!isset($unitids[$unitnumber])) {
                throw new \moodle_exception('invalidplan', 'local_aicoursebuilder');
            }
            $lessonid = $DB->insert_record('local_aicoursebuilder_lesson', (object)[
                'programid' => $programid,
                'unitid' => $unitids[$unitnumber],
                'lessonnumber' => $lessonnumber,
                'title' => clean_param($lesson['title'] ?? 'Lesson ' . $lessonnumber, PARAM_TEXT),
                'outcomesjson' => json_encode($lesson['outcomes'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'toolsjson' => json_encode($lesson['tools'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'sectionid' => null,
                'status' => 'planned',
            ]);
            foreach (array_unique($lesson['tools'] ?? []) as $component) {
                $DB->insert_record('local_aicoursebuilder_job', (object)[
                    'programid' => $programid,
                    'lessonid' => $lessonid,
                    'component' => clean_param($component, PARAM_ALPHANUMEXT),
                    'cmid' => null,
                    'externaljobid' => null,
                    'status' => 'queued',
                    'attempts' => 0,
                    'error' => null,
                    'resultjson' => null,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
        }
        $transaction->allow_commit();
        return $programid;
    }

    /**
     * Queue one failed or blocked activity to be generated again.
     *
     * The activity keeps its place in the course. Course Information and Mapping are
     * course-level: when a lesson activity is retried after they were made, they are
     * queued again too, so they include it once it is built.
     *
     * @param int $jobid
     * @return void
     */
    public static function retry_job(int $jobid): void {
        global $DB;
        $job = $DB->get_record('local_aicoursebuilder_job', ['id' => $jobid], '*', MUST_EXIST);
        $ids = [$job->id];
        if (!in_array($job->component, ['mod_courseinfo', 'mod_learningmapping'], true)) {
            $lesson = $DB->get_record('local_aicoursebuilder_lesson', ['id' => $job->lessonid], 'id, unitid', MUST_EXIST);
            $first = $DB->get_records('local_aicoursebuilder_lesson', ['unitid' => $lesson->unitid], 'lessonnumber ASC', 'id', 0, 1);
            $courselevel = $DB->get_records_select(
                'local_aicoursebuilder_job',
                'programid = ? AND lessonid = ? AND component IN (?, ?)',
                [$job->programid, (int)array_key_first($first), 'mod_courseinfo', 'mod_learningmapping']
            );
            foreach ($courselevel as $cl) {
                $ids[] = $cl->id;
            }
        }
        $now = time();
        $current = $DB->get_records_list('local_aicoursebuilder_job', 'id', $ids);
        foreach ($ids as $id) {
            $DB->update_record('local_aicoursebuilder_job', (object)[
                'id' => $id, 'status' => 'queued', 'externaljobid' => null, 'error' => null,
                'resultjson' => null, 'timemodified' => $now,
                // "Try again" after a price change accepts the price shown in the message.
                'approvedcredits' => strpos((string)$current[$id]->error, '[pricechanged]') === 0
                    ? null : $current[$id]->approvedcredits,
            ]);
            $task = new \local_aicoursebuilder\task\generate_activity();
            $task->set_custom_data(['jobid' => (int)$id]);
            \core\task\manager::queue_adhoc_task($task);
        }
        $DB->update_record('local_aicoursebuilder_program', (object)[
            'id' => $job->programid, 'status' => 'building', 'error' => null, 'timemodified' => $now,
        ]);
    }

    /**
     * Save the teacher's edits to a plan that has not been approved yet.
     *
     * Lessons are renumbered in the order the courses list them, references to deleted
     * outcomes are dropped and notes are trimmed, so the saved plan is always one that
     * the review page and approval understand.
     *
     * @param int $programid
     * @param int $userid
     * @param array $plan
     * @return array the plan as saved
     */
    public static function save_draft(int $programid, int $userid, array $plan): array {
        global $DB;
        $program = $DB->get_record('local_aicoursebuilder_program', ['id' => $programid, 'userid' => $userid], '*', MUST_EXIST);
        if ($program->status !== 'planned' || $program->categoryid) {
            throw new \moodle_exception('programnotapprovable', 'local_aicoursebuilder');
        }
        $plan = self::tidy_plan($plan);
        $program->planjson = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $program->timemodified = time();
        $DB->update_record('local_aicoursebuilder_program', $program);
        return $plan;
    }

    /**
     * Renumber lessons by course order and drop references that no longer exist.
     *
     * @param array $plan
     * @return array
     */
    public static function tidy_plan(array $plan): array {
        $outcomes = [];
        $keys = [];
        foreach (array_values((array)($plan['outcomes'] ?? [])) as $outcome) {
            if (!is_array($outcome) || trim((string)($outcome['text'] ?? '')) === '') {
                continue;
            }
            $outcome['text'] = \core_text::substr(trim(clean_param((string)$outcome['text'], PARAM_TEXT)), 0, 1000);
            $outcome['code'] = \core_text::substr(trim(clean_param((string)($outcome['code'] ?? ''), PARAM_TEXT)), 0, 50);
            $key = $outcome['code'] !== '' ? $outcome['code'] : $outcome['text'];
            if (isset($keys[$key])) {
                continue;
            }
            $keys[$key] = true;
            $outcomes[] = $outcome;
        }
        $bynumber = [];
        foreach ((array)($plan['lessons'] ?? []) as $lesson) {
            if (is_array($lesson)) {
                $bynumber[(int)($lesson['number'] ?? 0)] = $lesson;
            }
        }
        $units = [];
        $lessons = [];
        foreach (array_values((array)($plan['units'] ?? [])) as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $numbers = [];
            foreach ((array)($unit['lessons'] ?? []) as $old) {
                $lesson = $bynumber[(int)$old] ?? null;
                if ($lesson === null) {
                    continue;
                }
                unset($bynumber[(int)$old]);
                $lesson['number'] = count($lessons) + 1;
                $lesson['title'] = \core_text::substr(trim(clean_param((string)($lesson['title'] ?? ''), PARAM_TEXT)), 0, 255);
                $lesson['outcomes'] = array_values(array_unique(array_filter(
                    array_map('strval', (array)($lesson['outcomes'] ?? [])),
                    static fn($k) => isset($keys[$k])
                )));
                $lesson['tools'] = array_values(array_unique(array_map('strval', (array)($lesson['tools'] ?? []))));
                $lesson['notes'] = \core_text::substr(trim(clean_param((string)($lesson['notes'] ?? ''), PARAM_TEXT)), 0, 1000);
                if ($lesson['notes'] === '') {
                    unset($lesson['notes']);
                }
                $numbers[] = $lesson['number'];
                $lessons[] = $lesson;
            }
            if ($numbers) {
                $unit['number'] = count($units) + 1;
                $unit['lessons'] = $numbers;
                $units[] = $unit;
            }
        }
        $plan['outcomes'] = $outcomes;
        $plan['units'] = $units;
        $plan['lessons'] = $lessons;
        $notes = \core_text::substr(trim(clean_param((string)($plan['teacherNotes'] ?? ''), PARAM_TEXT)), 0, 2000);
        if ($notes === '') {
            unset($plan['teacherNotes']);
        } else {
            $plan['teacherNotes'] = $notes;
        }
        return $plan;
    }

    /**
     * Create a private course category, one hidden course per unit, and lesson sections.
     *
     * Activity generation remains separate so a partially failed tool cannot be reported as a
     * completed course build.
     *
     * @param int $programid
     * @param int $userid
     * @param array $plan
     * @return void
     */
    public static function approve(int $programid, int $userid, array $plan, int $parentcategory = 0): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        $program = $DB->get_record('local_aicoursebuilder_program', ['id' => $programid, 'userid' => $userid], '*', MUST_EXIST);
        if ($program->status !== 'planned' || $program->categoryid) {
            throw new \moodle_exception('programnotapprovable', 'local_aicoursebuilder');
        }
        if (empty($plan['outcomes']) || empty($plan['units']) || empty($plan['lessons'])) {
            throw new \moodle_exception('invalidplan', 'local_aicoursebuilder');
        }
        foreach (['mod_courseinfo', 'mod_learningmapping'] as $component) {
            if (!\local_aicoursebuilder\local\generator_runtime::component_available($component)) {
                throw new \moodle_exception('generatornotready', 'local_aicoursebuilder', '', $component);
            }
        }
        $transaction = $DB->start_delegated_transaction();
        $now = time();
        $DB->delete_records('local_aicoursebuilder_job', ['programid' => $programid]);
        $DB->delete_records('local_aicoursebuilder_lesson', ['programid' => $programid]);
        $DB->delete_records('local_aicoursebuilder_unit', ['programid' => $programid]);
        $DB->delete_records('local_aicoursebuilder_outcome', ['programid' => $programid]);
        foreach ($plan['outcomes'] as $index => $outcome) {
            if (empty($outcome['text'])) {
                throw new \moodle_exception('invalidplan', 'local_aicoursebuilder');
            }
            $DB->insert_record('local_aicoursebuilder_outcome', (object)[
                'programid' => $programid,
                'code' => empty($outcome['code']) ? null : clean_param($outcome['code'], PARAM_TEXT),
                'outcometext' => clean_param($outcome['text'], PARAM_TEXT),
                'strand' => clean_param($outcome['strand'] ?? 'General', PARAM_TEXT),
                'weight' => min(3, max(1, (int)($outcome['weight'] ?? 1))),
                'tagsjson' => json_encode(self::clean_outcome_tags($outcome['tags'] ?? []), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'sortorder' => $index,
            ]);
        }
        $unitids = [];
        foreach ($plan['units'] as $unit) {
            $number = (int)($unit['number'] ?? 0);
            $title = clean_param($unit['title'] ?? '', PARAM_TEXT);
            if ($number < 1 || $title === '' || empty($unit['lessons']) || !is_array($unit['lessons'])) {
                throw new \moodle_exception('invalidplan', 'local_aicoursebuilder');
            }
            $unitids[$number] = $DB->insert_record('local_aicoursebuilder_unit', (object)[
                'programid' => $programid,
                'unitnumber' => $number,
                'title' => $title,
                'courseid' => null,
                'sortorder' => $number,
            ]);
        }
        // For the approved prices: each outcome's text by its key, and the minutes in one session.
        $outcometext = [];
        foreach ($plan['outcomes'] as $outcome) {
            $outcometext[!empty($outcome['code']) ? (string)$outcome['code'] : (string)$outcome['text']] = (string)$outcome['text'];
        }
        $minutes = self::lesson_minutes(json_decode((string)$program->deliveryjson, true) ?: [], count($plan['lessons']));
        $validcomponents = [
            'mod_visuallearning', 'mod_contentcreator', 'mod_productexplainer', 'mod_aiactivities',
            'mod_aiknowledgecheck', 'mod_aisoftskills', 'mod_aibranchedscenario',
            'mod_aisafetyhazards', 'mod_aianatomy',
            'mod_courseinfo', 'mod_learningmapping',
        ];
        foreach ($plan['lessons'] as $lesson) {
            $number = (int)($lesson['number'] ?? 0);
            $unitnumber = self::unit_for_lesson($plan['units'], $number);
            $title = clean_param($lesson['title'] ?? '', PARAM_TEXT);
            $tools = array_values(array_unique(array_filter($lesson['tools'] ?? [], static function ($component) use ($validcomponents) {
                return is_string($component) && in_array($component, $validcomponents, true);
            })));
            // Teach first, then practise and apply, and close the lesson with the Knowledge Check.
            usort($tools, static function (string $a, string $b): int {
                $order = array_flip(ui::LESSON_ORDER);
                return ($order[$a] ?? 50) <=> ($order[$b] ?? 50);
            });
            if ($number < 1 || $title === '' || !isset($unitids[$unitnumber]) || !$tools) {
                throw new \moodle_exception('invalidplan', 'local_aicoursebuilder');
            }
            $lessonid = $DB->insert_record('local_aicoursebuilder_lesson', (object)[
                'programid' => $programid,
                'unitid' => $unitids[$unitnumber],
                'lessonnumber' => $number,
                'title' => $title,
                'outcomesjson' => json_encode($lesson['outcomes'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'toolsjson' => json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'sectionid' => null,
                'status' => 'planned',
            ]);
            foreach ($tools as $component) {
                if (in_array($component, ['mod_courseinfo', 'mod_learningmapping'], true)) {
                    // These two are course-level activities, created once per unit below.
                    continue;
                }
                // The price the teacher approved for this activity. The build never charges more.
                $approved = ui::estimate($component, $title, array_values(array_filter(array_map(
                    static fn($key) => $outcometext[(string)$key] ?? '',
                    (array)($lesson['outcomes'] ?? [])
                ))), $minutes);
                $DB->insert_record('local_aicoursebuilder_job', (object)[
                    'programid' => $programid,
                    'lessonid' => $lessonid,
                    'component' => $component,
                    'cmid' => null,
                    'externaljobid' => null,
                    'status' => 'queued',
                    'attempts' => 0,
                    'approvedcredits' => $approved,
                    'error' => null,
                    'resultjson' => null,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
        }
        // Course Information and Mapping are course-level deliverables, not one copy per
        // lesson. Their jobs are queued after the lesson jobs, with a runtime dependency gate.
        foreach ($unitids as $unitnumber => $unitid) {
            $unitlessons = $DB->get_records('local_aicoursebuilder_lesson', ['unitid' => $unitid], 'lessonnumber ASC');
            $firstlesson = reset($unitlessons);
            if (!$firstlesson) {
                throw new \moodle_exception('invalidplan', 'local_aicoursebuilder');
            }
            foreach (['mod_courseinfo', 'mod_learningmapping'] as $component) {
                $DB->insert_record('local_aicoursebuilder_job', (object)[
                    'programid' => $programid,
                    'lessonid' => $firstlesson->id,
                    'component' => $component,
                    'cmid' => null,
                    'externaljobid' => null,
                    'status' => 'queued',
                    'attempts' => 0,
                    'approvedcredits' => ui::estimate($component, (string)$firstlesson->title, []),
                    'error' => null,
                    'resultjson' => null,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
        }
        // A new private category for the build, inside the chosen category, unless the teacher may
        // only create courses there: then the hidden courses go straight into that category.
        $parentcontext = $parentcategory ? \context_coursecat::instance($parentcategory) : \context_system::instance();
        if (!$parentcategory || has_capability('moodle/category:manage', $parentcontext)) {
            $category = \core_course_category::create((object)[
                'name' => $program->name,
                'idnumber' => self::free_value('course_categories', 'idnumber', 'aicb-' . $programid),
                'parent' => $parentcategory,
                'visible' => 0,
            ]);
        } else {
            $category = \core_course_category::get($parentcategory);
        }
        $units = $DB->get_records('local_aicoursebuilder_unit', ['programid' => $programid], 'unitnumber ASC');
        $jobbylessoncomponent = [];
        foreach ($DB->get_records('local_aicoursebuilder_job', ['programid' => $programid]) as $job) {
            $jobbylessoncomponent[$job->lessonid][$job->component] = $job;
        }
        // Courses run one after another from the day the plan is approved. Each starts after
        // the weeks the previous courses take, from the teacher's sessions per week.
        $delivery = json_decode((string)$program->deliveryjson, true) ?: [];
        $perweek = max(1, (int)($delivery['sessionsPerWeek'] ?? 1));
        $selfpaced = !empty($delivery['selfPaced']);
        // The teacher's chosen start date (or today) and course format (or AI Course Format).
        $coursestart = !empty($delivery['startDate']) ? (int)$delivery['startDate'] : usergetmidnight(time());
        $enabledformats = \core_plugin_manager::instance()->get_enabled_plugins('format') ?: [];
        $courseformat = in_array((string)($delivery['courseFormat'] ?? ''), $enabledformats, true)
            ? (string)$delivery['courseFormat'] : 'aicourse';
        foreach ($units as $unit) {
            $unitlessoncount = $DB->count_records('local_aicoursebuilder_lesson', ['unitid' => $unit->id]);
            $unitweeks = max(1, (int)ceil($unitlessoncount / $perweek));
            $course = create_course((object)[
                'startdate' => $coursestart,
                'enddate' => $selfpaced ? 0 : $coursestart + $unitweeks * WEEKSECS,
                'fullname' => sprintf('%02d · %s', $unit->unitnumber, $unit->title),
                'shortname' => self::free_value('course', 'shortname', sprintf('AICB-%d-%02d', $programid, $unit->unitnumber)),
                'category' => $category->id,
                'visible' => 0,
                'format' => $courseformat,
                'numsections' => 0,
                // Course Report reads native activity completion, which needs course completion on.
                'enablecompletion' => 1,
            ]);
            $unit->courseid = $course->id;
            $DB->update_record('local_aicoursebuilder_unit', $unit);
            $lessons = $DB->get_records('local_aicoursebuilder_lesson', ['unitid' => $unit->id], 'lessonnumber ASC');
            // Lessons are numbered across the whole program, but each course starts again at
            // section 1: the second course's lessons 7-12 are its sections 1-6.
            $sectionnumbers = [];
            $position = [];
            foreach ($lessons as $lesson) {
                $position[$lesson->id] = count($sectionnumbers) + 1;
                $sectionnumbers[] = $position[$lesson->id];
            }
            $mappingsection = count($lessons) + 1;
            $sectionnumbers[] = $mappingsection;
            course_create_sections_if_missing($course->id, $sectionnumbers);
            $framework = $DB->get_record('local_aicoursebuilder_framework', ['programid' => $programid], '*', MUST_EXIST);
            foreach ($lessons as $lesson) {
                $sectionnumber = $position[$lesson->id];
                $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $sectionnumber], '*', MUST_EXIST);
                // "Lesson N · title": Course Report and parent reports find lesson sections by this name.
                $section->name = get_string('lessonsection', 'local_aicoursebuilder', [
                    'number' => $sectionnumber,
                    'title' => $lesson->title,
                ]);
                $section->sequence = '';
                $DB->update_record('course_sections', $section);
                $lesson->sectionid = $section->id;
                $DB->update_record('local_aicoursebuilder_lesson', $lesson);
                $tools = json_decode($lesson->toolsjson, true) ?: [];
                // Anatomy needs one body model (heart, lungs, hand...). A lesson about body
                // systems in general matches none, so it is taught with Visual Learning instead,
                // the same fallback the planner uses, rather than failing the whole approval.
                if (in_array('mod_aianatomy', $tools, true) && !self::anatomy_pack_matches($lesson)) {
                    $anatomyjob = $jobbylessoncomponent[$lesson->id]['mod_aianatomy'] ?? null;
                    if (in_array('mod_visuallearning', $tools, true)) {
                        $tools = array_values(array_diff($tools, ['mod_aianatomy']));
                        if ($anatomyjob) {
                            $DB->delete_records('local_aicoursebuilder_job', ['id' => $anatomyjob->id]);
                        }
                    } else {
                        $tools = array_values(array_map(fn($t) => $t === 'mod_aianatomy' ? 'mod_visuallearning' : $t, $tools));
                        if ($anatomyjob) {
                            $anatomyjob->component = 'mod_visuallearning';
                            $DB->update_record('local_aicoursebuilder_job', $anatomyjob);
                            $jobbylessoncomponent[$lesson->id]['mod_visuallearning'] = $anatomyjob;
                        }
                    }
                    unset($jobbylessoncomponent[$lesson->id]['mod_aianatomy']);
                    $lesson->toolsjson = json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $DB->update_record('local_aicoursebuilder_lesson', $lesson);
                }
                foreach ($tools as $component) {
                    if (in_array($component, ['mod_courseinfo', 'mod_learningmapping'], true)) {
                        continue;
                    }
                    $module = self::create_module_instance(
                        $course,
                        self::module_name((string)$component),
                        $sectionnumber,
                        get_string('lessonn', 'local_aicoursebuilder', $sectionnumber) . ' — ' . self::tool_label((string)$component),
                        $lesson,
                        $programid,
                        $framework
                    );
                    $job = $jobbylessoncomponent[$lesson->id][$component] ?? null;
                    if ($job) {
                        $job->cmid = (int)$module->coursemodule;
                        $job->timemodified = time();
                        $DB->update_record('local_aicoursebuilder_job', $job);
                    }
                }
            }
            $courseinfojob = self::find_course_job($jobbylessoncomponent, $lessons, 'mod_courseinfo');
            $courseinfo = self::create_module_instance(
                $course,
                'courseinfo',
                0,
                'Course Information',
                null,
                $programid,
                $framework
            );
            $courseinfojob->cmid = (int)$courseinfo->coursemodule;
            $courseinfojob->timemodified = time();
            $DB->update_record('local_aicoursebuilder_job', $courseinfojob);

            $mapping = self::create_module_instance(
                $course,
                'learningmapping',
                $mappingsection,
                'Learning Outcome Mapping',
                null,
                $programid,
                $framework
            );
            $mappingjob = self::find_course_job($jobbylessoncomponent, $lessons, 'mod_learningmapping');
            $mappingjob->cmid = (int)$mapping->coursemodule;
            $mappingjob->timemodified = time();
            $DB->update_record('local_aicoursebuilder_job', $mappingjob);
            self::finish_sections((int)$course->id, $lessons, $mappingsection);
            if (!$selfpaced) {
                $coursestart += $unitweeks * WEEKSECS;
            }
        }
        $program->categoryid = $category->id;
        $program->status = 'building';
        $program->planjson = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $program->timemodified = $now;
        $DB->update_record('local_aicoursebuilder_program', $program);
        $transaction->allow_commit();
        $jobs = $DB->get_records('local_aicoursebuilder_job', ['programid' => $programid, 'status' => 'queued']);
        foreach ($jobs as $job) {
            $task = new \local_aicoursebuilder\task\generate_activity();
            $task->set_custom_data(['jobid' => (int)$job->id]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * The English locale for narration and spelling, and whether that locale's country is the
     * course's own (so pictures can reflect it). Other countries get neutral, international pictures.
     *
     * @param \stdClass|null $framework
     * @return array [locale, is the course's own country]
     */
    public static function locale_for(?\stdClass $framework): array {
        $country = \core_text::strtolower(trim((string)($framework->country ?? '')));
        $spelling = \core_text::strtolower(trim((string)($framework->spelling ?? '')));
        $bycountry = ['australia' => 'en-AU', 'united kingdom' => 'en-GB', 'united states' => 'en-US', 'india' => 'en-IN'];
        if (isset($bycountry[$country])) {
            return [$bycountry[$country], true];
        }
        $locale = str_contains($spelling, 'american') ? 'en-US' : (str_contains($spelling, 'australian') ? 'en-AU' : 'en-GB');
        return [$locale, false];
    }

    /**
     * A value not yet used in a unique column: the wanted one, or the wanted one with "-2", "-3"…
     * (for example after the plugin was reinstalled, or courses were restored from another site).
     *
     * @param string $table
     * @param string $field
     * @param string $wanted
     * @return string
     */
    private static function free_value(string $table, string $field, string $wanted): string {
        global $DB;
        $value = $wanted;
        for ($n = 2; $DB->record_exists($table, [$field => $value]); $n++) {
            $value = $wanted . '-' . $n;
        }
        return $value;
    }

    /**
     * Minutes in one lesson's session: the planned session length, or for self-paced
     * courses the planned learner hours shared across the lessons (60 when unknown).
     *
     * @param array $delivery
     * @param int $lessoncount
     * @return int
     */
    public static function lesson_minutes(array $delivery, int $lessoncount): int {
        $minutes = (int)($delivery['minutesPerSession'] ?? 0);
        if ($minutes <= 0 && !empty($delivery['totalLearnerHours']) && $lessoncount > 0) {
            $minutes = (int)round((float)$delivery['totalLearnerHours'] * 60 / $lessoncount);
        }
        // Unknown: plan for a typical one-hour session, as the wizard does.
        return $minutes > 0 ? min(600, $minutes) : 60;
    }

    /**
     * Repair what the planning service gets wrong, using the teacher's own curriculum.
     *
     * - Keeps the teacher's outcome wording and codes (performance criteria, content
     *   descriptions, LO numbers) when the service rewords them or drops the codes.
     * - Restores the coded outcomes when the service replaced them with topic phrases.
     * - Puts every outcome in a lesson: none is left untaught.
     * - Gives lessons full titles instead of a sentence cut off with "…", and the course
     *   the name the teacher typed.
     * - Uses a long sample only in the lessons it matches, unless the teacher asked for
     *   Content Creator in every lesson.
     * What was repaired is listed in $plan['repairs'] for the review page.
     *
     * @param array $plan
     * @param array $sources [['name' => ..., 'text' => ...]]
     * @param string $name the program name the teacher typed
     * @param array $delivery
     * @return array
     */
    public static function repair_plan(array $plan, array $sources, string $name, array $delivery = []): array {
        $repairs = [];
        $norm = static function (string $text): string {
            $text = \core_text::strtolower(trim($text));
            $text = preg_replace('/\([^)]*\)/u', ' ', $text);
            $text = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $text);
            return trim(preg_replace('/\s+/u', ' ', $text));
        };
        $similar = static function (string $a, string $b) use ($norm): float {
            $a = $norm($a);
            $b = $norm($b);
            if ($a === '' || $b === '') {
                return 0.0;
            }
            if ($a === $b) {
                return 100.0;
            }
            similar_text($a, $b, $percent);
            return (float)$percent;
        };
        // Coded lines in the teacher's curriculum: "1.1 Interpret …", "AC9S8U02 …", "LO2 …".
        $coded = [];
        $plain = [];
        $elements = [];
        foreach ($sources as $source) {
            foreach (preg_split('/\R/u', (string)($source['text'] ?? '')) as $line) {
                $line = trim(preg_replace('/^\s*[-•*–]\s*/u', '', $line));
                if (
                    preg_match('/^(?:[Ee]lement\s+)?(\d{1,2})[.:)]?\s+(\p{Lu}.{5,})$/u', $line, $m)
                        && !preg_match('/^\d+\.\d/', $line)
                ) {
                    $elements[(int)$m[1]] = trim(rtrim($m[2], ':'));
                    continue;
                }
                if (
                    preg_match(
                        '/^((?:[A-Z]{2,}[A-Z0-9]*\d[A-Z0-9]*)|(?:LO\s?\d{1,2})|(?:\d{1,2}\.\d{1,2}(?:\.\d{1,2})?))\s*[-–:.)]?\s+(\S.{7,})$/u',
                        $line,
                        $m
                    )
                ) {
                    $code = preg_replace('/\s+/', '', $m[1]);
                    $text = trim($m[2]);
                    if (!isset($coded[$code]) && \core_text::strlen($text) <= 400) {
                        $coded[$code] = $text;
                    }
                } else if (\core_text::strlen($line) >= 15 && \core_text::strlen($line) <= 400) {
                    $plain[] = $line;
                }
            }
        }
        $outcomes = array_values((array)($plan['outcomes'] ?? []));
        $lessons = array_values((array)($plan['lessons'] ?? []));
        $rename = [];
        $matched = 0;
        foreach ($outcomes as $index => $outcome) {
            $text = (string)($outcome['text'] ?? '');
            $oldkey = !empty($outcome['code']) ? (string)$outcome['code'] : $text;
            $best = null;
            $bestscore = 0.0;
            foreach ($coded as $code => $line) {
                $score = $similar($text, $line);
                if ($score > $bestscore) {
                    $best = $code;
                    $bestscore = $score;
                }
            }
            if ($best !== null && $bestscore >= 85) {
                $matched++;
                if (empty($outcome['code'])) {
                    $outcome['code'] = $best;
                    $repairs['codes'] = true;
                }
                if ($coded[$best] !== $text) {
                    $outcome['text'] = $coded[$best];
                    $repairs['wording'] = true;
                }
                if (
                    preg_match('/^(\d+)\.\d/', (string)$outcome['code'], $m) && isset($elements[(int)$m[1]])
                        && in_array((string)($outcome['strand'] ?? ''), ['', 'General'], true)
                ) {
                    $outcome['strand'] = $elements[(int)$m[1]];
                }
            }
            if ($best === null || $bestscore < 85) {
                // An uncoded outcome: keep the teacher's own wording when the service reworded it.
                foreach ($plain as $line) {
                    $line = rtrim($line, '.');
                    if ($line !== rtrim($text, '.') && $similar($text, $line) >= 90) {
                        $outcome['text'] = $line;
                        $repairs['wording'] = true;
                        break;
                    }
                }
            }
            $newkey = !empty($outcome['code']) ? (string)$outcome['code'] : (string)$outcome['text'];
            if ($newkey !== $oldkey) {
                $rename[$oldkey] = $newkey;
            }
            $outcomes[$index] = $outcome;
        }
        foreach ($lessons as &$lesson) {
            $lesson['outcomes'] = array_values(array_unique(array_map(static function ($key) use ($rename) {
                $key = is_array($key) ? (string)($key['code'] ?? $key['text'] ?? '') : (string)$key;
                return $rename[$key] ?? $key;
            }, (array)($lesson['outcomes'] ?? []))));
        }
        unset($lesson);
        // The service replaced the coded outcomes with topic phrases: put the teacher's back.
        $realcoded = array_filter($coded, static fn($code) => !preg_match('/^[A-Z]{3,}\d{3}[A-Z]?$/', (string)$code)
            || \core_text::strlen((string)$code) < 8, ARRAY_FILTER_USE_KEY);
        if (count($realcoded) >= 2 && $matched === 0 && $lessons) {
            $outcomes = [];
            foreach ($realcoded as $code => $text) {
                $strand = preg_match('/^(\d+)\.\d/', (string)$code, $m) && isset($elements[(int)$m[1]])
                    ? $elements[(int)$m[1]] : 'General';
                $outcomes[] = ['code' => (string)$code, 'text' => $text, 'strand' => $strand, 'weight' => 2, 'tags' => []];
            }
            foreach ($lessons as &$lesson) {
                $lesson['outcomes'] = [];
            }
            unset($lesson);
            $repairs['restored'] = true;
        }
        // Every outcome in a lesson: spread any left out across the lessons in order.
        $covered = [];
        foreach ($lessons as $lesson) {
            foreach ($lesson['outcomes'] as $key) {
                $covered[$key] = true;
            }
        }
        $total = count($outcomes);
        foreach ($outcomes as $index => $outcome) {
            $key = !empty($outcome['code']) ? (string)$outcome['code'] : (string)($outcome['text'] ?? '');
            if ($key === '' || isset($covered[$key]) || !$lessons) {
                continue;
            }
            $target = (int)min(count($lessons) - 1, floor($index * count($lessons) / max(1, $total)));
            $lessons[$target]['outcomes'][] = $key;
            $covered[$key] = true;
            $repairs['added'][] = ['outcome' => $key, 'lesson' => (int)($lessons[$target]['number'] ?? $target + 1)];
        }
        // A lesson with no outcome gets the outcome it is most about.
        foreach ($lessons as &$lesson) {
            if ($lesson['outcomes'] || !$outcomes) {
                continue;
            }
            $titlewords = array_filter(explode(' ', $norm((string)($lesson['title'] ?? ''))), static fn($w) => \core_text::strlen($w) >= 4);
            $bestkey = null;
            $bestcount = -1;
            foreach ($outcomes as $outcome) {
                $key = !empty($outcome['code']) ? (string)$outcome['code'] : (string)($outcome['text'] ?? '');
                $text = ' ' . $norm((string)($outcome['text'] ?? '')) . ' ';
                $count = 0;
                foreach ($titlewords as $word) {
                    $stem = \core_text::substr($word, 0, max(4, \core_text::strlen($word) - 2));
                    $count += strpos($text, ' ' . $stem) !== false ? 1 : 0;
                }
                if ($count > $bestcount) {
                    $bestkey = $key;
                    $bestcount = $count;
                }
            }
            $lesson['outcomes'][] = $bestkey;
        }
        unset($lesson);
        // Full lesson titles instead of a sentence cut off with "…".
        $titlefrom = static function (string $text): string {
            $text = trim(preg_replace('/\s*\([^)]*\)/u', '', $text));
            $text = rtrim($text, " .;:");
            if (\core_text::strlen($text) > 140) {
                $cut = \core_text::substr($text, 0, 140);
                $text = rtrim(\core_text::substr($cut, 0, (int)strrpos($cut, ' ')), ' ,;:') . '…';
            }
            return $text;
        };
        $bykey = [];
        foreach ($outcomes as $outcome) {
            $bykey[!empty($outcome['code']) ? (string)$outcome['code'] : (string)($outcome['text'] ?? '')] = (string)($outcome['text'] ?? '');
        }
        foreach ($lessons as &$lesson) {
            $title = trim((string)($lesson['title'] ?? ''));
            if ($title !== '' && !preg_match('/(…|\.\.\.)$/u', $title)) {
                continue;
            }
            $prefix = $norm(preg_replace('/(…|\.\.\.)$/u', '', $title));
            $full = '';
            foreach ($bykey as $text) {
                if ($prefix !== '' && strpos($norm($text), $prefix) === 0) {
                    $full = $text;
                    break;
                }
            }
            if ($full === '' && !empty($lesson['outcomes'][0]) && isset($bykey[$lesson['outcomes'][0]])) {
                $full = $bykey[$lesson['outcomes'][0]];
            }
            if ($full !== '') {
                $lesson['title'] = $titlefrom($full);
                $repairs['titles'] = true;
            }
        }
        unset($lesson);
        // A title that lost its first word ("and report emergency situations") is rebuilt from its outcome.
        foreach ($lessons as &$lesson) {
            $title = trim(preg_replace('/^\s*(unit|course|lesson|module)\s*\d+\s*[·:\-–.]\s*/iu', '', (string)($lesson['title'] ?? '')));
            $first = (string)strtok($title, ' ');
            if ($title === '' || !preg_match('/^\p{Ll}{2,}[,;:]?$/u', $first)) {
                continue;
            }
            $full = '';
            $needle = $norm($title);
            foreach ($lesson['outcomes'] as $key) {
                if (isset($bykey[$key]) && $needle !== '' && strpos($norm($bykey[$key]), $needle) !== false) {
                    $full = $bykey[$key];
                    break;
                }
            }
            if ($full === '' && !empty($lesson['outcomes'][0]) && isset($bykey[$lesson['outcomes'][0]])) {
                $full = $bykey[$lesson['outcomes'][0]];
            }
            $lesson['title'] = $full !== '' ? $titlefrom($full) : \core_text::strtoupper(\core_text::substr($title, 0, 1))
                . \core_text::substr($title, 1);
            $repairs['titles'] = true;
        }
        unset($lesson);
        // The course is called what the teacher called it.
        $units = array_values((array)($plan['units'] ?? []));
        $name = trim($name);
        if ($name !== '') {
            foreach ($units as $u => &$unit) {
                $title = trim((string)($unit['title'] ?? ''));
                $looksbroken = $title === '' || preg_match('/(…|\.\.\.)$/u', $title);
                foreach ($lessons as $lesson) {
                    if ($title !== '' && $norm($title) !== '' && strpos($norm((string)$lesson['title']), $norm(rtrim($title, '….'))) === 0) {
                        $looksbroken = true;
                    }
                }
                if (count($units) === 1 && ($looksbroken || $title !== $name)) {
                    $unit['title'] = $name;
                } else if ($looksbroken) {
                    $unit['title'] = $name . ' – ' . get_string('partn', 'local_aicoursebuilder', $u + 1);
                }
            }
            unset($unit);
        }
        // A long sample belongs in the lessons it matches, unless the teacher wants it in every lesson.
        $sample = '';
        foreach ($sources as $source) {
            if (stripos((string)($source['name'] ?? ''), 'sample') !== false) {
                $sample .= ' ' . (string)$source['text'];
            }
        }
        $ccmode = (string)(($delivery['toolModes'] ?? [])['mod_contentcreator'] ?? 'auto');
        $withcc = array_keys(array_filter($lessons, static fn($l) => in_array('mod_contentcreator', (array)($l['tools'] ?? []), true)));
        if ($sample !== '' && $ccmode !== 'always' && count($lessons) > 2 && count($withcc) > count($lessons) / 2) {
            $words = static function (string $text) use ($norm): array {
                return array_unique(array_filter(explode(' ', $norm($text)), static fn($w) => \core_text::strlen($w) >= 5));
            };
            $samplewords = array_flip($words($sample));
            $scores = [];
            foreach ($withcc as $i) {
                $text = (string)$lessons[$i]['title'];
                foreach ($lessons[$i]['outcomes'] as $key) {
                    $text .= ' ' . ($bykey[$key] ?? $key);
                }
                $scores[$i] = count(array_intersect_key(array_flip($words($text)), $samplewords));
            }
            arsort($scores);
            $keep = array_slice(array_keys(array_filter($scores, static fn($v) => $v >= 2)), 0, max(1, (int)ceil(count($lessons) / 3)), false);
            if (!$keep) {
                $keep = [array_key_first($scores)];
            }
            foreach ($withcc as $i) {
                if (!in_array($i, $keep, true)) {
                    $left = array_values(array_diff((array)$lessons[$i]['tools'], ['mod_contentcreator']));
                    if ($left) {
                        $lessons[$i]['tools'] = $left;
                        $repairs['contentcreator'] = true;
                    }
                }
            }
        }
        $plan['outcomes'] = $outcomes;
        $plan['lessons'] = $lessons;
        $plan['units'] = $units;
        if ($repairs) {
            $plan['repairs'] = $repairs;
        }
        return $plan;
    }

    /**
     * Tidy a planner response before it is stored.
     *
     * Titles never repeat the "Unit 01 ·" or "Lesson 3 ·" prefix that Moodle adds itself, and every
     * lesson gets the default teaching, practice and check activities when the plan left them out.
     *
     * @param array $plan
     * @return array
     */
    public static function normalise_plan(array $plan, ?array $allowed = null, ?array $modes = null): array {
        $usable = static function (string $component) use ($allowed): bool {
            return ($allowed === null || in_array($component, $allowed, true))
                && generator_runtime::component_available($component);
        };
        $strip = static function (string $title): string {
            $title = preg_replace('/^\s*(unit|course|lesson|module)\s*\d+\s*[·:\-–.]\s*/iu', '', $title);
            $title = trim((string)$title);
            if (\core_text::strlen($title) <= 140) {
                return $title;
            }
            $cut = \core_text::substr($title, 0, 140);
            return rtrim(\core_text::substr($cut, 0, (int)strrpos($cut, ' ')), ' ,;:') . '…';
        };
        foreach ($plan['units'] as &$unit) {
            $unit['title'] = $strip((string)($unit['title'] ?? ''));
        }
        unset($unit);
        // Drop lessons that repeat an earlier lesson, renumber the rest and update each unit's lesson list.
        $seen = [];
        $map = [];
        $kept = [];
        foreach ($plan['lessons'] as $lesson) {
            $key = \core_text::strtolower($strip((string)($lesson['title'] ?? '')));
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $old = (int)($lesson['number'] ?? 0);
            $lesson['number'] = count($kept) + 1;
            $map[$old] = $lesson['number'];
            $kept[] = $lesson;
        }
        if (count($kept) < count($plan['lessons'])) {
            $plan['lessons'] = $kept;
            foreach ($plan['units'] as &$unit) {
                $unit['lessons'] = array_values(array_filter(array_map(static function ($n) use ($map) {
                    return $map[(int)$n] ?? null;
                }, $unit['lessons'] ?? [])));
            }
            unset($unit);
            $plan['units'] = array_values(array_filter($plan['units'], static function ($unit) {
                return !empty($unit['lessons']);
            }));
        }
        $teaching = ['mod_visuallearning', 'mod_contentcreator', 'mod_productexplainer'];
        foreach ($plan['lessons'] as &$lesson) {
            $lesson['title'] = $strip((string)($lesson['title'] ?? ''));
            // The old Slides activity is retired; plans that still ask for it get AI Slide Flow.
            $tools = array_map(static function ($t) {
                return (string)$t === 'mod_slides' ? 'mod_productexplainer' : (string)$t;
            }, $lesson['tools'] ?? []);
            $tools = array_values(array_unique($tools));
            $hasteaching = (bool)array_intersect($teaching, $tools);
            $add = [];
            if (!$hasteaching && $usable('mod_visuallearning')) {
                $add[] = 'mod_visuallearning';
            }
            foreach (['mod_aiactivities', 'mod_aiknowledgecheck'] as $core) {
                if (!in_array($core, $tools, true) && $usable($core) && ($modes === null || ($modes[$core] ?? '') === 'always')) {
                    $add[] = $core;
                }
            }
            // Tools the teacher asked for in every lesson.
            foreach ((array)$modes as $component => $mode) {
                if ($mode === 'always' && is_string($component) && $usable($component) && !in_array($component, $tools, true)) {
                    $add[] = $component;
                }
            }
            if ($allowed !== null) {
                // The planner may also suggest a tool the teacher switched off.
                $tools = array_values(array_filter($tools, static fn($t) => in_array($t, $allowed, true)
                    || in_array($t, ['mod_courseinfo', 'mod_learningmapping'], true)));
            }
            // Keep the order a learner meets them: teach, practise, check, then any tagged extras.
            $order = array_merge($teaching, ['mod_aiactivities', 'mod_aiknowledgecheck']);
            $merged = array_values(array_unique(array_merge($tools, $add)));
            usort($merged, static function (string $a, string $b) use ($order): int {
                $ia = array_search($a, $order, true);
                $ib = array_search($b, $order, true);
                return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
            });
            $lesson['tools'] = $merged;
        }
        unset($lesson);
        return $plan;
    }

    /**
     * Name the mapping section and give every section an icon in AI Course Format.
     *
     * @param int $courseid
     * @param array $lessons lesson records keyed by id
     * @param int $mappingsection section number of the mapping section
     * @return void
     */
    public static function finish_sections(int $courseid, array $lessons, int $mappingsection): void {
        global $DB;
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $mappingsection]);
        if ($section && trim((string)$section->name) === '') {
            $section->name = get_string('mappingsectionname', 'local_aicoursebuilder');
            $DB->update_record('course_sections', $section);
        }
        // Mapping is a trainer and auditor document, not a lesson: keep its section hidden
        // from learners. Teachers still see it and can show it if they choose.
        if ($section && (int)$section->visible === 1) {
            if (method_exists('\\core_courseformat\\local\\sectionactions', 'set_visibility')) {
                // Moodle 5.2 and later.
                $sectioninfo = get_fast_modinfo($courseid)->get_section_info($mappingsection);
                \core_courseformat\formatactions::section($courseid)->set_visibility($sectioninfo, false);
            } else {
                global $CFG;
                require_once($CFG->dirroot . '/course/lib.php');
                set_section_visible($courseid, $mappingsection, 0);
            }
        }
        if (!class_exists('\format_aicourse\local\icons') || get_course($courseid)->format !== 'aicourse') {
            return;
        }
        $library = \format_aicourse\local\icons::get_library();
        $n = 0;
        foreach ($lessons as $lesson) {
            $n++;
            $icon = 'num-' . min(10, $n);
            if (!empty($lesson->sectionid) && isset($library[$icon])) {
                \format_aicourse\local\icons::set_section_icon($courseid, (int)$lesson->sectionid, $icon);
            }
        }
        if ($section && isset($library['target'])) {
            \format_aicourse\local\icons::set_section_icon($courseid, (int)$section->id, 'target');
        }
        rebuild_course_cache($courseid, true);
    }

    /**
     * Create an activity through Moodle's supported module API and return its module info.
     *
     * @param \stdClass $course
     * @param string $modulename
     * @param int $sectionnumber
     * @param string $name
     * @param \stdClass|null $lesson
     * @param int $programid
     * @param \stdClass|null $framework
     * @return \stdClass
     */
    private static function create_module_instance(
        \stdClass $course,
        string $modulename,
        int $sectionnumber,
        string $name,
        ?\stdClass $lesson,
        int $programid,
        ?\stdClass $framework = null
    ): \stdClass {
        global $DB;
        $moduleid = $DB->get_field('modules', 'id', ['name' => $modulename], MUST_EXIST);
        $description = '';
        if ($lesson) {
            $outcomes = \local_aicoursebuilder\task\generate_activity::lesson_outcomes(
                (int)$lesson->programid,
                json_decode((string)$lesson->outcomesjson, true) ?: []
            );
            $description = implode("\n", array_map(static function ($outcome) {
                return clean_param(is_array($outcome) ? (string)($outcome['text'] ?? '') : (string)$outcome, PARAM_TEXT);
            }, $outcomes));
        }
        // Course Report uses Moodle's native completion state for its class grid.
        // Only enable view completion for lesson activities whose own module API
        // explicitly supports it; do not mark course-level setup activities complete.
        $tracksviews = $lesson && plugin_supports(
            'mod',
            $modulename,
            FEATURE_COMPLETION_TRACKS_VIEWS
        );
        $info = (object)[
            'modulename' => $modulename,
            'module' => (int)$moduleid,
            'course' => (int)$course->id,
            'section' => $sectionnumber,
            'name' => clean_param($name, PARAM_TEXT),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'groupmode' => NOGROUPS,
            'groupingid' => 0,
            'completion' => $tracksviews ? COMPLETION_TRACKING_AUTOMATIC : COMPLETION_TRACKING_NONE,
            'completionview' => $tracksviews ? 1 : 0,
            'completionexpected' => 0,
            'availability' => null,
            'idnumber' => '',
            'cmidnumber' => '',
        ];
        // Each lesson activity completes on its own learning rule, not on a view, so the
        // Course Report and parent reports show work actually done, not pages opened.
        $learningrules = [
            'aiknowledgecheck' => 'completionallcorrect',
            'aiactivities' => 'completionallcorrect',
            'aibranchedscenario' => 'completionfinish',
            'aianatomy' => 'completionfinish',
            'visuallearning' => 'completionlistened',
            'contentcreator' => 'completionviewallslides',
            'aisoftskills' => 'completionallscenes',
            'aisafetyhazards' => 'completionallscenes',
        ];
        if (
            $lesson && isset($learningrules[$modulename])
                && $DB->get_manager()->field_exists($modulename, $learningrules[$modulename])
        ) {
            $info->completion = COMPLETION_TRACKING_AUTOMATIC;
            $info->completionview = 0;
            $info->{$learningrules[$modulename]} = 1;
        }
        // Give each lesson activity the end of its teaching week as its expected completion
        // date, from the teacher's schedule, so Course Report and parent reports can tell
        // "on track" from "behind". Self-paced programs have no weekly schedule.
        if ($lesson && !empty($info->completion)) {
            $delivery = json_decode((string)$DB->get_field('local_aicoursebuilder_program', 'deliveryjson', ['id' => $programid]), true) ?: [];
            if (empty($delivery['selfPaced']) && !empty($course->startdate)) {
                $perweek = max(1, (int)($delivery['sessionsPerWeek'] ?? 1));
                $first = (int)$DB->get_field_sql(
                    'SELECT MIN(lessonnumber) FROM {local_aicoursebuilder_lesson} WHERE unitid = ?',
                    [(int)$lesson->unitid]
                );
                $week = intdiv(max(0, (int)$lesson->lessonnumber - max(1, $first)), $perweek);
                $info->completionexpected = (int)$course->startdate + ($week + 1) * WEEKSECS - 1;
            }
        }
        if ($lesson) {
            // Narration accent and the place the pictures reflect follow the course, not Australia.
            [$locale, $local] = self::locale_for($framework);
            if ($modulename === 'visuallearning') {
                $info->sourcecontent = $description;
                $info->objectives = $description;
                $info->language = $locale;
                $info->imageculture = $local ? 'language' : 'international';
                $level = strtolower((string)($framework->level ?? ''));
                $info->learnerlevel = str_contains($level, 'primary') ? 'primary'
                    : (str_contains($level, 'secondary') ? 'secondary' : 'adult');
            } else if ($modulename === 'aiactivities') {
                $info->content = $description;
                $info->activitycount = 5;
                $info->language = $locale;
                $info->voiceoverenabled = 0;
            } else if ($modulename === 'aiknowledgecheck') {
                $info->sourcecontext = json_encode(['topics' => $description, 'outcomes' => $outcomes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $info->voiceoverenabled = 0;
                $info->questioncount = 0;
            } else if ($modulename === 'productexplainer') {
                $info->voicelanguage = $locale;
            } else if ($modulename === 'aianatomy') {
                $info->pack = self::anatomy_pack_for($description);
            }
        } else if ($framework) {
            $frameworkdata = [
                'country' => (string)$framework->country,
                'state' => (string)$framework->state,
                'sector' => (string)$framework->sector,
                'level' => (string)$framework->level,
                'subject' => (string)$framework->subject,
                'learnerProfile' => (string)$framework->learnerprofile,
                'language' => (string)$framework->language,
                'spelling' => (string)$framework->spelling,
                // The outcomes the teacher pasted, limited to this course's unit.
                'outcomes' => self::unit_outcomes((int)$course->id),
                // How the course is actually taught, so Course Information does not describe a
                // scheduled class as "self-paced" (or the other way round).
                'delivery' => self::delivery_summary($programid),
            ];
            $info->framework = self::service_framework((string)$framework->framework);
            $info->frameworkdata = json_encode($frameworkdata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($modulename === 'courseinfo') {
                $info->enableasqa = $info->framework === 'vet' ? 1 : 0;
                // Volume of learning needs the unit's nominal hours, which only VET programs give.
                $nominalhours = (int)($framework->nominalhours ?? 0);
                $info->enablevol = ($info->framework === 'vet' && $nominalhours > 0) ? 1 : 0;
            }
            if ($info->framework === 'vet') {
                $info->unitcode = (string)($framework->unitcode ?? '');
                if ($modulename === 'courseinfo') {
                    $info->unittitle = (string)($framework->unittitle ?? '');
                    $info->nominalhours = (int)($framework->nominalhours ?? 0);
                }
            }
        }
        $added = add_moduleinfo($info, $course);
        if (empty($added->coursemodule)) {
            throw new \moodle_exception('activitycreationfailed', 'local_aicoursebuilder', '', $modulename);
        }
        return $added;
    }

    /**
     * The framework id the LMS Labs Course Information and Mapping services accept.
     *
     * @param string $framework
     * @return string vet, au_curriculum, sg_moe, university, workplace or custom
     */
    public static function service_framework(string $framework): string {
        $f = \core_text::strtolower(trim($framework));
        $aliases = ['au_training_package' => 'vet', 'training_package' => 'vet', 'rto' => 'vet', 'au_acara' => 'au_curriculum',
            'acara' => 'au_curriculum', 'singapore' => 'sg_moe', 'moe' => 'sg_moe', 'higher_ed' => 'university'];
        $f = $aliases[$f] ?? $f;
        return in_array($f, ['vet', 'au_curriculum', 'sg_moe', 'university', 'workplace', 'custom'], true) ? $f : 'custom';
    }

    /**
     * The curriculum outcomes taught in the unit that owns a course, in plan order.
     *
     * Course Information and Mapping use these so each course is described and mapped
     * against the outcomes the teacher supplied, not the whole program.
     *
     * @param int $courseid
     * @return string[]
     */
    public static function unit_outcomes(int $courseid): array {
        global $DB;
        $unit = $DB->get_record('local_aicoursebuilder_unit', ['courseid' => $courseid], 'id, programid');
        if (!$unit) {
            return [];
        }
        $norm = static function (string $text): string {
            return \core_text::strtolower(rtrim(trim($text), '. '));
        };
        $wanted = [];
        foreach ($DB->get_records('local_aicoursebuilder_lesson', ['unitid' => $unit->id], 'lessonnumber ASC', 'id, outcomesjson') as $lesson) {
            foreach (json_decode((string)$lesson->outcomesjson, true) ?: [] as $outcome) {
                $text = is_array($outcome) ? (string)($outcome['text'] ?? '') : (string)$outcome;
                if (trim($text) !== '') {
                    $wanted[$norm($text)] = true;
                }
            }
        }
        $out = [];
        foreach ($DB->get_records('local_aicoursebuilder_outcome', ['programid' => $unit->programid], 'sortorder ASC') as $record) {
            $text = clean_param((string)$record->outcometext, PARAM_TEXT);
            if (!$wanted || isset($wanted[$norm($text)]) || (!empty($record->code) && isset($wanted[$norm((string)$record->code)]))) {
                $out[(int)$record->id] = $text;
            }
        }
        return $out;
    }

    /**
     * Match tagged anatomy outcomes to a pack that the existing activity actually teaches.
     *
     * @param string $description
     * @return string
     */
    /**
     * The teacher's schedule in words, for Course Information.
     *
     * @param int $programid
     * @return string
     */
    private static function delivery_summary(int $programid): string {
        global $DB;
        $d = json_decode((string)$DB->get_field('local_aicoursebuilder_program', 'deliveryjson', ['id' => $programid]), true) ?: [];
        if (!empty($d['selfPaced'])) {
            $hours = (float)($d['totalLearnerHours'] ?? 0);
            return 'Self-paced' . ($hours > 0 ? ', about ' . $hours . ' learner hours in total' : '') . '.';
        }
        return sprintf(
            'Scheduled: %d week(s), %d session(s) a week, %d minutes each.',
            (int)($d['weeks'] ?? 0),
            (int)($d['sessionsPerWeek'] ?? 0),
            (int)($d['minutesPerSession'] ?? 0)
        );
    }

    /**
     * Whether an AI Anatomy pack fits the lesson.
     *
     * @param \stdClass $lesson
     * @return bool
     */
    private static function anatomy_pack_matches(\stdClass $lesson): bool {
        $outcomes = \local_aicoursebuilder\task\generate_activity::lesson_outcomes(
            (int)$lesson->programid,
            json_decode((string)$lesson->outcomesjson, true) ?: []
        );
        $text = implode("\n", array_map(static fn($o) => is_array($o) ? (string)($o['text'] ?? '') : (string)$o, $outcomes));
        try {
            self::anatomy_pack_for($text);
            return true;
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * The AI Anatomy pack that best fits the lesson's description.
     *
     * @param string $description
     * @return string
     */
    private static function anatomy_pack_for(string $description): string {
        $text = \core_text::strtolower($description);
        $rules = [
            '/\b(heart|cardiac|circulatory|cardiovascular)\b/' => 'heart',
            '/\b(lung|lungs|respirat|breathing)\b/' => 'respiratory',
            '/\b(digest|stomach|intestin|gastro)\b/' => 'digestive',
            '/\b(urinary|kidney|kidneys|bladder)\b/' => 'urinary',
            '/\b(brain|nervous system|neuron|neurolog)\b/' => 'brain',
            '/\b(skull|cranial)\b/' => 'skull',
            '/\b(spine|spinal|vertebr)\b/' => 'vertebral_column',
            '/\b(rib|ribs|thoracic|chest cage)\b/' => 'thoracic_cage',
            '/\b(pelvis|hip|thigh|lower limb|leg|knee)\b/' => 'pelvis_lower_limb_right',
            '/\b(foot|feet|ankle)\b/' => 'foot_right',
            '/\b(hand|hands|finger|fingers|wrist)\b/' => 'hand_right',
            '/\b(arm|upper limb|shoulder|elbow)\b/' => 'upper_limb_right',
            '/\b(muscle|muscular)\b.*\b(leg|lower limb|thigh|foot)\b|\b(leg|lower limb|thigh|foot)\b.*\b(muscle|muscular)\b/' =>
                'muscles_lower_limb_right',
            '/\b(muscle|muscular)\b.*\b(arm|upper limb|shoulder)\b|\b(arm|upper limb|shoulder)\b.*\b(muscle|muscular)\b/' =>
                'muscles_upper_limb_right',
            '/\b(muscle|muscular)\b/' => 'muscles_trunk',
        ];
        foreach ($rules as $pattern => $pack) {
            if (preg_match($pattern, $text)) {
                if (!isset(\mod_aianatomy\local\pack::list()[$pack])) {
                    throw new \moodle_exception('generationfailed', 'local_aicoursebuilder');
                }
                return $pack;
            }
        }
        throw new \moodle_exception('generationfailed', 'local_aicoursebuilder');
    }

    /**
     * Find the one per-unit course-level generation job.
     *
     * @param array $jobs
     * @param array $lessons
     * @param string $component
     * @return \stdClass
     */
    private static function find_course_job(array $jobs, array $lessons, string $component): \stdClass {
        foreach ($lessons as $lesson) {
            if (isset($jobs[$lesson->id][$component])) {
                return $jobs[$lesson->id][$component];
            }
        }
        throw new \moodle_exception('invalidplan', 'local_aicoursebuilder');
    }

    /**
     * Moodle module name for a component.
     *
     * @param string $component
     * @return string
     */
    private static function module_name(string $component): string {
        $map = [
            'mod_visuallearning' => 'visuallearning',
            'mod_contentcreator' => 'contentcreator',
            'mod_productexplainer' => 'productexplainer',
            'mod_aiactivities' => 'aiactivities',
            'mod_aiknowledgecheck' => 'aiknowledgecheck',
            'mod_aisoftskills' => 'aisoftskills',
            'mod_aibranchedscenario' => 'aibranchedscenario',
            'mod_aisafetyhazards' => 'aisafetyhazards',
            'mod_aianatomy' => 'aianatomy',
        ];
        if (!isset($map[$component])) {
            throw new \moodle_exception('invalidplan', 'local_aicoursebuilder');
        }
        return $map[$component];
    }

    /**
     * Short activity label used in activity names.
     *
     * @param string $component
     * @return string
     */
    private static function tool_label(string $component): string {
        $labels = [
            'mod_visuallearning' => 'Visual Learning',
            'mod_contentcreator' => 'Content Creator',
            'mod_productexplainer' => 'Slide Flow',
            'mod_aiactivities' => 'Learning Activities',
            'mod_aiknowledgecheck' => 'Knowledge Check',
            'mod_aisoftskills' => 'Soft Skills',
            'mod_aibranchedscenario' => 'Branched Scenario',
            'mod_aisafetyhazards' => 'Safety Hazards',
            'mod_aianatomy' => 'Anatomy',
        ];
        return $labels[$component] ?? $component;
    }

    /**
     * Unit number that contains a lesson.
     *
     * @param array $units
     * @param int $lessonnumber
     * @return int
     */
    private static function unit_for_lesson(array $units, int $lessonnumber): int {
        foreach ($units as $unit) {
            if (in_array($lessonnumber, array_map('intval', $unit['lessons'] ?? []), true)) {
                return (int)$unit['number'];
            }
        }
        return 0;
    }


    /**
     * Allow only the controlled Course Builder outcome tags.
     *
     * @param mixed $tags
     * @return array
     */
    private static function clean_outcome_tags($tags): array {
        $tags = array_values(array_filter((array)$tags, 'is_string'));
        return array_values(array_intersect(
            ['workplace_safety', 'anatomy_physiology'],
            $tags
        ));
    }
}
