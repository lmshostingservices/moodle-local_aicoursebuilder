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
 * Presentation helpers shared by the AI Course Builder pages.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_aicoursebuilder\local;

use html_writer;
use moodle_url;

/**
 * Presentation helpers shared by the AI Course Builder pages.
 */
final class ui {
    /** @var string[] Lesson tools in the order they are offered. */
    public const LESSON_TOOLS = [
        'mod_visuallearning', 'mod_contentcreator', 'mod_productexplainer', 'mod_aiactivities',
        'mod_aiknowledgecheck', 'mod_aisoftskills', 'mod_aibranchedscenario',
        'mod_aisafetyhazards', 'mod_aianatomy',
    ];

    /** @var string[] Tools that are only added to lessons with a matching tagged outcome. */
    public const TAGGED_TOOLS = ['mod_aisoftskills', 'mod_aibranchedscenario', 'mod_aisafetyhazards', 'mod_aianatomy'];

    /**
     * Set up a builder page with the standard layout (no site-administration tabs).
     *
     * @param moodle_url $url
     * @param string $title
     * @param string|null $heading
     * @return void
     */
    public static function setup_page(moodle_url $url, string $title, ?string $heading = null): void {
        global $PAGE;
        $PAGE->set_url($url);
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('report');
        $PAGE->set_title($title);
        $PAGE->set_heading($heading ?? $title);
        $PAGE->add_body_class('local-aicb');
        $PAGE->navbar->add(get_string('pluginname', 'local_aicoursebuilder'), new moodle_url('/local/aicoursebuilder/index.php'));
    }

    /**
     * Friendly name of a component.
     *
     * @param string $component
     * @return string
     */
    public static function tool_name(string $component): string {
        $key = 'tool_' . $component;
        if (get_string_manager()->string_exists($key, 'local_aicoursebuilder')) {
            return get_string($key, 'local_aicoursebuilder');
        }
        return $component;
    }

    /**
     * One-line description of a component.
     *
     * @param string $component
     * @return string
     */
    public static function tool_description(string $component): string {
        $key = 'tooldesc_' . $component;
        if (get_string_manager()->string_exists($key, 'local_aicoursebuilder')) {
            return get_string($key, 'local_aicoursebuilder');
        }
        return '';
    }

    /**
     * Whether a component's plugin is installed on this site.
     *
     * @param string $component
     * @return bool
     */
    public static function tool_installed(string $component): bool {
        $plugin = substr($component, 4);
        return \core_component::get_component_directory($component) !== null
            && file_exists(\core_component::get_component_directory($component) . '/version.php')
            && $plugin !== '';
    }

    /**
     * The activity's own icon.
     *
     * @param string $component
     * @param string $class
     * @return string
     */
    public static function tool_icon(string $component, string $class = 'aicb-toolicon'): string {
        global $OUTPUT;
        $modname = substr($component, 4);
        if (!self::tool_installed($component)) {
            return html_writer::span('', $class . ' aicb-toolicon-missing', ['aria-hidden' => 'true']);
        }
        $url = $OUTPUT->image_url('monologo', $modname);
        return html_writer::span(
            html_writer::empty_tag('img', ['src' => $url, 'alt' => '', 'class' => 'aicb-toolicon-img']),
            $class . ' aicb-tool-' . $modname,
            ['aria-hidden' => 'true']
        );
    }

    /**
     * Status chip.
     *
     * @param string $status
     * @return string
     */
    public static function status_chip(string $status): string {
        $known = ['draft', 'planned', 'building', 'complete', 'failed', 'blocked', 'queued', 'generating', 'polling',
            'waiting', 'retry'];
        $key = in_array($status, $known, true) ? $status : 'other';
        $label = get_string_manager()->string_exists('status_' . $key, 'local_aicoursebuilder')
            ? get_string('status_' . $key, 'local_aicoursebuilder')
            : s($status);
        $tone = [
            'draft' => 'muted', 'planned' => 'info', 'building' => 'active', 'complete' => 'done',
            'failed' => 'attention', 'blocked' => 'attention', 'queued' => 'muted', 'generating' => 'active',
            'polling' => 'active', 'waiting' => 'muted', 'retry' => 'attention', 'other' => 'muted',
        ][$key];
        return html_writer::span($label, 'aicb-chip aicb-chip-' . $tone);
    }

    /**
     * Credit estimate for one lesson tool, or null when the plugin cannot price it here.
     *
     * @param string $component
     * @param string $lessontitle
     * @param array $outcomes outcome texts
     * @return int|null
     */
    public static function estimate(string $component, string $lessontitle, array $outcomes): ?int {
        try {
            if (!generator_runtime::component_available($component)) {
                return null;
            }
            return generator_runtime::estimate($component, [
                'lesson' => ['title' => $lessontitle, 'outcomes' => array_values($outcomes)],
                'outcomes' => array_values($outcomes),
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Stat tile.
     *
     * @param string $value
     * @param string $label
     * @param string $extra
     * @return string
     */
    public static function stat(string $value, string $label, string $extra = ''): string {
        return html_writer::div(
            html_writer::div($value, 'aicb-stat-value', $extra ? ['data-aicb-stat' => $extra] : []) .
            html_writer::div($label, 'aicb-stat-label'),
            'aicb-stat'
        );
    }
}
