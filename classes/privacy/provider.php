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

namespace block_coursesync\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for block_coursesync.
 *
 * The only personal data this plugin stores is block_coursesync_synclog -
 * one row per "Sync now" run, recording which user triggered it (see
 * classes/local/sync_history.php). Nothing else this plugin touches is
 * user-specific: the remote URL/token belong to the block instance (an
 * admin/teacher configuration choice, not personal data about the person
 * who saved it), and no content pulled from the remote site is itself
 * personal data about a Moodle user on this site.
 *
 * Each synclog row lives in its block instance's own CONTEXT_BLOCK context,
 * exactly like every other per-instance-data block provider (see
 * blocks/html/classes/privacy/provider.php for the pattern this follows) -
 * except that, unlike block_html, more than one user's rows can exist in
 * the same context (any user with block/coursesync:sync can trigger a
 * sync), so deletion here only ever removes that one user's own rows from
 * a context, never the whole context's history, except when core asks to
 * purge a context entirely (delete_data_for_all_users_in_context()).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    core_userlist_provider {
    /**
     * Describes the personal data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_coursesync_synclog', [
            'blockinstanceid' => 'privacy:metadata:block_coursesync_synclog:blockinstanceid',
            'courseid' => 'privacy:metadata:block_coursesync_synclog:courseid',
            'userid' => 'privacy:metadata:block_coursesync_synclog:userid',
            'timecreated' => 'privacy:metadata:block_coursesync_synclog:timecreated',
            'success' => 'privacy:metadata:block_coursesync_synclog:success',
            'errorcode' => 'privacy:metadata:block_coursesync_synclog:errorcode',
            'createdcount' => 'privacy:metadata:block_coursesync_synclog:createdcount',
            'conflictcount' => 'privacy:metadata:block_coursesync_synclog:conflictcount',
            'failedcount' => 'privacy:metadata:block_coursesync_synclog:failedcount',
            'unsupportedcount' => 'privacy:metadata:block_coursesync_synclog:unsupportedcount',
        ], 'privacy:metadata:block_coursesync_synclog');

        return $collection;
    }

    /**
     * Every block context containing a synclog row triggered by this user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {block_coursesync_synclog} scl
                  JOIN {context} ctx ON ctx.instanceid = scl.blockinstanceid AND ctx.contextlevel = :contextblock
                 WHERE scl.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextblock' => CONTEXT_BLOCK,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Every user who has a synclog row in the given block context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_block) {
            return;
        }

        $sql = "SELECT userid
                  FROM {block_coursesync_synclog}
                 WHERE blockinstanceid = :blockinstanceid";

        $userlist->add_from_sql('userid', $sql, ['blockinstanceid' => $context->instanceid]);
    }

    /**
     * Exports this user's own synclog rows in each approved context.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_block) {
                continue;
            }

            $records = $DB->get_records('block_coursesync_synclog', [
                'blockinstanceid' => $context->instanceid,
                'userid' => $user->id,
            ], 'timecreated ASC');

            if (empty($records)) {
                continue;
            }

            $synclogs = [];
            foreach ($records as $record) {
                $synclogs[] = (object) [
                    'courseid' => (int) $record->courseid,
                    'timecreated' => transform::datetime((int) $record->timecreated),
                    'success' => transform::yesno((bool) $record->success),
                    'errorcode' => $record->errorcode,
                    'createdcount' => (int) $record->createdcount,
                    'conflictcount' => (int) $record->conflictcount,
                    'failedcount' => (int) $record->failedcount,
                    'unsupportedcount' => (int) $record->unsupportedcount,
                ];
            }

            writer::with_context($context)->export_data([], (object) ['synclogs' => $synclogs]);
        }
    }

    /**
     * Deletes every synclog row in the given block context - used when core
     * purges a context entirely (e.g. the block instance is being deleted),
     * not for a single user's own erasure request (see delete_data_for_user()).
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_block) {
            return;
        }

        $DB->delete_records('block_coursesync_synclog', ['blockinstanceid' => $context->instanceid]);
    }

    /**
     * Deletes this user's own synclog rows from each approved context, leaving
     * other users' rows in the same context (and block instance) untouched.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_block) {
                continue;
            }

            $DB->delete_records('block_coursesync_synclog', [
                'blockinstanceid' => $context->instanceid,
                'userid' => $userid,
            ]);
        }
    }

    /**
     * Deletes the listed users' synclog rows from one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_block) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $inparams['blockinstanceid'] = $context->instanceid;

        $DB->delete_records_select(
            'block_coursesync_synclog',
            "blockinstanceid = :blockinstanceid AND userid $insql",
            $inparams
        );
    }
}
