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

/**
 * Reconciles course build and activity job status for the live report.
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_jobs extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('pluginname', 'local_aicoursebuilder') . ' status reconciliation';
    }

    /**
     * Reconcile the status of programs that are building.
     */
    public function execute(): void {
        global $DB;
        $programs = $DB->get_records('local_aicoursebuilder_program', ['status' => 'building']);
        foreach ($programs as $program) {
            $counts = $DB->get_records_sql(
                "SELECT status, COUNT(1) AS count
                   FROM {local_aicoursebuilder_job}
                  WHERE programid = :programid
               GROUP BY status",
                ['programid' => $program->id]
            );
            $haspending = false;
            $hasfailure = false;
            foreach ($counts as $count) {
                if (in_array($count->status, ['queued', 'generating', 'running', 'retry'], true)) {
                    $haspending = true;
                }
                if (in_array($count->status, ['failed', 'blocked'], true)) {
                    $hasfailure = true;
                }
            }
            if ($haspending) {
                continue;
            }
            $program->status = $hasfailure ? 'failed' : 'complete';
            $program->timemodified = time();
            if ($hasfailure) {
                $program->error = 'One or more plugin generation jobs failed or were blocked. Review the job-level report before making courses visible.';
            } else {
                // A retried build that now succeeds must not keep the old failure warning.
                $program->error = null;
            }
            $DB->update_record('local_aicoursebuilder_program', $program);
            $DB->set_field('local_aicoursebuilder_lesson', 'status', $hasfailure ? 'failed' : 'complete', ['programid' => $program->id, 'status' => 'building']);
        }
    }
}
