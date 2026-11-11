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

/**
 * Try one failed or blocked activity again.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$jobid = required_param('jobid', PARAM_INT);
$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/aicoursebuilder/retry.php', ['jobid' => $jobid]));
$PAGE->set_context($context);
\local_aicoursebuilder\local\ui::require_builder();
require_sesskey();

$job = $DB->get_record('local_aicoursebuilder_job', ['id' => $jobid], '*', MUST_EXIST);
$program = $DB->get_record('local_aicoursebuilder_program', ['id' => $job->programid, 'userid' => $USER->id], '*', MUST_EXIST);
$report = new moodle_url('/local/aicoursebuilder/report.php', ['id' => $program->id]);
if (!in_array($job->status, ['failed', 'blocked'], true)) {
    redirect($report);
}
\local_aicoursebuilder\local\course_builder::retry_job((int)$job->id);
redirect($report, get_string('retryqueued', 'local_aicoursebuilder'), null, \core\output\notification::NOTIFY_SUCCESS);
