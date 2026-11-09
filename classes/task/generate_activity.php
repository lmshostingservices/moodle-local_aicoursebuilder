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
                'idempotencyKey' => 'aicb-' . $job->id,
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
            }
            $job->status = 'running';
            $job->timemodified = time();
            $DB->update_record('local_aicoursebuilder_job', $job);
            $state = $class::status((int)$job->cmid, $externaljobid);
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
            if ($generationstarted && $job->status === 'generating' && !$job->externaljobid) {
                // The remote service may have accepted and charged the request before a network or
                // response error. Never start another paid generation when its outcome is unknown.
                $job->status = 'blocked';
                $job->error = 'Generation start outcome is uncertain; check the owning plugin before retrying. Detail: '
                    . $detail;
                $DB->update_record('local_aicoursebuilder_job', $job);
                return;
            }
            if ((int)$job->attempts <= 3) {
                $job->status = 'retry';
                $DB->update_record('local_aicoursebuilder_job', $job);
                $this->reschedule(30 * (2 ** max(0, (int)$job->attempts - 1)));
            } else {
                $job->status = 'failed';
                $DB->update_record('local_aicoursebuilder_job', $job);
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
        $job->timemodified = time();
        $DB->update_record('local_aicoursebuilder_job', $job);
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
            'id, status, error'
        );
        foreach ($activityjobs as $dependency) {
            if (in_array($dependency->status, ['failed', 'blocked'], true)) {
                $job->status = 'blocked';
                $job->error = 'A lesson activity failed, so the course-level result was not generated.';
                $job->timemodified = time();
                $DB->update_record('local_aicoursebuilder_job', $job);
                return true;
            }
            if ($dependency->status !== 'complete') {
                $this->reschedule(30);
                return true;
            }
        }
        if ($job->component === 'mod_learningmapping') {
            $courseinfo = $DB->get_record('local_aicoursebuilder_job', [
                'programid' => $job->programid,
                'lessonid' => $job->lessonid,
                'component' => 'mod_courseinfo',
            ]);
            if (!$courseinfo || in_array($courseinfo->status, ['failed', 'blocked'], true)) {
                $job->status = 'blocked';
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
