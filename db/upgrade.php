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

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for the local_aicoursebuilder plugin.
 *
 * @param int $oldversion
 * @return bool
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
function xmldb_local_aicoursebuilder_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026110515) {
        $table = new xmldb_table('local_aicoursebuilder_outcome');
        $field = new xmldb_field('tagsjson', XMLDB_TYPE_TEXT, null, null, null, null, null, 'weight');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110515, 'local', 'aicoursebuilder');
    }

    if ($oldversion < 2026111000) {
        // VET programs record the unit code, title and nominal hours for Course Information and Mapping.
        $table = new xmldb_table('local_aicoursebuilder_framework');
        $fields = [
            new xmldb_field('unitcode', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '', 'learnerprofile'),
            new xmldb_field('unittitle', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '', 'unitcode'),
            new xmldb_field('nominalhours', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'unittitle'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026111000, 'local', 'aicoursebuilder');
    }

    return true;
}
