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
 * Tests for reading the other site's description of what students handed in.
 *
 * Whatever the other site sends is untrusted. With every attempt asked for the
 * list of attempts is required and must be believable: one student cannot
 * have two rows for one attempt, a history in the wrong order, or no end of
 * attempts, and each attempt is cleaned as strictly as the latest.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(submissions_result::class)]
final class submissions_result_test extends advanced_testcase {
    /**
     * One attempt as the source describes it.
     *
     * @param array $over fields to change
     * @return array
     */
    protected function attempt(array $over = []): array {
        return $over + [
            'attemptnumber' => 0, 'timecreated' => 10, 'timemodified' => 20, 'onlinetext' => '<p>x</p>',
            'onlineformat' => FORMAT_HTML, 'files' => [], 'fingerprint' => sha1('x'),
        ];
    }

    /**
     * A source's answer holding one student.
     *
     * @param array $student
     * @return array
     */
    protected function answer(array $student): array {
        return ['items' => [['cmid' => 5, 'reason' => '', 'plugins' => ['onlinetext'], 'submissions' => [$student]]]];
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
        $result = submissions_result::from_response($this->answer($student));

        $this->assertTrue($result->success);
        $this->assertNull($result->items[0]->submissions[0]->attempts);
        $this->assertSame(1, $result->items[0]->submissions[0]->attemptnumber);
    }

    /**
     * Asked for, each attempt comes with the student's username, in order.
     */
    public function test_every_attempt_is_read(): void {
        $attempts = [
            $this->attempt(['onlinetext' => 'first']),
            $this->attempt(['attemptnumber' => 2, 'onlinetext' => 'third']),
        ];

        $result = submissions_result::from_response($this->answer($this->student($attempts)), true);

        $this->assertTrue($result->success);
        $read = $result->items[0]->submissions[0]->attempts;
        $this->assertSame([0, 2], array_column($read, 'attemptnumber'));
        $this->assertSame(['first', 'third'], array_column($read, 'onlinetext'));
        $this->assertSame(['sam', 'sam'], array_column($read, 'username'));
    }

    /**
     * Each attempt's text is cleaned like the latest's, not trusted.
     */
    public function test_each_attempts_text_is_cleaned(): void {
        $attempts = [
            $this->attempt(['onlinetext' => '<p>safe</p><script>alert(1)</script>']),
            $this->attempt(['attemptnumber' => 1, 'onlinetext' => '<img src=x onerror=alert(1)>ok']),
        ];

        $read = submissions_result::from_response($this->answer($this->student($attempts)), true)
            ->items[0]->submissions[0]->attempts;

        foreach ($read as $attempt) {
            $this->assertStringNotContainsString('script', $attempt->onlinetext);
            $this->assertStringNotContainsString('onerror', $attempt->onlinetext);
        }

        $this->assertStringContainsString('safe', $read[0]->onlinetext);
    }

    /**
     * Believable or refused: a list that is missing, empty, repeats an attempt,
     * is out of order, is endless, or holds an attempt that is itself wrong.
     */
    public function test_a_list_that_cannot_be_believed_is_refused(): void {
        $one = $this->attempt();
        $two = $this->attempt(['attemptnumber' => 1]);
        $badfile = $this->attempt(['attemptnumber' => 1, 'files' => [[
            'area' => 'submission_files', 'filepath' => '/../', 'filename' => 'a.txt',
            'contenthash' => sha1('a'), 'filesize' => 1, 'timemodified' => 1,
        ]]]);
        $cases = [
            'missing' => ['username' => 'sam'] + $two,
            'not a list' => ['username' => 'sam'] + $two + ['attempts' => 'all of them'],
            'empty' => array_merge($this->student([$one]), ['attempts' => []]),
            'repeated' => $this->student([$one, $one]),
            'out of order' => $this->student([$two, $one]),
            'an attempt without a fingerprint' => $this->student([$one, array_diff_key($two, ['fingerprint' => 1])]),
            'an attempt with a bad file' => $this->student([$one, $badfile]),
            'endless' => $this->student(array_map(
                fn($n) => $this->attempt(['attemptnumber' => $n]),
                range(0, submissions_result::MAX_ATTEMPTS)
            )),
        ];

        foreach ($cases as $name => $student) {
            $result = submissions_result::from_response($this->answer($student), true);

            $this->assertFalse($result->success, $name);
            $this->assertSame('errorbadresponse', $result->errorkey, $name);
        }

        // The longest history that is believed.
        $longest = submissions_result::from_response($this->answer($this->student(array_map(
            fn($n) => $this->attempt(['attemptnumber' => $n]),
            range(0, submissions_result::MAX_ATTEMPTS - 1)
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

            $this->assertFalse(submissions_result::from_response($this->answer($student), $all)->success);
        }
    }
}
