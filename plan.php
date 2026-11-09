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
require_once($CFG->libdir . '/filelib.php');

require_login();
$PAGE->set_url(new moodle_url('/local/aicoursebuilder/plan.php'));
$PAGE->set_context(context_system::instance());
$context = context_system::instance();
require_capability('local/aicoursebuilder:create', $context);
require_sesskey();

$clean = static function (string $name, int $max = 1000): string {
    $value = required_param($name, PARAM_RAW); // pipeline-ignore: PARAM_RAW — long text is immediately sanitized as PARAM_TEXT and length-bounded.
    return mb_substr(trim(clean_param($value, PARAM_TEXT)), 0, $max);
};
$program = [
    'name' => $clean('name', 255),
    'country' => $clean('country', 80),
    'state' => $clean('state', 80),
    'sector' => $clean('sector', 120),
    'level' => $clean('level', 120),
    'subject' => $clean('subject', 160),
    'language' => $clean('language', 40),
    'spelling' => $clean('spelling', 30),
    'learnerProfile' => $clean('learnerprofile', 1500),
    'framework' => $clean('framework', 80) ?: 'custom',
    'unitCode' => mb_substr(trim(clean_param(optional_param('unitcode', '', PARAM_TEXT), PARAM_ALPHANUMEXT)), 0, 50),
    'unitTitle' => mb_substr(trim(optional_param('unittitle', '', PARAM_TEXT)), 0, 255),
    'nominalHours' => max(0, min(5000, optional_param('nominalhours', 0, PARAM_INT))),
];
$curriculum = $clean('curriculum', 50000);
$upload = $_FILES['curriculumfile'] ?? null;
if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (
        ($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK
        || empty($upload['tmp_name'])
        || !is_uploaded_file($upload['tmp_name'])
        || (int)($upload['size'] ?? 0) > 5 * 1024 * 1024
    ) {
        throw new moodle_exception('curriculumuploadfailed', 'local_aicoursebuilder');
    }
    $extension = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'docx'], true)) {
        throw new moodle_exception('curriculumuploadfailed', 'local_aicoursebuilder');
    }
    $filebytes = file_get_contents($upload['tmp_name']);
    if ($filebytes === false || $filebytes === '') {
        throw new moodle_exception('curriculumuploadfailed', 'local_aicoursebuilder');
    }
    try {
        $extracted = \local_aicoursebuilder\local\api_client::request_json(
            'POST',
            '/api/moodle/ai-course-builder/extract-curriculum',
            [
                'fileName' => 'curriculum.' . $extension,
                'dataBase64' => base64_encode($filebytes),
            ],
            60
        );
    } catch (Throwable $e) {
        throw new moodle_exception('curriculumuploadfailed', 'local_aicoursebuilder');
    }
    if (empty($extracted['success']) || trim((string)($extracted['text'] ?? '')) === '') {
        throw new moodle_exception('curriculumuploadfailed', 'local_aicoursebuilder');
    }
    $curriculum = mb_substr(trim($curriculum . "\n\n" . $extracted['text']), 0, 50000);
}
$sample = optional_param('samplecontent', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — long text is immediately sanitized as PARAM_TEXT and length-bounded.
$sample = mb_substr(trim(clean_param($sample, PARAM_TEXT)), 0, 50000);
$sources = [];
if ($curriculum !== '') {
    $sources[] = ['name' => 'Curriculum and learning outcomes', 'text' => $curriculum];
}
if ($sample !== '') {
    $sources[] = ['name' => 'Sample content', 'text' => $sample];
}
if (!$sources) {
    throw new moodle_exception('invalidplan', 'local_aicoursebuilder');
}
$selfpaced = optional_param('selfpaced', 0, PARAM_BOOL);
$delivery = [
    'weeks' => max(1, min(104, optional_param('weeks', 8, PARAM_INT))),
    'sessionsPerWeek' => max(1, min(7, optional_param('sessionsperweek', 2, PARAM_INT))),
    'minutesPerSession' => max(15, min(240, optional_param('minutespersession', 60, PARAM_INT))),
    'reservePercent' => max(0, min(40, optional_param('reservepercent', 15, PARAM_INT))),
    'selfPaced' => (bool)$selfpaced,
    'totalLearnerHours' => optional_param('totallearnerhours', 0, PARAM_FLOAT),
];
$allowedtools = [
    'mod_visuallearning', 'mod_contentcreator', 'mod_productexplainer', 'mod_aiactivities',
    'mod_aiknowledgecheck', 'mod_aisoftskills', 'mod_aibranchedscenario',
    'mod_aisafetyhazards', 'mod_aianatomy',
];
$selectedtools = array_values(array_intersect($allowedtools, optional_param_array('tools', [], PARAM_ALPHANUMEXT)));
if (!$selectedtools) {
    throw new moodle_exception('invalidplan', 'local_aicoursebuilder');
}
$payload = [
    'program' => $program,
    'sources' => $sources,
    'delivery' => $delivery,
    'tools' => [
        'allowed' => $selectedtools,
        'softSkills' => optional_param('softskills', 'auto', PARAM_ALPHA),
        'scenarios' => optional_param('scenarios', 'auto', PARAM_ALPHA),
    ],
];
$plan = \local_aicoursebuilder\local\api_client::plan($payload);
// Remember the teacher's tool choice so the plan is never topped up with tools they switched off.
$delivery['allowedTools'] = $selectedtools;
$programid = \local_aicoursebuilder\local\course_builder::save_plan(
    (int)$USER->id,
    $program['name'] ?: $program['subject'],
    $program,
    $delivery,
    $sources,
    $plan
);
redirect(new moodle_url('/local/aicoursebuilder/review.php', ['id' => $programid]));
