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

namespace block_coursesync\external;

use advanced_testcase;
use block_coursesync\local\attempts;
use block_coursesync\local\mark_fixtures;
use block_coursesync\local\marks;
use block_coursesync\local\submission_fixtures;
use block_coursesync\local\submissions;
use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local/submission_fixtures.php');
require_once(__DIR__ . '/../local/mark_fixtures.php');

/**
 * Tests for asking a source for every attempt, not only the latest.
 *
 * The describing functions and the file functions have to agree on which
 * attempts exist, so a file is served only for an attempt that was described,
 * and nothing about earlier attempts leaves a site that has not agreed to it.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_submissions::class)]
#[CoversClass(get_submission_file::class)]
#[CoversClass(get_marks::class)]
#[CoversClass(get_mark_file::class)]
#[CoversClass(attempts::class)]
#[CoversClass(submissions::class)]
#[CoversClass(marks::class)]
final class every_attempt_test extends advanced_testcase {
    use submission_fixtures;
    use mark_fixtures;

    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass A student, username "sam". */
    protected \stdClass $sam;

    /**
     * Every kind of sharing is on, including earlier attempts, unless a test says otherwise.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        foreach (['allowsubmissionexport', 'allowmarksexport', 'allowattemptsexport'] as $switch) {
            set_config($switch, 1, 'block_coursesync');
        }

        $this->course = $this->getDataGenerator()->create_course();
        $this->sam = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'sam']);
    }

    /**
     * An assignment sam handed in to twice, with a mark and a comment file on each go.
     *
     * @return \stdClass
     */
    protected function two_goes(): \stdClass {
        $assign = $this->markable($this->course, ['attemptreopenmethod' => 'manual', 'maxattempts' => -1]);

        $this->submit($this->sam, $assign, 'first go', ['a.txt' => 'one']);
        $this->give_mark($assign, $this->sam, 40, 'Try again', ['first.txt' => 'FIRST']);
        $this->reopen($assign, $this->sam);
        $this->submit($this->sam, $assign, 'second go', ['b.txt' => 'two']);
        $this->give_mark($assign, $this->sam, 70, 'Better', ['second.txt' => 'SECOND']);

        return $assign;
    }

    /**
     * Ask for submissions the way the web service layer would.
     *
     * @param \stdClass $assign
     * @param bool|null $all null to leave the parameter out, as an older caller does
     * @return array the student's entry
     */
    protected function submissions_of(\stdClass $assign, ?bool $all = true): array {
        $raw = $all === null
            ? get_submissions::execute($this->course->id, [$assign->cmid])
            : get_submissions::execute($this->course->id, [$assign->cmid], '', $all);
        $result = external_api::clean_returnvalue(get_submissions::execute_returns(), $raw);

        return $result['items'][0]['submissions'][0] ?? [];
    }

    /**
     * Ask for marks the way the web service layer would.
     *
     * @param \stdClass $assign
     * @param bool|null $all null to leave the parameter out
     * @return array the whole item
     */
    protected function marks_of(\stdClass $assign, ?bool $all = true): array {
        $raw = $all === null
            ? get_marks::execute($this->course->id, [$assign->cmid])
            : get_marks::execute($this->course->id, [$assign->cmid], '', $all);

        return external_api::clean_returnvalue(get_marks::execute_returns(), $raw)['items'][0];
    }

    /**
     * Assert a call is refused with a Course Sync error.
     *
     * @param string $code
     * @param callable $call
     * @return void
     */
    protected function assert_refused(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Not refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    /**
     * Every attempt handed in is described, oldest first, and the top of the
     * entry is still the latest one.
     */
    public function test_every_submitted_attempt_is_described_oldest_first(): void {
        global $DB;

        $assign = $this->two_goes();
        $this->assertSame(2, $DB->count_records('assign_submission', ['assignment' => $assign->id, 'userid' => $this->sam->id]));

        $entry = $this->submissions_of($assign);

        $this->assertSame([0, 1], array_column($entry['attempts'], 'attemptnumber'));
        $this->assertSame(['first go', 'second go'], array_column($entry['attempts'], 'onlinetext'));
        $this->assertSame(['a.txt'], array_column($entry['attempts'][0]['files'], 'filename'));
        $this->assertSame(['b.txt'], array_column($entry['attempts'][1]['files'], 'filename'));
        $this->assertSame(1, $entry['attemptnumber']);
        $this->assertSame('second go', $entry['onlinetext']);
        $this->assertSame($entry['attempts'][1]['fingerprint'], $entry['fingerprint']);
        $this->assertSame(attempts::history_fingerprint($entry['attempts']), $entry['historyfingerprint']);
    }

    /**
     * Without allattempts - or from a caller that does not know about it - the
     * answer has no trace of earlier attempts.
     */
    public function test_by_default_there_is_no_history_in_the_answer(): void {
        $assign = $this->two_goes();

        foreach ([null, false] as $all) {
            $entry = $this->submissions_of($assign, $all);

            $this->assertSame(1, $entry['attemptnumber']);
            $this->assertArrayNotHasKey('attempts', $entry);
            $this->assertArrayNotHasKey('historyfingerprint', $entry);

            $item = $this->marks_of($assign, $all);
            $this->assertArrayNotHasKey('attempts', $item['marks'][0]);
            $this->assertArrayNotHasKey('historyfingerprint', $item['marks'][0]);
            $this->assertArrayNotHasKey('attemptsreason', $item);
        }
    }

    /**
     * A draft, an attempt reopened and not handed in, and an attempt handed in
     * with nothing in it are not described - and do not hide the others.
     */
    public function test_only_attempts_with_work_handed_in(): void {
        global $DB;

        $assign = $this->two_goes();
        $third = $this->reopen($assign, $this->sam);
        $this->assertSame(2, $third);
        $this->assertSame(3, $DB->count_records('assign_submission', ['assignment' => $assign->id, 'userid' => $this->sam->id]));

        $this->assertSame([0, 1], array_column($this->submissions_of($assign)['attempts'], 'attemptnumber'));

        // The same attempt as a draft.
        $DB->set_field('assign_submission', 'status', 'draft', ['assignment' => $assign->id, 'attemptnumber' => 2]);
        $this->assertSame([0, 1], array_column($this->submissions_of($assign)['attempts'], 'attemptnumber'));

        // Handed in, but empty.
        $DB->set_field('assign_submission', 'status', 'submitted', ['assignment' => $assign->id, 'attemptnumber' => 2]);
        $this->assertSame([0, 1], array_column($this->submissions_of($assign)['attempts'], 'attemptnumber'));
    }

    /**
     * The history fingerprint moves when an earlier attempt changes, though the
     * latest one - and so the top-level fingerprint - does not.
     */
    public function test_the_history_fingerprint_sees_an_earlier_attempt_change(): void {
        global $DB;

        $assign = $this->two_goes();
        $before = $this->submissions_of($assign);

        $first = $DB->get_field('assign_submission', 'id', ['assignment' => $assign->id, 'attemptnumber' => 0]);
        $DB->set_field('assignsubmission_onlinetext', 'onlinetext', 'first go, edited', ['submission' => $first]);
        $after = $this->submissions_of($assign);

        $this->assertSame($before['fingerprint'], $after['fingerprint']);
        $this->assertNotSame($before['historyfingerprint'], $after['historyfingerprint']);
        $this->assertNotSame($before['attempts'][0]['fingerprint'], $after['attempts'][0]['fingerprint']);
        $this->assertSame($before['attempts'][1]['fingerprint'], $after['attempts'][1]['fingerprint']);
    }

    /**
     * Every attempt's mark and comment is described with its attempt.
     */
    public function test_every_attempts_mark_is_described(): void {
        global $DB;

        $assign = $this->two_goes();
        $this->assertSame(2, $DB->count_records('assign_grades', ['assignment' => $assign->id, 'userid' => $this->sam->id]));

        $mark = $this->marks_of($assign)['marks'][0];

        $this->assertSame([0, 1], array_column($mark['attempts'], 'attemptnumber'));
        $this->assertEquals([40, 70], array_column($mark['attempts'], 'grade'));
        $this->assertSame(['Try again', 'Better'], array_column($mark['attempts'], 'comment'));
        $this->assertSame(['first.txt'], array_column($mark['attempts'][0]['files'], 'filename'));
        $this->assertSame(['second.txt'], array_column($mark['attempts'][1]['files'], 'filename'));
        $this->assertSame(1, $mark['attemptnumber']);
        $this->assertEquals(70, $mark['grade']);
        $this->assertSame(attempts::history_fingerprint($mark['attempts']), $mark['historyfingerprint']);
    }

    /**
     * An attempt nobody has marked yet has no row to describe, so the earlier
     * mark is the latest one carried.
     */
    public function test_an_attempt_not_yet_marked_is_not_described(): void {
        $assign = $this->markable($this->course, ['attemptreopenmethod' => 'manual', 'maxattempts' => -1]);
        $this->submit($this->sam, $assign, 'first go');
        $this->give_mark($assign, $this->sam, 40, 'Try again');
        $this->reopen($assign, $this->sam);
        $this->submit($this->sam, $assign, 'second go');

        $mark = $this->marks_of($assign)['marks'][0];

        $this->assertSame([0], array_column($mark['attempts'], 'attemptnumber'));
        $this->assertSame(0, $mark['attemptnumber']);
        $this->assertEquals(40, $mark['grade']);
    }

    /**
     * A draft attempt's mark is not carried, whatever its row says.
     */
    public function test_a_drafts_mark_is_not_described(): void {
        global $DB;

        $assign = $this->two_goes();
        $DB->set_field('assign_submission', 'status', 'draft', ['assignment' => $assign->id, 'attemptnumber' => 1]);

        $mark = $this->marks_of($assign)['marks'][0];

        $this->assertSame([0], array_column($mark['attempts'], 'attemptnumber'));
    }

    /**
     * A student who handed nothing in still has their latest mark, as before.
     */
    public function test_a_mark_with_nothing_handed_in(): void {
        $assign = $this->markable($this->course);
        $this->give_mark($assign, $this->sam, 55, 'No work, but marked');

        $mark = $this->marks_of($assign)['marks'][0];

        $this->assertCount(1, $mark['attempts']);
        $this->assertEquals(55, $mark['attempts'][0]['grade']);
        $this->assertEquals(55, $mark['grade']);
    }

    /**
     * Under a marking workflow only the latest mark is known to be released, so
     * only it is described - and the assignment says earlier ones were held back.
     */
    public function test_a_marking_workflow_holds_earlier_marks_back(): void {
        $assign = $this->markable($this->course, [
            'markingworkflow' => 1, 'attemptreopenmethod' => 'manual', 'maxattempts' => -1,
        ]);
        $this->submit($this->sam, $assign, 'first go');
        $this->give_mark($assign, $this->sam, 40, 'Try again');
        $this->reopen($assign, $this->sam);
        $this->submit($this->sam, $assign, 'second go');
        $this->give_mark($assign, $this->sam, 70, 'Better');

        $object = $this->assign_object($assign);
        $flags = $object->get_user_flags((int) $this->sam->id, true);
        $flags->workflowstate = marks::STATE_RELEASED;
        $object->update_user_flags($flags);

        $item = $this->marks_of($assign);

        $this->assertSame(attempts::REASON_WORKFLOW, $item['attemptsreason']);
        $this->assertSame([1], array_column($item['marks'][0]['attempts'], 'attemptnumber'));
        $this->assertArrayNotHasKey('attemptsreason', $this->marks_of($assign, false));

        // And an assignment without a workflow does not say it.
        $plain = $this->two_goes();
        $this->assertArrayNotHasKey('attemptsreason', $this->marks_of($plain));
    }

    /**
     * The latest of the marks for every attempt is the mark for the latest attempt.
     */
    public function test_the_last_of_every_mark_is_the_mark(): void {
        global $DB;

        $assign = $this->two_goes();
        $record = $DB->get_record('assign', ['id' => $assign->id]);
        $every = marks::marks_for($record, (int) $this->sam->id);

        $this->assertSame([0, 1], array_keys($every));
        $this->assertSame((int) end($every)->id, (int) marks::mark_for($record, (int) $this->sam->id)->id);

        // Nothing handed in: both give the latest grade row.
        $plain = $this->markable($this->course);
        $this->give_mark($plain, $this->sam, 12);
        $record = $DB->get_record('assign', ['id' => $plain->id]);

        $only = marks::marks_for($record, (int) $this->sam->id);

        $this->assertCount(1, $only);
        $this->assertSame((int) marks::mark_for($record, (int) $this->sam->id)->id, (int) reset($only)->id);
    }

    /**
     * Asking for every attempt needs the switch, on top of the ones for
     * submissions and marks - and asking the ordinary way still works without it.
     */
    public function test_every_attempt_needs_its_own_switch(): void {
        $assign = $this->two_goes();
        set_config('allowattemptsexport', 0, 'block_coursesync');

        $this->assert_refused('errorattemptsexportdisabled', fn() => $this->submissions_of($assign));
        $this->assert_refused('errorattemptsexportdisabled', fn() => $this->marks_of($assign));

        $this->assertSame(1, $this->submissions_of($assign, false)['attemptnumber']);
        $this->assertSame(1, $this->marks_of($assign, false)['marks'][0]['attemptnumber']);
    }

    /**
     * The attempts switch is not a way round the submissions or marks switch.
     */
    public function test_the_attempts_switch_is_not_enough(): void {
        $assign = $this->two_goes();

        set_config('allowsubmissionexport', 0, 'block_coursesync');
        $this->assert_refused('errorsubmissionexportdisabled', fn() => $this->submissions_of($assign));

        set_config('allowmarksexport', 0, 'block_coursesync');
        $this->assert_refused('errormarksexportdisabled', fn() => $this->marks_of($assign));
    }

    /**
     * A named attempt's file is served from that attempt, not the latest.
     */
    public function test_a_submission_file_from_a_named_attempt(): void {
        $assign = $this->two_goes();
        $read = static fn(string $name, int $attempt = -1) =>
            base64_decode(get_submission_file::execute(
                (int) $assign->cmid,
                'sam',
                submissions::AREA_FILES,
                '/',
                $name,
                0,
                100,
                $attempt
            )['content']);

        $this->assertSame('two', $read('b.txt'));
        $this->assertSame('two', $read('b.txt', 1));
        $this->assertSame('one', $read('a.txt', 0));

        // Without a number only the latest attempt's files are there.
        $this->assert_refused('errorfilenotfound', fn() => $read('a.txt'));
        // And a file belongs to its own attempt.
        $this->assert_refused('errorfilenotfound', fn() => $read('a.txt', 1));
        $this->assert_refused('errorfilenotfound', fn() => $read('b.txt', 0));
    }

    /**
     * A named attempt must have been handed in, and must exist.
     */
    public function test_a_submission_file_only_from_attempts_handed_in(): void {
        global $DB;

        $assign = $this->two_goes();
        $read = static fn(int $attempt) => get_submission_file::execute(
            (int) $assign->cmid,
            'sam',
            submissions::AREA_FILES,
            '/',
            'b.txt',
            0,
            100,
            $attempt
        );

        $this->assert_refused('errorfilenotallowed', fn() => $read(7));
        $this->assert_refused('invalidparameter', fn() => $read(-2));

        $DB->set_field('assign_submission', 'status', 'draft', ['assignment' => $assign->id, 'attemptnumber' => 1]);
        $this->assert_refused('errorfilenotallowed', fn() => $read(1));

        $DB->set_field('assign_submission', 'status', 'reopened', ['assignment' => $assign->id, 'attemptnumber' => 1]);
        $this->assert_refused('errorfilenotallowed', fn() => $read(1));
    }

    /**
     * Naming an attempt - even the latest - needs the switch; not naming one does not.
     */
    public function test_a_named_attempt_needs_the_switch(): void {
        $assign = $this->two_goes();
        set_config('allowattemptsexport', 0, 'block_coursesync');

        $read = static fn(int $attempt) => get_submission_file::execute(
            (int) $assign->cmid,
            'sam',
            submissions::AREA_FILES,
            '/',
            'b.txt',
            0,
            100,
            $attempt
        );

        $this->assert_refused('errorattemptsexportdisabled', fn() => $read(0));
        $this->assert_refused('errorattemptsexportdisabled', fn() => $read(1));
        $latest = get_submission_file::execute((int) $assign->cmid, 'sam', submissions::AREA_FILES, '/', 'b.txt');
        $this->assertSame('two', base64_decode($latest['content']));

        $mark = static fn(int $attempt) =>
            get_mark_file::execute((int) $assign->cmid, 'sam', '/', 'second.txt', 0, 100, $attempt);
        $this->assert_refused('errorattemptsexportdisabled', fn() => $mark(1));
        $this->assertSame(
            'SECOND',
            base64_decode(get_mark_file::execute((int) $assign->cmid, 'sam', '/', 'second.txt')['content'])
        );
    }

    /**
     * A comment file is served from the mark of the attempt named.
     */
    public function test_a_mark_file_from_a_named_attempt(): void {
        $assign = $this->two_goes();
        $read = static fn(string $name, int $attempt = -1) => base64_decode(
            get_mark_file::execute((int) $assign->cmid, 'sam', '/', $name, 0, 100, $attempt)['content']
        );

        $this->assertSame('SECOND', $read('second.txt'));
        $this->assertSame('FIRST', $read('first.txt', 0));
        $this->assertSame('SECOND', $read('second.txt', 1));

        // Not named: the latest mark's files only, as before.
        $this->assert_refused('errorfilenotfound', fn() => $read('first.txt'));
        // Named: a file in another attempt's comment is not this attempt's.
        $this->assert_refused('errorfilenotfound', fn() => $read('first.txt', 1));
        $this->assert_refused('errorfilenotfound', fn() => $read('second.txt', 0));
        // An attempt with no mark.
        $this->assert_refused('errorfilenotallowed', fn() => $read('first.txt', 5));
    }

    /**
     * A file left in a comment's area after the comment was emptied is not part
     * of the mark, so a named attempt does not serve it.
     */
    public function test_a_file_left_behind_by_an_emptied_comment_is_not_served(): void {
        global $DB;

        $assign = $this->two_goes();
        $first = $DB->get_field('assign_grades', 'id', ['assignment' => $assign->id, 'attemptnumber' => 0]);
        $DB->set_field('assignfeedback_comments', 'commenttext', '', ['grade' => $first]);

        $this->assertNotEmpty(get_file_storage()->get_area_files(
            \context_module::instance($assign->cmid)->id,
            marks::COMPONENT,
            marks::AREA,
            $first,
            'filename',
            false
        ));
        $this->assert_refused(
            'errorfilenotfound',
            fn() => get_mark_file::execute((int) $assign->cmid, 'sam', '/', 'first.txt', 0, 100, 0)
        );
    }

    /**
     * Under a marking workflow an earlier attempt's comment file cannot be read.
     */
    public function test_a_marking_workflow_serves_no_earlier_mark_file(): void {
        $assign = $this->markable($this->course, [
            'markingworkflow' => 1, 'attemptreopenmethod' => 'manual', 'maxattempts' => -1,
        ]);
        $this->submit($this->sam, $assign, 'first go');
        $this->give_mark($assign, $this->sam, 40, 'Try again', ['first.txt' => 'FIRST']);
        $this->reopen($assign, $this->sam);
        $this->submit($this->sam, $assign, 'second go');
        $this->give_mark($assign, $this->sam, 70, 'Better', ['second.txt' => 'SECOND']);

        $object = $this->assign_object($assign);
        $flags = $object->get_user_flags((int) $this->sam->id, true);
        $flags->workflowstate = marks::STATE_RELEASED;
        $object->update_user_flags($flags);

        $read = static fn(string $name, int $attempt) =>
            get_mark_file::execute((int) $assign->cmid, 'sam', '/', $name, 0, 100, $attempt);

        $this->assertSame('SECOND', base64_decode($read('second.txt', 1)['content']));
        $this->assert_refused('errorfilenotallowed', fn() => $read('first.txt', 0));
    }

    /**
     * A student the gradebook does not list gets nothing, named attempt or not.
     */
    public function test_a_named_attempt_is_still_only_for_gradebook_students(): void {
        $assign = $this->two_goes();
        $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher', ['username' => 'teach']);

        $this->assert_refused('errorfilenotallowed', fn() => get_submission_file::execute(
            (int) $assign->cmid,
            'teach',
            submissions::AREA_FILES,
            '/',
            'a.txt',
            0,
            100,
            0
        ));
        $this->assert_refused('errorfilenotallowed', fn() => get_mark_file::execute(
            (int) $assign->cmid,
            'teach',
            '/',
            'first.txt',
            0,
            100,
            0
        ));
    }
}
