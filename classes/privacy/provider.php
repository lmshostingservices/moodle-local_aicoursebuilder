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

namespace local_aicoursebuilder\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for AI Course Builder's creator-owned program data.
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Describe personal and user-submitted data stored by the plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_user_preference('local_aicoursebuilder_tools', 'privacy:metadata:preference:tools');
        $collection->add_database_table('local_aicoursebuilder_program', [
            'userid' => 'privacy:metadata:userid',
            'name' => 'privacy:metadata:name',
            'status' => 'privacy:metadata:status',
            'deliveryjson' => 'privacy:metadata:delivery',
            'planjson' => 'privacy:metadata:plan',
            'error' => 'privacy:metadata:error',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:program');
        $collection->add_database_table('local_aicoursebuilder_framework', [
            'country' => 'privacy:metadata:country',
            'state' => 'privacy:metadata:state',
            'sector' => 'privacy:metadata:sector',
            'level' => 'privacy:metadata:level',
            'subject' => 'privacy:metadata:subject',
            'language' => 'privacy:metadata:language',
            'spelling' => 'privacy:metadata:spelling',
            'learnerprofile' => 'privacy:metadata:learnerprofile',
        ], 'privacy:metadata:framework');
        $collection->add_database_table('local_aicoursebuilder_outcome', [
            'code' => 'privacy:metadata:outcomecode',
            'outcometext' => 'privacy:metadata:outcometext',
            'strand' => 'privacy:metadata:strand',
            'weight' => 'privacy:metadata:weight',
            'tagsjson' => 'privacy:metadata:tags',
            'sortorder' => 'privacy:metadata:sortorder',
        ], 'privacy:metadata:outcomes');
        $collection->add_database_table('local_aicoursebuilder_unit', [
            'unitnumber' => 'privacy:metadata:unitnumber',
            'title' => 'privacy:metadata:title',
            'courseid' => 'privacy:metadata:courseid',
            'sortorder' => 'privacy:metadata:sortorder',
        ], 'privacy:metadata:units');
        $collection->add_database_table('local_aicoursebuilder_lesson', [
            'title' => 'privacy:metadata:title',
            'outcomesjson' => 'privacy:metadata:outcomesjson',
            'toolsjson' => 'privacy:metadata:toolsjson',
            'sectionid' => 'privacy:metadata:sectionid',
            'status' => 'privacy:metadata:status',
        ], 'privacy:metadata:lessons');
        $collection->add_database_table('local_aicoursebuilder_source', [
            'name' => 'privacy:metadata:sourcename',
            'sourcetext' => 'privacy:metadata:sourcetext',
            'sourcehash' => 'privacy:metadata:sourcehash',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:sources');
        $collection->add_database_table('local_aicoursebuilder_job', [
            'component' => 'privacy:metadata:component',
            'cmid' => 'privacy:metadata:cmid',
            'externaljobid' => 'privacy:metadata:externaljobid',
            'status' => 'privacy:metadata:status',
            'attempts' => 'privacy:metadata:attempts',
            'error' => 'privacy:metadata:error',
            'resultjson' => 'privacy:metadata:resultjson',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:jobs');
        return $collection;
    }

    /**
     * The plugin associates its stored program data with the owning Moodle user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contexts = new contextlist();
        if ($DB->record_exists('local_aicoursebuilder_program', ['userid' => $userid])) {
            $contexts->add_system_context();
        }
        return $contexts;
    }

    /**
     * Add users with saved programs to the privacy user list.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!($context instanceof \context_system)) {
            return;
        }
        $userlist->add_from_sql(
            'id',
            'SELECT userid AS id FROM {local_aicoursebuilder_program}',
            []
        );
    }

    /**
     * Export program plans, submitted curriculum, and generation records.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        $programs = $DB->get_records('local_aicoursebuilder_program', ['userid' => $user->id], 'id ASC');
        if (!$programs) {
            return;
        }
        $context = \context_system::instance();
        foreach ($programs as $program) {
            $programid = (int)$program->id;
            $data = [
                'program' => (array)$program,
                'framework' => self::record_as_array(
                    $DB->get_record('local_aicoursebuilder_framework', ['programid' => $programid])
                ),
                'outcomes' => self::records_as_arrays(
                    $DB->get_records('local_aicoursebuilder_outcome', ['programid' => $programid], 'sortorder ASC')
                ),
                'units' => self::records_as_arrays(
                    $DB->get_records('local_aicoursebuilder_unit', ['programid' => $programid], 'unitnumber ASC')
                ),
                'lessons' => self::records_as_arrays(
                    $DB->get_records('local_aicoursebuilder_lesson', ['programid' => $programid], 'lessonnumber ASC')
                ),
                'sources' => self::records_as_arrays(
                    $DB->get_records('local_aicoursebuilder_source', ['programid' => $programid], 'id ASC')
                ),
                'jobs' => self::records_as_arrays(
                    $DB->get_records('local_aicoursebuilder_job', ['programid' => $programid], 'id ASC')
                ),
            ];
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_aicoursebuilder'), 'program_' . $programid],
                (object)$data
            );
        }
    }

    /**
     * Remove plugin-owned records for all users in the system context.
     *
     * Generated Moodle courses and activities remain Moodle course content and are not deleted here.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!($context instanceof \context_system)) {
            return;
        }
        foreach (
            [
            'local_aicoursebuilder_job',
            'local_aicoursebuilder_lesson',
            'local_aicoursebuilder_source',
            'local_aicoursebuilder_outcome',
            'local_aicoursebuilder_framework',
            'local_aicoursebuilder_unit',
            'local_aicoursebuilder_program',
            ] as $table
        ) {
            $DB->delete_records($table);
        }
    }

    /**
     * Delete one user's programs and all dependent plugin-owned records.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        self::delete_user_programs((int)$user->id);
    }

    /**
     * Delete plugin data for users in an approved user list.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_programs((int)$userid);
        }
    }

    /**
     * Delete only the plugin-owned records associated with the given account.
     *
     * @param int $userid
     */
    private static function delete_user_programs(int $userid): void {
        global $DB;
        $programids = $DB->get_fieldset_select(
            'local_aicoursebuilder_program',
            'id',
            'userid = :userid',
            ['userid' => $userid]
        );
        foreach ($programids as $programid) {
            $conditions = ['programid' => (int)$programid];
            foreach (
                [
                'local_aicoursebuilder_job',
                'local_aicoursebuilder_lesson',
                'local_aicoursebuilder_source',
                'local_aicoursebuilder_outcome',
                'local_aicoursebuilder_framework',
                'local_aicoursebuilder_unit',
                ] as $table
            ) {
                $DB->delete_records($table, $conditions);
            }
            $DB->delete_records('local_aicoursebuilder_program', ['id' => (int)$programid]);
        }
    }

    /**
     * Convert an optional record to an array for the privacy writer.
     *
     * @param stdClass|false $record
     * @return array|null
     */
    private static function record_as_array($record): ?array {
        return $record ? (array)$record : null;
    }

    /**
     * Convert database records to arrays for the privacy writer.
     *
     * @param array $records
     * @return array
     */
    private static function records_as_arrays(array $records): array {
        return array_map(static function ($record) {
            return (array)$record;
        }, array_values($records));
    }

    /**
     * Export the activities the user last chose in the wizard.
     *
     * @param int $userid
     * @return void
     */
    public static function export_user_preferences(int $userid) {
        $tools = get_user_preferences('local_aicoursebuilder_tools', null, $userid);
        if ($tools !== null) {
            writer::export_user_preference(
                'local_aicoursebuilder',
                'local_aicoursebuilder_tools',
                $tools,
                get_string('privacy:metadata:preference:tools', 'local_aicoursebuilder')
            );
        }
    }
}
