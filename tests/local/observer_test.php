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

namespace block_coursesync\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for forgetting what pulls remembered when a course is reset or a user
 * is deleted.
 *
 * Each test drives core's own reset_course_userdata() or delete_user(), not
 * the observer directly: what matters is that core's event reaches it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(observer::class)]
final class observer_test extends \advanced_testcase {
    /** @var \stdClass The course that gets reset, or whose users are deleted. */
    protected \stdClass $course;

    /** @var \stdClass A second course whose records must never be touched. */
    protected \stdClass $other;

    /** @var \stdClass A student. */
    protected \stdClass $student;

    /** @var \stdClass Another student. */
    protected \stdClass $classmate;

    /** @var int Makes each remembered row refer to a different grade item and attempt. */
    protected int $serial = 0;

    /**
     * Two courses, two students, and a remembered grade and attempt for each
     * student in each course, plus one run in the first course.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->other = $generator->create_course();
        $this->student = $generator->create_user();
        $this->classmate = $generator->create_user();

        foreach ([$this->course, $this->other] as $course) {
            // A graded activity, so the course has a gradebook to reset.
            $generator->create_module('assign', ['course' => $course->id]);

            foreach ([$this->student, $this->classmate] as $user) {
                $generator->enrol_user($user->id, $course->id, 'student');
                $this->remember($course, $user);
            }
        }

        global $DB;
        $DB->insert_record('block_coursesync_run', (object) [
            'blockinstanceid' => 1,
            'courseid' => $this->course->id,
            'userid' => $this->student->id,
            'kind' => 'activities',
            'timestarted' => 1750000000,
            'timefinished' => 1750000001,
            'status' => 'ok',
        ]);
    }

    /**
     * Record that a pull wrote this user's grade, quiz attempt and assignment
     * submission in a course.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @return void
     */
    protected function remember(\stdClass $course, \stdClass $user): void {
        global $DB;

        $serial = ++$this->serial;

        $DB->insert_record('block_coursesync_grade', (object) [
            'blockinstanceid' => 1,
            'courseid' => $course->id,
            'userid' => $user->id,
            'gradeitemid' => $serial,
            'remotecmid' => 777,
            'itemnumber' => 0,
            'finalgrade' => 5,
            'feedbackhash' => sha1(''),
            'remotetime' => 1750000000,
            'timepulled' => 1750000100,
        ]);
        $DB->insert_record('block_coursesync_attempt', (object) [
            'blockinstanceid' => 1,
            'courseid' => $course->id,
            'userid' => $user->id,
            'quizid' => $serial,
            'attemptid' => $serial,
            'remotecmid' => 778,
            'remoteattemptid' => 9,
            'marks' => '[]',
            'timeimported' => 1750000100,
        ]);
        $DB->insert_record('block_coursesync_submission', (object) [
            'blockinstanceid' => 1,
            'courseid' => $course->id,
            'userid' => $user->id,
            'assignid' => $serial,
            'submissionid' => $serial,
            'remotecmid' => 779,
            'remoteattempt' => 0,
            'fingerprint' => sha1('there'),
            'localfingerprint' => sha1('here'),
            'remotetime' => 1750000000,
            'timeimported' => 1750000100,
        ]);
        $DB->insert_record('block_coursesync_mark', (object) [
            'blockinstanceid' => 1,
            'courseid' => $course->id,
            'userid' => $user->id,
            'assignid' => $serial,
            'gradeid' => $serial,
            'remotecmid' => 780,
            'remoteattempt' => 0,
            'fingerprint' => sha1('there'),
            'localfingerprint' => sha1('here'),
            'remotetime' => 1750000000,
            'timeimported' => 1750000100,
        ]);
    }

    /**
     * Reset a course the way the reset page does, with only these options on.
     *
     * @param \stdClass $course
     * @param string[] $options names of the reset options that are ticked
     * @return void
     */
    protected function reset(\stdClass $course, array $options): void {
        $data = (object) array_fill_keys($options, 1);
        $data->id = $course->id;

        reset_course_userdata($data);
    }

    /**
     * How many remembered rows a table holds, optionally for one course.
     *
     * @param string $table
     * @param int|null $courseid
     * @return int
     */
    protected function rows(string $table, ?int $courseid = null): int {
        global $DB;

        return $courseid === null
            ? $DB->count_records($table)
            : $DB->count_records($table, ['courseid' => $courseid]);
    }

    /**
     * Resetting quiz attempts forgets the attempts that were pulled, so the
     * next pull brings them back; the grades, other courses and the history
     * are left alone.
     */
    public function test_resetting_quiz_attempts_forgets_pulled_attempts(): void {
        $this->reset($this->course, ['reset_quiz_attempts']);

        $this->assertSame(0, $this->rows('block_coursesync_attempt', $this->course->id));
        $this->assertSame(2, $this->rows('block_coursesync_attempt', $this->other->id));
        $this->assertSame(2, $this->rows('block_coursesync_grade', $this->course->id));
        $this->assertSame(1, $this->rows('block_coursesync_run'));
    }

    /**
     * Resetting assignment submissions forgets the submissions that were
     * pulled, so the next pull brings them back; the grades, the attempts and
     * other courses are left alone.
     */
    public function test_resetting_assign_submissions_forgets_pulled_submissions(): void {
        $this->reset($this->course, ['reset_assign_submissions']);

        $this->assertSame(0, $this->rows('block_coursesync_submission', $this->course->id));
        $this->assertSame(2, $this->rows('block_coursesync_submission', $this->other->id));
        // The assignment's reset takes its marks with its submissions.
        $this->assertSame(0, $this->rows('block_coursesync_mark', $this->course->id));
        $this->assertSame(2, $this->rows('block_coursesync_mark', $this->other->id));
        $this->assertSame(2, $this->rows('block_coursesync_grade', $this->course->id));
        $this->assertSame(2, $this->rows('block_coursesync_attempt', $this->course->id));
    }

    /**
     * Removing quiz attempts says nothing about assignment submissions.
     */
    public function test_resetting_quiz_attempts_keeps_pulled_submissions(): void {
        $this->reset($this->course, ['reset_quiz_attempts']);

        $this->assertSame(2, $this->rows('block_coursesync_submission', $this->course->id));
        $this->assertSame(2, $this->rows('block_coursesync_mark', $this->course->id));
    }

    /**
     * Removing the gradebook's grades forgets the grades that were pulled.
     */
    public function test_resetting_gradebook_grades_forgets_pulled_grades(): void {
        $this->reset($this->course, ['reset_gradebook_grades']);

        $this->assertSame(0, $this->rows('block_coursesync_grade', $this->course->id));
        $this->assertSame(2, $this->rows('block_coursesync_grade', $this->other->id));
        $this->assertSame(2, $this->rows('block_coursesync_attempt', $this->course->id));
        $this->assertSame(1, $this->rows('block_coursesync_run'));
    }

    /**
     * Removing the gradebook's items takes their grades with them.
     */
    public function test_resetting_gradebook_items_forgets_pulled_grades(): void {
        $this->reset($this->course, ['reset_gradebook_items']);

        $this->assertSame(0, $this->rows('block_coursesync_grade', $this->course->id));
        $this->assertSame(2, $this->rows('block_coursesync_grade', $this->other->id));
    }

    /**
     * A reset that removes neither grades nor attempts forgets nothing: a
     * pulled attempt a teacher deleted by hand is still their decision.
     */
    public function test_an_unrelated_reset_forgets_nothing(): void {
        $this->reset($this->course, ['reset_events']);

        $this->assertSame(4, $this->rows('block_coursesync_grade'));
        $this->assertSame(4, $this->rows('block_coursesync_attempt'));
        $this->assertSame(4, $this->rows('block_coursesync_submission'));
        $this->assertSame(4, $this->rows('block_coursesync_mark'));
    }

    /**
     * Deleting a user forgets what was pulled for them, in every course, and
     * nobody else's; the history of runs stays.
     */
    public function test_deleting_a_user_forgets_what_was_pulled_for_them(): void {
        global $DB;

        delete_user($this->student);

        $tables = ['block_coursesync_grade', 'block_coursesync_attempt', 'block_coursesync_submission', 'block_coursesync_mark'];

        foreach ($tables as $table) {
            $this->assertSame(0, $DB->count_records($table, ['userid' => $this->student->id]), $table);
            $this->assertSame(2, $DB->count_records($table, ['userid' => $this->classmate->id]), $table);
        }

        $this->assertSame(1, $this->rows('block_coursesync_run'));
    }
}
