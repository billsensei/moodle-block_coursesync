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
use block_coursesync\local\mark_fixtures;
use block_coursesync\local\mark_writer;
use block_coursesync\local\marks;
use block_coursesync\local\submission_fixtures;
use core\http_client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local/source_on_this_site.php');
require_once(__DIR__ . '/local/submission_fixtures.php');
require_once(__DIR__ . '/local/mark_fixtures.php');

/**
 * Tests for pulling the marks of every attempt of an assignment.
 *
 * The "other site" is this site, as in marks_pull_test. What matters most is
 * what each mark is put on: the same attempt, and only work that is that
 * attempt from the other site; and that an earlier attempt's mark, which is
 * history, never changes what belongs to the newest one - the gradebook, the
 * workflow state, a grade sync's override.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(marks_pull::class)]
#[CoversClass(marks_result::class)]
#[CoversClass(mark_writer::class)]
final class marks_attempts_pull_test extends advanced_testcase {
    use local\source_on_this_site;
    use submission_fixtures;
    use mark_fixtures;

    /** @var \stdClass The destination course. */
    protected \stdClass $course;

    /** @var int The Course Sync block in the destination. */
    protected int $instanceid;

    /** @var \stdClass A student in both courses, username "sam". */
    protected \stdClass $sam;

    /** @var \stdClass The assignment on the source. */
    protected \stdClass $there;

    /** @var \stdClass The copy of it here. */
    protected \stdClass $here;

    /** @var callable[] Changes to what the source says, by function name. */
    protected array $tamper = [];

    /** @var array[] Parameters of every call the source received. */
    protected array $calls = [];

    /**
     * A source course and a destination course with an assignment that allows
     * as many attempts as there are, a block mapped between them, a student in
     * both, and every switch on.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $source = $generator->create_course();
        $this->course = $generator->create_course();
        $this->sam = $generator->create_and_enrol($source, 'student', ['username' => 'sam']);
        $generator->enrol_user($this->sam->id, $this->course->id, 'student');

        $attempts = ['attemptreopenmethod' => 'manual', 'maxattempts' => -1];
        $this->there = $this->markable($source, ['name' => 'Essay'] + $attempts);
        $this->here = $this->markable(
            $this->course,
            ['name' => 'Essay'] + $attempts,
            ['idnumber' => 'coursesync-' . $this->there->cmid]
        );

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($this->course->id));
        $page->set_course($this->course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $this->course->format);
        $page->set_url('/course/view.php', ['id' => $this->course->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');

        $this->instanceid = (int) $DB->get_field('block_instances', 'id', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($this->course->id)->id,
        ], MUST_EXIST);

        connection::set_url($this->instanceid, $this->course->id, 'https://source.example.edu');
        connection::set_token($this->instanceid, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            $this->instanceid,
            'REMOTE1',
            course_result::success((int) $source->id, 'REMOTE1', 'Remote Source Course', true, 1)
        );

        $switches = [
            'allowmarkspull', 'allowmarksexport', 'allowsubmissionpull', 'allowsubmissionexport',
            'allowattemptspull', 'allowattemptsexport',
        ];

        foreach ($switches as $switch) {
            set_config($switch, 1, 'block_coursesync');
        }
    }

    /**
     * The source: this site's own functions, which a test can make lie.
     *
     * @return http_client
     */
    protected function source(): http_client {
        return new http_client(['mock' => function (RequestInterface $request) {
            parse_str((string) $request->getBody(), $params);
            $this->calls[] = $params;
            $function = (string) ($params['wsfunction'] ?? '');
            $puller = $GLOBALS['USER'];
            $this->setAdminUser();

            try {
                $body = $this->answer($function, $params);

                if (isset($this->tamper[$function])) {
                    $body = ($this->tamper[$function])($body, $params);
                }
            } catch (\moodle_exception $e) {
                $body = ['exception' => get_class($e), 'errorcode' => $e->errorcode, 'message' => $e->getMessage()];
            } finally {
                $this->setUser($puller);
            }

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
        }]);
    }

    /**
     * Three goes on the source, each marked, each with a comment file of its own.
     *
     * @param bool $marknewest false to leave the third go unmarked
     * @return void
     */
    protected function three_goes(bool $marknewest = true): void {
        $this->submit($this->sam, $this->there, 'first', ['a.txt' => 'one']);
        $this->give_mark($this->there, $this->sam, 40, 'Try again', ['first.txt' => 'FIRST']);
        $this->reopen($this->there, $this->sam);
        $this->submit($this->sam, $this->there, 'second', ['b.txt' => 'two']);
        $this->give_mark($this->there, $this->sam, 60, 'Better', ['second.txt' => 'SECOND']);
        $this->reopen($this->there, $this->sam);
        $this->submit($this->sam, $this->there, 'third', ['c.txt' => 'three']);

        if ($marknewest) {
            $this->give_mark($this->there, $this->sam, 80, 'Good', ['third.txt' => 'THIRD']);
        }
    }

    /**
     * Pull the attempts themselves, as the submissions pull does.
     *
     * @param bool $every every attempt, not only the latest
     * @return grade_pull_result
     */
    protected function pull_work(bool $every = true): grade_pull_result {
        return submission_pull::run($this->instanceid, $this->course->id, $this->source(), null, $every);
    }

    /**
     * Preview a marks pull of every attempt.
     *
     * @return grade_pull_result
     */
    protected function preview_all(): grade_pull_result {
        return marks_pull::preview($this->instanceid, $this->course->id, $this->source(), null, true);
    }

    /**
     * Pull the mark of every attempt for real.
     *
     * @return grade_pull_result
     */
    protected function pull_all(): grade_pull_result {
        return marks_pull::run($this->instanceid, $this->course->id, $this->source(), null, true);
    }

    /**
     * Pull the latest mark alone, as before.
     *
     * @return grade_pull_result
     */
    protected function pull_latest(): grade_pull_result {
        return marks_pull::run($this->instanceid, $this->course->id, $this->source());
    }

    /**
     * What a pull did to each attempt's mark: outcome (and reason) by attempt number.
     *
     * Notes about a whole assignment, and the line saying a grade sync's
     * override was taken away, are not about an attempt and are left out.
     *
     * @param grade_pull_result $result
     * @return string[]
     */
    protected function outcomes(grade_pull_result $result): array {
        $this->assertTrue($result->success, (string) $result->errorkey);
        $found = [];

        foreach ($result->entries as $entry) {
            if ($entry->userid && $entry->outcome !== grade_pull_result::RELEASED) {
                $found[$entry->attempt] = $entry->outcome . ($entry->reason ? ':' . $entry->reason : '');
            }
        }

        return $found;
    }

    /**
     * The student's grade rows here: attempt number => [mark, comment, comment files].
     *
     * @return array[]
     */
    protected function grades_here(): array {
        global $DB;

        $found = [];
        $by = ['assignment' => $this->here->id, 'userid' => $this->sam->id];
        $rows = $DB->get_records('assign_grades', $by, 'attemptnumber ASC');

        foreach ($rows as $row) {
            $text = $DB->get_field('assignfeedback_comments', 'commenttext', ['grade' => $row->id]);
            $files = [];

            foreach (
                get_file_storage()->get_area_files(
                    \context_module::instance($this->here->cmid)->id,
                    marks::COMPONENT,
                    marks::AREA,
                    $row->id,
                    'filename',
                    false
                ) as $file
            ) {
                $files[] = $file->get_filename() . '=' . $file->get_content();
            }

            $found[(int) $row->attemptnumber] = [
                $row->grade < 0 ? null : (float) $row->grade,
                $text === false ? null : $text,
                $files,
            ];
        }

        return $found;
    }

    /**
     * How the student's attempts stand here: the rows, and which are latest.
     *
     * A marks pull writes grades, not attempts, so this must not move.
     *
     * @return int[] [number of rows, number marked latest]
     */
    protected function submission_state(): array {
        global $DB;

        $by = ['assignment' => $this->here->id, 'userid' => $this->sam->id];

        return [$DB->count_records('assign_submission', $by), $DB->count_records('assign_submission', $by + ['latest' => 1])];
    }

    /**
     * What the gradebook shows for the student here.
     *
     * @return float|null
     */
    protected function gradebook_here(): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'assign', $this->here->id, [$this->sam->id]);
        $final = $grades->items[0]->grades[$this->sam->id]->grade ?? null;

        return $final === null ? null : (float) $final;
    }

    /**
     * The records of what was pulled, by attempt number.
     *
     * @return \stdClass[]
     */
    protected function ledger(): array {
        global $DB;

        $found = [];

        foreach ($DB->get_records('block_coursesync_mark', ['assignid' => $this->here->id], 'attemptnumber ASC') as $row) {
            $found[(int) $row->attemptnumber] = $row;
        }

        return $found;
    }

    /**
     * The grade item of the assignment here.
     *
     * @return \grade_item
     */
    protected function grade_item_here(): \grade_item {
        return \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $this->here->id,
            'itemnumber' => 0,
        ]);
    }

    /**
     * Only the grade sync side: write the override grade sync would, with its record.
     *
     * @param \grade_item $item
     * @param float $grade
     * @return void
     */
    protected function grade_sync_wrote(\grade_item $item, float $grade): void {
        global $DB;

        $item->update_final_grade($this->sam->id, $grade, grade_pull::SOURCE, 'sync feedback', FORMAT_HTML);
        $stored = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->sam->id]);
        $DB->insert_record('block_coursesync_grade', (object) [
            'blockinstanceid' => $this->instanceid,
            'courseid' => $this->course->id,
            'userid' => $this->sam->id,
            'gradeitemid' => $item->id,
            'remotecmid' => $this->there->cmid,
            'itemnumber' => 0,
            'finalgrade' => $stored->finalgrade,
            'feedbackhash' => sha1((string) $stored->feedback),
            'remotetime' => time(),
            'timepulled' => time(),
        ]);
    }

    /**
     * Every attempt's mark goes on the same attempt, with its comment and
     * files, a record each; only the newest changes the gradebook; and the
     * attempts themselves are not touched.
     */
    public function test_every_attempts_mark_goes_on_the_same_attempt(): void {
        global $DB;

        $this->three_goes();
        $this->pull_work();
        $state = $this->submission_state();
        $this->assertSame([3, 1], $state);

        // A preview writes nothing.
        $this->assertSame([0 => 'add', 1 => 'add', 2 => 'add'], $this->outcomes($this->preview_all()));
        $this->assertSame([], $this->grades_here());
        $this->assertSame([], $this->ledger());

        $result = $this->pull_all();
        $this->assertTrue($result->everyattempt);
        $this->assertSame([0 => 'add', 1 => 'add', 2 => 'add'], $this->outcomes($result));

        $this->assertSame([
            0 => [40.0, 'Try again', ['first.txt=FIRST']],
            1 => [60.0, 'Better', ['second.txt=SECOND']],
            2 => [80.0, 'Good', ['third.txt=THIRD']],
        ], $this->grades_here());
        $this->assertSame($state, $this->submission_state());
        $this->assertEquals(80, $this->gradebook_here());

        $ledger = $this->ledger();
        $this->assertSame([0, 1, 2], array_keys($ledger));

        foreach ($ledger as $number => $record) {
            $this->assertSame($number, (int) $record->remoteattempt);
            $this->assertSame((int) $DB->get_field('assign_grades', 'id', [
                'assignment' => $this->here->id, 'userid' => $this->sam->id, 'attemptnumber' => $number,
            ]), (int) $record->gradeid);
        }
    }

    /**
     * Each file is asked for by the attempt whose comment holds it.
     */
    public function test_comment_files_are_fetched_from_the_named_attempt(): void {
        $this->three_goes();
        $this->pull_work();
        $this->calls = [];
        $this->pull_all();

        $fetched = [];

        foreach ($this->calls as $call) {
            if ($call['wsfunction'] === 'block_coursesync_get_mark_file') {
                $fetched[$call['filename']] = (int) $call['attemptnumber'];
            }
        }

        $this->assertSame(['first.txt' => 0, 'second.txt' => 1, 'third.txt' => 2], $fetched);
    }

    /**
     * A second pull finds everything the same.
     */
    public function test_pulling_again_changes_nothing(): void {
        global $DB;

        $this->three_goes();
        $this->pull_work();
        $this->pull_all();
        $grades = $this->grades_here();
        $ledger = $DB->get_records('block_coursesync_mark');

        $this->assertSame([0 => 'same', 1 => 'same', 2 => 'same'], $this->outcomes($this->pull_all()));

        $this->assertSame($grades, $this->grades_here());
        $this->assertEquals($ledger, $DB->get_records('block_coursesync_mark'));
        $this->assertSame([3, 1], $this->submission_state());
    }

    /**
     * A change to an earlier mark on the source updates that mark here, and
     * the gradebook - which is about the newest attempt - does not move.
     */
    public function test_a_change_to_an_earlier_mark_leaves_the_gradebook_alone(): void {
        global $DB;

        $this->three_goes();
        $this->pull_work();
        $this->pull_all();
        $DB->set_field('assign_grades', 'grade', 45, [
            'assignment' => $this->there->id, 'userid' => $this->sam->id, 'attemptnumber' => 0,
        ]);

        $this->assertSame([0 => 'update', 1 => 'same', 2 => 'same'], $this->outcomes($this->pull_all()));

        $this->assertSame([45.0, 60.0, 80.0], array_column($this->grades_here(), 0));
        $this->assertEquals(80, $this->gradebook_here());
    }

    /**
     * A mark someone changed here is kept - and only that one.
     */
    public function test_a_mark_changed_here_is_kept(): void {
        global $DB;

        $this->three_goes();
        $this->pull_work();
        $this->pull_all();
        $DB->set_field('assign_grades', 'grade', 99, [
            'assignment' => $this->here->id, 'userid' => $this->sam->id, 'attemptnumber' => 1,
        ]);
        $DB->set_field('assign_grades', 'grade', 65, [
            'assignment' => $this->there->id, 'userid' => $this->sam->id, 'attemptnumber' => 1,
        ]);

        $this->assertSame(
            [0 => 'same', 1 => 'conflict:markreasonchanged', 2 => 'same'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame([40.0, 99.0, 80.0], array_column($this->grades_here(), 0));
    }

    /**
     * An earlier attempt's mark is history: written alone it neither takes
     * away the override that stands in for the newest attempt's grade nor
     * releases the student's workflow state. The newest attempt's mark does both.
     */
    public function test_an_earlier_mark_does_not_touch_what_belongs_to_the_newest(): void {
        global $DB;

        $DB->set_field('assign', 'markingworkflow', 1, ['id' => $this->here->id]);
        $this->three_goes(false);
        $this->pull_work();

        $item = $this->grade_item_here();
        $this->grade_sync_wrote($item, 33);
        $state = $this->submission_state();
        $assign = $this->assign_object($this->here);

        // Only the first two attempts are marked on the source. Nothing about
        // them takes the override away - and a preview does not say it would.
        $this->assertSame([], $this->preview_all()->with_outcome(grade_pull_result::RELEASED));
        $this->assertSame([0 => 'add', 1 => 'add'], $this->outcomes($this->pull_all()));

        $this->assertSame([40.0, 60.0], array_column($this->grades_here(), 0));
        $this->assertEquals(33, $this->gradebook_here());
        $this->assertSame(1, $DB->count_records('block_coursesync_grade'));
        $this->assertNotNull(mark_writer::releasable_override($this->course, (int) $this->here->id, (int) $this->sam->id));
        $flags = $assign->get_user_flags((int) $this->sam->id, false);
        $this->assertNotSame(marks::STATE_RELEASED, $flags ? $flags->workflowstate : '');
        $this->assertSame($state, $this->submission_state());

        // The newest attempt's mark does what an ordinary pull does.
        $this->give_mark($this->there, $this->sam, 80, 'Good');
        $this->assertCount(1, $this->preview_all()->with_outcome(grade_pull_result::RELEASED));
        $result = $this->pull_all();

        $this->assertSame([0 => 'same', 1 => 'same', 2 => 'add'], $this->outcomes($result));
        $this->assertCount(1, $result->with_outcome(grade_pull_result::RELEASED));
        $this->assertSame(0, $DB->count_records('block_coursesync_grade'));
        $this->assertSame(marks::STATE_RELEASED, $assign->get_user_flags((int) $this->sam->id, false)->workflowstate);
        $this->assertSame($state, $this->submission_state());
    }

    /**
     * A student with only their own work here gets marks for the attempt
     * that is there, and no more; no attempt is made up for a mark.
     */
    public function test_a_mark_needs_an_attempt_here(): void {
        $this->three_goes();
        $this->submit($this->sam, $this->here, 'my own first go');

        $this->assertSame(
            [0 => 'add', 1 => 'skipped:markreasonnoattempt', 2 => 'skipped:markreasonnoattempt'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame([40.0], array_column($this->grades_here(), 0));
        $this->assertSame([1, 1], $this->submission_state());
    }

    /**
     * A student with no work here at all is marked at attempt 0, as before,
     * and only there.
     */
    public function test_a_student_with_no_work_here_is_marked_at_attempt_0(): void {
        $this->give_mark($this->there, $this->sam, 55, 'No work, but marked');

        $this->assertSame([0 => 'add'], $this->outcomes($this->pull_all()));
        $this->assertSame([55.0], array_column($this->grades_here(), 0));
        $this->assertSame([1, 1], $this->submission_state());
    }

    /**
     * A student with no work here is not given attempts to hold marks for the
     * attempts they have not got.
     */
    public function test_attempts_are_not_made_up_for_a_student_with_no_work(): void {
        $this->three_goes();

        $this->assertSame(
            [0 => 'add', 1 => 'skipped:markreasonnoattempt', 2 => 'skipped:markreasonnoattempt'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame([1, 1], $this->submission_state());
    }

    /**
     * Work here that is the newest attempt from the other site copied as
     * attempt 0 is not that attempt 0: no mark goes on it.
     */
    public function test_a_latest_only_copy_of_the_work_is_not_marked_as_attempt_0(): void {
        $this->three_goes();
        $this->pull_work(false);

        $this->assertSame(
            [0 => 'skipped:markreasonmismatch', 1 => 'skipped:markreasonnoattempt', 2 => 'skipped:markreasonnoattempt'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame([], $this->grades_here());
    }

    /**
     * Where the student has work of their own for an attempt, the mark for that
     * attempt is not put on it; the attempts below it still are marked.
     */
    public function test_a_mark_is_not_put_on_the_students_own_work(): void {
        $this->submit($this->sam, $this->there, 'first');
        $this->give_mark($this->there, $this->sam, 40, 'Try again');
        $this->pull_work();

        $this->reopen($this->here, $this->sam);
        $this->submit($this->sam, $this->here, 'my own second go');

        $this->reopen($this->there, $this->sam);
        $this->submit($this->sam, $this->there, 'second');
        $this->give_mark($this->there, $this->sam, 60, 'Better');
        $this->reopen($this->there, $this->sam);
        $this->submit($this->sam, $this->there, 'third');
        $this->give_mark($this->there, $this->sam, 80, 'Good');

        $this->assertSame(
            [0 => 'add', 1 => 'skipped:markreasonmismatch', 2 => 'skipped:markreasonnoattempt'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame([40.0], array_column($this->grades_here(), 0));
        $this->assertSame([2, 1], $this->submission_state());
    }

    /**
     * A pull of the latest mark alone leaves a student whose attempts were
     * pulled exactly as they are: the mark it already pulled is not reported
     * as removed, and nothing new is put on the wrong attempt.
     */
    public function test_a_latest_only_pull_leaves_a_student_whose_attempts_were_pulled(): void {
        global $DB;

        $this->three_goes();
        $this->pull_work(false);
        // Keyed by the attempt on the source (2); it is written to the only attempt here (0).
        $this->assertSame([2 => 'add'], $this->outcomes($this->pull_latest()));
        $this->assertSame([80.0], array_column($this->grades_here(), 0));

        // The attempts arrive, as an every-attempt submissions pull brings them.
        $this->pull_work();
        $this->assertSame([3, 1], $this->submission_state());
        $before = $this->grades_here();
        $ledger = $DB->get_records('block_coursesync_mark');

        $this->assertSame([2 => 'skipped:markreasonattemptsdone'], $this->outcomes($this->pull_latest()));

        $this->assertSame($before, $this->grades_here());
        $this->assertEquals($ledger, $DB->get_records('block_coursesync_mark'));
        $this->assertSame([3, 1], $this->submission_state());
    }

    /**
     * A record of a submission pulled is only good for the submission it points
     * at: where the work now there is another row, the mark is not put on it.
     */
    public function test_a_mark_is_not_put_on_work_that_replaced_what_was_pulled(): void {
        global $DB;

        $this->three_goes();
        $this->pull_work();
        $DB->set_field('block_coursesync_submission', 'submissionid', 987654, [
            'assignid' => $this->here->id, 'userid' => $this->sam->id, 'attemptnumber' => 1,
        ]);

        $this->assertSame(
            [0 => 'add', 1 => 'skipped:markreasonmismatch', 2 => 'add'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame([0, 2], array_keys($this->grades_here()));
    }

    /**
     * Pulling every attempt's mark then mends what the latest-only pull left:
     * the mark that was put on attempt 0 goes back to being attempt 0's.
     */
    public function test_pulling_every_mark_mends_a_latest_only_copy(): void {
        $this->three_goes();
        $this->pull_work(false);
        $this->pull_latest();
        $this->pull_work();

        $this->assertSame([0 => 'update', 1 => 'add', 2 => 'add'], $this->outcomes($this->pull_all()));

        $this->assertSame([40.0, 60.0, 80.0], array_column($this->grades_here(), 0));
        $this->assertSame(0, (int) $this->ledger()[0]->remoteattempt);
        $this->assertSame([3, 1], $this->submission_state());
        $this->assertEquals(80, $this->gradebook_here());
    }

    /**
     * A student with several marks pulled is left alone by a latest-only pull
     * even when no submission was ever pulled for them: the marks themselves say so.
     */
    public function test_a_latest_only_pull_leaves_several_pulled_marks_alone(): void {
        global $DB;

        $this->three_goes();
        $this->submit($this->sam, $this->here, 'a');
        $this->reopen($this->here, $this->sam);
        $this->submit($this->sam, $this->here, 'b');
        $this->reopen($this->here, $this->sam);
        $this->submit($this->sam, $this->here, 'c');
        $this->assertSame(0, $DB->count_records('block_coursesync_submission'));

        $this->assertSame([0 => 'add', 1 => 'add', 2 => 'add'], $this->outcomes($this->pull_all()));
        $before = $this->grades_here();

        $this->assertSame([2 => 'skipped:markreasonattemptsdone'], $this->outcomes($this->pull_latest()));
        $this->assertSame($before, $this->grades_here());
    }

    /**
     * Under a marking workflow on the source only the newest attempt's mark is
     * known to be released: the others are held back, the assignment says so
     * once, and the students are still worked through.
     */
    public function test_a_source_marking_workflow_holds_earlier_marks_back(): void {
        global $DB;

        $DB->set_field('assign', 'markingworkflow', 1, ['id' => $this->there->id]);
        $this->three_goes();
        $object = $this->assign_object($this->there);
        $flags = $object->get_user_flags((int) $this->sam->id, true);
        $flags->workflowstate = marks::STATE_RELEASED;
        $object->update_user_flags($flags);
        $this->pull_work();

        $result = $this->pull_all();

        $this->assertSame([2 => 'add'], $this->outcomes($result));
        $notes = array_values(array_filter($result->entries, static fn($entry) => !$entry->userid));
        $this->assertCount(1, $notes);
        $this->assertSame('markreasonworkflowhistory', $notes[0]->reason);
        $this->assertSame([80.0], array_column($this->grades_here(), 0));
        $this->assertEquals(80, $this->gradebook_here());
        $this->assertSame([2], array_keys($this->ledger()));
    }

    /**
     * A mark whose file does not arrive intact is not written; the others are.
     */
    public function test_a_failed_file_does_not_hold_back_other_marks(): void {
        $this->three_goes();
        $this->pull_work();
        $this->tamper['block_coursesync_get_marks'] = static function (array $body): array {
            $body['items'][0]['marks'][0]['attempts'][1]['files'][0]['contenthash'] = sha1('not the file');

            return $body;
        };

        $this->assertSame(
            [0 => 'add', 1 => 'skipped:subreasoncorrupt', 2 => 'add'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame([0, 2], array_keys($this->grades_here()));
        $this->assertSame([0, 2], array_keys($this->ledger()));
    }

    /**
     * A grade locked in the gradebook stops the student's marks for every
     * attempt, not only the newest: nobody's locked grade is touched.
     */
    public function test_a_locked_gradebook_grade_stops_every_attempts_mark(): void {
        $this->three_goes();
        $this->pull_work();
        $item = $this->grade_item_here();
        $item->set_locked(time());

        $this->assertSame(
            [0 => 'skipped:markreasonlocked', 1 => 'skipped:markreasonlocked', 2 => 'skipped:markreasonlocked'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame([], $this->grades_here());

        $item->set_locked(0);
        $this->assertSame([0 => 'add', 1 => 'add', 2 => 'add'], $this->outcomes($this->pull_all()));
    }

    /**
     * Asking for every attempt is refused unless this site allows it, before
     * the other site is asked anything.
     */
    public function test_pulling_every_attempt_needs_this_sites_switch(): void {
        $this->three_goes();
        set_config('allowattemptspull', 0, 'block_coursesync');

        $result = $this->pull_all();

        $this->assertFalse($result->success);
        $this->assertSame('errorattemptspulloff', $result->errorkey);
        $this->assertSame([], $this->calls);
    }

    /**
     * A source that does not offer every attempt is not asked for them, and
     * nothing is pulled in their place.
     */
    public function test_a_source_that_does_not_offer_every_attempt_is_not_asked(): void {
        $this->three_goes();
        $this->pull_work();
        set_config('allowattemptsexport', 0, 'block_coursesync');
        $this->calls = [];

        $result = $this->pull_all();

        $this->assertFalse($result->success);
        $this->assertSame('errorattemptssourceunable', $result->errorkey);
        $this->assertSame(['block_coursesync_ping'], array_column($this->calls, 'wsfunction'));
        $this->assertSame([], $this->grades_here());

        // And a ping that fails stops it too.
        $this->calls = [];
        set_config('allowattemptsexport', 1, 'block_coursesync');
        $this->tamper['block_coursesync_ping'] = static function (): array {
            return ['exception' => 'moodle_exception', 'errorcode' => 'invalidtoken', 'message' => 'no'];
        };

        $this->assertFalse($this->pull_all()->success);
        $this->assertNotContains('block_coursesync_get_marks', array_column($this->calls, 'wsfunction'));
    }

    /**
     * A pull of the latest never mentions attempts to the source, and does not ping.
     */
    public function test_a_latest_only_pull_sends_nothing_about_attempts(): void {
        $this->submit($this->sam, $this->there, 'first');
        $this->give_mark($this->there, $this->sam, 40, 'Try again', ['first.txt' => 'FIRST']);
        $this->submit($this->sam, $this->here, 'mine');
        $this->calls = [];

        $this->pull_latest();

        $this->assertContains('block_coursesync_get_mark_file', array_column($this->calls, 'wsfunction'));

        foreach ($this->calls as $call) {
            $this->assertArrayNotHasKey('allattempts', $call);
            $this->assertArrayNotHasKey('attemptnumber', $call);
        }

        $this->assertNotContains('block_coursesync_ping', array_column($this->calls, 'wsfunction'));
    }
}
