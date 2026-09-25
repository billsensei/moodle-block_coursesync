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

use advanced_testcase;
use core\http_client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local/quiz_on_the_source.php');
require_once(__DIR__ . '/local/attempt_pull_clashing.php');

/**
 * Tests for bringing students' quiz attempts across, as marks.
 *
 * The "source" is a second course on this same site, answered by the real
 * get_quiz_attempts; its quiz is copied into the destination by the real quiz
 * handler, so slots, marks and the random slot are what a sync makes.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(attempt_pull::class)]
#[CoversClass(quiz_attempts_result::class)]
final class attempt_pull_test extends advanced_testcase {
    use local\quiz_on_the_source;

    /**
     * The shared fixture: see quiz_on_the_source.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->set_up_quiz_source();
    }

    /**
     * Bring the source's attempts across the way grade_pull does, through
     * attempt_pull::work(), for tests about the attempts alone.
     *
     * @param http_client $client
     * @param bool $write false for a preview
     * @return grade_pull_result
     */
    protected function pull_attempts(http_client $client, bool $write = true): grade_pull_result {
        $result = attempt_pull::work(
            $this->instanceid,
            $this->course->id,
            connection::get($this->instanceid),
            connection::get_token($this->instanceid),
            $write,
            $client
        );
        $result->preview = !$write;

        return $result;
    }

    /**
     * The only entry a pull produced.
     *
     * @param grade_pull_result $result
     * @return \stdClass
     */
    protected function only_entry(grade_pull_result $result): \stdClass {
        $this->assertTrue($result->success, (string) $result->errorkey);
        $this->assertCount(1, $result->entries);

        return $result->entries[0];
    }

    /**
     * A graded attempt becomes a finished attempt here with the same times,
     * the same mark on every question, the same total and a quiz grade - and
     * is remembered, so it is not brought twice.
     */
    public function test_an_attempt_is_brought_across(): void {
        global $DB;

        $source = $this->source_attempt($this->student, $this->answers(), 4);
        $this->assertEqualsWithDelta(9.4, $source->sumgrades, 0.00001);

        $entry = $this->only_entry($this->pull_attempts($this->local_source()));

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertSame([], $entry->notes);
        $attempts = $this->copy_attempts();
        $this->assertCount(1, $attempts);
        $attempt = $attempts[0];
        $this->assertSame((int) $attempt->id, $entry->attemptid);
        $this->assertSame(quiz_attempt::FINISHED, $attempt->state);
        $this->assertEquals(1, $attempt->attempt);
        $this->assertEquals($source->timestart, $attempt->timestart);
        $this->assertEquals($source->timefinish, $attempt->timefinish);
        $this->assertEqualsWithDelta(9.4, $attempt->sumgrades, 0.00001);
        $this->assertEquals($attempt->timefinish, $attempt->gradednotificationsenttime);

        $marks = $this->marks_here($attempt);
        $this->assertEquals(2, $marks[1]);
        $this->assertEqualsWithDelta(2.4, $marks[2], 0.00001);
        $this->assertEquals(4, $marks[3]);
        $this->assertEquals(1, $marks[4], 'the random slot keeps its mark, whichever question it drew here');

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $this->assertStringContainsString('Answered on Source Site', $quba->get_question_attempt(1)->get_manual_comment()[0]);

        // The quiz grade: 9.4 of 11 marks, out of 10.
        $this->assertEqualsWithDelta(
            9.4 / 11 * 10,
            $DB->get_field('quiz_grades', 'grade', ['quiz' => $attempt->quiz, 'userid' => $this->student->id]),
            0.00001
        );

        $this->assertTrue($DB->record_exists('block_coursesync_attempt', [
            'quizid' => $attempt->quiz,
            'attemptid' => $attempt->id,
            'remoteattemptid' => $source->id,
            'remotecmid' => $this->quiz->cmid,
        ]));
    }

    /**
     * A preview says what would happen and writes nothing.
     */
    public function test_preview_writes_nothing(): void {
        global $DB;

        $this->source_attempt($this->student, $this->answers(), 4);

        $result = $this->pull_attempts($this->local_source(), false);

        $this->assertTrue($result->preview);
        $this->assertSame(grade_pull_result::ADD, $this->only_entry($result)->outcome);
        $this->assertSame([], $this->copy_attempts());
        $this->assertSame(0, $DB->count_records('block_coursesync_attempt'));
    }

    /**
     * Pulling again brings nothing twice; a new attempt on the source comes
     * across as the student's next attempt here.
     */
    public function test_pulling_again(): void {
        $this->source_attempt($this->student, $this->answers(), 4);
        $this->pull_attempts($this->local_source());

        $entry = $this->only_entry($this->pull_attempts($this->local_source()));
        $this->assertSame(grade_pull_result::SAME, $entry->outcome);
        $this->assertCount(1, $this->copy_attempts());

        $this->source_attempt($this->student, [1 => ['answer' => 'frog']]);
        $result = $this->pull_attempts($this->local_source());

        $this->assertSame([grade_pull_result::SAME, grade_pull_result::ADD], array_column($result->entries, 'outcome'));
        $this->assertSame([1, 2], array_map(static fn($a) => (int) $a->attempt, $this->copy_attempts()));
    }

    /**
     * An attempt still waiting for its essay to be marked comes once it is.
     */
    public function test_an_attempt_waiting_to_be_graded_comes_later(): void {
        $source = $this->source_attempt($this->student, $this->answers());

        $entry = $this->only_entry($this->pull_attempts($this->local_source()));
        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('attemptskipnotgraded', $entry->reason);
        $this->assertSame([], $this->copy_attempts());

        $this->mark_source_essay((int) $source->id, 5);
        $entry = $this->only_entry($this->pull_attempts($this->local_source()));
        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertEquals(5, $this->marks_here($this->copy_attempts()[0])[3]);
    }

    /**
     * Regraded on the source, an attempt nobody here has touched is updated;
     * once someone here changes its marks, it is theirs.
     */
    public function test_regrading_on_the_source(): void {
        global $DB;

        $source = $this->source_attempt($this->student, $this->answers(), 4);
        $this->pull_attempts($this->local_source());

        $this->mark_source_essay((int) $source->id, 5);
        $entry = $this->only_entry($this->pull_attempts($this->local_source()));

        $this->assertSame(grade_pull_result::UPDATE, $entry->outcome);
        $attempt = $this->copy_attempts()[0];
        $this->assertEquals(5, $this->marks_here($attempt)[3]);
        $this->assertEqualsWithDelta(10.4, $attempt->sumgrades, 0.00001);
        $this->assertEqualsWithDelta(
            10.4 / 11 * 10,
            $DB->get_field('quiz_grades', 'grade', ['quiz' => $attempt->quiz, 'userid' => $this->student->id]),
            0.00001
        );

        // A teacher here regrades the essay; then the source changes it again.
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->manual_grade(3, 'Regraded here', 3, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($quba);
        $this->mark_source_essay((int) $source->id, 2);

        $entry = $this->only_entry($this->pull_attempts($this->local_source()));

        $this->assertSame(grade_pull_result::CONFLICT, $entry->outcome);
        $this->assertSame('attemptconflict', $entry->reason);
        $this->assertEquals(3, $this->marks_here($this->copy_attempts()[0])[3]);
    }

    /**
     * Attempts count towards the limit here: an imported attempt after one
     * made here is numbered after it, and flagged if it goes over.
     */
    public function test_attempts_made_here_and_the_limit(): void {
        global $DB;

        $DB->set_field('quiz', 'attempts', 1, ['id' => $this->copycm->instance]);

        // One attempt here first, straight into the copy.
        $local = quiz_settings::create($this->copycm->instance, $this->student->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $local->get_context());
        $quba->set_preferred_behaviour($local->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($local, 1, null, 1700000000, false, $this->student->id);
        quiz_start_new_attempt($local, $quba, $attempt, 1, 1700000000);
        quiz_attempt_save_started($local, $quba, $attempt, 1700000000);

        $this->source_attempt($this->student, $this->answers(), 4);
        $entry = $this->only_entry($this->pull_attempts($this->local_source()));

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertSame(['attemptoverlimit'], $entry->notes);
        $this->assertSame([1, 2], array_map(static fn($a) => (int) $a->attempt, $this->copy_attempts()));
    }

    /**
     * Times from the source are taken as given only when they make sense: an
     * attempt cannot have been started or finished in the future.
     */
    public function test_times_in_the_future_are_brought_back_to_now(): void {
        global $DB;

        $source = $this->source_attempt($this->student, $this->answers(), 4);
        $DB->update_record('quiz_attempts', (object) [
            'id' => $source->id,
            'timestart' => time() + 50 * DAYSECS,
            'timefinish' => time() + 60 * DAYSECS,
        ]);

        $before = time();
        $this->pull_attempts($this->local_source());
        $attempt = $this->copy_attempts()[0];

        $this->assertLessThanOrEqual(time(), (int) $attempt->timestart);
        $this->assertLessThanOrEqual(time(), (int) $attempt->timefinish);
        $this->assertGreaterThanOrEqual($before, (int) $attempt->timefinish);
        $this->assertLessThanOrEqual((int) $attempt->timefinish, (int) $attempt->timestart);
    }

    /**
     * Should a student start an attempt at this quiz at the very moment one of
     * theirs is brought across, both could claim the same attempt number. The
     * attempt being brought is then left for the next pull - whole: nothing of
     * it is half-written - and the rest of the pull goes on.
     */
    public function test_a_clash_over_the_attempt_number(): void {
        global $DB;

        // PHPUnit normally runs each test inside one transaction it rolls back
        // afterwards; inside that, the import's own transaction could not roll
        // back on its own. Real pages have no such outer transaction.
        $this->preventResetByRollback();

        // One attempt here already: attempt number 1.
        $local = quiz_settings::create($this->copycm->instance, $this->student->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $local->get_context());
        $quba->set_preferred_behaviour($local->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($local, 1, null, 1700000000, false, $this->student->id);
        quiz_start_new_attempt($local, $quba, $attempt, 1, 1700000000);
        quiz_attempt_save_started($local, $quba, $attempt, 1700000000);

        $this->source_attempt($this->student, $this->answers(), 4);
        $usages = $DB->count_records('question_usages');

        // As if the student's attempt number 1 had appeared after the pull
        // chose its number.
        $result = local\attempt_pull_clashing::work(
            $this->instanceid,
            $this->course->id,
            connection::get($this->instanceid),
            connection::get_token($this->instanceid),
            true,
            $this->local_source()
        );

        $entry = $this->only_entry($result);
        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('attemptskipbusy', $entry->reason);
        $this->assertCount(1, $this->copy_attempts(), 'only the attempt made here');
        $this->assertSame($usages, $DB->count_records('question_usages'), 'nothing half-written');
        $this->assertSame(0, $DB->count_records('block_coursesync_attempt'));

        // The next pull brings it.
        $entry = $this->only_entry($this->pull_attempts($this->local_source()));
        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertCount(2, $this->copy_attempts());
    }

    /**
     * A copy someone has changed - here, a question's mark - no longer lines
     * up with the source's attempts, and they are refused as a whole.
     */
    public function test_a_changed_copy_is_refused(): void {
        global $DB;

        $this->source_attempt($this->student, $this->answers(), 4);
        $DB->set_field('quiz_slots', 'maxmark', 4, ['quizid' => $this->copycm->instance, 'slot' => 1]);

        $entry = $this->only_entry($this->pull_attempts($this->local_source()));

        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('attemptskipchanged', $entry->reason);
        $this->assertSame('', $entry->username);
        $this->assertSame([], $this->copy_attempts());
    }

    /**
     * An imported attempt deleted here is not brought back.
     */
    public function test_an_attempt_deleted_here_stays_deleted(): void {
        $this->source_attempt($this->student, $this->answers(), 4);
        $this->pull_attempts($this->local_source());

        quiz_delete_attempt($this->copy_attempts()[0], $this->copy_quiz());

        $entry = $this->only_entry($this->pull_attempts($this->local_source()));

        $this->assertSame('attemptskipdeletedhere', $entry->reason);
        $this->assertSame([], $this->copy_attempts());
    }

    /**
     * Nobody is emailed about an attempt made elsewhere - not on import, and
     * not later by the task that tells students a marked attempt is graded.
     */
    public function test_nobody_is_emailed(): void {
        global $DB;

        // Let the student see manual comments (they follow specific feedback)
        // at every stage, which is when the quiz's task would tell them a
        // marked attempt is graded - so this test would catch a message if
        // one were due.
        $DB->set_field('quiz', 'reviewspecificfeedback', 0x11110, ['id' => $this->copycm->instance]);
        // No role may receive those messages by default; students may here.
        assign_capability(
            'mod/quiz:emailnotifyattemptgraded',
            CAP_ALLOW,
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            \context_system::instance()->id,
            true
        );
        $this->source_attempt($this->student, $this->answers(), 4);

        // The task looks at every attempt on the site; the source course's
        // own attempt would rightly get its message, so it is marked as done
        // - what is being tested is only the attempts the pull creates.
        $DB->set_field('quiz_attempts', 'gradednotificationsenttime', 1, ['quiz' => $this->quiz->id]);

        $sink = $this->redirectMessages();
        $emails = $this->redirectEmails();
        $this->pull_attempts($this->local_source());
        $this->expectOutputRegex('/graded notification/');
        (new \mod_quiz\task\quiz_notify_attempt_manual_grading_completed())->execute();

        $this->assertCount(1, $this->copy_attempts());
        $this->assertSame(0, $sink->count());
        $this->assertSame(0, $emails->count());
    }

    /**
     * A student not in this course, or with no account here, is skipped.
     */
    public function test_unmatched_students(): void {
        $outsider = $this->getDataGenerator()->create_and_enrol($this->source, 'student', ['username' => 'elsewhere']);
        $this->source_attempt($outsider, $this->answers(), 4);

        $entry = $this->only_entry($this->pull_attempts($this->local_source(), false));
        $this->assertSame('gradeskipnotenrolled', $entry->reason);

        // A username with no account at all here needs a source other than
        // this site, where every account exists: the real answer, renamed.
        $body = \block_coursesync\external\get_quiz_attempts::execute((int) $this->source->id, [(int) $this->quiz->cmid]);
        $body['quizzes'][0]['attempts'][0]['username'] = 'nobody';
        $result = $this->pull_attempts($this->source_saying($body), false);
        $this->assertSame('gradeskipnouser', $this->only_entry($result)->reason);
    }

    /**
     * Grade pulling switched off, a source too old for attempts, and an
     * answer of the wrong shape are all refused before anything happens.
     */
    public function test_refusals(): void {
        set_config('allowgradepull', 0, 'block_coursesync');
        $this->assertSame(
            'errorgradepulloff',
            grade_pull::run($this->instanceid, $this->course->id, $this->local_source())->errorkey
        );
        set_config('allowgradepull', 1, 'block_coursesync');

        $old = $this->source_saying(['exception' => 'dml_missing_record_exception', 'errorcode' => 'invalidrecord']);
        $this->assertSame('errorattemptsourceoutdated', $this->pull_attempts($old)->errorkey);

        $bad = $this->source_saying(['formatversion' => 1, 'quizzes' => [['cmid' => 1]]]);
        $this->assertSame('errorbadresponse', $this->pull_attempts($bad)->errorkey);
    }

    /**
     * A source that answers every call with the same body.
     *
     * @param array $body
     * @return http_client
     */
    protected function source_saying(array $body): http_client {
        return new http_client(['mock' => fn() => Create::promiseFor(new Response(200, [], json_encode($body)))]);
    }
}
