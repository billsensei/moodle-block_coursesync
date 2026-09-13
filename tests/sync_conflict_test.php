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
     * An activity of the same type and name already sitting in the
     * destination course - e.g. because the course was built from a backup
     * of the source, or a teacher independently created equivalent content -
     * must be excluded, even though nothing shares its idnumber: it isn't
     * flagged as a conflict, isn't created a second time, and isn't counted
     * in any part of the result.
     */
    public function test_same_name_and_type_activity_is_silently_excluded(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Remote page',
        ]);

        $client = new stub_remote_client(
            [['cmid' => 666, 'modname' => 'page', 'name' => 'Remote page', 'idnumber' => '', 'timemodified' => 1000]],
            [666 => $this->page_payload()]
        );

        $result = (new sync_runner($client, $course->id, 'irrelevant'))->run(0);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['created']);
        $this->assertCount(0, $result['conflicts']);
        $this->assertCount(0, $result['unsupported']);
        $this->assertCount(0, $result['failed']);

        // Still only the one, original page - nothing pulled in alongside it.
        $this->assertEquals(1, $DB->count_records('page', ['course' => $course->id]));

        $idnumber = sync_runner::make_idnumber(666);
        $this->assertEquals(
            0,
            $DB->count_records('course_modules', ['course' => $course->id, 'idnumber' => $idnumber])
        );
    }

    /**
     * The match is by name AND type together - an existing activity of a
     * different type under the same name must not suppress the pull.
     */
    public function test_same_name_but_different_type_is_still_created(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'name' => 'Remote page',
        ]);

        $client = new stub_remote_client(
            [['cmid' => 111, 'modname' => 'page', 'name' => 'Remote page', 'idnumber' => '', 'timemodified' => 1000]],
            [111 => $this->page_payload()]
        );

        $result = (new sync_runner($client, $course->id, 'irrelevant'))->run(0);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['created']);
        $this->assertCount(0, $result['conflicts']);

        $idnumber = sync_runner::make_idnumber(111);
        $this->assertEquals(
            1,
            $DB->count_records('course_modules', ['course' => $course->id, 'idnumber' => $idnumber])
        );
    }

    /**
     * The name comparison ignores case and surrounding whitespace, so minor
     * formatting differences don't defeat the match.
     */
    public function test_same_name_match_ignores_case_and_whitespace(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => '  Remote PAGE  ',
        ]);

        $client = new stub_remote_client(
            [['cmid' => 222, 'modname' => 'page', 'name' => 'remote page', 'idnumber' => '', 'timemodified' => 1000]],
            [222 => $this->page_payload()]
        );

        $result = (new sync_runner($client, $course->id, 'irrelevant'))->run(0);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['created']);
        $this->assertCount(0, $result['conflicts']);
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
     * A pulled activity must land in the destination section with the same
     * relative number it had on the source - not always section 0 - and
     * that destination section must be created first if the destination
     * course doesn't have enough sections yet.
     */
    public function test_created_activity_lands_in_the_corresponding_section(): void {
        global $DB;

        // Only sections 0 and 1 exist here - section 4 doesn't yet.
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);

        $client = new stub_remote_client(
            [['cmid' => 444, 'modname' => 'page', 'name' => 'Remote page', 'idnumber' => '',
                'timemodified' => 1000, 'section' => 4]],
            [444 => $this->page_payload()]
        );

        $result = (new sync_runner($client, $course->id, 'irrelevant'))->run(0);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['created']);

        $idnumber = sync_runner::make_idnumber(444);
        $cm = $DB->get_record('course_modules', ['course' => $course->id, 'idnumber' => $idnumber], '*', MUST_EXIST);

        // Course_modules.section is a course_sections.id foreign key, not the
        // relative section number itself - resolve it to confirm the actual
        // destination section number, not just that some section was used.
        $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);
        $this->assertSame(4, (int) $section->section);
    }

    /**
     * A source running an older plugin version won't include 'section' in
     * its get_modified_activities response - that must fall back to the
     * pre-existing "always section 0" behaviour, not a missing-key error.
     */
    public function test_missing_section_field_falls_back_to_section_zero(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $client = new stub_remote_client(
            [['cmid' => 333, 'modname' => 'page', 'name' => 'Remote page', 'idnumber' => '', 'timemodified' => 1000]],
            [333 => $this->page_payload()]
        );

        $result = (new sync_runner($client, $course->id, 'irrelevant'))->run(0);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['created']);

        $idnumber = sync_runner::make_idnumber(333);
        $cm = $DB->get_record('course_modules', ['course' => $course->id, 'idnumber' => $idnumber], '*', MUST_EXIST);
        $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);
        $this->assertSame(0, (int) $section->section);
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
