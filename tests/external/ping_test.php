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
use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the source-side ping external function.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(ping::class)]
final class ping_test extends advanced_testcase {
    /**
     * A caller holding the capability gets the site's details back.
     */
    public function test_ping_returns_site_details(): void {
        global $CFG, $SITE;

        $this->resetAfterTest();
        $this->setAdminUser();

        $result = ping::execute();
        $result = external_api::clean_returnvalue(ping::execute_returns(), $result);

        $this->assertTrue($result['status']);
        $this->assertSame($SITE->fullname, $result['sitename']);
        $this->assertSame($CFG->release, $result['release']);
        $this->assertGreaterThan(0, $result['pluginversion']);
    }

    /**
     * A caller without the capability is refused.
     */
    public function test_ping_requires_the_sync_capability(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        ping::execute();
    }

    /**
     * Granting the capability at system level is enough, even though the
     * capability is declared at course level for role overrides.
     */
    public function test_capability_can_be_granted_at_system_level(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'coursesyncservice']);
        assign_capability('block/coursesync:sync', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);

        $this->setUser($user);

        $this->assertTrue(has_capability('block/coursesync:sync', $context));

        $result = external_api::clean_returnvalue(ping::execute_returns(), ping::execute());
        $this->assertTrue($result['status']);

        // Sanity check that the role really is the thing granting it.
        $this->assertTrue($DB->record_exists('role_capabilities', [
            'roleid' => $roleid,
            'capability' => 'block/coursesync:sync',
        ]));
    }

    /**
     * The function is declared in db/services.php and wired to this class.
     */
    public function test_function_is_registered(): void {
        global $DB;

        $this->resetAfterTest();

        $function = $DB->get_record('external_functions', ['name' => 'block_coursesync_ping']);

        $this->assertNotEmpty($function);
        $this->assertSame(ping::class, $function->classname);
        $this->assertSame('block_coursesync', $function->component);
    }

    /**
     * The shipped service exists, is restricted to named users, and is off
     * until an administrator turns it on.
     */
    public function test_service_is_shipped_disabled_and_restricted(): void {
        global $DB;

        $this->resetAfterTest();

        $service = $DB->get_record('external_services', ['shortname' => 'block_coursesync']);

        $this->assertNotEmpty($service);
        $this->assertSame('0', (string) $service->enabled);
        $this->assertSame('1', (string) $service->restrictedusers);
        $this->assertTrue($DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => 'block_coursesync_ping',
        ]));
    }
}
