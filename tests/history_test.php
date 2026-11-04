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

/**
 * Tests for the record of past sync runs.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(history::class)]
final class history_test extends advanced_testcase {
    /** @var int A stand-in block instance id. */
    public const INSTANCE = 4242;

    /**
     * A result with one activity of each outcome.
     *
     * @return sync_result
     */
    protected function mixed_result(): sync_result {
        $result = new sync_result();
        $result->since = 1750000000;
        $result->lastsyncupdated = true;
        $result->add_created('Copied page', 'page', 11, 101, ['syncfilewarning']);
        $result->add_conflict('Blocked page', 'page', 12, 102, 'conflictlocalactivity');
        $result->add_skipped('A quiz', 'quiz', 13, 'syncskippedtype');
        $result->add_failed('Broken page', 'page', 14, 'errorcreatefailed');

        return $result;
    }

    /**
     * A run is stored with its counts and its lists.
     */
    public function test_record_stores_the_whole_run(): void {
        $this->resetAfterTest();

        $id = history::record(self::INSTANCE, 7, 3, 1750000000, $this->mixed_result());

        $this->assertGreaterThan(0, $id);

        $runs = history::get_runs(self::INSTANCE);

        $this->assertCount(1, $runs);
        $run = $runs[0];

        $this->assertSame(7, (int) $run->courseid);
        $this->assertSame(3, (int) $run->userid);
        $this->assertSame(1750000000, (int) $run->timestarted);
        $this->assertSame(1, (int) $run->lastsyncmoved);
        $this->assertSame(1, (int) $run->pulledcount);
        $this->assertSame(1, (int) $run->conflictcount);
        $this->assertSame(1, (int) $run->skippedcount);
        $this->assertSame(1, (int) $run->failedcount);
    }

    /**
     * Each activity is filed under the right list, with what it needs to be
     * understood later.
     */
    public function test_activities_are_filed_by_outcome(): void {
        $this->resetAfterTest();

        history::record(self::INSTANCE, 7, 3, 1750000000, $this->mixed_result());
        $run = history::get_runs(self::INSTANCE)[0];

        $this->assertCount(1, $run->pulled);
        $this->assertSame('Copied page', $run->pulled[0]['name']);
        $this->assertSame(101, (int) $run->pulled[0]['localcmid']);
        $this->assertSame(['syncfilewarning'], $run->pulled[0]['notes']);

        $this->assertCount(1, $run->conflicts);
        $this->assertSame('conflictlocalactivity', $run->conflicts[0]['detail']);

        // Skipped and failed share the "everything else" list.
        $this->assertCount(2, $run->others);
        $this->assertSame(
            ['skipped', 'failed'],
            array_column($run->others, 'outcome')
        );
    }

    /**
     * A run that flagged something is marked as needing review.
     */
    public function test_status_reflects_what_happened(): void {
        $this->resetAfterTest();

        $clean = new sync_result();
        $clean->add_created('One', 'page', 11, 101);
        history::record(self::INSTANCE, 7, 3, 1, $clean);

        $flagged = new sync_result();
        $flagged->add_conflict('Two', 'page', 12, 102, 'conflictlocalactivity');
        history::record(self::INSTANCE, 7, 3, 2, $flagged);

        $broken = sync_result::failure('errornotmapped');
        history::record(self::INSTANCE, 7, 3, 3, $broken);

        $runs = history::get_runs(self::INSTANCE);

        // Newest first.
        $this->assertSame(history::STATUS_FAILED, $runs[0]->status);
        $this->assertSame(history::STATUS_REVIEW, $runs[1]->status);
        $this->assertSame(history::STATUS_OK, $runs[2]->status);
    }

    /**
     * Runs come back newest first, which is the order the history page shows.
     */
    public function test_runs_are_returned_newest_first(): void {
        $this->resetAfterTest();

        foreach ([100, 300, 200] as $time) {
            $result = new sync_result();
            $result->add_created('At ' . $time, 'page', $time, $time, []);
            history::record(self::INSTANCE, 7, 3, $time, $result);
        }

        $runs = history::get_runs(self::INSTANCE);

        $this->assertSame([300, 200, 100], array_map(static fn($r) => (int) $r->timestarted, $runs));
    }

    /**
     * History is per block instance; another block's runs are not mixed in.
     */
    public function test_runs_are_scoped_to_the_block_instance(): void {
        $this->resetAfterTest();

        $result = new sync_result();
        $result->add_created('Ours', 'page', 11, 101);
        history::record(self::INSTANCE, 7, 3, 1750000000, $result);

        $other = new sync_result();
        $other->add_created('Theirs', 'page', 22, 202);
        history::record(self::INSTANCE + 1, 8, 3, 1750000000, $other);

        $this->assertCount(1, history::get_runs(self::INSTANCE));
        $this->assertSame('Ours', history::get_runs(self::INSTANCE)[0]->pulled[0]['name']);
    }

    /**
     * The history is what tells a conflict whether this plugin created the local
     * copy, so the lookup has to match on both ends of the pair.
     */
    public function test_was_pulled_here(): void {
        $this->resetAfterTest();

        $result = new sync_result();
        $result->add_created('Copied page', 'page', 11, 101);
        history::record(self::INSTANCE, 7, 3, 1750000000, $result);

        $this->assertTrue(history::was_pulled_here(self::INSTANCE, 11, 101));

        // Same remote activity, different local copy: not the one we made.
        $this->assertFalse(history::was_pulled_here(self::INSTANCE, 11, 999));
        // Same local activity, different remote origin.
        $this->assertFalse(history::was_pulled_here(self::INSTANCE, 999, 101));
        // A different block's history does not answer for this one.
        $this->assertFalse(history::was_pulled_here(self::INSTANCE + 1, 11, 101));
    }

    /**
     * Something only ever flagged was never pulled here.
     */
    public function test_a_flagged_activity_does_not_count_as_pulled(): void {
        $this->resetAfterTest();

        $result = new sync_result();
        $result->add_conflict('Blocked page', 'page', 11, 101, 'conflictlocalactivity');
        history::record(self::INSTANCE, 7, 3, 1750000000, $result);

        $this->assertFalse(history::was_pulled_here(self::INSTANCE, 11, 101));
    }

    /**
     * Removing a block instance takes its history with it, and leaves other
     * blocks' history alone.
     */
    public function test_delete_for_block_instance(): void {
        $this->resetAfterTest();

        $result = new sync_result();
        $result->add_created('One', 'page', 11, 101);
        history::record(self::INSTANCE, 7, 3, 1750000000, $result);
        history::record(self::INSTANCE + 1, 8, 3, 1750000000, $result);

        history::delete_for_block_instance(self::INSTANCE);

        $this->assertCount(0, history::get_runs(self::INSTANCE));
        $this->assertCount(1, history::get_runs(self::INSTANCE + 1));
    }

    /**
     * An empty history is an empty list rather than an error.
     */
    public function test_no_runs_yet(): void {
        $this->resetAfterTest();

        $this->assertSame([], history::get_runs(self::INSTANCE));
        $this->assertFalse(history::was_pulled_here(self::INSTANCE, 11, 101));
    }

    /**
     * A pull of every attempt says so in its record, so the history does not
     * read its counts - of attempts - as students; and keeps counts only.
     */
    public function test_a_pull_of_every_attempt_says_so_in_its_record(): void {
        global $DB;

        $this->resetAfterTest();

        foreach ([false, true] as $every) {
            $result = new grade_pull_result();
            $result->everyattempt = $every;
            $result->add((object) [
                'kind' => grade_pull_result::KIND_SUBMISSION, 'cmid' => 7, 'activity' => 'Essay', 'itemnumber' => 0,
                'gradeitemid' => 0, 'userid' => 3, 'username' => 'sam', 'outcome' => grade_pull_result::ADD,
                'reason' => null, 'grade' => null, 'localgrade' => null, 'attempt' => 1, 'files' => 0,
            ]);

            $id = history::record_grade_pull(self::INSTANCE, 2, 9, time(), $result, history::KIND_SUBMISSIONS);
            $row = $DB->get_record('block_coursesync_run', ['id' => $id], '*', MUST_EXIST);
            $activities = json_decode($row->pulled, true);

            $this->assertSame(1, (int) $row->pulledcount);
            $this->assertCount(1, $activities);
            $this->assertSame($every, !empty($activities[0]['every']));
            // Counts only: not who, and not which attempt.
            $this->assertStringNotContainsString('"sam"', $row->pulled);
            $this->assertStringNotContainsString('attempt', $row->pulled);
        }
    }

    /**
     * Warnings are things to say about a pull, not outcomes: they are not
     * counted, and a pull has none to begin with.
     */
    public function test_warnings_are_not_outcomes(): void {
        $result = new grade_pull_result();

        $this->assertSame([], $result->warnings);
        $this->assertFalse($result->everyattempt);

        $result->warn('submissionswarnmaxattempts', (object) ['activity' => 'Essay', 'attempts' => 3, 'allowed' => 2]);

        $this->assertCount(1, $result->warnings);
        $this->assertSame('submissionswarnmaxattempts', $result->warnings[0]->key);
        $this->assertSame(0, $result->changes());
        $this->assertSame(array_fill_keys(grade_pull_result::OUTCOMES, 0), $result->counts());
    }
}
