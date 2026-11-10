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
 * Four-step wizard that collects everything the planner needs.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

use local_aicoursebuilder\local\ui;

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
\local_aicoursebuilder\local\ui::require_builder();

ui::setup_page(new moodle_url('/local/aicoursebuilder/wizard.php'), get_string('newprogram', 'local_aicoursebuilder'));
$PAGE->navbar->add(get_string('newprogram', 'local_aicoursebuilder'));
$PAGE->requires->js_call_amd('local_aicoursebuilder/wizard', 'init');

$str = static fn(string $key, $a = null): string => get_string($key, 'local_aicoursebuilder', $a);

// Render a labelled field.
$field = static function (string $name, string $label, string $control, string $help = '', string $class = 'col-md-6'): string {
    $out = html_writer::tag('label', $label, ['for' => 'id_' . $name, 'class' => 'form-label aicb-label']);
    $out .= $control;
    if ($help !== '') {
        $out .= html_writer::div($help, 'form-text', ['id' => 'id_' . $name . '_help']);
    }
    return html_writer::div($out, $class . ' mb-3');
};
$text = static function (string $name, array $attrs = []): string {
    return html_writer::empty_tag('input', ['type' => 'text', 'id' => 'id_' . $name, 'name' => $name,
        'class' => 'form-control'] + $attrs);
};
$number = static function (string $name, string $value, int $min, int $max, string $step = '1'): string {
    return html_writer::empty_tag('input', ['type' => 'number', 'id' => 'id_' . $name, 'name' => $name, 'value' => $value,
        'min' => $min, 'max' => $max, 'step' => $step, 'class' => 'form-control']);
};

echo $OUTPUT->header();
echo html_writer::start_div('aicb aicb-wizard');

echo html_writer::start_tag('form', [
    'method' => 'post',
    'enctype' => 'multipart/form-data',
    'action' => new moodle_url('/local/aicoursebuilder/plan.php'),
    'id' => 'aicb-wizard-form',
    'novalidate' => 'novalidate',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Stepper.
echo html_writer::start_tag('ol', ['class' => 'aicb-stepper', 'aria-label' => $str('newprogram')]);
foreach ([1, 2, 3, 4] as $n) {
    echo html_writer::tag(
        'li',
        html_writer::span($n, 'aicb-stepper-num') .
        html_writer::span($str('step' . $n), 'aicb-stepper-label'),
        ['class' => 'aicb-stepper-item' . ($n === 1 ? ' is-current' : ''), 'data-step' => $n]
    );
}
echo html_writer::end_tag('ol');

echo html_writer::start_div('aicb-wizard-grid');
echo html_writer::start_div('aicb-wizard-main');

// Step 1: where you teach.
echo html_writer::start_tag('section', ['class' => 'aicb-card aicb-step is-current', 'data-step' => 1]);
echo html_writer::tag('h3', $str('step1'), ['class' => 'aicb-card-title']);
echo html_writer::tag('p', $str('step1desc'), ['class' => 'aicb-card-lead']);
echo html_writer::start_div('row');
echo $field('country', $str('country'), $text('country', ['list' => 'country_options', 'autocomplete' => 'off']) .
    html_writer::tag('datalist', '', ['id' => 'country_options']));
echo $field('state', $str('state'), $text('state', ['list' => 'state_options', 'autocomplete' => 'off']) .
    html_writer::tag('datalist', '', ['id' => 'state_options']));
echo $field('sector', $str('sector'), $text('sector', ['list' => 'sector_options', 'autocomplete' => 'off']) .
    html_writer::tag('datalist', '', ['id' => 'sector_options']));
echo $field('level', $str('level'), $text('level', ['list' => 'level_options', 'autocomplete' => 'off', 'required' => 'required']) .
    html_writer::tag('datalist', '', ['id' => 'level_options']));
echo $field('subject', $str('subject'), $text('subject', ['required' => 'required', 'placeholder' => $str('example_subject')]));
echo $field('language', $str('language'), $text('language', ['value' => 'English']), '', 'col-md-3');
echo $field('spelling', $str('spelling'), $text('spelling', ['list' => 'spelling_options']) .
    html_writer::tag(
        'datalist',
        html_writer::tag('option', '', ['value' => 'British']) . html_writer::tag('option', '', ['value' => 'American']) .
        html_writer::tag('option', '', ['value' => 'Australian']),
        ['id' => 'spelling_options']
    ), '', 'col-md-3');
echo html_writer::end_div();
echo html_writer::end_tag('section');

// Step 2: curriculum.
echo html_writer::start_tag('section', ['class' => 'aicb-card aicb-step', 'data-step' => 2, 'hidden' => 'hidden']);
echo html_writer::tag('h3', $str('step2'), ['class' => 'aicb-card-title']);
echo html_writer::tag('p', $str('step2desc'), ['class' => 'aicb-card-lead']);
echo html_writer::start_div('row');
echo $field('name', $str('title'), $text('name', ['required' => 'required', 'placeholder' => $str('example_title')]), '', 'col-md-8');
echo $field('framework', $str('framework'), html_writer::select(
    ['custom' => $str('framework_custom')], 'framework', 'custom', false,
    ['id' => 'id_framework', 'class' => 'form-select', 'data-labels' => json_encode(array_combine(
        ['vet', 'au_curriculum', 'sg_moe', 'university', 'workplace', 'custom'],
        array_map(fn($k) => $str('framework_' . $k), ['vet', 'au_curriculum', 'sg_moe', 'university', 'workplace', 'custom'])
    ))]
), '', 'col-md-4');
echo html_writer::start_div('col-12', ['id' => 'aicb-vet-fields', 'hidden' => 'hidden']);
echo html_writer::start_div('row');
echo $field('unitcode', $str('unitcode'), $text('unitcode', ['placeholder' => 'CHCCCS031', 'maxlength' => 50]),
    $str('unitcode_help'), 'col-md-3');
echo $field('unittitle', $str('unittitle'), $text('unittitle', ['maxlength' => 255,
    'placeholder' => $str('unittitle_example')]), '', 'col-md-6');
echo $field('nominalhours', $str('nominalhours'), $number('nominalhours', '', 0, 5000), $str('nominalhours_help'), 'col-md-3');
echo html_writer::end_div();
echo html_writer::end_div();
echo $field('curriculum', $str('sourcecurriculum'), html_writer::tag('textarea', '', [
    'id' => 'id_curriculum', 'name' => 'curriculum', 'rows' => 9, 'class' => 'form-control aicb-mono',
    'placeholder' => $str('example_curriculum')]), '', 'col-12');
echo $field('curriculumfile', $str('curriculumupload'), html_writer::empty_tag('input', [
    'type' => 'file', 'id' => 'id_curriculumfile', 'name' => 'curriculumfile', 'class' => 'form-control',
    'accept' => '.pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
]), $str('curriculumupload_help'), 'col-md-6');
echo $field('samplecontent', $str('samplesource'), html_writer::tag('textarea', '', [
    'id' => 'id_samplecontent', 'name' => 'samplecontent', 'rows' => 9, 'class' => 'form-control',
    'placeholder' => $str('example_sample')]), '', 'col-md-6');
echo $field('learnerprofile', $str('learnerprofile'), html_writer::tag('textarea', '', [
    'id' => 'id_learnerprofile', 'name' => 'learnerprofile', 'rows' => 2, 'class' => 'form-control',
    'aria-describedby' => 'id_learnerprofile_help']), $str('learnerprofile_help'), 'col-12');
echo html_writer::end_div();
echo html_writer::end_tag('section');

// Step 3: time and pacing.
echo html_writer::start_tag('section', ['class' => 'aicb-card aicb-step', 'data-step' => 3, 'hidden' => 'hidden']);
echo html_writer::tag('h3', $str('step3'), ['class' => 'aicb-card-title']);
echo html_writer::tag('p', $str('step3desc'), ['class' => 'aicb-card-lead']);
echo html_writer::start_div('row');
echo $field('weeks', $str('weeks'), $number('weeks', '8', 1, 104), '', 'col-md-3');
echo $field('sessionsperweek', $str('sessionsperweek'), $number('sessionsperweek', '2', 1, 7), '', 'col-md-3');
echo $field('minutespersession', $str('minutespersession'), $number('minutespersession', '60', 15, 240, '5'), '', 'col-md-3');
echo $field('reservepercent', $str('reservepercent'), $number('reservepercent', '15', 0, 40), '', 'col-md-3');
echo html_writer::div(
    html_writer::div(
        html_writer::empty_tag('input', ['type' => 'checkbox', 'class' => 'form-check-input', 'id' => 'id_selfpaced',
            'name' => 'selfpaced', 'value' => 1]) .
        html_writer::tag('label', $str('selfpaced'), ['class' => 'form-check-label', 'for' => 'id_selfpaced']),
        'form-check form-switch'
    ),
    'col-md-6 mb-3 d-flex align-items-end'
);
echo $field('totallearnerhours', $str('totallearnerhours'), $number('totallearnerhours', '', 1, 2000, '0.5'), '', 'col-md-3');
echo html_writer::end_div();
// When the courses start, and which course format they use.
$formats = [];
foreach (\core_plugin_manager::instance()->get_enabled_plugins('format') ?: [] as $format) {
    if (in_array($format, ['singleactivity', 'social'], true)) {
        continue;
    }
    $formats[$format] = get_string('pluginname', 'format_' . $format);
}
// The LMS Labs theme can make every course use AI Course Format; then that is the only choice.
if (isset($formats['aicourse']) && class_exists('\\theme_lmslabs\\local\\aiformat')
        && method_exists('\\theme_lmslabs\\local\\aiformat', 'enabled') && \theme_lmslabs\local\aiformat::enabled()) {
    $formats = ['aicourse' => $formats['aicourse']];
}
$defaultformat = isset($formats['aicourse']) ? 'aicourse' : (string)get_config('moodlecourse', 'format');
echo html_writer::start_div('row');
echo $field('startdate', $str('coursestart'), html_writer::empty_tag('input', ['type' => 'date', 'id' => 'id_startdate',
    'name' => 'startdate', 'class' => 'form-control', 'value' => userdate(time(), '%Y-%m-%d', 99, false)]), '', 'col-md-3');
echo $field('courseformat', $str('courseformat'), html_writer::select($formats, 'courseformat', $defaultformat, false,
    ['id' => 'id_courseformat', 'class' => 'custom-select form-select']), '', 'col-md-4');
echo html_writer::end_div();
echo html_writer::div(
    html_writer::div('', 'aicb-pace-value', ['data-aicb' => 'lessons']) .
    html_writer::div($str('lessonsfromtime'), 'aicb-pace-note'),
    'aicb-pace'
);
echo html_writer::div('', 'alert alert-warning mt-2', ['data-aicb' => 'hoursnote', 'hidden' => 'hidden']);
echo html_writer::end_tag('section');

// Step 4: activities.
echo html_writer::start_tag('section', ['class' => 'aicb-card aicb-step', 'data-step' => 4, 'hidden' => 'hidden']);
echo html_writer::tag('h3', $str('step4'), ['class' => 'aicb-card-title']);
echo html_writer::tag('p', $str('step4desc'), ['class' => 'aicb-card-lead']);
echo html_writer::start_div('aicb-tools');
// The teacher's last choice: "component:mode" pairs (older values hold only the component).
$favourites = [];
foreach (array_filter(explode(',', (string)get_user_preferences('local_aicoursebuilder_tools', ''))) as $pair) {
    [$favcomponent, $favmode] = array_pad(explode(':', $pair, 2), 2, '');
    $favourites[$favcomponent] = in_array($favmode, ['always', 'auto'], true) ? $favmode : '';
}
foreach (ui::LESSON_TOOLS as $component) {
    $installed = ui::tool_installed($component);
    $hasadapter = $installed && \local_aicoursebuilder\local\generator_runtime::component_available($component);
    $notready = $hasadapter ? \local_aicoursebuilder\local\generator_runtime::component_ready($component) : null;
    if ($installed && !$hasadapter) {
        $notready = get_string('needsupdatehelp', 'local_aicoursebuilder');
    }
    $badge = '';
    if (!$installed) {
        $badge = html_writer::span($str('notinstalled'), 'aicb-chip aicb-chip-muted');
    } else if (!$hasadapter) {
        $badge = html_writer::span($str('needsupdate'), 'aicb-chip aicb-chip-muted', ['title' => $notready]);
    } else if ($notready !== null) {
        $badge = html_writer::span($str('needssetup'), 'aicb-chip aicb-chip-muted', ['title' => $notready]);
    }
    $usable = $installed && $notready === null;
    $input = html_writer::empty_tag('input', array_filter([
        'type' => 'checkbox', 'name' => 'tools[]', 'value' => $component, 'class' => 'aicb-tool-check',
        'checked' => $usable && (!$favourites || isset($favourites[$component])) ? 'checked' : null,
        'disabled' => $usable ? null : 'disabled',
    ]));
    $mode = !empty($favourites[$component]) ? $favourites[$component] : ui::default_tool_mode($component);
    $modeselect = $usable ? html_writer::select(
        ['always' => $str('mode_always'), 'auto' => $str('mode_auto')],
        'toolmode[' . $component . ']',
        $mode,
        false,
        ['class' => 'custom-select form-select aicb-tool-mode', 'aria-label' => $str('howoften', ui::tool_name($component))]
    ) : '';
    echo html_writer::tag(
        'label',
        $input .
        html_writer::div(
            ui::tool_icon($component) .
            html_writer::div(
                html_writer::div(s(ui::tool_name($component)) . ' ' . $badge, 'aicb-tool-name') .
                html_writer::div(s($notready ?? ui::tool_description($component)), 'aicb-tool-desc') . $modeselect,
                'aicb-tool-text'
            ),
            'aicb-tool-body'
        ),
        ['class' => 'aicb-tool' . ($usable ? '' : ' is-disabled')]
    );
}
echo html_writer::end_div();
echo html_writer::end_tag('section');

// Navigation.
echo html_writer::start_div('aicb-wizard-nav');
echo html_writer::tag('button', $str('back'), ['type' => 'button', 'class' => 'btn btn-outline-secondary',
    'data-aicb' => 'back', 'hidden' => 'hidden']);
echo html_writer::span($str('planningfree'), 'aicb-wizard-free');
echo html_writer::tag('button', $str('next'), ['type' => 'button', 'class' => 'btn btn-primary', 'data-aicb' => 'next']);
echo html_writer::tag('button', $str('planmycourse'), ['type' => 'submit', 'class' => 'btn btn-primary aicb-btn-main',
    'data-aicb' => 'submit', 'hidden' => 'hidden']);
echo html_writer::end_div();
echo html_writer::end_div();

// Summary card.
echo html_writer::start_tag('aside', ['class' => 'aicb-card aicb-summary', 'aria-live' => 'polite']);
echo html_writer::tag('h3', $str('summarycard'), ['class' => 'aicb-card-title']);
foreach (
    [
    'where' => $str('step1'),
    'subject' => $str('subject'),
    'curriculum' => $str('step2'),
    'time' => $str('step3'),
    'lessons' => $str('estimatedlessons'),
    'hours' => $str('learnerhours'),
    'tools' => $str('step4'),
    ] as $key => $label
) {
    echo html_writer::div(
        html_writer::div($label, 'aicb-summary-label') . html_writer::div('—', 'aicb-summary-value', ['data-aicb-sum' => $key]),
        'aicb-summary-row'
    );
}
echo html_writer::end_tag('aside');
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo $OUTPUT->footer();
