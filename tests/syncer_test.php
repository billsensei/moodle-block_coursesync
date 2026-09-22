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
 * Tests for the parts of a sync run that do not need a remote site.
 *
 * The full pull-and-rebuild path is proven between two live servers; what is
 * pinned here is the bookkeeping that decides whether an activity is new, and
 * whether the last synced marker is allowed to move.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(syncer::class)]
#[CoversClass(sync_result::class)]
final class syncer_test extends advanced_testcase {
    /**
     * The marker written on a synced activity is derived from the remote id.
     */
    public function test_idnumber_is_derived_from_the_remote_id(): void {
        $this->resetAfterTest();

        $this->assertSame('coursesync-42', syncer::build_idnumber(42));
        $this->assertNotSame(syncer::build_idnumber(42), syncer::build_idnumber(43));
    }

    /**
     * An activity already pulled into this course is recognised, and one pulled
     * into a different course is not mistaken for it.
     */
    public function test_find_existing_is_scoped_to_the_course(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $this->assertSame(0, syncer::find_existing($course->id, 42));

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(42), ['id' => $page->cmid]);

        $this->assertSame((int) $page->cmid, syncer::find_existing($course->id, 42));
        $this->assertSame(0, syncer::find_existing($other->id, 42));
        $this->assertSame(0, syncer::find_existing($course->id, 43));
    }

    /**
     * A module only flagged deletioninprogress = 1 - the state the
     * standard "Delete" action leaves behind while its adhoc task is still
     * waiting to run - is treated the same as already gone, so a teacher
     * does not have to wait for cron before syncing the activity back.
     */
    public function test_find_existing_ignores_a_module_pending_async_deletion(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(42), ['id' => $page->cmid]);

        $this->assertSame((int) $page->cmid, syncer::find_existing($course->id, 42));

        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $page->cmid]);

        $this->assertSame(0, syncer::find_existing($course->id, 42));
    }

    /**
     * A run that created everything it tried to is clean.
     */
    public function test_result_is_clean_when_nothing_failed(): void {
        $this->resetAfterTest();

        $result = new sync_result();
        $result->add_created('One', 'page', 11, 101);
        $result->add_skipped('Two', 'forum', 12, 'syncskippedtype');

        $this->assertTrue($result->is_clean());
        $this->assertSame(1, $result->count('created'));
        $this->assertSame(1, $result->count('skipped'));
        $this->assertSame(0, $result->count('failed'));
        $this->assertStringContainsString('1', $result->get_message());
    }

    /**
     * A single failure makes the whole run untrustworthy, which is what stops
     * the last synced marker from moving past the activity that failed.
     */
    public function test_result_is_not_clean_when_something_failed(): void {
        $this->resetAfterTest();

        $result = new sync_result();
        $result->add_created('One', 'page', 11, 101);
        $result->add_failed('Two', 'page', 12, 'errorcreatefailed');

        $this->assertFalse($result->is_clean());
        $this->assertSame(1, $result->count('failed'));
    }

    /**
     * A run that could not start at all reports why.
     */
    public function test_failed_run(): void {
        $this->resetAfterTest();

        $result = sync_result::failure('errornotmapped');

        $this->assertFalse($result->success);
        $this->assertFalse($result->is_clean());
        $this->assertNotEmpty($result->get_message());
    }

    /**
     * A sync cannot run before a course is mapped.
     */
    public function test_run_refuses_without_a_mapping(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $result = syncer::run(4242, $course->id);

        $this->assertFalse($result->success);
        $this->assertSame('errornotmapped', $result->errorkey);
    }

    /**
     * The last synced marker can be written and read back. Phase 3 deliberately
     * never wrote it; from phase 4 a successful run does.
     */
    public function test_last_sync_round_trips(): void {
        $this->resetAfterTest();

        connection::set_url(4242, 7, 'https://source.example.edu');
        connection::set_token(4242, 'abcdef0123456789abcdef0123456789');

        $this->assertNull(connection::get_last_sync(4242));

        connection::set_last_sync(4242, 1750000000);

        $this->assertSame(1750000000, connection::get_last_sync(4242));
    }

    /**
     * Every reason a sync item can carry has a message behind it.
     */
    public function test_every_outcome_reason_has_a_string(): void {
        $this->resetAfterTest();

        $reasons = [
            'syncskippedtype',
            'conflictlocalactivity',
            'conflictchangedupstream',
            'errorcreatefailed',
            'errorunsupportedtype',
            'errorwronghandler',
            'erroractivitynotfound',
            'errornopackage',
            'syncskippeddeselected',
            'syncskippedpresent',
        ];

        foreach ($reasons as $reason) {
            $this->assertNotEmpty(get_string($reason, 'block_coursesync'), "Missing string: {$reason}");
        }
    }
}
