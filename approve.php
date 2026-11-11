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
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$PAGE->set_url(new moodle_url('/local/aicoursebuilder/approve.php'));
$PAGE->set_context(context_system::instance());
require_sesskey();
$id = required_param('id', PARAM_INT);
$rawplan = required_param('planjson', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON is decoded immediately and structurally validated before use.
$context = context_system::instance();
\local_aicoursebuilder\local\ui::require_builder();

$program = $DB->get_record('local_aicoursebuilder_program', ['id' => $id, 'userid' => $USER->id], '*', MUST_EXIST);
// Where the courses go: a new top-level category (site course creators), or a category the
// teacher may create courses in.
$parentcategory = optional_param('parentcategory', 0, PARAM_INT);
if ($parentcategory) {
    if (!array_key_exists($parentcategory, \local_aicoursebuilder\local\ui::target_categories())) {
        throw new required_capability_exception(context_coursecat::instance($parentcategory), 'moodle/course:create', 'nopermissions', '');
    }
} else {
    require_capability('moodle/course:create', $context);
    require_capability('moodle/category:manage', $context);
}
$plan = json_decode($rawplan, true);
if (!is_array($plan) || empty($plan['outcomes']) || empty($plan['units']) || empty($plan['lessons'])) {
    throw new moodle_exception('invalidplan', 'local_aicoursebuilder');
}
$plan = \local_aicoursebuilder\local\course_builder::tidy_plan($plan);
\local_aicoursebuilder\local\course_builder::approve($id, (int)$USER->id, $plan, $parentcategory);
redirect(new moodle_url('/local/aicoursebuilder/report.php', ['id' => $id]));
