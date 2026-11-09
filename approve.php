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
 * local_aicoursebuilder file.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$PAGE->set_url(new moodle_url('/local/aicoursebuilder/approve.php'));
$PAGE->set_context(context_system::instance());
require_sesskey();
$id = required_param('id', PARAM_INT);
$rawplan = required_param('planjson', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON is decoded immediately and structurally validated before use.
$context = context_system::instance();
require_capability('local/aicoursebuilder:create', $context);
require_capability('moodle/course:create', $context);

$program = $DB->get_record('local_aicoursebuilder_program', ['id' => $id, 'userid' => $USER->id], '*', MUST_EXIST);
$plan = json_decode($rawplan, true);
if (!is_array($plan) || empty($plan['outcomes']) || empty($plan['units']) || empty($plan['lessons'])) {
    throw new moodle_exception('invalidplan', 'local_aicoursebuilder');
}
$plan['outcomes'] = array_values($plan['outcomes']);
$plan['units'] = array_values($plan['units']);
$plan['lessons'] = array_values($plan['lessons']);
\local_aicoursebuilder\local\course_builder::approve($id, (int)$USER->id, $plan);
redirect(new moodle_url('/local/aicoursebuilder/report.php', ['id' => $id]));
