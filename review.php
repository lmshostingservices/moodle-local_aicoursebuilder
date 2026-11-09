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
 * Review the plan, edit lessons and tools, see the credit estimate, approve.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

use local_aicoursebuilder\local\ui;

require_once(__DIR__ . '/../../config.php');

require_login();
$id = required_param('id', PARAM_INT);
$context = context_system::instance();
require_capability('local/aicoursebuilder:create', $context);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/aicoursebuilder/review.php', ['id' => $id]));
$program = $DB->get_record('local_aicoursebuilder_program', ['id' => $id, 'userid' => $USER->id], '*', MUST_EXIST);
if ($program->status !== 'planned') {
    redirect(new moodle_url('/local/aicoursebuilder/report.php', ['id' => $id]));
}
$framework = $DB->get_record('local_aicoursebuilder_framework', ['programid' => $id]);
$delivery = json_decode($program->deliveryjson, true) ?: [];
$plan = json_decode($program->planjson, true) ?: [];
$outcomes = array_values($plan['outcomes'] ?? []);
$units = array_values($plan['units'] ?? []);
$lessons = array_values($plan['lessons'] ?? []);

$str = static fn(string $key, $a = null): string => get_string($key, 'local_aicoursebuilder', $a);
ui::setup_page(new moodle_url('/local/aicoursebuilder/review.php', ['id' => $id]), $str('reviewplan'), format_string($program->name));
$PAGE->navbar->add(format_string($program->name));
$PAGE->requires->js_call_amd('local_aicoursebuilder/review', 'init');

// Outcome lookup: lessons refer to outcomes by code or by text.
$outcomebykey = [];
foreach ($outcomes as $index => $outcome) {
    $key = !empty($outcome['code']) ? (string)$outcome['code'] : (string)($outcome['text'] ?? '');
    $outcomebykey[$key] = $outcome + ['index' => $index + 1];
}
$outcometexts = static function (array $lesson) use ($outcomebykey): array {
    $texts = [];
    foreach ($lesson['outcomes'] ?? [] as $key) {
        $texts[] = (string)($outcomebykey[$key]['text'] ?? $key);
    }
    return $texts;
};

// Credit estimates, per lesson tool and per course-level activity.
$prices = [];
$totals = [];
foreach ($lessons as $lesson) {
    foreach (ui::LESSON_TOOLS as $component) {
        $prices[(int)$lesson['number']][$component] = ui::estimate($component, (string)$lesson['title'], $outcometexts($lesson));
    }
}
$courselevel = [];
foreach (['mod_courseinfo', 'mod_learningmapping'] as $component) {
    $courselevel[$component] = ui::estimate($component, (string)($units[0]['title'] ?? ''), []);
}

$minutes = (int)($delivery['minutesPerSession'] ?? 60);
$hours = round(count($lessons) * $minutes / 60, 1);

echo $OUTPUT->header();
echo html_writer::start_div('aicb aicb-review', [
    'data-courselevel' => json_encode($courselevel),
    'data-units' => count($units),
]);

// Summary.
$meta = array_filter([$framework->country ?? '', $framework->state ?? '', $framework->sector ?? '', $framework->level ?? '',
    $framework->subject ?? '']);
echo html_writer::start_div('aicb-review-head');
echo html_writer::div(implode('', array_map(static fn($m) => html_writer::span(s($m), 'aicb-chip aicb-chip-meta'), $meta)) .
    ui::status_chip($program->status), 'aicb-review-meta');
echo html_writer::tag('p', $str('reviewintro'), ['class' => 'aicb-card-lead']);
echo html_writer::start_div('aicb-stats');
echo ui::stat((string)count($units), $str('courses'));
echo ui::stat((string)count($lessons), $str('lessons'));
echo ui::stat((string)count($outcomes), $str('outcomes'));
echo ui::stat(format_float($hours, 1, true, true), $str('learnerhours'));
echo ui::stat('—', $str('estimatedcredits'), 'credits');
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/aicoursebuilder/approve.php'),
    'id' => 'aicb-review-form']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('aicb-review-grid');
echo html_writer::start_div('aicb-review-main');

foreach ($units as $uindex => $unit) {
    $unitnumber = (int)($unit['number'] ?? ($uindex + 1));
    echo html_writer::start_tag('section', ['class' => 'aicb-card aicb-unit', 'data-unit' => $uindex]);
    echo html_writer::start_div('aicb-unit-head');
    echo html_writer::span(sprintf('%02d', $unitnumber), 'aicb-unit-num');
    echo html_writer::div(
        html_writer::div($str('unitn', $unitnumber), 'aicb-unit-kicker') .
        html_writer::empty_tag('input', ['type' => 'text', 'class' => 'form-control aicb-inline-input aicb-unit-title',
            'value' => (string)($unit['title'] ?? ''), 'aria-label' => $str('unitn', $unitnumber), 'data-unit-title' => $uindex]),
        'aicb-unit-titlewrap'
    );
    echo html_writer::div($str('lessonscount', count($unit['lessons'] ?? [])), 'aicb-unit-count');
    echo html_writer::end_div();
    echo html_writer::div(
        ui::tool_icon('mod_courseinfo', 'aicb-toolicon aicb-toolicon-sm') . html_writer::span(s(ui::tool_name('mod_courseinfo'))) .
        html_writer::span('→', 'aicb-unit-arrow') . html_writer::span($str('lessons')) . html_writer::span('→', 'aicb-unit-arrow') .
        ui::tool_icon('mod_learningmapping', 'aicb-toolicon aicb-toolicon-sm') .
        html_writer::span(s(ui::tool_name('mod_learningmapping'))),
        'aicb-unit-flow',
        ['title' => $str('coursecontents')]
    );

    foreach ($lessons as $lindex => $lesson) {
        if (!in_array((int)$lesson['number'], array_map('intval', $unit['lessons'] ?? []), true)) {
            continue;
        }
        $number = (int)$lesson['number'];
        $selected = $lesson['tools'] ?? [];
        echo html_writer::start_div('aicb-lesson', ['data-lesson' => $lindex]);
        echo html_writer::span($number, 'aicb-lesson-num');
        echo html_writer::start_div('aicb-lesson-body');
        echo html_writer::empty_tag('input', ['type' => 'text', 'class' => 'form-control aicb-inline-input aicb-lesson-title',
            'value' => (string)$lesson['title'], 'aria-label' => $str('lessonn', $number), 'data-lesson-title' => $lindex]);
        $chips = '';
        foreach ($lesson['outcomes'] ?? [] as $key) {
            $o = $outcomebykey[$key] ?? null;
            $label = $o ? (!empty($o['code']) ? $o['code'] : 'O' . $o['index']) : $key;
            $chips .= html_writer::span(s($label), 'aicb-chip aicb-chip-outcome', ['title' => s($o['text'] ?? $key)]);
        }
        echo html_writer::div($chips, 'aicb-lesson-outcomes');
        $pills = '';
        foreach (ui::LESSON_TOOLS as $component) {
            $on = in_array($component, $selected, true);
            if (!$on && in_array($component, ui::TAGGED_TOOLS, true)) {
                continue;
            }
            if (!$on && !ui::tool_installed($component)) {
                continue;
            }
            $price = $prices[$number][$component];
            $pills .= html_writer::tag(
                'label',
                html_writer::empty_tag('input', array_filter(['type' => 'checkbox', 'class' => 'aicb-pill-check',
                    'data-tool' => $component, 'data-price' => $price === null ? '' : $price,
                    'checked' => $on ? 'checked' : null])) .
                ui::tool_icon($component, 'aicb-toolicon aicb-toolicon-xs') .
                html_writer::span(s(ui::tool_name($component))),
                ['class' => 'aicb-pill' . ($on ? ' is-on' : '')]
            );
        }
        echo html_writer::div($pills, 'aicb-lesson-tools');
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_tag('section');
}

// Outcomes.
echo html_writer::start_tag('section', ['class' => 'aicb-card']);
echo html_writer::tag('h3', $str('outcomes'), ['class' => 'aicb-card-title']);
// An outcome no lesson teaches is never taught or assessed: say so before anything is built.
$covered = [];
foreach ($lessons as $planlesson) {
    foreach ((array)($planlesson['outcomes'] ?? []) as $reference) {
        $reference = is_array($reference) ? ($reference['code'] ?? $reference['text'] ?? '') : $reference;
        $covered[clean_param((string)$reference, PARAM_TEXT)] = true;
    }
}
$uncovered = 0;
foreach ($outcomes as $outcome) {
    $keys = array_filter([clean_param((string)($outcome['code'] ?? ''), PARAM_TEXT), clean_param((string)($outcome['text'] ?? ''), PARAM_TEXT)]);
    if (!array_intersect_key($covered, array_flip($keys))) {
        $uncovered++;
    }
}
if ($uncovered) {
    echo html_writer::div($str('uncoveredoutcomes', $uncovered), 'alert alert-warning');
}
echo html_writer::start_tag('ol', ['class' => 'aicb-outcomes']);
foreach ($outcomes as $index => $outcome) {
    $tags = '';
    $keys = array_filter([clean_param((string)($outcome['code'] ?? ''), PARAM_TEXT), clean_param((string)($outcome['text'] ?? ''), PARAM_TEXT)]);
    if (!array_intersect_key($covered, array_flip($keys))) {
        $tags .= html_writer::span($str('notinanylesson'), 'aicb-chip aicb-chip-attention');
    }
    foreach ($outcome['tags'] ?? [] as $tag) {
        $tags .= html_writer::span(s($tag), 'aicb-chip aicb-chip-info');
    }
    echo html_writer::tag(
        'li',
        html_writer::span(s(!empty($outcome['code']) ? $outcome['code'] : 'O' . ($index + 1)), 'aicb-chip aicb-chip-outcome') .
        html_writer::span(s($outcome['text'] ?? ''), 'aicb-outcome-text') . $tags
    );
}
echo html_writer::end_tag('ol');
echo html_writer::end_tag('section');

echo html_writer::tag(
    'details',
    html_writer::tag('summary', $str('editjson')) .
    html_writer::tag('textarea', s($program->planjson), ['id' => 'id_planjson', 'name' => 'planjson', 'rows' => 14,
        'class' => 'form-control aicb-mono mt-2', 'spellcheck' => 'false']),
    ['class' => 'aicb-card aicb-json']
);
echo html_writer::end_div();

// Credit estimate and approval.
echo html_writer::start_tag('aside', ['class' => 'aicb-card aicb-estimate']);
echo html_writer::tag('h3', $str('creditestimate'), ['class' => 'aicb-card-title']);
echo html_writer::tag('p', $str('creditestimatedesc'), ['class' => 'aicb-estimate-lead']);
echo html_writer::start_tag('table', ['class' => 'aicb-estimate-table']);
echo html_writer::tag('thead', html_writer::tag(
    'tr',
    html_writer::tag('th', $str('plugin')) . html_writer::tag('th', $str('activities'), ['class' => 'num']) .
    html_writer::tag('th', $str('credits'), ['class' => 'num'])
));
echo html_writer::start_tag('tbody', ['data-aicb' => 'estimate-rows']);
foreach (array_merge(ui::LESSON_TOOLS, ['mod_courseinfo', 'mod_learningmapping']) as $component) {
    echo html_writer::tag(
        'tr',
        html_writer::tag('td', ui::tool_icon($component, 'aicb-toolicon aicb-toolicon-xs') . s(ui::tool_name($component))) .
        html_writer::tag('td', '0', ['class' => 'num', 'data-count' => $component]) .
        html_writer::tag('td', '0', ['class' => 'num', 'data-credits' => $component]),
        ['data-row' => $component, 'hidden' => 'hidden']
    );
}
echo html_writer::end_tag('tbody');
echo html_writer::tag('tfoot', html_writer::tag(
    'tr',
    html_writer::tag('th', $str('total')) . html_writer::tag('th', '0', ['class' => 'num', 'data-aicb' => 'total-count']) .
    html_writer::tag('th', '0', ['class' => 'num', 'data-aicb' => 'total-credits'])
));
echo html_writer::end_tag('table');
echo html_writer::tag('p', $str('approvenote'), ['class' => 'aicb-estimate-note']);
echo html_writer::tag('button', $str('approvebuild'), ['type' => 'submit', 'class' => 'btn btn-primary btn-lg w-100 aicb-btn-main',
    'data-aicb' => 'approve']);
echo html_writer::tag('p', $str('planningnote'), ['class' => 'aicb-estimate-free']);
echo html_writer::end_tag('aside');

echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo $OUTPUT->footer();
