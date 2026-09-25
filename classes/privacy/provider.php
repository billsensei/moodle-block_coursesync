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
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for block_coursesync.
 *
 * Up to phase 5 this plugin stored no personal data and declared itself a null
 * provider. The sync history added in phase 6 records who started each run, so
 * that is no longer true and the real provider is implemented here.
 *
 * Grade sync (phases 34-38) added two more things to account for:
 * - block_coursesync_grade, this site's note of which students' grades a pull
 *   wrote and what it wrote, kept so a later pull can tell its own grades from
 *   a teacher's. It lives in the block's context, like the run history.
 * - students' grades themselves, which go into the core gradebook here (and are
 *   reported and deleted by core_grades), and which this site sends to another
 *   site when it is the source and grade sharing is switched on.
 *
 * A grade pull's run history holds counts per activity only - no students -
 * so a student's data here is only ever in block_coursesync_grade.
 *
 * The connection record - the remote address and its encrypted token - is course
 * configuration rather than anything about a person, and is not reported here.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe what this plugin stores about people.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_coursesync_run', [
            'userid' => 'privacy:metadata:run:userid',
            'kind' => 'privacy:metadata:run:kind',
            'timestarted' => 'privacy:metadata:run:timestarted',
            'timefinished' => 'privacy:metadata:run:timefinished',
            'status' => 'privacy:metadata:run:status',
            'pulled' => 'privacy:metadata:run:pulled',
            'conflicts' => 'privacy:metadata:run:conflicts',
        ], 'privacy:metadata:run');

        $collection->add_database_table('block_coursesync_grade', [
            'userid' => 'privacy:metadata:grade:userid',
            'gradeitemid' => 'privacy:metadata:grade:gradeitemid',
            'remotecmid' => 'privacy:metadata:grade:remotecmid',
            'finalgrade' => 'privacy:metadata:grade:finalgrade',
            'feedbackhash' => 'privacy:metadata:grade:feedbackhash',
            'remotetime' => 'privacy:metadata:grade:remotetime',
            'timepulled' => 'privacy:metadata:grade:timepulled',
        ], 'privacy:metadata:grade');

        // Pulled grades are written into the gradebook, which reports them.
        $collection->add_subsystem_link('core_grades', [], 'privacy:metadata:core_grades');

        // When this site is the source and grade sharing is on, students'
        // grades leave it for the site that asks.
        $collection->add_external_location_link('othersite', [
            'username' => 'privacy:metadata:othersite:username',
            'grade' => 'privacy:metadata:othersite:grade',
            'feedback' => 'privacy:metadata:othersite:feedback',
        ], 'privacy:metadata:othersite');

        return $collection;
    }

    /**
     * Which contexts hold data about this user.
     *
     * A run is recorded against a block instance, so the block's own context is
     * where its data lives.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {block_coursesync_run} r
                  JOIN {block_instances} bi ON bi.id = r.blockinstanceid
                  JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                 WHERE r.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_BLOCK,
            'userid' => $userid,
        ]);

        $sql = "SELECT ctx.id
                  FROM {block_coursesync_grade} g
                  JOIN {block_instances} bi ON bi.id = g.blockinstanceid
                  JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                 WHERE g.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_BLOCK,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Which users have data in this context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof \context_block) {
            return;
        }

        $userlist->add_from_sql(
            'userid',
            "
                SELECT r.userid
                  FROM {block_coursesync_run} r
                 WHERE r.blockinstanceid = :blockinstanceid",
            ['blockinstanceid' => $context->instanceid]
        );

        $userlist->add_from_sql(
            'userid',
            "
                SELECT g.userid
                  FROM {block_coursesync_grade} g
                 WHERE g.blockinstanceid = :blockinstanceid",
            ['blockinstanceid' => $context->instanceid]
        );
    }

    /**
     * Export what this plugin holds about a user.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_block) {
                continue;
            }

            self::export_pulled_grades($context, (int) $user->id);

            $runs = $DB->get_records('block_coursesync_run', [
                'blockinstanceid' => $context->instanceid,
                'userid' => $user->id,
            ], 'timestarted ASC');

            if (!$runs) {
                continue;
            }

            $data = [];

            foreach ($runs as $run) {
                $data[] = [
                    'timestarted' => transform::datetime($run->timestarted),
                    'timefinished' => transform::datetime($run->timefinished),
                    'status' => $run->status,
                    'pulledcount' => $run->pulledcount,
                    'conflictcount' => $run->conflictcount,
                    'pulled' => json_decode((string) $run->pulled, true) ?: [],
                    'conflicts' => json_decode((string) $run->conflicts, true) ?: [],
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:path:runs', 'block_coursesync')],
                (object) ['runs' => $data]
            );
        }
    }

    /**
     * Export the grades a pull wrote for a student, from one block.
     *
     * @param \context_block $context
     * @param int $userid
     * @return void
     */
    protected static function export_pulled_grades(\context_block $context, int $userid): void {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT g.*, gi.itemname
               FROM {block_coursesync_grade} g
          LEFT JOIN {grade_items} gi ON gi.id = g.gradeitemid
              WHERE g.blockinstanceid = :blockinstanceid AND g.userid = :userid
           ORDER BY g.timepulled ASC, g.id ASC",
            ['blockinstanceid' => $context->instanceid, 'userid' => $userid]
        );

        if (!$rows) {
            return;
        }

        $grades = [];

        foreach ($rows as $row) {
            $grades[] = [
                'gradeitem' => $row->itemname !== null ? format_string($row->itemname) : '',
                'finalgrade' => $row->finalgrade,
                'timepulled' => transform::datetime($row->timepulled),
                'timechangedonothersite' => $row->remotetime ? transform::datetime($row->remotetime) : '',
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:path:grades', 'block_coursesync')],
            (object) ['grades' => $grades]
        );
    }

    /**
     * Delete everything this plugin holds in a context.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_block) {
            return;
        }

        $DB->delete_records('block_coursesync_run', ['blockinstanceid' => $context->instanceid]);
        $DB->delete_records('block_coursesync_grade', ['blockinstanceid' => $context->instanceid]);
    }

    /**
     * Delete everything this plugin holds about one user.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_block) {
                continue;
            }

            // Only this plugin's own record of the pull. The grade itself is
            // in the gradebook, and core_grades deletes that.
            foreach (['block_coursesync_run', 'block_coursesync_grade'] as $table) {
                $DB->delete_records($table, [
                    'blockinstanceid' => $context->instanceid,
                    'userid' => $userid,
                ]);
            }
        }
    }

    /**
     * Delete everything this plugin holds about a list of users in one context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
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

        foreach (['block_coursesync_run', 'block_coursesync_grade'] as $table) {
            $DB->delete_records_select($table, "blockinstanceid = :blockinstanceid AND userid {$insql}", $params);
        }
    }
}
