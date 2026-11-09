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

defined('MOODLE_INTERNAL') || die();

/**
 * Stores editable plans and creates the approved course structure.
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
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
        $plan = self::normalise_plan($plan, isset($delivery['allowedTools']) ? (array)$delivery['allowedTools'] : null);
        self::assert_tagged_tools($plan);
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
            $courselevel = $DB->get_records_select('local_aicoursebuilder_job',
                'programid = ? AND lessonid = ? AND component IN (?, ?)',
                [$job->programid, (int)array_key_first($first), 'mod_courseinfo', 'mod_learningmapping']);
            foreach ($courselevel as $cl) {
                $ids[] = $cl->id;
            }
        }
        $now = time();
        foreach ($ids as $id) {
            $DB->update_record('local_aicoursebuilder_job', (object)[
                'id' => $id, 'status' => 'queued', 'externaljobid' => null, 'error' => null,
                'resultjson' => null, 'timemodified' => $now,
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
    public static function approve(int $programid, int $userid, array $plan): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        $program = $DB->get_record('local_aicoursebuilder_program', ['id' => $programid, 'userid' => $userid], '*', MUST_EXIST);
        if ($program->status !== 'planned' || $program->categoryid) {
            throw new \moodle_exception('programnotapprovable', 'local_aicoursebuilder');
        }
        if (empty($plan['outcomes']) || empty($plan['units']) || empty($plan['lessons'])) {
            throw new \moodle_exception('invalidplan', 'local_aicoursebuilder');
        }
        self::assert_tagged_tools($plan);
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
                $DB->insert_record('local_aicoursebuilder_job', (object)[
                    'programid' => $programid,
                    'lessonid' => $lessonid,
                    'component' => $component,
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
                    'error' => null,
                    'resultjson' => null,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
        }
        $category = \core_course_category::create((object)[
            'name' => $program->name,
            'idnumber' => 'aicb-' . $programid,
            'parent' => 0,
        ]);
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
        $coursestart = usergetmidnight(time());
        foreach ($units as $unit) {
            $unitlessoncount = $DB->count_records('local_aicoursebuilder_lesson', ['unitid' => $unit->id]);
            $unitweeks = max(1, (int)ceil($unitlessoncount / $perweek));
            $course = create_course((object)[
                'startdate' => $coursestart,
                'enddate' => $selfpaced ? 0 : $coursestart + $unitweeks * WEEKSECS,
                'fullname' => sprintf('%02d · %s', $unit->unitnumber, $unit->title),
                'shortname' => sprintf('AICB-%d-%02d', $programid, $unit->unitnumber),
                'category' => $category->id,
                'visible' => 0,
                'format' => 'aicourse',
                'numsections' => 0,
                // Course Report reads native activity completion, which needs course completion on.
                'enablecompletion' => 1,
            ]);
            $unit->courseid = $course->id;
            $DB->update_record('local_aicoursebuilder_unit', $unit);
            $lessons = $DB->get_records('local_aicoursebuilder_lesson', ['unitid' => $unit->id], 'lessonnumber ASC');
            $sectionnumbers = [];
            foreach ($lessons as $lesson) {
                $sectionnumbers[] = $lesson->lessonnumber;
            }
            $mappingsection = count($lessons) + 1;
            $sectionnumbers[] = $mappingsection;
            course_create_sections_if_missing($course->id, $sectionnumbers);
            $framework = $DB->get_record('local_aicoursebuilder_framework', ['programid' => $programid], '*', MUST_EXIST);
            foreach ($lessons as $lesson) {
                $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $lesson->lessonnumber], '*', MUST_EXIST);
                $section->name = get_string('lessonsection', 'local_aicoursebuilder', [
                    'number' => $lesson->lessonnumber,
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
                        (int)$lesson->lessonnumber,
                        'Lesson ' . (int)$lesson->lessonnumber . ' — ' . self::tool_label((string)$component),
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
     * Tidy a planner response before it is stored.
     *
     * Titles never repeat the "Unit 01 ·" or "Lesson 3 ·" prefix that Moodle adds itself, and every
     * lesson gets the default teaching, practice and check activities when the plan left them out.
     *
     * @param array $plan
     * @return array
     */
    public static function normalise_plan(array $plan, ?array $allowed = null): array {
        $usable = static function (string $component) use ($allowed): bool {
            return ($allowed === null || in_array($component, $allowed, true))
                && generator_runtime::component_available($component);
        };
        $strip = static function (string $title): string {
            $title = preg_replace('/^\s*(unit|course|lesson|module)\s*\d+\s*[·:\-–.]\s*/iu', '', $title);
            $title = trim((string)$title);
            return \core_text::strlen($title) > 90 ? rtrim(\core_text::substr($title, 0, 88), ' ,;:') . '…' : $title;
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
            if (!in_array('mod_aiactivities', $tools, true) && $usable('mod_aiactivities')) {
                $add[] = 'mod_aiactivities';
            }
            if (!in_array('mod_aiknowledgecheck', $tools, true) && $usable('mod_aiknowledgecheck')) {
                $add[] = 'mod_aiknowledgecheck';
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
        if (!class_exists('\format_aicourse\local\icons')) {
            return;
        }
        $library = \format_aicourse\local\icons::get_library();
        foreach ($lessons as $lesson) {
            $icon = 'num-' . min(10, max(1, (int)$lesson->lessonnumber));
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
            $outcomes = json_decode((string)$lesson->outcomesjson, true) ?: [];
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
        if ($lesson && isset($learningrules[$modulename])
                && $DB->get_manager()->field_exists($modulename, $learningrules[$modulename])) {
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
                $first = (int)$DB->get_field_sql('SELECT MIN(lessonnumber) FROM {local_aicoursebuilder_lesson} WHERE unitid = ?',
                    [(int)$lesson->unitid]);
                $week = intdiv(max(0, (int)$lesson->lessonnumber - max(1, $first)), $perweek);
                $info->completionexpected = (int)$course->startdate + ($week + 1) * WEEKSECS - 1;
            }
        }
        if ($lesson) {
            if ($modulename === 'visuallearning') {
                $info->sourcecontent = $description;
                $info->objectives = $description;
                $level = strtolower((string)($framework->level ?? ''));
                $info->learnerlevel = str_contains($level, 'primary') ? 'primary'
                    : (str_contains($level, 'secondary') ? 'secondary' : 'adult');
            } else if ($modulename === 'aiactivities') {
                $info->content = $description;
                $info->activitycount = 5;
                $info->language = 'en-AU';
                $info->voiceoverenabled = 0;
            } else if ($modulename === 'aiknowledgecheck') {
                $info->sourcecontext = json_encode(['topics' => $description, 'outcomes' => $outcomes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $info->voiceoverenabled = 0;
                $info->questioncount = 0;
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
        return sprintf('Scheduled: %d week(s), %d session(s) a week, %d minutes each.',
            (int)($d['weeks'] ?? 0), (int)($d['sessionsPerWeek'] ?? 0), (int)($d['minutesPerSession'] ?? 0));
    }

    private static function anatomy_pack_matches(\stdClass $lesson): bool {
        $outcomes = json_decode((string)$lesson->outcomesjson, true) ?: [];
        $text = implode("\n", array_map(static fn($o) => is_array($o) ? (string)($o['text'] ?? '') : (string)$o, $outcomes));
        try {
            self::anatomy_pack_for($text);
            return true;
        } catch (\moodle_exception $e) {
            return false;
        }
    }

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
     * Keep domain-specific generators restricted to lessons linked to tagged outcomes.
     *
     * @param array $plan
     * @return void
     */
    private static function assert_tagged_tools(array $plan): void {
        $outcomes = [];
        foreach ($plan['outcomes'] ?? [] as $outcome) {
            $tags = self::clean_outcome_tags($outcome['tags'] ?? []);
            $identifiers = [];
            if (!empty($outcome['code'])) {
                $identifiers[] = clean_param($outcome['code'], PARAM_TEXT);
            }
            if (!empty($outcome['text'])) {
                $identifiers[] = clean_param($outcome['text'], PARAM_TEXT);
            }
            foreach ($identifiers as $identifier) {
                $outcomes[$identifier] = $tags;
            }
        }
        foreach ($plan['lessons'] ?? [] as $lesson) {
            $tags = [];
            foreach ((array)($lesson['outcomes'] ?? []) as $reference) {
                if (is_array($reference)) {
                    $reference = $reference['code'] ?? $reference['text'] ?? '';
                }
                $reference = clean_param((string)$reference, PARAM_TEXT);
                $tags = array_merge($tags, $outcomes[$reference] ?? []);
            }
            $tags = array_unique($tags);
            $tools = (array)($lesson['tools'] ?? []);
            if (in_array('mod_aisafetyhazards', $tools, true) && !in_array('workplace_safety', $tags, true)) {
                throw new \moodle_exception('toolrequiresoutcometag', 'local_aicoursebuilder', '', 'mod_aisafetyhazards');
            }
            if (in_array('mod_aianatomy', $tools, true) && !in_array('anatomy_physiology', $tags, true)) {
                throw new \moodle_exception('toolrequiresoutcometag', 'local_aicoursebuilder', '', 'mod_aianatomy');
            }
        }
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
