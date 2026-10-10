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

namespace block_coursesync;

use block_coursesync\local\mark_writer;
use block_coursesync\local\marks;
use core\http_client;

/**
 * Brings the marks and written feedback teachers gave across from the mapped
 * course.
 *
 * What counts is each student's mark for the attempt the submission pull
 * carries, on an assignment that Course Sync copied or linked and that is
 * graded in points: the number, and the comment with any files embedded in it.
 * It is previewed first, and written only when the teacher confirms.
 *
 * The one rule is the project's usual one: nobody's mark is overwritten. A
 * mark goes in only where the student has none here, or where an earlier pull
 * wrote it and nobody has touched it since. Anything else is flagged and left
 * exactly as it is.
 *
 * It is written through mod_assign's own grade update, so the grader screen
 * and the gradebook agree. That is also why this takes over from grade sync
 * for an assignment: a grade that grade sync wrote as a gradebook override,
 * and nobody has touched since, is taken away once the real grade is in, and
 * grade sync leaves a student alone once a marks pull has written their mark.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marks_pull {
    /** @var int Most file bytes one pull brings; the rest wait for the next pull. */
    public const RUN_BYTES = 536870912;

    /**
     * Show what a pull would do, writing nothing.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param http_client|null $client injected only by tests
     * @return grade_pull_result
     */
    public static function preview(int $blockinstanceid, int $courseid, ?http_client $client = null): grade_pull_result {
        $result = self::pull($blockinstanceid, $courseid, false, $client);
        $result->preview = true;

        return $result;
    }

    /**
     * Pull the marks.
     *
     * Holds the same per-block lock as a sync of activities, so marks are never
     * being written into an activity that a sync is replacing, and is written
     * to the same history.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param http_client|null $client injected only by tests
     * @return grade_pull_result
     */
    public static function run(int $blockinstanceid, int $courseid, ?http_client $client = null): grade_pull_result {
        global $USER;

        $timestarted = time();
        $lock = \core\lock\lock_config::get_lock_factory('block_coursesync')
            ->get_lock('run-' . $blockinstanceid, 0, syncer::LOCK_LIFETIME);

        if (!$lock) {
            $result = grade_pull_result::failure('errorsyncinprogress');
        } else {
            try {
                $result = self::pull($blockinstanceid, $courseid, true, $client);
            } finally {
                $lock->release();
            }
        }

        // Written down whatever happened - but not when pulling was never
        // allowed to begin: that is not a pull that happened.
        if (!in_array($result->errorkey, ['errornomarkspullpermission', 'errormarkspulloff'], true)) {
            history::record_grade_pull(
                $blockinstanceid,
                $courseid,
                (int) $USER->id,
                $timestarted,
                $result,
                history::KIND_MARKS
            );
        }

        return $result;
    }

    /**
     * Why the current user may not pull marks into a course, if they may not.
     *
     * Checked by the engine itself, not only by the pages that call it. A
     * preview counts: it shows other people's marks from the other site.
     *
     * @param int $courseid
     * @return string|null language string identifier, or null if allowed
     */
    public static function check_allowed(int $courseid): ?string {
        if (!get_config('block_coursesync', 'allowmarkspull')) {
            return 'errormarkspulloff';
        }

        // The marks are written as if the person pulling had given them, so
        // it must be someone allowed to grade.
        if (!has_all_capabilities(['block/coursesync:pullmarks', 'mod/assign:grade'], \context_course::instance($courseid))) {
            return 'errornomarkspullpermission';
        }

        return null;
    }

    /**
     * Forget what earlier pulls wrote for a block that is being deleted.
     *
     * The marks themselves stay: they are the students' grades now.
     *
     * @param int $blockinstanceid
     * @return void
     */
    public static function delete_for_block_instance(int $blockinstanceid): void {
        global $DB;

        $DB->delete_records('block_coursesync_mark', ['blockinstanceid' => $blockinstanceid]);
    }

    /**
     * Work through every mark, writing if asked to.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param bool $write false for a preview
     * @param http_client|null $client
     * @return grade_pull_result
     */
    protected static function pull(int $blockinstanceid, int $courseid, bool $write, ?http_client $client): grade_pull_result {
        $notallowed = self::check_allowed($courseid);

        if ($notallowed !== null) {
            return grade_pull_result::failure($notallowed);
        }

        $record = connection::get($blockinstanceid);

        if (!connection::is_mapped($record)) {
            return grade_pull_result::failure('errornotmapped');
        }

        $token = connection::get_token($blockinstanceid);

        if ($token === null) {
            return grade_pull_result::failure('errortokenunreadable');
        }

        $result = new grade_pull_result();
        $copies = submission_pull::local_copies($courseid);

        if (!$copies) {
            return $result;
        }

        // Who this pull is for, before the source is asked anything. With
        // nobody there is nothing to ask for - and an empty list would mean
        // "everyone".
        [$students] = grade_pull::reach($courseid);

        if (!$students) {
            return $result;
        }

        $found = remote_client::get_marks(
            $record->remoteurl,
            $token,
            (int) $record->remotecourseid,
            array_keys($copies),
            $client,
            array_map('strval', array_keys($students))
        );

        if (!$found->success) {
            return grade_pull_result::failure($found->errorkey);
        }

        $remote = [];

        foreach ($found->items as $item) {
            $remote[$item->cmid] = $item;
        }

        // What every student's mark in this pull shares.
        $run = (object) [
            'course' => get_course($courseid),
            'blockinstanceid' => $blockinstanceid,
            'write' => $write,
            'budget' => self::RUN_BYTES,
            'record' => $record,
            'token' => $token,
            'client' => $client,
        ];

        foreach ($copies as $remotecmid => $cm) {
            $item = $remote[$remotecmid] ?? null;
            $reason = self::assignment_problem($cm, $item);

            if ($reason !== null) {
                $result->add(self::entry($cm, null, grade_pull_result::SKIPPED, $reason));
                continue;
            }

            foreach ($item->marks as $mark) {
                $student = $students[$mark->username] ?? null;

                // Not a student here, or out of this teacher's reach: not
                // previewed, not written, not mentioned.
                if ($student) {
                    self::pull_mark($result, $run, $cm, $remotecmid, $item, $mark, $student);
                }
            }
        }

        return $result;
    }

    /**
     * Why a whole assignment cannot take marks, if it cannot.
     *
     * @param \cm_info $cm the copy here
     * @param \stdClass|null $item what the source said about it
     * @return string|null language string identifier, or null if it can
     */
    protected static function assignment_problem(\cm_info $cm, ?\stdClass $item): ?string {
        global $DB;

        if ($item === null) {
            // Most often the original was deleted there.
            return 'subreasongone';
        }

        if ($item->reason !== '') {
            return 'markreason' . $item->reason;
        }

        $assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, grade, teamsubmission', MUST_EXIST);

        return match (marks::assignment_problem($assign)) {
            'team' => 'subreasonteamlocal',
            'scale', 'nograde' => 'markreasonlocalgrade',
            default => null,
        };
    }

    /**
     * Decide what happens to one student's mark, do it if asked to, and add
     * what happened to the result.
     *
     * @param grade_pull_result $result
     * @param \stdClass $run what the whole pull shares: course, blockinstanceid,
     *      write (false for a preview), budget (file bytes it may still bring,
     *      reduced as it goes), record (the block's connection), token (the
     *      source's web service token) and client
     * @param \cm_info $cm the copy here
     * @param int $remotecmid
     * @param \stdClass $item what the source said about the assignment
     * @param \stdClass $mark what the source described
     * @param \stdClass $student the student here
     * @return void
     */
    protected static function pull_mark(
        grade_pull_result $result,
        \stdClass $run,
        \cm_info $cm,
        int $remotecmid,
        \stdClass $item,
        \stdClass $mark,
        \stdClass $student
    ): void {
        global $DB;

        $assignid = (int) $cm->instance;
        $context = \context_module::instance($cm->id);
        $assign = $DB->get_record('assign', ['id' => $assignid], 'id, grade', MUST_EXIST);
        $incoming = $mark->grade === null ? null : marks::convert($mark->grade, $item->grademax, (float) $assign->grade);
        $local = mark_writer::local_grade($assignid, (int) $student->id);
        $entry = self::entry($cm, $student, grade_pull_result::SKIPPED, null);
        $entry->attempt = $mark->attemptnumber;
        $entry->files = count($mark->files);
        $entry->grade = $incoming;
        $entry->localgrade = $local && marks::is_marked($local) ? (float) $local->grade : null;
        $result->add($entry);

        if ($mark->comment !== null && !marks::comments_enabled($assignid)) {
            $entry->reason = 'markreasonnocomments';

            return;
        }

        if (mark_writer::gradebook_locked($run->course, $assignid, (int) $student->id)) {
            $entry->reason = 'markreasonlocked';

            return;
        }

        $ledger = $DB->get_record('block_coursesync_mark', ['assignid' => $assignid, 'userid' => $student->id]);
        [$outcome, $reason] = self::classify($mark, $incoming, $ledger, $local, $context, $assignid);
        $entry->outcome = $outcome;
        $entry->reason = $reason;

        if (!in_array($outcome, [grade_pull_result::ADD, grade_pull_result::UPDATE], true)) {
            return;
        }

        $bytes = array_sum(array_column($mark->files, 'filesize'));

        if ($bytes > $run->budget) {
            // Not an error: the next pull carries on from here.
            $entry->outcome = grade_pull_result::SKIPPED;
            $entry->reason = 'subreasonrunlimit';

            return;
        }

        $run->budget -= $bytes;
        $released = $incoming !== null && mark_writer::releasable_override($run->course, $assignid, (int) $student->id) !== null;

        if ($run->write) {
            try {
                // Everything is fetched before anything here changes, so a
                // transfer that fails leaves the student exactly as they were.
                $paths = mark_writer::fetch_files($run->record, $run->token, $remotecmid, $mark, $run->client);

                try {
                    $released = mark_writer::write($run, $cm, $mark, $incoming, $student, $remotecmid, $ledger, $paths);
                } finally {
                    foreach ($paths as $path) {
                        @unlink($path);
                    }
                }
            } catch (\moodle_exception $e) {
                $entry->outcome = grade_pull_result::SKIPPED;
                $entry->reason = match (true) {
                    $e->errorcode === 'errorfilecorrupt' => 'subreasoncorrupt',
                    $e instanceof \dml_exception, $e->errorcode === 'errormarkrefused' => 'subreasonfailed',
                    default => 'subreasontransfer',
                };

                return;
            }
        }

        if ($released) {
            $result->add(self::entry($cm, $student, grade_pull_result::RELEASED, null));
        }
    }

    /**
     * What to do with one mark, given what is here.
     *
     * @param \stdClass $mark what the source described
     * @param float|null $incoming the mark in this assignment's terms
     * @param \stdClass|false $ledger the record of an earlier pull, if any
     * @param \stdClass|null $local the grade row here, if any
     * @param \context_module $context
     * @param int $assignid
     * @return array [outcome, reason (language string identifier or null)]
     */
    protected static function classify(
        \stdClass $mark,
        ?float $incoming,
        $ledger,
        ?\stdClass $local,
        \context_module $context,
        int $assignid
    ): array {
        if (!mark_writer::has_mark($context, $local, $assignid)) {
            // Nothing here. If an earlier pull wrote it and it has gone, a
            // person removed it: it is not brought back.
            return $ledger
                ? [grade_pull_result::SKIPPED, 'markreasondeleted']
                : [grade_pull_result::ADD, null];
        }

        $here = mark_writer::fingerprint_here($context, $local, $assignid);

        if (!$ledger || (int) $ledger->gradeid !== (int) $local->id) {
            // A mark here that Course Sync did not write: if it happens to be
            // the very same mark there is nothing to do, otherwise it is left.
            $same = marks::fingerprint($incoming, $mark->comment, $mark->commentformat, $mark->files);

            return $here === $same
                ? [grade_pull_result::SAME, null]
                : [grade_pull_result::CONFLICT, 'markreasonexists'];
        }

        // Nothing new on the source: whatever has happened here since is no
        // business of this pull, and not worth flagging on every pull after.
        if ($mark->fingerprint === $ledger->fingerprint) {
            return [grade_pull_result::SAME, null];
        }

        // The source has changed. If what is here is exactly what the earlier
        // pull wrote, it can be replaced; if anyone has touched it, it stays.
        return $here === $ledger->localfingerprint
            ? [grade_pull_result::UPDATE, null]
            : [grade_pull_result::CONFLICT, 'markreasonchanged'];
    }

    /**
     * A fresh entry for the result.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $student null for an entry about the whole assignment
     * @param string $outcome
     * @param string|null $reason
     * @return \stdClass
     */
    protected static function entry(\cm_info $cm, ?\stdClass $student, string $outcome, ?string $reason): \stdClass {
        return (object) [
            'kind' => grade_pull_result::KIND_MARK,
            'cmid' => (int) $cm->id,
            'activity' => $cm->name,
            'itemnumber' => 0,
            'gradeitemid' => 0,
            'userid' => $student ? (int) $student->id : 0,
            'username' => $student ? $student->username : '',
            'outcome' => $outcome,
            'reason' => $reason,
            'grade' => null,
            'localgrade' => null,
            'attempt' => 0,
            'files' => 0,
        ];
    }
}
