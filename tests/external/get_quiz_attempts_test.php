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
use core_external\external_api;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for handing students' quiz attempts, as marks, to another site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_quiz_attempts::class)]
final class get_quiz_attempts_test extends advanced_testcase {
    /** @var \stdClass */
    protected \stdClass $course;

    /** @var \stdClass A quiz: shortanswer (2 marks), shortanswer (3 marks), essay (5), random (1). */
    protected \stdClass $quiz;

    /**
     * A course with a four-slot quiz, and grade sharing switched on.
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allowgradeexport', 1, 'block_coursesync');

        $this->course = $this->getDataGenerator()->create_course();
        $this->quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'grade' => 10,
            'questionsperpage' => 0,
        ]);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $qgen->create_question_category();
        $pool = $qgen->create_question_category(['name' => 'Pool']);

        // The generator's short-answer question takes "frog" (100%) and "toad" (80%).
        \quiz_add_quiz_question($qgen->create_question('shortanswer', null, ['category' => $category->id])->id, $this->quiz, 0, 2);
        \quiz_add_quiz_question($qgen->create_question('shortanswer', null, ['category' => $category->id])->id, $this->quiz, 0, 3);
        \quiz_add_quiz_question($qgen->create_question('essay', null, ['category' => $category->id])->id, $this->quiz, 0, 5);

        $qgen->create_question('truefalse', null, ['category' => $pool->id]);
        quiz_settings::create($this->quiz->id)->get_structure()->add_random_questions(0, 1, [
            'filter' => [
                'category' => [
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => [$pool->id],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
        ]);

        // Adding questions does not total the marks; the edit page does.
        quiz_settings::create($this->quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    }

    /**
     * Start an attempt, answer it, and optionally submit it, as a student would.
     *
     * @param \stdClass $user
     * @param array $responses slot => simulated response
     * @param bool $finish submit and grade it
     * @param bool $preview make it a teacher's preview
     * @return \stdClass the quiz_attempts record
     */
    protected function attempt(\stdClass $user, array $responses, bool $finish = true, bool $preview = false): \stdClass {
        global $DB;

        $this->setUser($user);
        $quizobj = quiz_settings::create($this->quiz->id, $user->id);
        $number = 1 + $DB->count_records('quiz_attempts', ['quiz' => $this->quiz->id, 'userid' => $user->id]);
        $time = 1750000000 + 100 * $number;

        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($quizobj, $number, null, $time, $preview, $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, $number, $time);
        quiz_attempt_save_started($quizobj, $quba, $attempt, $time);

        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($time + 10, false, $responses);

        if ($finish) {
            $attemptobj->process_submit($time + 60, false);
            $attemptobj->process_grade_submission($time + 60);
        }

        $this->setAdminUser();

        return $DB->get_record('quiz_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
    }

    /**
     * Call the function the way the web service layer would.
     *
     * @param int[] $cmids
     * @return array
     */
    protected function call(array $cmids): array {
        return external_api::clean_returnvalue(
            get_quiz_attempts::execute_returns(),
            get_quiz_attempts::execute($this->course->id, $cmids)
        );
    }

    /**
     * The quiz's outline comes back slot by slot: type and maximum mark, and
     * "random" for a slot that draws from a category.
     */
    public function test_outline(): void {
        $result = $this->call([$this->quiz->cmid]);

        $this->assertSame(get_quiz_attempts::FORMAT_VERSION, $result['formatversion']);
        $this->assertCount(1, $result['quizzes']);
        $quiz = $result['quizzes'][0];
        $this->assertSame((int) $this->quiz->cmid, $quiz['cmid']);
        $this->assertEquals(10, $quiz['grade']);
        $this->assertEquals(11, $quiz['sumgrades']);
        $this->assertSame(
            [[1, 'shortanswer', 2.0], [2, 'shortanswer', 3.0], [3, 'essay', 5.0], [4, 'random', 1.0]],
            array_map(static fn($slot) => [$slot['slot'], $slot['qtype'], (float) $slot['maxmark']], $quiz['slots'])
        );
        $this->assertSame([], $quiz['attempts']);
    }

    /**
     * A finished attempt carries its times, total and a mark and state per
     * slot, under the student's username. An answered essay waiting to be
     * graded by hand has no mark yet, and so the attempt has no total.
     */
    public function test_a_finished_attempt(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'sam']);
        $record = $this->attempt($student, [
            1 => ['answer' => 'frog'],
            2 => ['answer' => 'toad'],
            3 => ['answer' => 'My essay', 'answerformat' => FORMAT_HTML],
        ]);

        $attempts = $this->call([$this->quiz->cmid])['quizzes'][0]['attempts'];

        $this->assertCount(1, $attempts);
        $attempt = $attempts[0];
        $this->assertSame((int) $record->id, $attempt['id']);
        $this->assertSame('sam', $attempt['username']);
        $this->assertSame(1, $attempt['attempt']);
        $this->assertSame(1750000100, $attempt['timestart']);
        $this->assertSame(1750000160, $attempt['timefinish']);
        $this->assertNull($attempt['sumgrades'], 'the essay still needs grading');

        $this->assertSame([1, 2, 3, 4], array_column($attempt['marks'], 'slot'));
        $marks = array_column($attempt['marks'], 'mark', 'slot');
        $states = array_column($attempt['marks'], 'state', 'slot');
        $this->assertEquals(2.0, $marks[1]);
        $this->assertSame('gradedright', $states[1]);
        $this->assertEqualsWithDelta(2.4, $marks[2], 0.00001);
        $this->assertSame('gradedpartial', $states[2]);
        $this->assertNull($marks[3]);
        $this->assertSame('needsgrading', $states[3]);
        $this->assertNull($marks[4]);
        $this->assertSame('gaveup', $states[4], 'a slot left unanswered');
    }

    /**
     * A question never answered has no mark either - but it does not hold
     * up the total, which is what the state lets the destination tell apart
     * from one waiting to be graded.
     */
    public function test_an_unanswered_question(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'sam']);
        $this->attempt($student, [1 => ['answer' => 'frog'], 2 => ['answer' => 'toad']]);

        $attempt = $this->call([$this->quiz->cmid])['quizzes'][0]['attempts'][0];

        $this->assertSame('gaveup', array_column($attempt['marks'], 'state', 'slot')[3]);
        $this->assertNull(array_column($attempt['marks'], 'mark', 'slot')[3]);
        $this->assertEqualsWithDelta(4.4, $attempt['sumgrades'], 0.00001);
    }

    /**
     * Once the essay is graded by hand, the attempt has its total.
     */
    public function test_a_manually_graded_attempt(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'sam']);
        $record = $this->attempt($student, [
            1 => ['answer' => 'frog'],
            3 => ['answer' => 'My essay', 'answerformat' => FORMAT_HTML],
        ]);

        // What the quiz's manual grading page does: grade the question, then
        // bring the attempt's total up to date.
        $quba = \question_engine::load_questions_usage_by_activity($record->uniqueid);
        $quba->manual_grade(3, 'Good essay', 4, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($quba);
        $DB->set_field('quiz_attempts', 'sumgrades', $quba->get_total_mark(), ['id' => $record->id]);

        $attempt = $this->call([$this->quiz->cmid])['quizzes'][0]['attempts'][0];

        $this->assertEquals(4.0, array_column($attempt['marks'], 'mark', 'slot')[3]);
        $this->assertEquals(6.0, $attempt['sumgrades']);
    }

    /**
     * Only finished attempts are results: not one in progress, and never a
     * teacher's preview. Several finished attempts come back in order.
     */
    public function test_only_finished_attempts(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'sam']);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $this->attempt($student, [1 => ['answer' => 'toad']]);
        $this->attempt($student, [1 => ['answer' => 'frog']]);
        $this->attempt($student, [1 => ['answer' => 'frog']], false);
        $this->attempt($teacher, [1 => ['answer' => 'frog']], true, true);

        $attempts = $this->call([$this->quiz->cmid])['quizzes'][0]['attempts'];

        $this->assertSame([1, 2], array_column($attempts, 'attempt'));
        $this->assertSame(['sam', 'sam'], array_column($attempts, 'username'));
    }

    /**
     * Only students the gradebook lists: a suspended student's attempt is
     * left out.
     */
    public function test_only_active_graded_students(): void {
        $active = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'active']);
        $suspended = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'paused']);
        $this->attempt($active, [1 => ['answer' => 'frog']]);
        $this->attempt($suspended, [1 => ['answer' => 'frog']]);

        $enrol = \enrol_get_plugin('manual');
        $instance = $GLOBALS['DB']->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual']);
        $enrol->update_user_enrol($instance, $suspended->id, ENROL_USER_SUSPENDED);

        $attempts = $this->call([$this->quiz->cmid])['quizzes'][0]['attempts'];

        $this->assertSame(['active'], array_column($attempts, 'username'));
    }

    /**
     * Anything that is not a quiz in this course is left out, not refused.
     */
    public function test_only_quizzes_in_the_course(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $other = $this->getDataGenerator()->create_course();
        $elsewhere = $this->getDataGenerator()->create_module('quiz', ['course' => $other->id]);

        $result = $this->call([$this->quiz->cmid, $page->cmid, $elsewhere->cmid, 999999]);

        $this->assertSame([(int) $this->quiz->cmid], array_column($result['quizzes'], 'cmid'));
        $this->assertSame([], $this->call([])['quizzes']);
    }

    /**
     * Behind the same switch as grades.
     */
    public function test_switched_off(): void {
        set_config('allowgradeexport', 0, 'block_coursesync');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorgradeexportdisabled', 'block_coursesync'));
        get_quiz_attempts::execute($this->course->id, [$this->quiz->cmid]);
    }

    /**
     * And behind the same permission: a sync account that may copy
     * activities cannot read attempts until it may also read grades.
     */
    public function test_needs_the_grades_permission(): void {
        $syncuser = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_course::instance($this->course->id);
        assign_capability('block/coursesync:sync', CAP_ALLOW, $roleid, $context->id, true);
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $syncuser->id, $context->id);
        $this->setUser($syncuser);

        try {
            get_quiz_attempts::execute($this->course->id, [$this->quiz->cmid]);
            $this->fail('Attempts were handed out without block/coursesync:exportgrades.');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_string('coursesync:exportgrades', 'block_coursesync'), $e->getMessage());
        }

        assign_capability('block/coursesync:exportgrades', CAP_ALLOW, $roleid, $context->id, true);

        $this->assertCount(1, $this->call([$this->quiz->cmid])['quizzes']);
    }
}
