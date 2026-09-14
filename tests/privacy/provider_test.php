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

namespace block_coursesync\privacy;

use block_coursesync\local\sync_history;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the block_coursesync privacy provider: block_coursesync_synclog is
 * the plugin's only personal data (see sync_history::record()) and, unlike
 * most per-block-instance providers, more than one user can have rows in
 * the same block context (any user with block/coursesync:sync can trigger
 * a sync) - so these tests specifically pin down that one user's export/
 * deletion never touches another user's rows in that same context.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Adds a real coursesync block instance to a course, the same way
     * token_encryption_test.php does, and returns its own CONTEXT_BLOCK context.
     *
     * @return array{0: \block_coursesync, 1: \context_block}
     */
    protected function add_block_instance(): array {
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $page = new \moodle_page();
        $page->set_context($coursecontext);
        $page->set_course($course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view');
        $page->blocks->add_region('side-pre');
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*', null);

        global $DB;
        $bi = $DB->get_record(
            'block_instances',
            ['blockname' => 'coursesync', 'parentcontextid' => $coursecontext->id],
            '*',
            MUST_EXIST
        );

        $block = block_instance('coursesync', $bi);

        return [$block, \context_block::instance($bi->id)];
    }

    /**
     * A minimal successful sync_runner::run()-shaped result, since only
     * sync_history::record()'s own field mapping is under test here, not
     * sync_runner itself.
     *
     * @return array
     */
    protected function fake_result(): array {
        return [
            'success' => true, 'errorcode' => null, 'technical' => null,
            'since' => 0, 'newlastsync' => 100,
            'created' => [['cmid' => 1]], 'conflicts' => [], 'unsupported' => [], 'failed' => [],
        ];
    }

    public function test_get_contexts_for_userid_only_returns_contexts_with_that_users_rows(): void {
        [$block, $context] = $this->add_block_instance();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user1->id, $this->fake_result());

        $contextlist = provider::get_contexts_for_userid($user1->id);
        $this->assertCount(1, $contextlist);
        $this->assertEquals($context->id, $contextlist->current()->id);

        // User2 has never synced from this instance.
        $emptylist = provider::get_contexts_for_userid($user2->id);
        $this->assertCount(0, $emptylist);
    }

    public function test_get_users_in_context_returns_every_user_with_a_row(): void {
        [$block, $context] = $this->add_block_instance();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user1->id, $this->fake_result());
        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user2->id, $this->fake_result());

        $userlist = new userlist($context, 'block_coursesync');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing([$user1->id, $user2->id], $userlist->get_userids());
    }

    public function test_export_user_data_exports_only_that_users_rows(): void {
        [$block, $context] = $this->add_block_instance();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user1->id, $this->fake_result());
        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user1->id, $this->fake_result());
        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user2->id, $this->fake_result());

        $approvedlist = new approved_contextlist($user1, 'block_coursesync', [$context->id]);
        provider::export_user_data($approvedlist);

        $data = writer::with_context($context)->get_data([]);
        $this->assertNotEmpty($data);
        $this->assertCount(2, $data->synclogs);
        foreach ($data->synclogs as $synclog) {
            $this->assertSame('Yes', $synclog->success);
            $this->assertSame((int) $block->instance->id, $synclog->courseid);
        }
    }

    public function test_delete_data_for_user_leaves_other_users_rows_in_the_same_context(): void {
        global $DB;

        [$block, $context] = $this->add_block_instance();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user1->id, $this->fake_result());
        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user2->id, $this->fake_result());

        $approvedlist = new approved_contextlist($user1, 'block_coursesync', [$context->id]);
        provider::delete_data_for_user($approvedlist);

        $remaining = $DB->get_records('block_coursesync_synclog', ['blockinstanceid' => $block->instance->id]);
        $this->assertCount(1, $remaining);
        $this->assertSame((int) $user2->id, (int) reset($remaining)->userid);
    }

    public function test_delete_data_for_users_removes_only_the_listed_users(): void {
        global $DB;

        [$block, $context] = $this->add_block_instance();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user1->id, $this->fake_result());
        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user2->id, $this->fake_result());
        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user3->id, $this->fake_result());

        $approvedusers = new approved_userlist($context, 'block_coursesync', [$user1->id, $user2->id]);
        provider::delete_data_for_users($approvedusers);

        $remaining = $DB->get_records('block_coursesync_synclog', ['blockinstanceid' => $block->instance->id]);
        $this->assertCount(1, $remaining);
        $this->assertSame((int) $user3->id, (int) reset($remaining)->userid);
    }

    public function test_delete_data_for_all_users_in_context_removes_every_row(): void {
        global $DB;

        [$block, $context] = $this->add_block_instance();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user1->id, $this->fake_result());
        sync_history::record((int) $block->instance->id, (int) $block->instance->id, $user2->id, $this->fake_result());

        provider::delete_data_for_all_users_in_context($context);

        $remaining = $DB->get_records('block_coursesync_synclog', ['blockinstanceid' => $block->instance->id]);
        $this->assertCount(0, $remaining);
    }
}
