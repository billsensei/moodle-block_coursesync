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
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for reading the other site's description of the marks it holds.
 *
 * Whatever the other site sends is untrusted. With every attempt asked for the
 * list of attempts is required and must be believable, and each mark in it is
 * held to what the latest one is: a finite number or none, a comment that is
 * cleaned, files that are honestly described.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(marks_result::class)]
final class marks_result_test extends advanced_testcase {
    /**
     * One attempt's mark as the source describes it.
     *
     * @param array $over fields to change
     * @return array
     */
    protected function attempt(array $over = []): array {
        return $over + [
            'attemptnumber' => 0, 'timemodified' => 20, 'grade' => 50.0, 'comment' => '<p>Fine</p>',
            'commentformat' => FORMAT_HTML, 'files' => [], 'fingerprint' => sha1('x'),
        ];
    }

    /**
     * A source's answer holding one student, with extra fields on the assignment.
     *
     * @param array $student
     * @param array $item
     * @return array
     */
    protected function answer(array $student, array $item = []): array {
        return ['items' => [$item + ['cmid' => 5, 'reason' => '', 'grademax' => 100, 'marks' => [$student]]]];
    }

    /**
     * A student with these attempts, the latest also at the top.
     *
     * @param array[] $attempts
     * @return array
     */
    protected function student(array $attempts): array {
        return ['username' => 'sam'] + end($attempts) + ['attempts' => $attempts];
    }

    /**
     * Not asked for, the list is ignored: a latest-only pull has no attempts.
     */
    public function test_attempts_are_ignored_unless_asked_for(): void {
        $student = $this->student([$this->attempt(), $this->attempt(['attemptnumber' => 1])]);

        $result = marks_result::from_response($this->answer($student));

        $this->assertTrue($result->success);
        $this->assertNull($result->items[0]->marks[0]->attempts);
        $this->assertSame(1, $result->items[0]->marks[0]->attemptnumber);
    }

    /**
     * Asked for, each attempt comes with the student's username, in order.
     */
    public function test_every_attempts_mark_is_read(): void {
        $attempts = [
            $this->attempt(['comment' => 'first', 'grade' => 40]),
            $this->attempt(['attemptnumber' => 2, 'comment' => 'third', 'grade' => null]),
        ];

        $result = marks_result::from_response($this->answer($this->student($attempts)), true);

        $this->assertTrue($result->success);
        $read = $result->items[0]->marks[0]->attempts;
        $this->assertSame([0, 2], array_column($read, 'attemptnumber'));
        $this->assertSame(['first', 'third'], array_column($read, 'comment'));
        $this->assertSame([40.0, null], array_column($read, 'grade'));
        $this->assertSame(['sam', 'sam'], array_column($read, 'username'));
    }

    /**
     * Each attempt's comment is cleaned like the latest's, not trusted.
     */
    public function test_each_attempts_comment_is_cleaned(): void {
        $attempts = [
            $this->attempt(['comment' => '<p>safe</p><script>alert(1)</script>']),
            $this->attempt(['attemptnumber' => 1, 'comment' => '<img src=x onerror=alert(1)>ok']),
        ];

        $read = marks_result::from_response($this->answer($this->student($attempts)), true)->items[0]->marks[0]->attempts;

        foreach ($read as $attempt) {
            $this->assertStringNotContainsString('script', $attempt->comment);
            $this->assertStringNotContainsString('onerror', $attempt->comment);
        }
    }

    /**
     * Believable or refused: a list that is missing, empty, repeats an attempt,
     * is out of order, is endless, or holds a mark that is itself wrong.
     */
    public function test_a_list_that_cannot_be_believed_is_refused(): void {
        $one = $this->attempt();
        $two = $this->attempt(['attemptnumber' => 1]);
        $cases = [
            'missing' => ['username' => 'sam'] + $two,
            'not a list' => ['username' => 'sam'] + $two + ['attempts' => 'all of them'],
            'empty' => array_merge($this->student([$one]), ['attempts' => []]),
            'repeated' => $this->student([$one, $one]),
            'out of order' => $this->student([$two, $one]),
            'a mark without a fingerprint' => $this->student([$one, array_diff_key($two, ['fingerprint' => 1])]),
            'an infinite mark' => $this->student([$one, $this->attempt(['attemptnumber' => 1, 'grade' => '1e999'])]),
            'a mark that is not a number' => $this->student([$one, $this->attempt(['attemptnumber' => 1, 'grade' => 'high'])]),
            'endless' => $this->student(array_map(
                fn($n) => $this->attempt(['attemptnumber' => $n]),
                range(0, marks_result::MAX_ATTEMPTS)
            )),
        ];

        foreach ($cases as $name => $student) {
            $result = marks_result::from_response($this->answer($student), true);

            $this->assertFalse($result->success, $name);
            $this->assertSame('errorbadresponse', $result->errorkey, $name);
        }

        $longest = marks_result::from_response($this->answer($this->student(array_map(
            fn($n) => $this->attempt(['attemptnumber' => $n]),
            range(0, marks_result::MAX_ATTEMPTS - 1)
        ))), true);
        $this->assertTrue($longest->success);
    }

    /**
     * A student without a username is refused whichever way it was asked.
     */
    public function test_a_student_needs_a_username(): void {
        foreach ([false, true] as $all) {
            $student = $this->student([$this->attempt()]);
            unset($student['username']);

            $this->assertFalse(marks_result::from_response($this->answer($student), $all)->success);
        }
    }

    /**
     * The reason earlier marks were held back is kept only if this version knows it.
     */
    public function test_the_reason_earlier_marks_are_missing_is_only_kept_if_known(): void {
        $student = $this->student([$this->attempt()]);

        $known = marks_result::from_response($this->answer($student, ['attemptsreason' => 'workflowhistory']), true);
        $this->assertSame('workflowhistory', $known->items[0]->attemptsreason);

        foreach (['', 'madeup', '<b>x</b>'] as $reason) {
            $other = marks_result::from_response($this->answer($student, ['attemptsreason' => $reason]), true);
            $this->assertSame('', $other->items[0]->attemptsreason, $reason);
        }

        $absent = marks_result::from_response($this->answer($student), true);
        $this->assertSame('', $absent->items[0]->attemptsreason);
    }
}
