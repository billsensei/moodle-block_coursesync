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

use core\http_client;
use mod_quiz\question\bank\qbank_helper;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Brings students' finished quiz attempts from the source into this site's
 * copies of the quizzes, as marks.
 *
 * Each attempt becomes a real, finished attempt here: the same start and
 * finish times, and each question given the mark it got on the source, with a
 * comment saying where it was answered. What the student actually answered
 * does not come (format version 1 carries marks only), so a question here
 * shows as not answered but marked. The quiz then works out the student's
 * grade from these attempts as it would from any others, and they count
 * towards the number of attempts the quiz allows here.
 *
 * The attempt is built directly rather than submitted through the quiz, so
 * that nobody is emailed about an attempt made weeks ago on another site: no
 * "attempt submitted" event, and the "graded" notification marked as sent.
 *
 * Which students, and who may do it, follow grade_pull exactly: students by
 * username who are active graded users here, and grade_pull::check_allowed()
 * for the person pulling - plus mod/quiz:grade in each quiz.
 *
 * Results use grade_pull_result, with these outcomes:
 * - ADD: a new attempt, created here;
 * - SAME: brought across before, and nothing has changed;
 * - UPDATE: brought across before, its marks since changed on the source,
 *   and nobody here has changed them;
 * - CONFLICT: as UPDATE, but someone here has changed them - kept;
 * - SKIPPED: with a reason.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_pull {
    /**
     * What a pull would do, without writing anything.
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
     * Bring the attempts across.
     *
     * Holds the same per-block lock as a sync and a grade pull.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param http_client|null $client injected only by tests
     * @return grade_pull_result
     */
    public static function run(int $blockinstanceid, int $courseid, ?http_client $client = null): grade_pull_result {
        $lock = \core\lock\lock_config::get_lock_factory('block_coursesync')
            ->get_lock('run-' . $blockinstanceid, 0, syncer::LOCK_LIFETIME);

        if (!$lock) {
            return grade_pull_result::failure('errorsyncinprogress');
        }

        try {
            return self::pull($blockinstanceid, $courseid, true, $client);
        } finally {
            $lock->release();
        }
    }

    /**
     * Forget which attempts a block brought across, when it is deleted.
     *
     * The attempts themselves stay: they are the students' attempts now.
     *
     * @param int $blockinstanceid
     * @return void
     */
    public static function delete_for_block_instance(int $blockinstanceid): void {
        global $DB;

        $DB->delete_records('block_coursesync_attempt', ['blockinstanceid' => $blockinstanceid]);
    }

    /**
     * Work through every attempt, writing if asked to.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param bool $write false for a preview
     * @param http_client|null $client
     * @return grade_pull_result
     */
    protected static function pull(int $blockinstanceid, int $courseid, bool $write, ?http_client $client): grade_pull_result {
        global $CFG;

        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $notallowed = grade_pull::check_allowed($courseid);

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

        return self::work($blockinstanceid, $courseid, $record, $token, $write, $client);
    }

    /**
     * The pull itself, once the person is allowed and the connection is
     * known to be usable - also how grade_pull brings attempts in first,
     * under the lock it already holds.
     *
     * Besides its entries, the result's attemptquizzes says which quizzes
     * here the source's attempts can be brought into, and whose; for those,
     * the quiz's own grade is the one that counts (see grade_pull).
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param \stdClass $record the block's connection
     * @param string $token
     * @param bool $write false for a preview
     * @param http_client|null $client
     * @return grade_pull_result
     */
    public static function work(
        int $blockinstanceid,
        int $courseid,
        \stdClass $record,
        string $token,
        bool $write,
        ?http_client $client
    ): grade_pull_result {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $result = new grade_pull_result();
        $copies = array_filter(grade_pull::local_copies($courseid), static fn(\cm_info $cm) => $cm->modname === 'quiz');

        if (!$copies) {
            return $result;
        }

        $found = remote_client::get_quiz_attempts(
            $record->remoteurl,
            $token,
            (int) $record->remotecourseid,
            array_keys($copies),
            $client
        );

        if (!$found->success) {
            return grade_pull_result::failure($found->errorkey);
        }

        $students = grade_pull::students($courseid);

        $usernames = [];

        foreach ($found->quizzes as $quiz) {
            foreach ($quiz->attempts as $attempt) {
                $usernames[] = $attempt->username;
            }
        }

        $knownhere = grade_pull::usernames_known_here($usernames, $students);
        $sitename = (string) ($record->remotesitename ?: $record->remoteurl);

        foreach ($found->quizzes as $remotequiz) {
            if (isset($copies[$remotequiz->cmid])) {
                self::pull_quiz(
                    $result,
                    $write,
                    $blockinstanceid,
                    $courseid,
                    $copies[$remotequiz->cmid],
                    $remotequiz,
                    $students,
                    $knownhere,
                    $sitename
                );
            }
        }

        return $result;
    }

    /**
     * Every attempt at one of the source's quizzes.
     *
     * @param grade_pull_result $result
     * @param bool $write
     * @param int $blockinstanceid
     * @param int $courseid
     * @param \cm_info $cm the copy here
     * @param \stdClass $remotequiz from quiz_attempts_result
     * @param \stdClass[] $students gradable users here, by username
     * @param bool[] $knownhere username => true for accounts that exist here
     * @param string $sitename the source, as named in the comment on each mark
     * @return void
     */
    protected static function pull_quiz(
        grade_pull_result $result,
        bool $write,
        int $blockinstanceid,
        int $courseid,
        \cm_info $cm,
        \stdClass $remotequiz,
        array $students,
        array $knownhere,
        string $sitename
    ): void {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $problem = self::quiz_problem($quiz, $context, $remotequiz);

        if ($problem !== null) {
            // Only worth saying when there was something to bring.
            if ($remotequiz->attempts) {
                $result->add(self::entry($cm, null, grade_pull_result::SKIPPED, $problem));
            }

            return;
        }

        // This quiz can take the source's attempts: for everyone who made
        // any there, its own grade is the one that counts from now on.
        $result->attemptquizzes[(int) $cm->id] = array_fill_keys(
            array_map(static fn(\stdClass $attempt) => $attempt->username, $remotequiz->attempts),
            true
        );

        if (!$remotequiz->attempts) {
            return;
        }

        $localslots = qbank_helper::get_question_structure($quiz->id, $context);
        $imported = [];

        foreach ($DB->get_records('block_coursesync_attempt', ['quizid' => $quiz->id]) as $row) {
            $imported[(int) $row->remoteattemptid] = $row;
        }

        // How many attempts each student has here so far, counting what
        // this pull adds, for numbering and for the attempt limit.
        $counts = [];

        foreach ($remotequiz->attempts as $remote) {
            $entry = self::entry($cm, $remote, grade_pull_result::SKIPPED, null);
            $user = $students[$remote->username] ?? null;

            if (!$user) {
                $entry->reason = isset($knownhere[$remote->username]) ? 'gradeskipnotenrolled' : 'gradeskipnouser';
                $result->add($entry);
                continue;
            }

            $entry->userid = (int) $user->id;
            $marks = self::local_marks($remote, $localslots);

            if (isset($imported[$remote->id])) {
                self::pull_again($result, $entry, $write, $imported[$remote->id], $marks, $quiz, $cm, $sitename);
                continue;
            }

            // Not graded yet over there: nothing to bring. It comes on a
            // later pull, once somebody has marked it.
            if ($remote->sumgrades === null || self::needs_grading($remote)) {
                $entry->reason = 'attemptskipnotgraded';
                $result->add($entry);
                continue;
            }

            $counts[$user->id] ??= (int) $DB->count_records('quiz_attempts', [
                'quiz' => $quiz->id,
                'userid' => $user->id,
                'preview' => 0,
            ]);
            $counts[$user->id]++;
            $entry->outcome = grade_pull_result::ADD;

            if ($quiz->attempts > 0 && $counts[$user->id] > $quiz->attempts) {
                $entry->notes[] = 'attemptoverlimit';
            }

            if ($write) {
                $entry->attemptid = self::create_attempt(
                    $blockinstanceid,
                    $courseid,
                    $quiz,
                    $cm,
                    $user,
                    $remote,
                    $marks,
                    $sitename
                );
                $entry->localgrade = self::sumgrades($entry->attemptid);
            }

            $result->add($entry);
        }
    }

    /**
     * Why a quiz here cannot take the source's attempts, if it cannot.
     *
     * An attempt's marks only mean something here if every slot is the same
     * kind of question, worth the same, as on the source. A teacher who has
     * changed the copy - added, removed or reordered questions, or changed
     * their marks - has made that untrue.
     *
     * @param \stdClass $quiz
     * @param \context_module $context
     * @param \stdClass $remotequiz
     * @return string|null language string identifier
     */
    protected static function quiz_problem(\stdClass $quiz, \context_module $context, \stdClass $remotequiz): ?string {
        if (!has_capability('mod/quiz:grade', $context)) {
            return 'attemptskipnoquizgrade';
        }

        $local = qbank_helper::get_question_structure($quiz->id, $context);

        if (count($local) !== count($remotequiz->slots)) {
            return 'attemptskipchanged';
        }

        foreach ($remotequiz->slots as $remoteslot) {
            $slot = $local[$remoteslot->slot] ?? null;

            if (
                !$slot
                || grade_floats_different((float) $slot->maxmark, $remoteslot->maxmark)
                || $slot->qtype === 'missingtype'
                || $slot->qtype !== $remoteslot->qtype
            ) {
                return 'attemptskipchanged';
            }
        }

        if ((float) $quiz->sumgrades <= 0) {
            return 'attemptskipnomarks';
        }

        return null;
    }

    /**
     * Is any question of a source attempt still waiting to be graded?
     *
     * @param \stdClass $remote
     * @return bool
     */
    protected static function needs_grading(\stdClass $remote): bool {
        foreach ($remote->marks as $mark) {
            if ($mark->state === 'needsgrading') {
                return true;
            }
        }

        return false;
    }

    /**
     * A source attempt's marks, slot by slot, in this quiz's terms.
     *
     * The source's mark is out of the maximum the question had in that
     * attempt; quiz_problem() has checked the slot is worth the same now, but
     * an attempt made before a mark was changed there was out of the old one.
     *
     * @param \stdClass $remote
     * @param \stdClass[] $localslots by slot number
     * @return array slot => mark (null for a question never answered)
     */
    protected static function local_marks(\stdClass $remote, array $localslots): array {
        $marks = [];

        foreach ($localslots as $number => $slot) {
            $source = $remote->marks[$number] ?? null;
            $max = (float) $slot->maxmark;

            if (!$source || $source->mark === null || $max <= 0) {
                $marks[$number] = null;
                continue;
            }

            $mark = $source->maxmark > 0 ? $source->mark * $max / $source->maxmark : 0.0;
            $marks[$number] = round(min($max, max(0.0, $mark)), 7);
        }

        return $marks;
    }

    /**
     * Build one attempt here from a source attempt.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param \stdClass $quiz
     * @param \cm_info $cm
     * @param \stdClass $user
     * @param \stdClass $remote
     * @param array $marks slot => mark, from local_marks()
     * @param string $sitename
     * @return int the new attempt's id
     */
    protected static function create_attempt(
        int $blockinstanceid,
        int $courseid,
        \stdClass $quiz,
        \cm_info $cm,
        \stdClass $user,
        \stdClass $remote,
        array $marks,
        string $sitename
    ): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $timestart = $remote->timestart ?: time();
        $timefinish = max($timestart, $remote->timefinish ?: $timestart);
        $number = 1 + (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(attempt), 0) FROM {quiz_attempts} WHERE quiz = ? AND userid = ?',
            [$quiz->id, $user->id]
        );

        $quizobj = quiz_settings::create($quiz->id, $user->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($quizobj, $number, null, $timestart, false, $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, $number, $timestart);
        $attempt = quiz_attempt_save_started($quizobj, $quba, $attempt, $timestart);

        $quba->finish_all_questions($timefinish);
        self::apply_marks($quba, $marks, get_string('attemptcomment', 'block_coursesync', s($sitename)));
        \question_engine::save_questions_usage_by_activity($quba);

        // Finished, in one step, without the events and emails a submission
        // brings - see the class comment.
        $attempt->state = quiz_attempt::FINISHED;
        $attempt->timefinish = $timefinish;
        $attempt->timemodified = $timefinish;
        $attempt->timecheckstate = null;
        $attempt->sumgrades = $quba->get_total_mark();
        $attempt->gradednotificationsenttime = $timefinish;
        $DB->update_record('quiz_attempts', $attempt);

        $DB->insert_record('block_coursesync_attempt', (object) [
            'blockinstanceid' => $blockinstanceid,
            'courseid' => $courseid,
            'userid' => (int) $user->id,
            'quizid' => (int) $quiz->id,
            'attemptid' => (int) $attempt->id,
            'remotecmid' => self::remote_cmid($cm),
            'remoteattemptid' => $remote->id,
            'marks' => json_encode($marks),
            'timeimported' => time(),
        ]);

        self::after_marks_changed($quizobj, $cm, $user->id);
        $transaction->allow_commit();

        return (int) $attempt->id;
    }

    /**
     * An attempt brought across before: is it unchanged, to be updated, or
     * changed by someone here?
     *
     * @param grade_pull_result $result
     * @param \stdClass $entry
     * @param bool $write
     * @param \stdClass $row its block_coursesync_attempt row
     * @param array $marks the source's marks now, from local_marks()
     * @param \stdClass $quiz
     * @param \cm_info $cm
     * @param string $sitename
     * @return void
     */
    protected static function pull_again(
        grade_pull_result $result,
        \stdClass $entry,
        bool $write,
        \stdClass $row,
        array $marks,
        \stdClass $quiz,
        \cm_info $cm,
        string $sitename
    ): void {
        global $DB;

        $entry->attemptid = (int) $row->attemptid;
        $attempt = $DB->get_record('quiz_attempts', ['id' => $row->attemptid]);

        // Deleted here since: that was somebody's decision, not a gap.
        if (!$attempt) {
            $entry->reason = 'attemptskipdeletedhere';
            $result->add($entry);

            return;
        }

        $entry->localgrade = $attempt->sumgrades === null ? null : (float) $attempt->sumgrades;
        $written = json_decode((string) $row->marks, true) ?: [];
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);

        if (self::same_marks($written, $marks)) {
            $entry->outcome = grade_pull_result::SAME;
        } else if (!self::same_marks($written, self::current_marks($quba, array_keys($marks)))) {
            $entry->outcome = grade_pull_result::CONFLICT;
            $entry->reason = 'attemptconflict';
        } else {
            $entry->outcome = grade_pull_result::UPDATE;

            if ($write) {
                $transaction = $DB->start_delegated_transaction();
                $changed = array_filter(
                    $marks,
                    static fn($mark, $slot) => !self::same_marks([$slot => $written[$slot] ?? null], [$slot => $mark]),
                    ARRAY_FILTER_USE_BOTH
                );
                self::apply_marks($quba, $changed, get_string('attemptcommentupdated', 'block_coursesync', s($sitename)));
                \question_engine::save_questions_usage_by_activity($quba);

                $DB->update_record('quiz_attempts', (object) [
                    'id' => $attempt->id,
                    'sumgrades' => $quba->get_total_mark(),
                    'timemodified' => time(),
                ]);
                $DB->update_record('block_coursesync_attempt', (object) [
                    'id' => $row->id,
                    'marks' => json_encode($marks),
                    'timeimported' => time(),
                ]);

                self::after_marks_changed(quiz_settings::create($quiz->id, $attempt->userid), $cm, (int) $attempt->userid);
                $transaction->allow_commit();
                $entry->localgrade = self::sumgrades((int) $attempt->id);
            }
        }

        $result->add($entry);
    }

    /**
     * Give questions their marks, as a teacher grading by hand would.
     *
     * A question the source never saw answered keeps no mark. A mark cannot
     * be taken away again once given, so an update to "no mark" gives zero.
     *
     * @param \question_usage_by_activity $quba
     * @param array $marks slot => mark or null
     * @param string $comment
     * @return void
     */
    protected static function apply_marks(\question_usage_by_activity $quba, array $marks, string $comment): void {
        foreach ($marks as $slot => $mark) {
            if ($mark === null && $quba->get_question_mark($slot) === null) {
                continue;
            }

            if ($quba->get_question_max_mark($slot) <= 0) {
                continue;
            }

            $quba->manual_grade($slot, $comment, $mark ?? 0.0, FORMAT_HTML);
        }
    }

    /**
     * The marks the questions of an attempt have here now.
     *
     * @param \question_usage_by_activity $quba
     * @param int[] $slots
     * @return array slot => mark or null
     */
    protected static function current_marks(\question_usage_by_activity $quba, array $slots): array {
        $marks = [];

        foreach ($slots as $slot) {
            $mark = $quba->get_question_mark($slot);
            $marks[$slot] = $mark === null ? null : (float) $mark;
        }

        return $marks;
    }

    /**
     * Are two sets of marks the same, slot by slot?
     *
     * @param array $a slot => mark or null
     * @param array $b slot => mark or null
     * @return bool
     */
    protected static function same_marks(array $a, array $b): bool {
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $slot) {
            $x = $a[$slot] ?? null;
            $y = $b[$slot] ?? null;

            if (($x === null) !== ($y === null) || ($x !== null && grade_floats_different((float) $x, (float) $y))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bring the student's quiz grade and completion up to date after their
     * attempts' marks changed, as finishing an attempt does.
     *
     * @param quiz_settings $quizobj
     * @param \cm_info $cm
     * @param int $userid
     * @return void
     */
    protected static function after_marks_changed(quiz_settings $quizobj, \cm_info $cm, int $userid): void {
        $quizobj->get_grade_calculator()->recompute_final_grade($userid);

        $completion = new \completion_info($cm->get_course());

        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }

    /**
     * An attempt's total mark, fresh from the database.
     *
     * @param int $attemptid
     * @return float|null
     */
    protected static function sumgrades(int $attemptid): ?float {
        global $DB;

        $sumgrades = $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attemptid]);

        return $sumgrades === null || $sumgrades === false ? null : (float) $sumgrades;
    }

    /**
     * The source's id for a copy, from its coursesync-<remote cmid> idnumber.
     *
     * @param \cm_info $cm
     * @return int
     */
    protected static function remote_cmid(\cm_info $cm): int {
        return (int) substr((string) $cm->idnumber, strlen('coursesync-'));
    }

    /**
     * A fresh entry for the result.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $remote the source attempt, or null for the whole quiz
     * @param string $outcome
     * @param string|null $reason
     * @return \stdClass
     */
    protected static function entry(\cm_info $cm, ?\stdClass $remote, string $outcome, ?string $reason): \stdClass {
        return (object) [
            'kind' => grade_pull_result::KIND_ATTEMPT,
            'cmid' => (int) $cm->id,
            'activity' => $cm->name,
            'itemnumber' => 0,
            'gradeitemid' => 0,
            'userid' => 0,
            'username' => $remote ? $remote->username : '',
            'attempt' => $remote ? $remote->attempt : 0,
            'attemptid' => 0,
            'outcome' => $outcome,
            'reason' => $reason,
            'notes' => [],
            // The attempt's total marks: on the source, and here.
            'grade' => $remote ? $remote->sumgrades : null,
            'localgrade' => null,
        ];
    }
}
