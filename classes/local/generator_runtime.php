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

namespace local_aicoursebuilder\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Fail-closed dispatcher for plugin-owned course-builder adapters.
 *
 * Each plugin must implement its own native adapter so generated data is saved
 * through that plugin's established route and persistence model. This class
 * deliberately refuses generic cross-plugin database writes.
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
final class generator_runtime {
    /**
     * Check whether a plugin has a complete native adapter.
     *
     * @param string $component
     * @return bool
     */
    public static function component_available(string $component): bool {
        if (!preg_match('/^mod_[a-z0-9_]+$/', $component)) {
            return false;
        }
        $class = '\\' . $component . '\\aicoursebuilder\\generator';
        return class_exists($class)
            && method_exists($class, 'native_estimate')
            && method_exists($class, 'native_generate')
            && method_exists($class, 'native_status')
            && method_exists($class, 'native_save_result');
    }

    /**
     * Why a component cannot be used right now, or null when it is ready.
     *
     * Adapters may define native_ready(): ?string to report site settings that stop
     * generation (for example AI drafting switched off), so the wizard does not offer
     * a tool that would only fail during the build.
     *
     * @param string $component
     * @return string|null
     */
    public static function component_ready(string $component): ?string {
        if (!self::component_available($component)) {
            return null;
        }
        // AI Anatomy is unlocked once per site; when its last check says it is locked, say so
        // up front instead of letting the build fail.
        if ($component === 'mod_aianatomy' && class_exists('\\mod_aianatomy\\local\\unlock')) {
            try {
                if ((\mod_aianatomy\local\unlock::state()['status'] ?? '') === 'locked') {
                    return get_string('anatomylocked', 'local_aicoursebuilder');
                }
            } catch (\Throwable $e) {
                // Unknown state: let the build report any refusal plainly.
                unset($e);
            }
        }
        $class = '\\' . $component . '\\aicoursebuilder\\generator';
        if (!method_exists($class, 'native_ready')) {
            return null;
        }
        try {
            $reason = $class::native_ready();
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    /**
     * Return a server-authoritative estimate via the plugin-owned adapter.
     *
     * @param string $component
     * @param array $brief
     * @return int
     */
    public static function estimate(string $component, array $brief): int {
        self::require_adapter($component);
        $class = '\\' . $component . '\\aicoursebuilder\\generator';
        $estimate = $class::native_estimate($brief);
        if (!is_int($estimate) || $estimate < 0) {
            throw new \moodle_exception('invalidestimate', 'local_aicoursebuilder');
        }
        return $estimate;
    }

    /**
     * Start generation using the plugin's existing path.
     *
     * @param string $component
     * @param int $cmid
     * @param array $brief
     * @return string
     */
    public static function generate(string $component, int $cmid, array $brief): string {
        self::require_adapter($component);
        $class = '\\' . $component . '\\aicoursebuilder\\generator';
        $jobid = $class::native_generate($cmid, $brief);
        if (!is_string($jobid) || trim($jobid) === '') {
            throw new \moodle_exception('generationfailed', 'local_aicoursebuilder');
        }
        return $jobid;
    }

    /**
     * Poll generation and let the owning plugin save completed results.
     *
     * @param string $component
     * @param int $cmid
     * @param string $jobid
     * @return array
     */
    public static function status(string $component, int $cmid, string $jobid): array {
        self::require_adapter($component);
        $class = '\\' . $component . '\\aicoursebuilder\\generator';
        $state = $class::native_status($cmid, $jobid);
        if (!is_array($state) || empty($state['status'])) {
            throw new \moodle_exception('invalidjobstatus', 'local_aicoursebuilder');
        }
        if ($state['status'] === 'complete') {
            if (empty($state['result']) || !is_array($state['result'])) {
                throw new \moodle_exception('emptygeneratorresult', 'local_aicoursebuilder');
            }
            // Native adapters may validate persisted data by returning void and throwing on failure,
            // or return a boolean. Treat only an explicit false as an unsuccessful save.
            $saved = $class::native_save_result($cmid, $state['result']);
            if ($saved === false) {
                throw new \moodle_exception('generationfailed', 'local_aicoursebuilder');
            }
        }
        return $state;
    }

    /**
     * Stop when a plugin has no complete native adapter.
     *
     * @param string $component
     * @return void
     */
    private static function require_adapter(string $component): void {
        if (!self::component_available($component)) {
            throw new \moodle_exception('generatornotready', 'local_aicoursebuilder', '', $component);
        }
    }
}
