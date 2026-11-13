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
use block_coursesync\local\submission_writer;
use block_coursesync\local\submissions;
use core\http_client;

/**
 * Brings students' assignment submissions across from the mapped course.
 *
 * What counts is each student's latest submitted attempt at an assignment that
 * Course Sync copied or linked: online text and uploaded files, nothing else.
 * It is previewed first, and written only when the teacher confirms.
 *
 * Or, when every attempt is asked for (and both sites have agreed to share
 * them), each attempt a student handed in, kept here as the same attempt
 * number with a record of its own. The two ways must not be mixed for one
 * student: a latest-only pull writes whatever is newest as attempt 0, which
 * would overwrite the real attempt 0 of a student whose attempts were pulled,
 * so such a student is left alone by a latest-only pull. The other way round
 * mends itself: pulling every attempt puts a latest-only copy back to the
 * attempt it really was, if nobody has touched it.
 *
 * The one rule is the project's usual one: nobody's work is overwritten. A
 * submission goes in only where the student has nothing here, or where an
 * earlier pull wrote it and nobody has touched it since. Anything else is
 * flagged and left exactly as it is.
 *
 * It is written straight into mod_assign's tables and file storage, as
 * mod_assign's own restore does, and not through the student-facing save
 * path: that would enforce deadlines, cut-off dates and locks, which are
 * about when a student may hand work in, not about work that was handed in
 * somewhere else; and it would send notifications and fire events for
 * something the student did not just do.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_pull {
    /** @var int Most file bytes one pull brings; the rest wait for the next pull. */
    public const RUN_BYTES = 536870912;

    /** @var string[] Why a pull was never allowed to begin, which is not a pull that happened. */
    protected const NOT_ALLOWED = ['errornosubmissionpullpermission', 'errorsubmissionpulloff', 'errorattemptspulloff'];

    /**
     * @var string[] The reasons after which a student's later attempts wait, so their history has no gap. An attempt the
     *      teacher left unticked is one of them: bringing the later ones across would leave a hole in the history.
     */
    protected const STOPS = ['subreasonrunlimit', 'subreasoncorrupt', 'subreasonfailed', 'subreasontransfer', 'subreasonnotchosen'];

    /** @var string[] What a person needs in an assignment itself to have work written into it. */
    public const MODULE_CAPABILITIES = ['mod/assign:grade', 'mod/assign:editothersubmission'];

    /**
     * Show what a pull would do, writing nothing.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param http_client|null $client injected only by tests
     * @param int[]|null $only remote course module ids the teacher chose; null means every copied assignment
     * @param bool $allattempts every attempt each student handed in, not only the latest
     * @param bool[]|null $chosen the work the teacher ticked, as a set of grade_pull_result::key() values; null means all of it
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
     * Pull the submissions.
     *
     * Holds the same per-block lock as a sync of activities, so submissions
     * are never being written into an activity that a sync is replacing, and
     * is written to the same history.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param http_client|null $client injected only by tests
     * @param int[]|null $only remote course module ids the teacher chose; null means every copied assignment
     * @param bool $allattempts every attempt each student handed in, not only the latest
     * @param bool[]|null $chosen the work the teacher ticked, as a set of grade_pull_result::key() values; null means all of it
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
                history::KIND_SUBMISSIONS
            );
        }

        return $result;
    }

    /**
     * Why the current user may not pull submissions into a course, if they may not.
     *
     * Checked by the engine itself, not only by the pages that call it, so
     * nothing reaching it by another route can skip it. A preview counts: it
     * shows other people's work from the other site.
     *
     * @param int $courseid
     * @param bool $allattempts every attempt is being asked for, which has a switch of its own
     * @return string|null language string identifier, or null if allowed
     */
    public static function check_allowed(int $courseid, bool $allattempts = false): ?string {
        if (!get_config('block_coursesync', 'allowsubmissionpull')) {
            return 'errorsubmissionpulloff';
        }

        if ($allattempts && !get_config('block_coursesync', 'allowattemptspull')) {
            return 'errorattemptspulloff';
        }

        $context = \context_course::instance($courseid);

        // The work is written as if the student had handed it in, so the
        // person pulling it must be someone allowed to work with
        // submissions by hand.
        $needed = array_merge(['block/coursesync:pullsubmissions'], self::MODULE_CAPABILITIES);

        if (!has_all_capabilities($needed, $context)) {
            return 'errornosubmissionpullpermission';
        }

        return null;
    }

    /**
     * The assignments in a course that this plugin copied or linked.
     *
     * @param int $courseid
     * @return \cm_info[] remote cmid => the copy here
     */
    public static function local_copies(int $courseid): array {
        return array_filter(
            grade_pull::local_copies($courseid),
            static fn(\cm_info $cm) => $cm->modname === 'assign'
        );
    }

    /**
     * Forget what earlier pulls wrote for a block that is being deleted.
     *
     * The submissions themselves stay: they are the students' work now.
     *
     * @param int $blockinstanceid
     * @return void
     */
    public static function delete_for_block_instance(int $blockinstanceid): void {
        global $DB;

        $DB->delete_records('block_coursesync_submission', ['blockinstanceid' => $blockinstanceid]);
    }

    /**
     * Work through every submission, writing if asked to.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param bool $write false for a preview
     * @param http_client|null $client
     * @param int[]|null $only remote course module ids to cover; null means every copied assignment
     * @param bool $allattempts every attempt each student handed in, not only the latest
     * @param bool[]|null $chosen the work the teacher ticked (see run()); null means all of it
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
        $result->everyattempt = $allattempts;
        $result->chosen = $chosen;
        $copies = self::local_copies($courseid);

        // Only what the teacher chose. This can only narrow the pull: an id
        // that is not one of this course's copies matches nothing.
        if ($only !== null) {
            $copies = array_intersect_key($copies, array_flip($only));
        }

        // The right to pull is checked for the course; a permission set on one
        // assignment (a prohibit, an override) must still hold. Those
        // assignments are left out before the source is asked about them.
        foreach (self::refused_by_permission($copies, self::MODULE_CAPABILITIES) as $remotecmid => $cm) {
            $result->add(self::entry($cm, null, grade_pull_result::SKIPPED, 'subreasonnomodulepermission'));
            unset($copies[$remotecmid]);
        }

        if (!$copies) {
            return $result;
        }

        // Who this pull is for, before the source is asked anything: it is
        // told these students and sends only theirs. With nobody, there is
        // nothing to ask for - and an empty list would mean "everyone".
        [$students] = grade_pull::reach($courseid);

        if (!$students) {
            return $result;
        }

        // Every attempt is asked for only of a source that says it can give
        // them: one that does not would refuse the question, and falling back
        // to the latest would not be what was asked for. It is the same for a
        // source that has not switched sharing them on.
        if ($allattempts) {
            $ping = remote_client::ping($record->remoteurl, $token, $client);

            if (!$ping->success) {
                return grade_pull_result::failure($ping->errorkey);
            }

            if (!$ping->offers(attempts::FEATURE)) {
                return grade_pull_result::failure('errorattemptssourceunable');
            }
        }

        $found = remote_client::get_submissions(
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

        // What every student's submission in this pull shares.
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

            if ($allattempts) {
                self::warn_if_too_many_attempts($result, $cm, $item, $students);
            }

            foreach ($item->submissions as $submission) {
                $student = $students[$submission->username] ?? null;

                // Not a student here, or out of this teacher's reach: not
                // previewed, not written, not mentioned.
                if (!$student) {
                    continue;
                }

                if ($allattempts) {
                    foreach (self::pull_attempts($run, $cm, $remotecmid, $submission, $student) as $entry) {
                        $result->add($entry);
                    }
                } else {
                    $result->add(self::pull_submission($run, $cm, $remotecmid, $submission, $student));
                }
            }
        }

        return $result;
    }

    /**
     * Say so when the other site has more attempts for a student than this
     * assignment allows.
     *
     * Not a reason to hold anything back: the attempts are history, and the
     * limit is about giving a student another go. It does mean no teacher here
     * can add one for those students, which is worth knowing before writing.
     *
     * @param grade_pull_result $result
     * @param \cm_info $cm the copy here
     * @param \stdClass $item what the source said about the assignment
     * @param \stdClass[] $students the students in reach, by username
     * @return void
     */
    protected static function warn_if_too_many_attempts(
        grade_pull_result $result,
        \cm_info $cm,
        \stdClass $item,
        array $students
    ): void {
        global $DB;

        $most = 0;

        foreach ($item->submissions as $submission) {
            if (isset($students[$submission->username]) && $submission->attempts) {
                $most = max($most, (int) end($submission->attempts)->attemptnumber + 1);
            }
        }

        // Minus one is "as many as you like" (ASSIGN_UNLIMITED_ATTEMPTS).
        $allowed = (int) $DB->get_field('assign', 'maxattempts', ['id' => $cm->instance], MUST_EXIST);

        if ($allowed !== -1 && $most > $allowed) {
            $result->warn('submissionswarnmaxattempts', (object) [
                'activity' => $cm->name,
                'attempts' => $most,
                'allowed' => $allowed,
            ]);
        }
    }

    /**
     * The assignments among these that the person may not write into, because
     * a permission is missing in the assignment itself.
     *
     * @param \cm_info[] $copies remote cmid => the copy here
     * @param string[] $needed the permissions needed in each assignment
     * @return \cm_info[] the refused ones, by the same ids
     */
    public static function refused_by_permission(array $copies, array $needed): array {
        return array_filter(
            $copies,
            static fn(\cm_info $cm) => !has_all_capabilities($needed, \context_module::instance($cm->id))
        );
    }

    /**
     * Why a whole assignment cannot be brought, if it cannot.
     *
     * @param \cm_info $cm the copy here
     * @param \stdClass|null $item what the source said about it
     * @return string|null language string identifier, or null if it can be
     */
    protected static function assignment_problem(\cm_info $cm, ?\stdClass $item): ?string {
        global $DB;

        if ($item === null) {
            // Most often the original was deleted there.
            return 'subreasongone';
        }

        if ($item->reason !== '') {
            return 'subreason' . $item->reason;
        }

        $assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, teamsubmission', MUST_EXIST);

        if ($assign->teamsubmission) {
            return 'subreasonteamlocal';
        }

        return null;
    }

    /**
     * Decide what happens to one student's submission, and do it if asked to.
     *
     * @param \stdClass $run what the whole pull shares: course, blockinstanceid,
     *      write (false for a preview), budget (file bytes it may still bring,
     *      reduced as it goes), record (the block's connection), token (the
     *      source's web service token) and client
     * @param \cm_info $cm the copy here
     * @param int $remotecmid
     * @param \stdClass $submission what the source described
     * @param \stdClass $student the student here
     * @return \stdClass the entry for the result
     */
    protected static function pull_submission(
        \stdClass $run,
        \cm_info $cm,
        int $remotecmid,
        \stdClass $submission,
        \stdClass $student
    ): \stdClass {
        global $DB;

        $assignid = (int) $cm->instance;
        $context = \context_module::instance($cm->id);
        $entry = self::entry($cm, $student, grade_pull_result::SKIPPED, null);
        $entry->attempt = $submission->attemptnumber;
        $entry->files = count($submission->files);

        // A student whose attempts were pulled one by one is not touched by a
        // pull of the latest alone: it writes the newest as attempt 0, over the
        // real attempt 0.
        $pulledbefore = $DB->record_exists_select(
            'block_coursesync_submission',
            'assignid = :assignid AND userid = :userid AND attemptnumber > 0',
            ['assignid' => $assignid, 'userid' => $student->id]
        );

        if ($pulledbefore) {
            $entry->reason = 'subreasonattemptsdone';

            return $entry;
        }

        $problem = self::content_problem($run->course, $assignid, $submission);

        if ($problem !== null) {
            $entry->reason = $problem;

            return $entry;
        }

        $ledger = $DB->get_record(
            'block_coursesync_submission',
            ['assignid' => $assignid, 'userid' => $student->id, 'attemptnumber' => 0]
        );
        $local = self::local_submission($assignid, (int) $student->id);
        [$outcome, $reason] = self::classify($submission, $ledger, $local, $context);
        $entry->outcome = $outcome;
        $entry->reason = $reason;

        return self::write_one($run, $cm, $remotecmid, $submission, $student, $ledger, $local, $entry);
    }

    /**
     * Every attempt one student handed in, each decided on its own and written
     * as the same attempt number here, oldest first.
     *
     * Their records and the rows they have here are read once, not for each
     * attempt. An attempt that cannot be brought - a failed transfer, the limit
     * for file data - holds back the student's later ones: a history here with
     * a hole in it, and the latest attempt above the hole, is worse than a
     * short one. An attempt that is the same, or kept because of a change made
     * here, does not.
     *
     * Work here that did not come from the other site marks where the two
     * histories part: attempts above the highest such row are left, not mixed
     * in below or above it.
     *
     * @param \stdClass $run what the whole pull shares (see pull_submission)
     * @param \cm_info $cm the copy here
     * @param int $remotecmid
     * @param \stdClass $submission what the source described, with its attempts
     * @param \stdClass $student the student here
     * @return \stdClass[] one entry for each attempt, for the result
     */
    protected static function pull_attempts(
        \stdClass $run,
        \cm_info $cm,
        int $remotecmid,
        \stdClass $submission,
        \stdClass $student
    ): array {
        global $DB;

        $assignid = (int) $cm->instance;
        $context = \context_module::instance($cm->id);
        $by = ['assignid' => $assignid, 'userid' => $student->id];
        $ledgers = [];

        foreach ($DB->get_records('block_coursesync_submission', $by) as $record) {
            $ledgers[(int) $record->attemptnumber] = $record;
        }

        $locals = [];

        foreach (
            $DB->get_records(
                'assign_submission',
                ['assignment' => $assignid, 'userid' => $student->id, 'groupid' => 0],
                'attemptnumber ASC'
            ) as $row
        ) {
            $locals[(int) $row->attemptnumber] = $row;
        }

        $written = array_flip(array_map(static fn($record) => (int) $record->submissionid, $ledgers));
        $barrier = self::barrier($locals, $written, $submission->attempts, $context);
        $entries = [];
        $stopped = false;

        foreach ($submission->attempts as $attempt) {
            $number = (int) $attempt->attemptnumber;
            $entry = self::entry($cm, $student, grade_pull_result::SKIPPED, null);
            $entry->attempt = $number;
            $entry->files = count($attempt->files);
            $entries[] = $entry;

            if ($stopped) {
                $entry->reason = 'subreasonearlierskipped';
                continue;
            }

            if ($barrier !== null && $number > $barrier) {
                $entry->reason = 'subreasonreopenedhere';
                continue;
            }

            $problem = self::content_problem($run->course, $assignid, $attempt);

            if ($problem !== null) {
                $entry->reason = $problem;
                $stopped = true;
                continue;
            }

            // A row that was only opened holds no work and is not in the way:
            // writing the attempt uses it.
            $local = $locals[$number] ?? null;

            if ($local && !in_array($local->status, [submissions::STATUS_SUBMITTED, 'draft'], true)) {
                $local = null;
            }

            $ledger = $ledgers[$number] ?? false;
            [$outcome, $reason] = self::classify($attempt, $ledger, $local, $context, true);
            $entry->outcome = $outcome;
            $entry->reason = $reason;
            self::write_one($run, $cm, $remotecmid, $attempt, $student, $ledger, $local, $entry);

            if ($entry->outcome === grade_pull_result::SKIPPED && in_array($entry->reason, self::STOPS, true)) {
                $stopped = true;
            }
        }

        return $entries;
    }

    /**
     * The highest attempt number here that holds work the other site did not
     * give: a student's own, or a teacher's, or an empty draft.
     *
     * A row that is exactly the other site's attempt of that number does not
     * count: it is the same work, whoever put it there.
     *
     * @param \stdClass[] $locals the student's rows here, by attempt number
     * @param int[] $written submission ids that a record says a pull wrote, as keys
     * @param \stdClass[] $attempts what the source described
     * @param \context_module $context
     * @return int|null null if there is none
     */
    protected static function barrier(array $locals, array $written, array $attempts, \context_module $context): ?int {
        $theirs = [];

        foreach ($attempts as $attempt) {
            $theirs[(int) $attempt->attemptnumber] = $attempt;
        }

        $barrier = null;

        foreach ($locals as $number => $row) {
            if (isset($written[(int) $row->id]) || !in_array($row->status, [submissions::STATUS_SUBMITTED, 'draft'], true)) {
                continue;
            }

            $source = $theirs[$number] ?? null;

            if (
                $source
                && self::fingerprint_here($context, $row)
                    === submissions::fingerprint($source->onlinetext, $source->onlineformat, $source->files)
            ) {
                continue;
            }

            $barrier = max($barrier ?? $number, $number);
        }

        return $barrier;
    }

    /**
     * Bring one attempt across if it is to be: within the run's budget, in a
     * preview not at all, and everything fetched before anything here changes.
     *
     * @param \stdClass $run what the whole pull shares
     * @param \cm_info $cm the copy here
     * @param int $remotecmid
     * @param \stdClass $submission what the source described (one attempt)
     * @param \stdClass $student
     * @param \stdClass|false $ledger the record of an earlier pull, if any
     * @param \stdClass|null $local the submission here that is in the way, if any
     * @param \stdClass $entry the entry for the result, with the outcome that classify() gave; changed here
     * @return \stdClass the same entry
     */
    protected static function write_one(
        \stdClass $run,
        \cm_info $cm,
        int $remotecmid,
        \stdClass $submission,
        \stdClass $student,
        $ledger,
        ?\stdClass $local,
        \stdClass $entry
    ): \stdClass {
        if (!in_array($entry->outcome, [grade_pull_result::ADD, grade_pull_result::UPDATE], true)) {
            return $entry;
        }

        // Left unticked: nothing is fetched, no budget is spent, and no record
        // is kept, so it is offered again next time.
        if ($run->chosen !== null && !isset($run->chosen[grade_pull_result::key($entry)])) {
            $entry->outcome = grade_pull_result::SKIPPED;
            $entry->reason = 'subreasonnotchosen';

            return $entry;
        }

        $bytes = array_sum(array_column($submission->files, 'filesize'));

        if ($bytes > $run->budget) {
            // Not an error: the next pull carries on from here.
            $entry->outcome = grade_pull_result::SKIPPED;
            $entry->reason = 'subreasonrunlimit';

            return $entry;
        }

        $run->budget -= $bytes;

        if (!$run->write) {
            return $entry;
        }

        try {
            // Everything is fetched before anything here changes, so a
            // transfer that fails leaves the student exactly as they were.
            $paths = submission_writer::fetch_files(
                $run->record,
                $run->token,
                $remotecmid,
                $submission,
                $run->client,
                $run->allattempts
            );

            try {
                submission_writer::write(
                    $run->course,
                    $cm,
                    $submission,
                    $student,
                    $run->blockinstanceid,
                    $remotecmid,
                    $ledger,
                    $local,
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
                $e instanceof \dml_exception => 'subreasonfailed',
                default => 'subreasontransfer',
            };
        }

        return $entry;
    }

    /**
     * Why this content cannot be brought into this assignment, if it cannot.
     *
     * @param \stdClass $course
     * @param int $assignid the assignment here
     * @param \stdClass $submission what the source described
     * @return string|null language string identifier, or null if it can be
     */
    protected static function content_problem(\stdClass $course, int $assignid, \stdClass $submission): ?string {
        global $CFG;

        $plugins = submissions::enabled_plugins($assignid);
        $needs = [];

        if ($submission->onlinetext !== null) {
            $needs['onlinetext'] = true;
        }

        foreach ($submission->files as $file) {
            $needs[submissions::AREAS[$file['area']] === 'assignsubmission_file' ? 'file' : 'onlinetext'] = true;
        }

        foreach (array_keys($needs) as $plugin) {
            if (!in_array($plugin, $plugins, true)) {
                return 'subreasonplugin';
            }
        }

        // The site's and the course's own limits, and nothing else: the
        // upload limits of this server's PHP are about a browser's request,
        // and a file that comes from the other site is not one.
        $limits = array_filter([(int) $CFG->maxbytes, (int) $course->maxbytes]);
        $maxbytes = $limits ? min($limits) : 0;

        foreach ($submission->files as $file) {
            if ($maxbytes > 0 && $file['filesize'] > $maxbytes) {
                return 'subreasonfilesize';
            }
        }

        return null;
    }

    /**
     * The student's submission here that counts as being in the way: one that
     * has been handed in or is a draft. A submission that was only ever
     * opened ("new", or "reopened") holds no work.
     *
     * @param int $assignid
     * @param int $userid
     * @return \stdClass|null the assign_submission row
     */
    protected static function local_submission(int $assignid, int $userid): ?\stdClass {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(['submitted', 'draft'], SQL_PARAMS_NAMED);
        $params += ['assignment' => $assignid, 'userid' => $userid];
        $rows = $DB->get_records_select(
            'assign_submission',
            "assignment = :assignment AND userid = :userid AND groupid = 0 AND status {$insql}",
            $params,
            'attemptnumber DESC',
            '*',
            0,
            1
        );

        return $rows ? reset($rows) : null;
    }

    /**
     * What to do with one submission, given what is here.
     *
     * @param \stdClass $submission what the source described
     * @param \stdClass|false $ledger the record of an earlier pull, if any
     * @param \stdClass|null $local the submission here that is in the way, if any
     * @param \context_module $context
     * @param bool $everyattempt the attempt is one of several and is not expected to be the latest here
     * @return array [outcome, reason (language string identifier or null)]
     */
    protected static function classify(
        \stdClass $submission,
        $ledger,
        ?\stdClass $local,
        \context_module $context,
        bool $everyattempt = false
    ): array {
        if (!$local) {
            // Nothing here. If an earlier pull wrote it and it has gone, a
            // person removed it: it is offered again, and says why, so the
            // teacher can leave it out. Writing it uses the same record.
            return [grade_pull_result::ADD, $ledger ? 'subreasonreturned' : null];
        }

        $here = self::fingerprint_here($context, $local);

        if (!$ledger || (int) $ledger->submissionid !== (int) $local->id) {
            // Work here that Course Sync did not write: if it happens to be
            // the very same work there is nothing to do, otherwise it is left.
            // Compared as it would be stored here: the source's own
            // fingerprint is of the text before it was cleaned.
            $same = submissions::fingerprint($submission->onlinetext, $submission->onlineformat, $submission->files);

            return $here === $same
                ? [grade_pull_result::SAME, null]
                : [grade_pull_result::CONFLICT, 'subreasonexists'];
        }

        // Nothing new on the source: whatever has happened here since is no
        // business of this pull, and not worth flagging on every pull after.
        if ($submission->fingerprint === $ledger->fingerprint) {
            return [grade_pull_result::SAME, null];
        }

        // The source has changed. If what is here is exactly what the earlier
        // pull wrote, it can be replaced; if anyone has touched it, it stays.
        // A copy that is no longer the latest attempt has been touched: a
        // teacher reopened the assignment for another go, and replacing it
        // would leave this and the new attempt both marked as the latest. Not
        // so with every attempt: an earlier attempt is not the latest, and
        // which row is, is settled when each is written.
        if (
            $here !== $ledger->localfingerprint
            || $local->status !== submissions::STATUS_SUBMITTED
            || (!$everyattempt && (int) $local->latest !== 1)
        ) {
            return [grade_pull_result::CONFLICT, 'subreasonchanged'];
        }

        return [grade_pull_result::UPDATE, null];
    }

    /**
     * The fingerprint of what a submission here holds.
     *
     * @param \context_module $context
     * @param \stdClass $local the assign_submission row
     * @return string
     */
    protected static function fingerprint_here(\context_module $context, \stdClass $local): string {
        [$text, $format, $files] = submissions::content($context, $local, submissions::PLUGINS);

        return submissions::fingerprint($text, $format, $files);
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
            'kind' => grade_pull_result::KIND_SUBMISSION,
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
