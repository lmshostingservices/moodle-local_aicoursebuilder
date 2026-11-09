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
 * AI Course Builder home: start a plan and see existing plans.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

use local_aicoursebuilder\local\ui;

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/aicoursebuilder:create', $context);

ui::setup_page(new moodle_url('/local/aicoursebuilder/index.php'), get_string('pluginname', 'local_aicoursebuilder'));

echo $OUTPUT->header();

echo html_writer::start_div('aicb');
echo html_writer::start_div('aicb-hero');
echo html_writer::start_div('aicb-hero-text');
echo html_writer::tag('h2', get_string('herotitle', 'local_aicoursebuilder'), ['class' => 'aicb-hero-title']);
echo html_writer::tag('p', get_string('herotext', 'local_aicoursebuilder'), ['class' => 'aicb-hero-lead']);
echo html_writer::link(
    new moodle_url('/local/aicoursebuilder/wizard.php'),
    get_string('newprogram', 'local_aicoursebuilder'),
    ['class' => 'btn btn-primary btn-lg aicb-btn-main']
);
echo html_writer::tag('p', get_string('herofree', 'local_aicoursebuilder'), ['class' => 'aicb-hero-note']);
echo html_writer::end_div();
echo html_writer::start_div('aicb-hero-steps', ['aria-hidden' => 'true']);
foreach ([['1', 'step2'], ['2', 'step3'], ['3', 'reviewplan'], ['4', 'buildheading']] as [$n, $key]) {
    echo html_writer::div(
        html_writer::span($n, 'aicb-hero-stepnum') . html_writer::span(get_string($key, 'local_aicoursebuilder')),
        'aicb-hero-step'
    );
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::tag('h3', get_string('yourplans', 'local_aicoursebuilder'), ['class' => 'aicb-section-title']);
$programs = $DB->get_records('local_aicoursebuilder_program', ['userid' => $USER->id], 'timemodified DESC');
if (!$programs) {
    echo html_writer::div(get_string('noplansyet', 'local_aicoursebuilder'), 'aicb-empty');
} else {
    echo html_writer::start_div('aicb-plans');
    foreach ($programs as $program) {
        $plan = json_decode($program->planjson, true) ?: [];
        $framework = $DB->get_record('local_aicoursebuilder_framework', ['programid' => $program->id]);
        $meta = array_filter([
            $framework->country ?? '',
            $framework->level ?? '',
            $framework->subject ?? '',
        ]);
        $counts = [
            (count($plan['units'] ?? []) === 1 ? get_string('coursescount_one', 'local_aicoursebuilder') : get_string('coursescount', 'local_aicoursebuilder', count($plan['units'] ?? []))),
            (count($plan['lessons'] ?? []) === 1 ? get_string('lessonscount_one', 'local_aicoursebuilder') : get_string('lessonscount', 'local_aicoursebuilder', count($plan['lessons'] ?? []))),
            (count($plan['outcomes'] ?? []) === 1 ? get_string('outcomescount_one', 'local_aicoursebuilder') : get_string('outcomescount', 'local_aicoursebuilder', count($plan['outcomes'] ?? []))),
        ];
        $actions = [];
        if ($program->status === 'planned') {
            $actions[] = html_writer::link(
                new moodle_url('/local/aicoursebuilder/review.php', ['id' => $program->id]),
                get_string('openplan', 'local_aicoursebuilder'),
                ['class' => 'btn btn-primary btn-sm']
            );
        } else {
            $actions[] = html_writer::link(
                new moodle_url('/local/aicoursebuilder/report.php', ['id' => $program->id]),
                get_string('openprogress', 'local_aicoursebuilder'),
                ['class' => 'btn btn-primary btn-sm']
            );
            $firstunit = $DB->get_records(
                'local_aicoursebuilder_unit',
                ['programid' => $program->id],
                'unitnumber ASC',
                'id, courseid',
                0,
                1
            );
            $firstunit = reset($firstunit);
            if ($firstunit && $firstunit->courseid) {
                $actions[] = html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $firstunit->courseid]),
                    get_string('opencourse', 'local_aicoursebuilder'),
                    ['class' => 'btn btn-outline-secondary btn-sm']
                );
            }
        }
        echo html_writer::start_div('aicb-plan');
        echo html_writer::div(
            html_writer::tag('h4', format_string($program->name), ['class' => 'aicb-plan-title']) . ui::status_chip($program->status),
            'aicb-plan-head'
        );
        if ($meta) {
            echo html_writer::div(s(implode(' · ', $meta)), 'aicb-plan-meta');
        }
        echo html_writer::div(implode(html_writer::span('·', 'aicb-dot'), $counts), 'aicb-plan-counts');
        echo html_writer::div(get_string('updated', 'local_aicoursebuilder', userdate(
            $program->timemodified,
            get_string('strftimedatetimeshort', 'langconfig')
        )), 'aicb-plan-updated');
        echo html_writer::div(implode(' ', $actions), 'aicb-plan-actions');
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo $OUTPUT->footer();
