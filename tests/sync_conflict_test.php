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

use block_coursesync\local\sync_runner;
use block_coursesync\local\stub_remote_client;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests sync_runner's creation and conflict-flagging logic (Phase 4/6) end
 * to end against a real course, using stub_remote_client in place of a
 * live remote site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync_runner::class)]
final class sync_conflict_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Not autoloaded (it lives in tests/fixtures, outside classes/) -
        // loaded here, inside a method, rather than at file scope, so this
        // test file itself stays free of top-level global-state changes.
        require_once(__DIR__ . '/fixtures/stub_remote_client.php');
    }

    /**
     * The central case Phase 6 exists for: an activity that would collide,
     * by idnumber, with something already in the destination course must
     * be flagged as a conflict - never overwritten, never duplicated -
     * regardless of whether that existing activity came from an earlier
     * sync or (as simulated here) a teacher's own manual work.
     */
    public function test_conflicting_activity_is_flagged_not_overwritten_or_duplicated(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $idnumber = sync_runner::make_idnumber(555);

        $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'idnumber' => $idnumber,
            'intro' => 'Manually created by the teacher - must not be touched.',
        ]);

        $client = new stub_remote_client(
            [['cmid' => 555, 'modname' => 'page', 'name' => 'Remote page', 'idnumber' => '', 'timemodified' => 1000]],
            [555 => $this->page_payload()]
        );

        $result = (new sync_runner($client, $course->id, 'irrelevant'))->run(0);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['created']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertSame(555, $result['conflicts'][0]['cmid']);
        $this->assertStringContainsString('Manually created', $result['conflicts'][0]['conflictname']);

        // Exactly one course_module under that idnumber - not duplicated.
        $this->assertEquals(
            1,
            $DB->count_records('course_modules', ['course' => $course->id, 'idnumber' => $idnumber])
        );

        // Still a label, still the original content - not overwritten into a page.
        $cm = $DB->get_record('course_modules', ['course' => $course->id, 'idnumber' => $idnumber], '*', MUST_EXIST);
        $labelmoduleid = $DB->get_field('modules', 'id', ['name' => 'label']);
        $this->assertEquals($labelmoduleid, $cm->module);
        $label = $DB->get_record('label', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('Manually created', $label->intro);
    }

    /**
     * The non-conflicting case: nothing existing under that idnumber, so
     * the activity is actually created.
     */
    public function test_non_conflicting_activity_is_created(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $client = new stub_remote_client(
            [['cmid' => 777, 'modname' => 'page', 'name' => 'Remote page', 'idnumber' => '', 'timemodified' => 1000]],
            [777 => $this->page_payload()]
        );

        $result = (new sync_runner($client, $course->id, 'irrelevant'))->run(0);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['created']);
        $this->assertCount(0, $result['conflicts']);

        $idnumber = sync_runner::make_idnumber(777);
        $this->assertEquals(
            1,
            $DB->count_records('course_modules', ['course' => $course->id, 'idnumber' => $idnumber])
        );
    }

    /**
     * An unsupported type is left alone (not a failure) and caps how far
     * lastsync can advance - it must reappear on the next sync, not be
     * silently skipped forever once a handler for it exists.
     */
    public function test_unsupported_type_caps_new_lastsync(): void {
        $course = $this->getDataGenerator()->create_course();

        $client = new stub_remote_client(
            [['cmid' => 888, 'modname' => 'quiz', 'name' => 'A quiz', 'idnumber' => '', 'timemodified' => 5000]],
            []
        );

        $result = (new sync_runner($client, $course->id, 'irrelevant'))->run(0);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['created']);
        $this->assertCount(0, $result['conflicts']);
        $this->assertCount(1, $result['unsupported']);
        $this->assertSame(4999, $result['newlastsync']);
    }

    /**
     * When everything found was actually handled, lastsync advances all
     * the way to when the run started - not capped at all.
     */
    public function test_lastsync_advances_fully_when_nothing_is_left_behind(): void {
        $course = $this->getDataGenerator()->create_course();

        $client = new stub_remote_client(
            [['cmid' => 999, 'modname' => 'page', 'name' => 'Remote page', 'idnumber' => '', 'timemodified' => 1000]],
            [999 => $this->page_payload()]
        );

        $before = time();
        $result = (new sync_runner($client, $course->id, 'irrelevant'))->run(0);
        $after = time();

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual($before, $result['newlastsync']);
        $this->assertLessThanOrEqual($after, $result['newlastsync']);
    }

    /**
     * A minimal, valid page_activity_exporter-shaped payload for the tests above.
     *
     * @return array
     */
    protected function page_payload(): array {
        return [
            'name' => 'Remote page',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'content' => 'Remote content.',
            'contentformat' => FORMAT_HTML,
            'display' => 0,
            'printintro' => 1,
            'printlastmodified' => 1,
        ];
    }
}
