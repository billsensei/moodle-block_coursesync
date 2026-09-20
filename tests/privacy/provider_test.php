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

use block_coursesync\history;
use block_coursesync\sync_result;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for what this plugin reports and deletes about people.
 *
 * The plugin was a null provider until the sync history started recording who
 * started each run. These tests are what stops it drifting back to claiming it
 * holds nothing.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var \stdClass The course holding the block. */
    protected \stdClass $course;

    /** @var int The block instance id. */
    protected int $instanceid = 0;

    /** @var \context_block The block's context. */
    protected \context_block $blockcontext;

    /**
     * Put a Course Sync block in a course.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();
        $this->setAdminUser();

        global $DB;

        $this->course = $this->getDataGenerator()->create_course();

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($this->course->id));
        $page->set_course($this->course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $this->course->format);
        $page->set_url('/course/view.php', ['id' => $this->course->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');

        $instance = $DB->get_record('block_instances', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($this->course->id)->id,
        ], '*', MUST_EXIST);

        $this->instanceid = (int) $instance->id;
        $this->blockcontext = \context_block::instance($this->instanceid);
    }

    /**
     * Record a run started by a user.
     *
     * @param int $userid
     * @param int $time
     * @return void
     */
    protected function record_run(int $userid, int $time): void {
        $result = new sync_result();
        $result->add_created('Week 1 Notes', 'page', 11, 101);

        history::record($this->instanceid, $this->course->id, $userid, $time, $result);
    }

    /**
     * The plugin says what it stores, rather than claiming it stores nothing.
     */
    public function test_metadata_is_declared(): void {
        $collection = new \core_privacy\local\metadata\collection('block_coursesync');
        $items = provider::get_metadata($collection)->get_collection();

        $this->assertNotEmpty($items);

        $tables = array_map(static fn($item) => $item->get_name(), $items);
        $this->assertContains('block_coursesync_run', $tables);
    }

    /**
     * A user who started a run has data in that block's context.
     */
    public function test_contexts_for_user(): void {
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->record_run($user->id, 1750000000);

        $contexts = provider::get_contexts_for_userid($user->id)->get_contextids();
        // Context ids come back from the database as strings.
        $this->assertSame([(int) $this->blockcontext->id], array_map('intval', array_values($contexts)));

        // Someone who never ran a sync has nothing here.
        $this->assertEmpty(provider::get_contexts_for_userid($other->id)->get_contextids());
    }

    /**
     * The users in a context are the ones who ran syncs there.
     */
    public function test_users_in_context(): void {
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $none = $this->getDataGenerator()->create_user();

        $this->record_run($one->id, 1750000000);
        $this->record_run($two->id, 1750000100);

        $userlist = new userlist($this->blockcontext, 'block_coursesync');
        provider::get_users_in_context($userlist);

        $found = $userlist->get_userids();

        $this->assertContains((int) $one->id, $found);
        $this->assertContains((int) $two->id, $found);
        $this->assertNotContains((int) $none->id, $found);
    }

    /**
     * A user's runs are exported, and another user's are not.
     */
    public function test_export_user_data(): void {
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->record_run($user->id, 1750000000);
        $this->record_run($other->id, 1750000100);

        $contextlist = new approved_contextlist($user, 'block_coursesync', [$this->blockcontext->id]);
        provider::export_user_data($contextlist);

        $writer = writer::with_context($this->blockcontext);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([get_string('privacy:path:runs', 'block_coursesync')]);

        $this->assertCount(1, $data->runs);
        $this->assertSame(1, (int) $data->runs[0]['pulledcount']);
    }

    /**
     * Deleting everything in a context leaves no runs behind.
     */
    public function test_delete_all_in_context(): void {
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();

        $this->record_run($one->id, 1750000000);
        $this->record_run($two->id, 1750000100);

        $this->assertCount(2, history::get_runs($this->instanceid));

        provider::delete_data_for_all_users_in_context($this->blockcontext);

        $this->assertCount(0, history::get_runs($this->instanceid));
    }

    /**
     * Deleting one user's data leaves everyone else's alone.
     */
    public function test_delete_for_one_user(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->record_run($user->id, 1750000000);
        $this->record_run($other->id, 1750000100);

        $contextlist = new approved_contextlist($user, 'block_coursesync', [$this->blockcontext->id]);
        provider::delete_data_for_user($contextlist);

        $remaining = history::get_runs($this->instanceid);

        $this->assertCount(1, $remaining);
        $this->assertSame((int) $other->id, (int) $remaining[0]->userid);
        $this->assertSame(0, $DB->count_records('block_coursesync_run', ['userid' => $user->id]));
    }

    /**
     * Deleting a named list of users removes exactly those.
     */
    public function test_delete_for_users(): void {
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $three = $this->getDataGenerator()->create_user();

        $this->record_run($one->id, 1750000000);
        $this->record_run($two->id, 1750000100);
        $this->record_run($three->id, 1750000200);

        $userlist = new approved_userlist($this->blockcontext, 'block_coursesync', [$one->id, $three->id]);
        provider::delete_data_for_users($userlist);

        $remaining = history::get_runs($this->instanceid);

        $this->assertCount(1, $remaining);
        $this->assertSame((int) $two->id, (int) $remaining[0]->userid);
    }

    /**
     * A context that is not a block context is ignored rather than acted on.
     */
    public function test_other_context_levels_are_ignored(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->record_run($user->id, 1750000000);

        provider::delete_data_for_all_users_in_context(\context_course::instance($this->course->id));

        $this->assertCount(1, history::get_runs($this->instanceid));
    }
}
