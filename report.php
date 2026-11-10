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
 * Build progress and final report for one course plan.
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
\local_aicoursebuilder\local\ui::require_builder();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/aicoursebuilder/report.php', ['id' => $id]));
$program = $DB->get_record('local_aicoursebuilder_program', ['id' => $id, 'userid' => $USER->id], '*', MUST_EXIST);
$jobs = $DB->get_records('local_aicoursebuilder_job', ['programid' => $id], 'id ASC');
$units = $DB->get_records('local_aicoursebuilder_unit', ['programid' => $id], 'unitnumber ASC');
$lessons = $DB->get_records('local_aicoursebuilder_lesson', ['programid' => $id], 'lessonnumber ASC');
// Each course numbers its lessons from 1, as its sections do.
$lessonpos = [];
$unitcount = [];
foreach ($lessons as $l) {
    $unitcount[$l->unitid] = ($unitcount[$l->unitid] ?? 0) + 1;
    $lessonpos[$l->id] = $unitcount[$l->unitid];
}
$outcomes = $DB->get_records('local_aicoursebuilder_outcome', ['programid' => $id], 'sortorder ASC');

$counts = ['complete' => 0, 'active' => 0, 'waiting' => 0, 'attention' => 0, 'total' => count($jobs)];
foreach ($jobs as $job) {
    if ($job->status === 'complete') {
        $counts['complete']++;
    } else if (in_array($job->status, ['failed', 'blocked'], true)) {
        $counts['attention']++;
    } else if ($job->status === 'queued') {
        $counts['waiting']++;
    } else {
        $counts['active']++;
    }
}
$percentage = $counts['total'] > 0 ? (int)floor(($counts['complete'] / $counts['total']) * 100) : 0;

$str = static fn(string $key, $a = null): string => get_string($key, 'local_aicoursebuilder', $a);
ui::setup_page(new moodle_url('/local/aicoursebuilder/report.php', ['id' => $id]), $str('openprogress'), format_string($program->name));
$PAGE->navbar->add(format_string($program->name));
if ($program->status === 'building') {
    header('Refresh: 10; url=' . $PAGE->url->out(false));
}

echo $OUTPUT->header();
echo html_writer::start_div('aicb aicb-report');

// Progress card.
$complete = $program->status === 'complete';
$tone = $counts['attention'] ? 'attention' : ($complete ? 'done' : 'active');
echo html_writer::start_div('aicb-card aicb-progress aicb-progress-' . $tone);
echo html_writer::start_div('aicb-progress-top');
echo html_writer::div(
    html_writer::tag('h3', $complete ? $str('buildcomplete') : $str('buildheading'), ['class' => 'aicb-card-title']) .
    html_writer::tag('p', $complete ? $str('buildcompletedesc') : $str('buildrunning'), ['class' => 'aicb-card-lead']),
    'aicb-progress-text'
);
echo html_writer::div($percentage . '%', 'aicb-progress-pct');
echo html_writer::end_div();
echo html_writer::div(html_writer::div('', 'aicb-progress-fill', ['style' => 'width: ' . $percentage . '%']), 'aicb-progress-bar', [
    'role' => 'progressbar', 'aria-valuenow' => $percentage, 'aria-valuemin' => 0, 'aria-valuemax' => 100,
    'aria-label' => $str('buildprogresslabel'),
]);
echo html_writer::div(
    get_string('buildprogress', 'local_aicoursebuilder', [
        'complete' => $counts['complete'], 'total' => $counts['total'], 'failed' => $counts['attention'],
    ]),
    'aicb-progress-caption'
);
echo html_writer::start_div('aicb-stats aicb-stats-sm');
echo ui::stat((string)$counts['complete'], $str('done'));
echo ui::stat((string)$counts['active'], $str('working'));
echo ui::stat((string)$counts['waiting'], $str('waitingtostart'));
echo ui::stat((string)$counts['attention'], $str('needsattention'));
echo html_writer::end_div();
if ($program->error) {
    echo $OUTPUT->notification(format_text($program->error, FORMAT_PLAIN), 'warning');
}
echo html_writer::end_div();

echo html_writer::div($str('coursehiddennote'), 'alert alert-info mt-3');

// Courses, lessons and activities.
$jobsbylesson = [];
foreach ($jobs as $job) {
    $jobsbylesson[$job->lessonid][] = $job;
}
$tile = static function (\stdClass $job) use ($str): string {
    $link = '';
    if ($job->cmid && str_starts_with($job->component, 'mod_')) {
        $link = html_writer::link(
            new moodle_url('/mod/' . substr($job->component, 4) . '/view.php', ['id' => $job->cmid]),
            $str('openactivity'),
            ['class' => 'aicb-tile-link']
        );
    }
    $error = $job->error ? html_writer::div(s(shorten_text($job->error, 220)), 'aicb-tile-error') : '';
    if (in_array($job->status, ['failed', 'blocked'], true)) {
        // Blocked means the service may already have started (and charged) the request:
        // the teacher is told to check the activity first; failed is safe to retry.
        $error .= html_writer::tag('form',
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'jobid', 'value' => $job->id]) .
            html_writer::tag('button', $str($job->status === 'blocked' ? 'tryagainchecked' : 'tryagain'),
                ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary mt-2']),
            ['method' => 'post', 'action' => (new moodle_url('/local/aicoursebuilder/retry.php'))->out(false)]
        );
    }
    return html_writer::div(
        ui::tool_icon($job->component, 'aicb-toolicon aicb-toolicon-sm') .
        html_writer::div(
            html_writer::div(s(ui::tool_name($job->component)), 'aicb-tile-name') .
            html_writer::div(ui::status_chip($job->status) . $link, 'aicb-tile-meta') . $error,
            'aicb-tile-text'
        ),
        'aicb-tile aicb-tile-' . s($job->status)
    );
};
foreach ($units as $unit) {
    echo html_writer::start_tag('section', ['class' => 'aicb-card aicb-unit']);
    echo html_writer::start_div('aicb-unit-head');
    echo html_writer::span(sprintf('%02d', $unit->unitnumber), 'aicb-unit-num');
    echo html_writer::div(
        html_writer::div($str('unitn', $unit->unitnumber), 'aicb-unit-kicker') .
        html_writer::tag('h3', format_string($unit->title), ['class' => 'aicb-unit-name']),
        'aicb-unit-titlewrap'
    );
    if ($unit->courseid) {
        echo html_writer::link(
            new moodle_url('/course/view.php', ['id' => $unit->courseid]),
            $str('opencourse'),
            ['class' => 'btn btn-outline-primary btn-sm']
        );
    }
    echo html_writer::end_div();
    $courselevel = [];
    foreach ($lessons as $lesson) {
        if ((int)$lesson->unitid !== (int)$unit->id) {
            continue;
        }
        $tiles = '';
        foreach ($jobsbylesson[$lesson->id] ?? [] as $job) {
            if (in_array($job->component, ['mod_courseinfo', 'mod_learningmapping'], true)) {
                $courselevel[] = $job;
                continue;
            }
            $tiles .= $tile($job);
        }
        echo html_writer::div(
            html_writer::span($lessonpos[$lesson->id], 'aicb-lesson-num') .
            html_writer::div(
                html_writer::div(format_string($lesson->title), 'aicb-lesson-name') .
                html_writer::div($tiles, 'aicb-tiles'),
                'aicb-lesson-body'
            ),
            'aicb-lesson'
        );
    }
    if ($courselevel) {
        echo html_writer::div(
            html_writer::span('', 'aicb-lesson-num aicb-lesson-num-course', ['aria-hidden' => 'true']) .
            html_writer::div(
                html_writer::div($str('courselevel'), 'aicb-lesson-name') .
                html_writer::div(implode('', array_map($tile, $courselevel)), 'aicb-tiles'),
                'aicb-lesson-body'
            ),
            'aicb-lesson'
        );
    }
    echo html_writer::end_tag('section');
}

// Outcome coverage.
if ($outcomes) {
    echo html_writer::start_tag('section', ['class' => 'aicb-card']);
    echo html_writer::tag('h3', $str('outcomecoverage'), ['class' => 'aicb-card-title']);
    $rows = '';
    foreach ($outcomes as $outcome) {
        $taught = [];
        $assessed = [];
        $identifier = $outcome->code ?: $outcome->outcometext;
        foreach ($lessons as $lesson) {
            $lessonoutcomes = json_decode($lesson->outcomesjson, true) ?: [];
            if (!in_array($identifier, $lessonoutcomes, true)) {
                continue;
            }
            $taught[] = (count($unitcount) > 1 ? $str('unitn', (int)($units[$lesson->unitid]->unitnumber ?? 0)) . ' · ' : '') . $str('lessonn', $lessonpos[$lesson->id]);
            foreach ($jobsbylesson[$lesson->id] ?? [] as $job) {
                if ($job->component === 'mod_aiknowledgecheck' && $job->status === 'complete') {
                    $assessed[] = ui::tool_name('mod_aiknowledgecheck') . ' ' . $lessonpos[$lesson->id];
                }
            }
        }
        $ok = html_writer::span('✓', 'aicb-tick', ['aria-hidden' => 'true']);
        $rows .= html_writer::tag(
            'tr',
            html_writer::tag('td', ($outcome->code ? html_writer::span(s($outcome->code), 'aicb-chip aicb-chip-outcome') : '') .
                format_string($outcome->outcometext)) .
            html_writer::tag('td', $taught ? $ok . s(implode(', ', $taught)) : html_writer::span($str('notmapped'), 'aicb-muted')) .
            html_writer::tag('td', $assessed ? $ok . s(implode(', ', $assessed)) : html_writer::span($str('notassessed'), 'aicb-muted'))
        );
    }
    echo html_writer::tag(
        'table',
        html_writer::tag('thead', html_writer::tag('tr', html_writer::tag('th', $str('outcomes')) .
            html_writer::tag('th', $str('taughtin')) . html_writer::tag('th', $str('assessedby')))) .
        html_writer::tag('tbody', $rows),
        ['class' => 'aicb-table']
    );
    echo html_writer::end_tag('section');
}
echo html_writer::div(
    html_writer::link(new moodle_url('/local/aicoursebuilder/index.php'), '← ' . $str('backtoplans')),
    'aicb-backlink'
);
echo html_writer::end_div();
echo $OUTPUT->footer();
