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
 * Quiz attempts (phases 40-44) add block_coursesync_attempt: which of a
 * student's attempts a pull brought across, and the marks it gave them. The
 * attempts themselves are the quiz's, and its provider covers them.
 *
 * Assignment submissions (phases 60-64) add block_coursesync_submission: which
 * of a student's submissions a pull brought across, and fingerprints of what
 * was written. The submissions and their files are the assignment's, and its
 * provider covers them.
 *
 * Assignment marks (phase 66) add block_coursesync_mark: which of a student's
 * marks a pull brought across, and fingerprints of what was written. The grade
 * and the comment are the assignment's, and its provider covers them.
 *
 * A grade pull's run history holds counts per activity only - no students -
 * so a student's data here is only ever in block_coursesync_grade,
 * block_coursesync_attempt, block_coursesync_submission and block_coursesync_mark.
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
    /** @var string[] Every table that holds something about a person, deleted for them together. */
    protected const TABLES = [
        'block_coursesync_run',
        'block_coursesync_grade',
        'block_coursesync_attempt',
        'block_coursesync_submission',
        'block_coursesync_mark',
    ];

    /** @var string[] The tables a pull writes, which record which of a person's data it brought across. */
    protected const PULLED = [
        'block_coursesync_grade',
        'block_coursesync_attempt',
        'block_coursesync_submission',
        'block_coursesync_mark',
    ];

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
            'pulledcount' => 'privacy:metadata:run:pulledcount',
            'conflictcount' => 'privacy:metadata:run:conflictcount',
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

        $collection->add_database_table('block_coursesync_attempt', [
            'userid' => 'privacy:metadata:attempt:userid',
            'quizid' => 'privacy:metadata:attempt:quizid',
            'attemptid' => 'privacy:metadata:attempt:attemptid',
            'remotecmid' => 'privacy:metadata:attempt:remotecmid',
            'remoteattemptid' => 'privacy:metadata:attempt:remoteattemptid',
            'marks' => 'privacy:metadata:attempt:marks',
            'timeimported' => 'privacy:metadata:attempt:timeimported',
        ], 'privacy:metadata:attempt');

        $collection->add_database_table('block_coursesync_submission', [
            'userid' => 'privacy:metadata:submission:userid',
            'assignid' => 'privacy:metadata:submission:assignid',
            'submissionid' => 'privacy:metadata:submission:submissionid',
            'remotecmid' => 'privacy:metadata:submission:remotecmid',
            'remoteattempt' => 'privacy:metadata:submission:remoteattempt',
            'fingerprint' => 'privacy:metadata:submission:fingerprint',
            'localfingerprint' => 'privacy:metadata:submission:localfingerprint',
            'remotetime' => 'privacy:metadata:submission:remotetime',
            'timeimported' => 'privacy:metadata:submission:timeimported',
        ], 'privacy:metadata:submission');

        $collection->add_database_table('block_coursesync_mark', [
            'userid' => 'privacy:metadata:mark:userid',
            'assignid' => 'privacy:metadata:mark:assignid',
            'gradeid' => 'privacy:metadata:mark:gradeid',
            'remotecmid' => 'privacy:metadata:mark:remotecmid',
            'remoteattempt' => 'privacy:metadata:mark:remoteattempt',
            'fingerprint' => 'privacy:metadata:mark:fingerprint',
            'localfingerprint' => 'privacy:metadata:mark:localfingerprint',
            'remotetime' => 'privacy:metadata:mark:remotetime',
            'timeimported' => 'privacy:metadata:mark:timeimported',
        ], 'privacy:metadata:mark');

        // Pulled grades are written into the gradebook, which reports them.
        $collection->add_subsystem_link('core_grades', [], 'privacy:metadata:core_grades');

        // Quiz attempts brought across are ordinary attempts in the quiz,
        // which reports and deletes them.
        $collection->add_plugintype_link('mod', [], 'privacy:metadata:mod');

        // When this site is the source and grade sharing is on, students'
        // grades leave it for the site that asks.
        $collection->add_external_location_link('othersite', [
            'username' => 'privacy:metadata:othersite:username',
            'grade' => 'privacy:metadata:othersite:grade',
            'feedback' => 'privacy:metadata:othersite:feedback',
            'attempts' => 'privacy:metadata:othersite:attempts',
            'submissions' => 'privacy:metadata:othersite:submissions',
            'marks' => 'privacy:metadata:othersite:marks',
        ], 'privacy:metadata:othersite');

        // And the other way round: when this site is the destination, the
        // usernames of the students it wants grades or attempts for go to the
        // source, which matches them to its own students.
        $collection->add_external_location_link('sourcesite', [
            'username' => 'privacy:metadata:sourcesite:username',
        ], 'privacy:metadata:sourcesite');

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

        foreach (self::PULLED as $table) {
            $sql = "SELECT ctx.id
                      FROM {{$table}} t
                      JOIN {block_instances} bi ON bi.id = t.blockinstanceid
                      JOIN {context} ctx ON ctx.instanceid = bi.id AND ctx.contextlevel = :contextlevel
                     WHERE t.userid = :userid";

            $contextlist->add_from_sql($sql, [
                'contextlevel' => CONTEXT_BLOCK,
                'userid' => $userid,
            ]);
        }

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

        foreach (self::PULLED as $table) {
            $userlist->add_from_sql(
                'userid',
                "SELECT t.userid FROM {{$table}} t WHERE t.blockinstanceid = :blockinstanceid",
                ['blockinstanceid' => $context->instanceid]
            );
        }
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
            self::export_pulled_attempts($context, (int) $user->id);
            self::export_pulled_submissions($context, (int) $user->id);
            self::export_pulled_marks($context, (int) $user->id);

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
                    'kind' => $run->kind,
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
                'gradeitem' => $row->itemname !== null ? format_string($row->itemname, true, ['context' => $context]) : '',
                'finalgrade' => $row->finalgrade,
                'timepulled' => transform::datetime($row->timepulled),
                'timechangedonothersite' => $row->remotetime ? transform::datetime($row->remotetime) : '',
                'remoteactivity' => (int) $row->remotecmid,
                'feedbackfingerprint' => $row->feedbackhash,
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:path:grades', 'block_coursesync')],
            (object) ['grades' => $grades]
        );
    }

    /**
     * Export which of a student's quiz attempts a pull brought across, from
     * one block. The attempts themselves are the quiz's to export.
     *
     * @param \context_block $context
     * @param int $userid
     * @return void
     */
    protected static function export_pulled_attempts(\context_block $context, int $userid): void {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT a.*, q.name AS quizname, qa.attempt AS attemptnumber
               FROM {block_coursesync_attempt} a
          LEFT JOIN {quiz} q ON q.id = a.quizid
          LEFT JOIN {quiz_attempts} qa ON qa.id = a.attemptid
              WHERE a.blockinstanceid = :blockinstanceid AND a.userid = :userid
           ORDER BY a.timeimported ASC, a.id ASC",
            ['blockinstanceid' => $context->instanceid, 'userid' => $userid]
        );

        if (!$rows) {
            return;
        }

        $attempts = [];

        foreach ($rows as $row) {
            $attempts[] = [
                'quiz' => $row->quizname !== null ? format_string($row->quizname, true, ['context' => $context]) : '',
                'attempt' => $row->attemptnumber,
                'marks' => json_decode((string) $row->marks, true) ?: [],
                'timeimported' => transform::datetime($row->timeimported),
                'remotequiz' => (int) $row->remotecmid,
                'remoteattempt' => (int) $row->remoteattemptid,
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:path:attempts', 'block_coursesync')],
            (object) ['attempts' => $attempts]
        );
    }

    /**
     * Export which of a student's assignment submissions a pull brought
     * across, from one block. The submissions themselves, and their files,
     * are the assignment's to export.
     *
     * @param \context_block $context
     * @param int $userid
     * @return void
     */
    protected static function export_pulled_submissions(\context_block $context, int $userid): void {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT s.*, a.name AS assignname
               FROM {block_coursesync_submission} s
          LEFT JOIN {assign} a ON a.id = s.assignid
              WHERE s.blockinstanceid = :blockinstanceid AND s.userid = :userid
           ORDER BY s.timeimported ASC, s.id ASC",
            ['blockinstanceid' => $context->instanceid, 'userid' => $userid]
        );

        if (!$rows) {
            return;
        }

        $submissions = [];

        foreach ($rows as $row) {
            $submissions[] = [
                'assignment' => $row->assignname !== null ? format_string($row->assignname, true, ['context' => $context]) : '',
                'submissionid' => (int) $row->submissionid,
                'timeimported' => transform::datetime($row->timeimported),
                'timechangedonothersite' => $row->remotetime ? transform::datetime($row->remotetime) : '',
                'remoteactivity' => (int) $row->remotecmid,
                'remoteattempt' => (int) $row->remoteattempt,
                'fingerprint' => $row->fingerprint,
                'localfingerprint' => $row->localfingerprint,
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:path:submissions', 'block_coursesync')],
            (object) ['submissions' => $submissions]
        );
    }

    /**
     * Export which of a student's assignment marks a pull brought across, from
     * one block. The grades themselves, and the comments, are the assignment's
     * and the gradebook's to export.
     *
     * @param \context_block $context
     * @param int $userid
     * @return void
     */
    protected static function export_pulled_marks(\context_block $context, int $userid): void {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT m.*, a.name AS assignname
               FROM {block_coursesync_mark} m
          LEFT JOIN {assign} a ON a.id = m.assignid
              WHERE m.blockinstanceid = :blockinstanceid AND m.userid = :userid
           ORDER BY m.timeimported ASC, m.id ASC",
            ['blockinstanceid' => $context->instanceid, 'userid' => $userid]
        );

        if (!$rows) {
            return;
        }

        $marks = [];

        foreach ($rows as $row) {
            $marks[] = [
                'assignment' => $row->assignname !== null ? format_string($row->assignname, true, ['context' => $context]) : '',
                'gradeid' => (int) $row->gradeid,
                'timeimported' => transform::datetime($row->timeimported),
                'timechangedonothersite' => $row->remotetime ? transform::datetime($row->remotetime) : '',
                'remoteactivity' => (int) $row->remotecmid,
                'remoteattempt' => (int) $row->remoteattempt,
                'fingerprint' => $row->fingerprint,
                'localfingerprint' => $row->localfingerprint,
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:path:marks', 'block_coursesync')],
            (object) ['marks' => $marks]
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
        $DB->delete_records('block_coursesync_attempt', ['blockinstanceid' => $context->instanceid]);
        $DB->delete_records('block_coursesync_submission', ['blockinstanceid' => $context->instanceid]);
        $DB->delete_records('block_coursesync_mark', ['blockinstanceid' => $context->instanceid]);
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
            foreach (self::TABLES as $table) {
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

        foreach (self::TABLES as $table) {
            $DB->delete_records_select($table, "blockinstanceid = :blockinstanceid AND userid {$insql}", $params);
        }
    }
}
