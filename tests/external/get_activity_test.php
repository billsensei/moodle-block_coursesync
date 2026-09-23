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

namespace block_coursesync\external;

use advanced_testcase;
use block_coursesync\local\handler\handler_registry;
use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for exporting a full activity payload from the source site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_activity::class)]
final class get_activity_test extends advanced_testcase {
    /**
     * A page comes back with everything needed to rebuild it.
     */
    public function test_export_page(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Week 1 Notes',
            'intro' => '<p>What this page covers.</p>',
            'introformat' => FORMAT_HTML,
            'content' => '<h2>Reading list</h2><p>Chapter one.</p>',
            'contentformat' => FORMAT_HTML,
            'section' => 2,
        ]);

        $result = external_api::clean_returnvalue(
            get_activity::execute_returns(),
            get_activity::execute($page->cmid)
        );

        $this->assertSame((int) $page->cmid, $result['cmid']);
        $this->assertSame('page', $result['modname']);
        $this->assertSame('Week 1 Notes', $result['name']);
        $this->assertSame(2, $result['sectionnum']);
        $this->assertTrue($result['visible']);
        $this->assertStringContainsString('What this page covers', $result['intro']);

        $settings = array_column($result['settings'], 'value', 'name');

        $this->assertStringContainsString('Reading list', $settings['content']);
        $this->assertSame((string) FORMAT_HTML, $settings['contentformat']);
        $this->assertArrayHasKey('display', $settings);
        $this->assertArrayHasKey('printintro', $settings);
        $this->assertArrayHasKey('printlastmodified', $settings);
    }

    /**
     * The serialized display options are unpacked into plain values, so the
     * destination never has to understand another module's storage format.
     */
    public function test_display_options_are_unpacked(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $DB->set_field('page', 'displayoptions', serialize([
            'printintro' => 1,
            'printlastmodified' => 0,
            'popupwidth' => 800,
            'popupheight' => 600,
        ]), ['id' => $page->id]);

        $result = get_activity::execute($page->cmid);
        $settings = array_column($result['settings'], 'value', 'name');

        $this->assertSame('1', $settings['printintro']);
        $this->assertSame('0', $settings['printlastmodified']);
        $this->assertSame('800', $settings['popupwidth']);
        $this->assertSame('600', $settings['popupheight']);
    }

    /**
     * An activity type with no handler is refused rather than half-exported.
     */
    public function test_unsupported_type_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        // Something installed here with no handler. Every type in standard
        // Moodle has one now, so this needs a third-party module; without one
        // there is nothing real to refuse, and saying so beats passing vacuously.
        $modname = handler_registry::first_unsupported_modname();

        if ($modname === null) {
            $this->markTestSkipped('Every installed activity type has a handler; this needs a third-party module installed.');
        }

        $module = $this->getDataGenerator()->create_module($modname, ['course' => $course->id]);

        try {
            get_activity::execute($module->cmid);
            $this->fail('Expected a moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorunsupportedtype', $e->errorcode);
        }
    }

    /**
     * A sync account set up the documented way can read every type this plugin
     * supports, including the ones an unenrolled account cannot normally view.
     *
     * This is the check that would have caught assignments, quizzes and wikis
     * being listed as available and then refused when they were asked for. Those
     * three need a module-level view capability that a sync account has no
     * reason to hold, so what authorises the read is block/coursesync:sync on
     * the course, exactly as it is for the listing.
     */
    public function test_a_sync_account_can_read_every_supported_type(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $cmids = [];

        foreach (handler_registry::supported_modnames() as $modname) {
            $module = $this->getDataGenerator()->create_module($modname, ['course' => $course->id]);
            $cmids[$modname] = (int) $module->cmid;
        }

        // A sync account as the documentation describes it: not enrolled, and
        // holding only the two permissions the setup guide asks for.
        $syncuser = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'coursesyncsource']);

        assign_capability('block/coursesync:sync', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $syncuser->id, \context_system::instance()->id);

        $this->setUser($syncuser);

        foreach ($cmids as $modname => $cmid) {
            $exported = get_activity::execute($cmid);

            $this->assertSame($modname, $exported['modname'], "{$modname} could not be read");
            $this->assertSame($cmid, $exported['cmid']);
        }
    }

    /**
     * An account without the sync permission is still refused, so reading the
     * course rather than the activity did not open this up to anyone else.
     */
    public function test_an_account_without_the_sync_permission_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);

        get_activity::execute($page->cmid);
    }

    /**
     * An activity that no longer exists is refused.
     */
    public function test_missing_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        try {
            get_activity::execute(-1);
            $this->fail('Expected a moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('erroractivitynotfound', $e->errorcode);
        }
    }

    /**
     * An activity awaiting deletion is treated as gone.
     */
    public function test_activity_being_deleted(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $page->cmid]);

        $this->expectException(\moodle_exception::class);
        get_activity::execute($page->cmid);
    }

    /**
     * The sync permission is required, in the course the activity belongs to.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        get_activity::execute($page->cmid);
    }

    /**
     * A hidden activity reports itself as hidden, so the copy can match.
     */
    public function test_hidden_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'visible' => 0,
        ]);

        $result = get_activity::execute($page->cmid);

        $this->assertFalse($result['visible']);
    }
}
