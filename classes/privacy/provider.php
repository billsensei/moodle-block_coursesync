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
 * Privacy provider for block_coursesync.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Describes and serves the one piece of personal data this block keeps.
 *
 * The sync history records who triggered each run, so that a teacher looking
 * at a course can see where its content came from and who brought it in. The
 * ledger of what has been pulled holds no personal data.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes the personal data this plugin stores.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_coursesync_log', [
            'userid' => 'privacy:metadata:log:userid',
            'name' => 'privacy:metadata:log:name',
            'outcome' => 'privacy:metadata:log:outcome',
            'timecreated' => 'privacy:metadata:log:timecreated',
        ], 'privacy:metadata:log');

        return $collection;
    }

    /**
     * Finds the block contexts holding history for a user.
     *
     * @param int $userid The user to look for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {block_coursesync_log} l
               JOIN {context} ctx ON ctx.instanceid = l.blockinstanceid AND ctx.contextlevel = :contextlevel
              WHERE l.userid = :userid",
            ['contextlevel' => CONTEXT_BLOCK, 'userid' => $userid]
        );

        return $contextlist;
    }

    /**
     * Finds the users with history in a context.
     *
     * @param userlist $userlist The userlist to add to.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_block) {
            return;
        }

        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {block_coursesync_log} WHERE blockinstanceid = :blockinstanceid",
            ['blockinstanceid' => $context->instanceid]
        );
    }

    /**
     * Exports a user's sync history.
     *
     * @param approved_contextlist $contextlist Contexts approved for export.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_block) {
                continue;
            }

            $rows = $DB->get_records('block_coursesync_log', [
                'blockinstanceid' => $context->instanceid,
                'userid' => $userid,
            ], 'timecreated ASC');

            if (!$rows) {
                continue;
            }

            $entries = [];
            foreach ($rows as $row) {
                $entries[] = (object) [
                    'activity' => $row->name,
                    'modname' => $row->modname,
                    'outcome' => $row->outcome,
                    'message' => $row->message,
                    'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:path:history', 'block_coursesync')],
                (object) ['runs' => $entries]
            );
        }
    }

    /**
     * Deletes all history in a context.
     *
     * @param \context $context The context to purge.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_block) {
            return;
        }

        $DB->delete_records('block_coursesync_log', ['blockinstanceid' => $context->instanceid]);
    }

    /**
     * Deletes one user's history in the approved contexts.
     *
     * @param approved_contextlist $contextlist Contexts approved for deletion.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_block) {
                continue;
            }

            $DB->delete_records('block_coursesync_log', [
                'blockinstanceid' => $context->instanceid,
                'userid' => $userid,
            ]);
        }
    }

    /**
     * Deletes several users' history in one context.
     *
     * @param approved_userlist $userlist Users approved for deletion.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_block) {
            return;
        }

        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['blockinstanceid'] = $context->instanceid;

        $DB->delete_records_select(
            'block_coursesync_log',
            "blockinstanceid = :blockinstanceid AND userid $insql",
            $params
        );
    }
}
