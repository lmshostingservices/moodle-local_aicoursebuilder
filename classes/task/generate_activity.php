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

namespace local_aicoursebuilder\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Runs one plugin-owned generation adapter with bounded retries.
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class generate_activity extends \core\task\adhoc_task {
    /** @var int Longest one generation attempt may run before it is treated as failed. */
    private const MAX_JOB_SECONDS = 3600;

    /**
     * Generate one planned activity.
     */
    public function execute(): void {
        global $CFG, $DB;
        // Some native activity adapters use Moodle's curl wrapper while polling
        // a scheduled generation job. Core does not autoload filelib in CLI tasks.
        require_once($CFG->libdir . '/filelib.php');
        $data = (array)$this->get_custom_data();
        $jobid = (int)($data['jobid'] ?? 0);
        if (!$jobid) {
            return;
        }
        $job = $DB->get_record('local_aicoursebuilder_job', ['id' => $jobid]);
        if (!$job || in_array($job->status, ['complete', 'failed', 'blocked'], true)) {
            return;
        }
        if (!$job->cmid) {
            $job->status = 'blocked';
            $job->error = 'No native Moodle activity instance is attached to this generation job.';
            $job->timemodified = time();
            $DB->update_record('local_aicoursebuilder_job', $job);
            return;
        }

        $factory = \core\lock\lock_config::get_lock_factory('local_aicoursebuilder');
        $lock = null;
        for ($slot = 0; $slot < 3 && !$lock; $slot++) {
            $lock = $factory->get_lock('generator-slot-' . $slot, 0);
        }
        if (!$lock) {
            $this->reschedule(30);
            return;
        }
        try {
            $generationstarted = false;
            $class = '\\' . $job->component . '\\aicoursebuilder\\generator';
            if (!class_exists($class) || !$class::is_available()) {
                $job->status = 'blocked';
                $job->error = 'The plugin has no complete native Course Builder adapter. No generation request or credit charge was made.';
                $job->timemodified = time();
                $DB->update_record('local_aicoursebuilder_job', $job);
                return;
            }
            $lesson = $DB->get_record('local_aicoursebuilder_lesson', ['id' => $job->lessonid], '*', MUST_EXIST);
            $program = $DB->get_record('local_aicoursebuilder_program', ['id' => $job->programid], '*', MUST_EXIST);
            $owner = $DB->get_record('user', ['id' => (int)$program->userid, 'deleted' => 0]);
            if (!$owner || !empty($owner->suspended)) {
                $job->status = 'blocked';
                $job->error = 'The teacher who approved this build is unavailable; no generation request or credit charge was made.';
                $job->timemodified = time();
                $DB->update_record('local_aicoursebuilder_job', $job);
                return;
            }
            // Scheduled tasks otherwise run as Moodle's guest user. Native plugin
            // adapters must act as the teacher who approved the course so that
            // ownership, capability checks, and credit attribution stay correct.
            \core\session\manager::set_user($owner);
            $framework = $DB->get_record('local_aicoursebuilder_framework', ['programid' => $job->programid]);
            if ($this->wait_for_course_level_dependencies($job, $lesson)) {
                return;
            }
            $outcomes = json_decode($lesson->outcomesjson, true) ?: [];
            $outcometexts = [];
            foreach ($outcomes as $outcome) {
                $text = is_array($outcome) ? trim((string)($outcome['text'] ?? '')) : trim((string)$outcome);
                if ($text !== '') {
                    $outcometexts[] = $text;
                }
            }
            $lessoncontent = implode("\n", $outcometexts);
            $sourcecontent = $lessoncontent;
            // Preserve the existing VET input exactly. Non-VET plans also carry their
            // stored curriculum into each native adapter so short lesson labels remain
            // grounded and tools that require substantive source text can start safely.
            if (($framework->framework ?? 'vet') !== 'vet') {
                $curriculumrecords = $DB->get_records(
                    'local_aicoursebuilder_source',
                    ['programid' => $job->programid],
                    'id ASC'
                );
                $curriculumsources = [];
                foreach ($curriculumrecords as $curriculumrecord) {
                    $text = trim((string)$curriculumrecord->sourcetext);
                    if ($text !== '') {
                        $curriculumsources[] = $text;
                    }
                }
                if ($curriculumsources) {
                    $context = [
                        'Course: ' . clean_param((string)$program->name, PARAM_TEXT),
                        'Subject: ' . clean_param((string)($framework->subject ?? ''), PARAM_TEXT),
                        'Learner level: ' . clean_param((string)($framework->level ?? ''), PARAM_TEXT),
                        'Lesson: ' . clean_param((string)$lesson->title, PARAM_TEXT),
                        'Learning outcomes: ' . implode('; ', $outcometexts),
                        'Curriculum source: ' . implode("\n\n", $curriculumsources),
                    ];
                    $lessoncontent = \core_text::substr(implode("\n\n", $context), 0, 50000);
                    $sourcecontent = $lessoncontent;
                }
            }
            $brief = [
                'programid' => (int)$job->programid,
                'lessonid' => (int)$lesson->id,
                'cmid' => (int)$job->cmid,
                'program' => [
                    'name' => (string)$program->name,
                    'country' => (string)($framework->country ?? ''),
                    'state' => (string)($framework->state ?? ''),
                    'sector' => (string)($framework->sector ?? ''),
                    'level' => (string)($framework->level ?? ''),
                    'subject' => (string)($framework->subject ?? ''),
                    'learnerProfile' => (string)($framework->learnerprofile ?? ''),
                    'language' => (string)($framework->language ?? 'English'),
                    'spelling' => (string)($framework->spelling ?? ''),
                ],
                'lesson' => [
                    'id' => (int)$lesson->id,
                    'title' => (string)$lesson->title,
                    'outcomes' => $outcomes,
                    'content' => $lessoncontent,
                ],
                'outcomes' => $outcomes,
                'sourcecontent' => $sourcecontent,
                // Every source the teacher supplied (curriculum, sample content), so
                // tools chosen because of a source, such as Content Creator for a long
                // sample, can use it. Existing adapters that ignore this key are unchanged.
                'sources' => array_values(array_map(static fn($r) => [
                    'name' => (string)$r->name,
                    'text' => (string)$r->sourcetext,
                ], $DB->get_records('local_aicoursebuilder_source', ['programid' => $job->programid], 'id ASC'))),
                'idempotencyKey' => 'aicb-' . $job->id,
                // The attempt this request belongs to. An adapter whose service rejects
                // a reused key with a changed payload can add it to its own key, so a
                // definite failure can be retried without replaying the failed job.
                'attempt' => (int)$job->attempts + 1,
            ];
            if ($job->externaljobid) {
                $externaljobid = (string)$job->externaljobid;
            } else {
                // An estimate is a read-only price check. Keep the job retryable if
                // it fails; only mark the request uncertain once generation starts.
                $class::estimate($brief);
                $job->status = 'generating';
                $job->attempts++;
                $job->timemodified = time();
                $DB->update_record('local_aicoursebuilder_job', $job);
                $generationstarted = true;
                $externaljobid = $class::generate((int)$job->cmid, $brief);
                $job->externaljobid = $externaljobid;
                // Remember when this attempt started, so a service job that never finishes
                // cannot keep the course "building" for ever.
                $job->resultjson = json_encode(['startedat' => time()]);
            }
            $job->status = 'running';
            $job->timemodified = time();
            $DB->update_record('local_aicoursebuilder_job', $job);
            $state = $class::status((int)$job->cmid, $externaljobid);
            $started = (int)((json_decode((string)$job->resultjson, true) ?: [])['startedat'] ?? 0);
            if (in_array($state['status'] ?? '', ['running', 'pending'], true) && $started > 0
                    && time() - $started > self::MAX_JOB_SECONDS) {
                $state = ['status' => 'failed', 'retryable' => true,
                    'error' => get_string('jobtooklong', 'local_aicoursebuilder')];
            }
            if (
                ($state['status'] ?? '') === 'failed' && ($state['retryable'] ?? true)
                    && (int)$job->attempts <= 3
            ) {
                $job->externaljobid = null;
                $job->status = 'retry';
                $job->error = clean_param((string)($state['error'] ?? 'Generation failed.'), PARAM_TEXT);
                $job->timemodified = time();
                $DB->update_record('local_aicoursebuilder_job', $job);
                $this->reschedule(30 * (2 ** max(0, (int)$job->attempts - 1)));
                return;
            }
            $this->save_status($job, $state);
            if (($state['status'] ?? '') === 'pending' || ($state['status'] ?? '') === 'running') {
                $this->reschedule(30);
            }
        } catch (\Throwable $error) {
            $detail = clean_param($error->getMessage(), PARAM_TEXT);
            $detail = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $detail);
            $detail = preg_replace('/(X-API-Key|apiKey|api_key)\s*[:=]\s*\S+/i', '$1 [redacted]', $detail);
            $detail = trim(substr($detail, 0, 240));
            if ($detail === '') {
                $detail = 'Adapter error (' . get_class($error) . ').';
            }
            $job = $DB->get_record('local_aicoursebuilder_job', ['id' => $jobid]);
            if (!$job) {
                return;
            }
            $job->error = $detail;
            $job->timemodified = time();
            // A clear refusal (the product is not unlocked, or not set up on this site) means
            // nothing was started or charged: say so plainly instead of "uncertain".
            $refused = preg_match('/entitlement|not entitled|not unlocked|switched off|not configured|'
                . 'site id and api key are not set|lmslabscredentialsmissing/i', $detail);
            if ($generationstarted && $refused) {
                $job->status = 'failed';
                $job->error = get_string('toolnotavailable', 'local_aicoursebuilder', (object)[
                    'tool' => \local_aicoursebuilder\local\ui::tool_name((string)$job->component),
                    'detail' => $detail,
                ]);
                $DB->update_record('local_aicoursebuilder_job', $job);
                $this->hide_failed_activity($job);
                return;
            }
            if ($generationstarted && $job->status === 'generating' && !$job->externaljobid) {
                // The remote service may have accepted and charged the request before a network or
                // response error. Never start another paid generation when its outcome is unknown.
                $job->status = 'blocked';
                $job->error = 'Generation start outcome is uncertain; check the owning plugin before retrying. Detail: '
                    . $detail;
                $DB->update_record('local_aicoursebuilder_job', $job);
                $this->hide_failed_activity($job);
                return;
            }
            if ((int)$job->attempts <= 3) {
                $job->status = 'retry';
                $DB->update_record('local_aicoursebuilder_job', $job);
                $this->reschedule(30 * (2 ** max(0, (int)$job->attempts - 1)));
            } else {
                $job->status = 'failed';
                $DB->update_record('local_aicoursebuilder_job', $job);
                $this->hide_failed_activity($job);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Persist only an explicitly complete or failed adapter response.
     *
     * @param \stdClass $job
     * @param array $state
     * @return void
     */
    private function save_status(\stdClass $job, array $state): void {
        global $DB;
        $status = (string)($state['status'] ?? '');
        if (!in_array($status, ['complete', 'failed', 'blocked', 'pending', 'running'], true)) {
            throw new \moodle_exception('invalidjobstatus', 'local_aicoursebuilder');
        }
        $job->status = $status;
        $job->error = in_array($status, ['failed', 'blocked'], true)
            ? clean_param((string)($state['error'] ?? 'Generation failed without a reason.'), PARAM_TEXT)
            : null;
        $job->resultjson = $status === 'complete'
            ? json_encode($state['result'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : null;
        if ($job->error !== null && preg_match('/entitlement|not entitled|not unlocked/i', $job->error)) {
            $job->status = 'failed';
            $job->error = get_string('toolnotavailable', 'local_aicoursebuilder', (object)[
                'tool' => \local_aicoursebuilder\local\ui::tool_name((string)$job->component),
                'detail' => $job->error,
            ]);
        }
        $job->timemodified = time();
        $DB->update_record('local_aicoursebuilder_job', $job);
        $this->hide_failed_activity($job);
        // An activity hidden after an earlier failure is shown again once it is built.
        if ($status === 'complete' && !empty($job->cmid)
                && $DB->record_exists('course_modules', ['id' => (int)$job->cmid, 'visible' => 0])
                && !in_array($job->component, ['mod_learningmapping'], true)) {
            global $CFG;
            require_once($CFG->dirroot . '/course/lib.php');
            set_coursemodule_visible((int)$job->cmid, 1);
        }
    }

    /**
     * Hide an activity that could not be generated, so learners never open an empty one.
     *
     * @param \stdClass $job
     * @return void
     */
    private function hide_failed_activity(\stdClass $job): void {
        global $DB, $CFG;
        if (empty($job->cmid) || !in_array($job->status, ['failed', 'blocked'], true)
                || !$DB->record_exists('course_modules', ['id' => (int)$job->cmid, 'visible' => 1])) {
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        set_coursemodule_visible((int)$job->cmid, 0);
    }

    /**
     * Requeue the same idempotent job with back-off.
     *
     * @param int $seconds
     * @return void
     */
    private function reschedule(int $seconds): void {
        $data = (array)$this->get_custom_data();
        $task = new self();
        $task->set_custom_data($data);
        $task->set_next_run_time(time() + $seconds);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Keep course-level reports behind lesson generation, and Mapping behind Course Information.
     *
     * @param \stdClass $job
     * @param \stdClass $lesson
     * @return bool True when the current task was blocked or rescheduled.
     */
    private function wait_for_course_level_dependencies(\stdClass $job, \stdClass $lesson): bool {
        global $DB;
        if (!in_array($job->component, ['mod_courseinfo', 'mod_learningmapping'], true)) {
            return false;
        }
        $unitlessons = $DB->get_records('local_aicoursebuilder_lesson', ['unitid' => $lesson->unitid], '', 'id');
        $lessonids = array_map('intval', array_keys($unitlessons));
        if (!$lessonids) {
            $job->status = 'blocked';
            $job->error = 'The course-level activity has no lesson dependencies.';
            $job->timemodified = time();
            $DB->update_record('local_aicoursebuilder_job', $job);
            return true;
        }
        $idsql = implode(',', $lessonids);
        $activityjobs = $DB->get_records_select(
            'local_aicoursebuilder_job',
            'programid = ? AND lessonid IN (' . $idsql . ') AND component NOT IN (?, ?)',
            [(int)$job->programid, 'mod_courseinfo', 'mod_learningmapping'],
            '',
            'id, status, error, cmid'
        );
        // Wait until every lesson activity has finished, successfully or not. One tool that
        // could not be generated (for example a product the site has not unlocked) must not
        // stop the course's Course Information and Mapping. Activities that failed are hidden
        // from learners so the course never shows an empty activity, and they are left out of
        // Course Information and Mapping; the teacher can regenerate them from the report.
        $failedcmids = [];
        foreach ($activityjobs as $dependency) {
            if (in_array($dependency->status, ['failed', 'blocked'], true)) {
                if (!empty($dependency->cmid)) {
                    $failedcmids[] = (int)$dependency->cmid;
                }
                continue;
            }
            if ($dependency->status !== 'complete') {
                $this->reschedule(30);
                return true;
            }
        }
        if ($failedcmids) {
            global $CFG;
            require_once($CFG->dirroot . '/course/lib.php');
        }
        foreach ($failedcmids as $failedcmid) {
            if ($DB->record_exists('course_modules', ['id' => $failedcmid, 'visible' => 1])) {
                set_coursemodule_visible($failedcmid, 0);
            }
        }
        if ($failedcmids) {
            rebuild_course_cache((int)$DB->get_field('course_modules', 'course', ['id' => reset($failedcmids)]), true);
        }
        if ($job->component === 'mod_learningmapping') {
            $courseinfo = $DB->get_record('local_aicoursebuilder_job', [
                'programid' => $job->programid,
                'lessonid' => $job->lessonid,
                'component' => 'mod_courseinfo',
            ]);
            if (!$courseinfo || in_array($courseinfo->status, ['failed', 'blocked'], true)) {
                // Nothing was started, so this is a plain failure the teacher can retry.
                $job->status = 'failed';
                $job->error = 'Course Information did not complete, so Learning Mapping was not generated.';
                $job->timemodified = time();
                $DB->update_record('local_aicoursebuilder_job', $job);
                return true;
            }
            if ($courseinfo->status !== 'complete') {
                $this->reschedule(30);
                return true;
            }
        }
        return false;
    }
}
