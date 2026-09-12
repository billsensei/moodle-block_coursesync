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
 * Tests activity_lookup - the Phase 3 change-detection logic that
 * block_coursesync_get_modified_activities is built on.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(activity_lookup::class)]
final class activity_lookup_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * since=0 must return every activity in the course, and since=(a time
     * after everything) must return none - the two ends of the range every
     * other case in this class sits between.
     */
    public function test_since_zero_returns_everything_and_far_future_returns_nothing(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'A page']);
        $this->getDataGenerator()->create_module('label', ['course' => $course->id]);

        $all = activity_lookup::get_modified_since($course->id, 0);
        $this->assertCount(2, $all);

        $none = activity_lookup::get_modified_since($course->id, time() + DAYSECS);
        $this->assertCount(0, $none);
    }

    /**
     * Precise scoping: an activity modified before $since is excluded, one
     * modified after it is included - not just "everything" or "nothing".
     */
    public function test_only_activities_modified_after_since_are_returned(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $old = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Old page']);
        $DB->set_field('page', 'timemodified', 1000, ['id' => $old->id]);

        $new = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'New page']);
        $DB->set_field('page', 'timemodified', 2000, ['id' => $new->id]);

        $since1500 = activity_lookup::get_modified_since($course->id, 1500);

        $this->assertCount(1, $since1500);
        $this->assertSame('New page', $since1500[0]['name']);
        $this->assertSame(2000, $since1500[0]['timemodified']);
    }

    /**
     * Results are scoped to the requested course - an activity in a
     * different course must never appear.
     */
    public function test_scoped_to_the_requested_course_only(): void {
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->create_module('page', ['course' => $course1->id, 'name' => 'In course 1']);
        $this->getDataGenerator()->create_module('page', ['course' => $course2->id, 'name' => 'In course 2']);

        $results = activity_lookup::get_modified_since($course1->id, 0);

        $this->assertCount(1, $results);
        $this->assertSame('In course 1', $results[0]['name']);
    }

    /**
     * The fallback itself: get_timemodified() (protected - reached through
     * get_modified_since() in every other test in this class) returns null
     * for a table with no timemodified column, rather than erroring.
     *
     * None of the five v1 activity tables (page/url/label/resource/forum)
     * actually lack this column, so this exercises the fallback branch
     * directly, against a real core table (block) that does lack it,
     * rather than relying on a real activity type that may not exist.
     * get_modified_since()'s own "|| (int) $cm->added" fallback (what
     * actually happens when this returns null for a real activity) is
     * straightforward enough not to need its own separate test on top of
     * this - there's no real activity type available to exercise it
     * end-to-end honestly.
     */
    public function test_get_timemodified_returns_null_for_a_table_without_that_column(): void {
        global $DB;

        $this->assertArrayNotHasKey('timemodified', $DB->get_columns('block'));
        $anyblockid = $DB->get_field('block', 'id', [], IGNORE_MULTIPLE);
        $this->assertNotEmpty($anyblockid, 'expected at least one installed block to test against');

        $method = new \ReflectionMethod(activity_lookup::class, 'get_timemodified');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, 'block', $anyblockid));
    }

    /**
     * Metadata only: each result has exactly the fields
     * block_coursesync_get_modified_activities promises, nothing more (no
     * content body has leaked through).
     */
    public function test_results_are_metadata_only(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Metadata check',
            'content' => 'This body must not appear in the result.',
        ]);

        $results = activity_lookup::get_modified_since($course->id, 0);

        $this->assertCount(1, $results);
        $this->assertSame(
            ['cmid', 'modname', 'name', 'idnumber', 'timemodified'],
            array_keys($results[0])
        );
        $this->assertSame('page', $results[0]['modname']);
    }
}
