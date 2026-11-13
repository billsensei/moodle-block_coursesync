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

use block_coursesync\local\attempts;
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
 * Or, when every attempt is asked for (and both sites have agreed to share
 * them), the mark of each attempt a student handed in, put on the same attempt
 * here. A mark goes only onto work that is recorded as that same attempt from
 * the other site (or, for a student whose submissions were never pulled, onto
 * the attempt of that number): grading a student's own different work with a
 * teacher's mark for something else would be worse than leaving it. Only the
 * newest attempt's mark can change the gradebook, the workflow state or a grade
 * sync's override; an earlier one is history.
 *
 * It is written through mod_assign's own grade update, so the grader screen
 * and the gradebook agree. That is also why this takes over from grade sync
 * for an assignment: a grade that grade sync wrote as a gradebook override,
 * and nobody has touched since, is taken away once the real grade is in, and
 * grade sync leaves a student alone once a marks pull has written their mark.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marks_pull {
    /** @var int Most file bytes one pull brings; the rest wait for the next pull. */
    public const RUN_BYTES = 536870912;

    /** @var string[] Why a pull was never allowed to begin, which is not a pull that happened. */
    protected const NOT_ALLOWED = ['errornomarkspullpermission', 'errormarkspulloff', 'errorattemptspulloff'];

    /**
     * Show what a pull would do, writing nothing.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param http_client|null $client injected only by tests
     * @param int[]|null $only remote course module ids the teacher chose; null means every copied assignment
     * @param bool $allattempts the mark of every attempt, not only the latest
     * @param bool[]|null $chosen the marks the teacher ticked, as a set of grade_pull_result::key() values; null means all of them
     * @return grade_pull_result
     */
    public static function preview(
        int $blockinstanceid,
        int $courseid,
        ?http_client $client = null,
        ?array $only = null,
        bool $allattempts = false,
        ?array $chosen = null
    ): grade_pull_result {
        $result = self::pull($blockinstanceid, $courseid, false, $client, $only, $allattempts, $chosen);
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
     * @param int[]|null $only remote course module ids the teacher chose; null means every copied assignment
     * @param bool $allattempts the mark of every attempt, not only the latest
     * @param bool[]|null $chosen the marks the teacher ticked, as a set of grade_pull_result::key() values; null means all of them
     * @return grade_pull_result
     */
    public static function run(
        int $blockinstanceid,
        int $courseid,
        ?http_client $client = null,
        ?array $only = null,
        bool $allattempts = false,
        ?array $chosen = null
    ): grade_pull_result {
        global $USER;

        $timestarted = time();
        $lock = \core\lock\lock_config::get_lock_factory('block_coursesync')
            ->get_lock('run-' . $blockinstanceid, 0, syncer::LOCK_LIFETIME);

        if (!$lock) {
            $result = grade_pull_result::failure('errorsyncinprogress');
        } else {
            try {
                $result = self::pull($blockinstanceid, $courseid, true, $client, $only, $allattempts, $chosen);
            } finally {
                $lock->release();
            }
        }

        // Written down whatever happened - but not when pulling was never
        // allowed to begin: that is not a pull that happened.
        if (!in_array($result->errorkey, self::NOT_ALLOWED, true)) {
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
     * @param bool $allattempts every attempt is being asked for, which has a switch of its own
     * @return string|null language string identifier, or null if allowed
     */
    public static function check_allowed(int $courseid, bool $allattempts = false): ?string {
        if (!get_config('block_coursesync', 'allowmarkspull')) {
            return 'errormarkspulloff';
        }

        if ($allattempts && !get_config('block_coursesync', 'allowattemptspull')) {
            return 'errorattemptspulloff';
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
     * @param int[]|null $only remote course module ids to cover; null means every copied assignment
     * @param bool $allattempts the mark of every attempt, not only the latest
     * @param bool[]|null $chosen the marks the teacher ticked, as a set of grade_pull_result::key() values; null means all of them
     * @return grade_pull_result
     */
    protected static function pull(
        int $blockinstanceid,
        int $courseid,
        bool $write,
        ?http_client $client,
        ?array $only = null,
        bool $allattempts = false,
        ?array $chosen = null
    ): grade_pull_result {
        $notallowed = self::check_allowed($courseid, $allattempts);

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
        $result->chosen = $chosen;
        $result->everyattempt = $allattempts;
        $copies = submission_pull::local_copies($courseid);

        // Only what the teacher chose. This can only narrow the pull: an id
        // that is not one of this course's copies matches nothing.
        if ($only !== null) {
            $copies = array_intersect_key($copies, array_flip($only));
        }

        // The right to grade is checked for the course; a permission set on one
        // assignment must still hold. Those are left out before the source is asked.
        foreach (submission_pull::refused_by_permission($copies, ['mod/assign:grade']) as $remotecmid => $cm) {
            $result->add(self::entry($cm, null, grade_pull_result::SKIPPED, 'subreasonnomodulepermission'));
            unset($copies[$remotecmid]);
        }

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

        // Every attempt is asked for only of a source that says it can give
        // them (see submission_pull): refused, not quietly cut down to the latest.
        if ($allattempts) {
            $ping = remote_client::ping($record->remoteurl, $token, $client);

            if (!$ping->success) {
                return grade_pull_result::failure($ping->errorkey);
            }

            if (!$ping->offers(attempts::FEATURE)) {
                return grade_pull_result::failure('errorattemptssourceunable');
            }
        }

        $found = remote_client::get_marks(
            $record->remoteurl,
            $token,
            (int) $record->remotecourseid,
            array_keys($copies),
            $client,
            array_map('strval', array_keys($students)),
            $allattempts
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
            'allattempts' => $allattempts,
            'chosen' => $chosen,
        ];

        foreach ($copies as $remotecmid => $cm) {
            $item = $remote[$remotecmid] ?? null;
            $reason = self::assignment_problem($cm, $item);

            if ($reason !== null) {
                $result->add(self::entry($cm, null, grade_pull_result::SKIPPED, $reason));
                continue;
            }

            // Said once for the assignment; the students are still worked through.
            if ($allattempts && $item->attemptsreason !== '') {
                $result->add(self::entry($cm, null, grade_pull_result::SKIPPED, 'markreason' . $item->attemptsreason));
            }

            foreach ($item->marks as $mark) {
                $student = $students[$mark->username] ?? null;

                // Not a student here, or out of this teacher's reach: not
                // previewed, not written, not mentioned.
                if (!$student) {
                    continue;
                }

                if ($allattempts) {
                    self::pull_attempt_marks($result, $run, $cm, $remotecmid, $item, $mark, $student);
                } else {
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

        // Marks are scaled by the source's maximum, so a source that gives
        // none cannot be trusted with any.
        if ($item->grademax <= 0) {
            return 'markreasonbadmax';
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
     * @param \stdClass $mark what the source described (one attempt's, when every attempt is pulled)
     * @param \stdClass $student the student here
     * @param \stdClass|null $each with every attempt: what is here for this student, read once (see
     *      pull_attempt_marks); null for the latest alone
     * @return void
     */
    protected static function pull_mark(
        grade_pull_result $result,
        \stdClass $run,
        \cm_info $cm,
        int $remotecmid,
        \stdClass $item,
        \stdClass $mark,
        \stdClass $student,
        ?\stdClass $each = null
    ): void {
        global $DB;

        $assignid = (int) $cm->instance;
        $context = \context_module::instance($cm->id);
        $assign = $DB->get_record('assign', ['id' => $assignid], 'id, grade', MUST_EXIST);
        $incoming = $mark->grade === null ? null : marks::convert($mark->grade, $item->grademax, (float) $assign->grade);
        $number = (int) $mark->attemptnumber;
        $local = $each
            ? mark_writer::local_grade_at($assignid, (int) $student->id, $number)
            : mark_writer::local_grade($assignid, (int) $student->id);
        $entry = self::entry($cm, $student, grade_pull_result::SKIPPED, null);
        $entry->attempt = $number;
        $entry->files = count($mark->files);
        $entry->grade = $incoming;
        $entry->localgrade = $local && marks::is_marked($local) ? (float) $local->grade : null;
        $result->add($entry);

        if ($each) {
            $unpaired = self::pairing_problem($each, $number);
        } else {
            $unpaired = self::mixing_problem($assignid, (int) $student->id);
        }

        if ($unpaired !== null) {
            $entry->reason = $unpaired;

            return;
        }

        // Whatever the source claims, a mark outside what this assignment can
        // hold is not written: the gradebook would carry a grade above its
        // maximum, or a negative one that assign reads as "not graded".
        if (
            $incoming !== null
            && ($incoming < 0 || $incoming > (float) $assign->grade + 0.5 * 10 ** -marks::PRECISION)
        ) {
            $entry->reason = 'markreasonoutofrange';

            return;
        }

        if ($mark->comment !== null && !marks::comments_enabled($assignid)) {
            $entry->reason = 'markreasonnocomments';

            return;
        }

        if (mark_writer::gradebook_locked($run->course, $assignid, (int) $student->id)) {
            $entry->reason = 'markreasonlocked';

            return;
        }

        $ledger = $each
            ? ($each->ledgers[$number] ?? false)
            : $DB->get_record('block_coursesync_mark', ['assignid' => $assignid, 'userid' => $student->id]);
        [$outcome, $reason] = self::classify($mark, $incoming, $ledger, $local, $context, $assignid);
        $entry->outcome = $outcome;
        $entry->reason = $reason;

        if (!in_array($outcome, [grade_pull_result::ADD, grade_pull_result::UPDATE], true)) {
            return;
        }

        // Left unticked: nothing is fetched, no budget is spent, and no record
        // is kept, so it is offered again next time. Marks have no gap to
        // guard against, so the other attempts carry on.
        if ($run->chosen !== null && !isset($run->chosen[grade_pull_result::key($entry)])) {
            $entry->outcome = grade_pull_result::SKIPPED;
            $entry->reason = 'subreasonnotchosen';

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
        $isnewest = !$each || $each->newest === null || $number >= $each->newest;
        $released = $isnewest && $incoming !== null
            && mark_writer::releasable_override($run->course, $assignid, (int) $student->id) !== null;

        if ($run->write) {
            try {
                // Everything is fetched before anything here changes, so a
                // transfer that fails leaves the student exactly as they were.
                $paths = mark_writer::fetch_files($run->record, $run->token, $remotecmid, $mark, $run->client, $run->allattempts);

                try {
                    $released = mark_writer::write(
                        $run,
                        $cm,
                        $mark,
                        $incoming,
                        $student,
                        $remotecmid,
                        $ledger,
                        $paths,
                        $run->allattempts
                    );
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
     * Each attempt's mark for one student, oldest first, every one decided on its own.
     *
     * What is here for the student is read once: their attempts, the records
     * of the submissions pulled, and the records of the marks pulled. Writing
     * goes oldest first, so what the newest attempt does to the gradebook
     * happens last. A mark that cannot be brought leaves no hole to guard
     * against, as a missing attempt would: the others carry on.
     *
     * @param grade_pull_result $result
     * @param \stdClass $run what the whole pull shares
     * @param \cm_info $cm the copy here
     * @param int $remotecmid
     * @param \stdClass $item what the source said about the assignment
     * @param \stdClass $mark what the source described, with its attempts
     * @param \stdClass $student the student here
     * @return void
     */
    protected static function pull_attempt_marks(
        grade_pull_result $result,
        \stdClass $run,
        \cm_info $cm,
        int $remotecmid,
        \stdClass $item,
        \stdClass $mark,
        \stdClass $student
    ): void {
        global $DB;

        $by = ['assignid' => (int) $cm->instance, 'userid' => (int) $student->id];
        $each = (object) ['locals' => [], 'subledgers' => [], 'ledgers' => [], 'newest' => null];

        foreach (
            $DB->get_records(
                'assign_submission',
                ['assignment' => $by['assignid'], 'userid' => $by['userid'], 'groupid' => 0],
                'attemptnumber ASC'
            ) as $row
        ) {
            $each->locals[(int) $row->attemptnumber] = $row;
        }

        foreach ($DB->get_records('block_coursesync_submission', $by) as $record) {
            $each->subledgers[(int) $record->attemptnumber] = $record;
        }

        foreach ($DB->get_records('block_coursesync_mark', $by) as $record) {
            $each->ledgers[(int) $record->attemptnumber] = $record;
        }

        $each->newest = $each->locals ? max(array_keys($each->locals)) : null;

        foreach ($mark->attempts as $attempt) {
            self::pull_mark($result, $run, $cm, $remotecmid, $item, $attempt, $student, $each);
        }
    }

    /**
     * Why a mark for one attempt cannot go onto the work here, if it cannot.
     *
     * A student with no work here at all is marked at attempt 0, as a teacher
     * marking someone who handed nothing in does. Otherwise the attempt must
     * be here. And if the student's submissions were pulled, it must be
     * recorded as that same attempt from the other site: the student's own
     * work, or another attempt's, is not what the mark was given for.
     *
     * @param \stdClass $each what is here for the student (see pull_attempt_marks)
     * @param int $number the attempt the mark is for
     * @return string|null language string identifier, or null if it can go
     */
    protected static function pairing_problem(\stdClass $each, int $number): ?string {
        if (!$each->locals) {
            return $number === 0 ? null : 'markreasonnoattempt';
        }

        $row = $each->locals[$number] ?? null;

        if (!$row || !in_array($row->status, ['submitted', 'draft'], true)) {
            return 'markreasonnoattempt';
        }

        if ($each->subledgers) {
            $pulled = $each->subledgers[$number] ?? null;

            if (!$pulled || (int) $pulled->remoteattempt !== $number || (int) $pulled->submissionid !== (int) $row->id) {
                return 'markreasonmismatch';
            }
        }

        return null;
    }

    /**
     * Why the latest alone cannot be pulled for this student, if it cannot.
     *
     * A pull of the latest puts the mark where the student's latest attempt is
     * here. For a student whose marks were pulled for several attempts, or
     * whose submissions were, that is the wrong attempt - or no attempt that
     * had it - and the mark it already pulled would be reported as removed.
     *
     * @param int $assignid
     * @param int $userid
     * @return string|null language string identifier, or null if the latest can be pulled
     */
    protected static function mixing_problem(int $assignid, int $userid): ?string {
        global $DB;

        $by = ['assignid' => $assignid, 'userid' => $userid];
        $severalmarks = $DB->count_records('block_coursesync_mark', $by) > 1;
        $laterwork = $DB->record_exists_select(
            'block_coursesync_submission',
            'assignid = :assignid AND userid = :userid AND attemptnumber > 0',
            $by
        );

        return $severalmarks || $laterwork ? 'markreasonattemptsdone' : null;
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
