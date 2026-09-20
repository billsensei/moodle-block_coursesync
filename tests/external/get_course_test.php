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
 * Tests for resolving a course reference on the source site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_course::class)]
final class get_course_test extends advanced_testcase {
    /**
     * A numeric reference finds the course.
     */
    public function test_resolve_by_id(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Remote Source Course',
            'shortname' => 'REMOTE1',
        ]);

        $result = external_api::clean_returnvalue(
            get_course::execute_returns(),
            get_course::execute((string) $course->id)
        );

        $this->assertSame((int) $course->id, $result['id']);
        $this->assertSame('REMOTE1', $result['shortname']);
        $this->assertSame('Remote Source Course', $result['fullname']);
        $this->assertTrue($result['visible']);
        $this->assertSame(0, $result['activitycount']);
    }

    /**
     * A shortname finds the same course.
     */
    public function test_resolve_by_shortname(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'REMOTE1']);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $result = external_api::clean_returnvalue(
            get_course::execute_returns(),
            get_course::execute('REMOTE1')
        );

        $this->assertSame((int) $course->id, $result['id']);
        $this->assertSame(2, $result['activitycount']);
    }

    /**
     * A shortname that happens to be numeric still resolves, by falling through
     * from the id lookup.
     */
    public function test_numeric_shortname_falls_through_to_shortname(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Pick a number that is certainly not a course id.
        $course = $this->getDataGenerator()->create_course(['shortname' => '99999999']);

        $result = external_api::clean_returnvalue(
            get_course::execute_returns(),
            get_course::execute('99999999')
        );

        $this->assertSame((int) $course->id, $result['id']);
    }

    /**
     * Hidden courses resolve, and say so.
     */
    public function test_hidden_course_is_reported_as_hidden(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'HIDDEN1', 'visible' => 0]);

        $result = external_api::clean_returnvalue(
            get_course::execute_returns(),
            get_course::execute('HIDDEN1')
        );

        $this->assertSame((int) $course->id, $result['id']);
        $this->assertFalse($result['visible']);
    }

    /**
     * An unknown reference is refused with our own error code, which the
     * destination maps to a message about checking the value.
     */
    public function test_unknown_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        try {
            get_course::execute('NOSUCHCOURSE');
            $this->fail('Expected a moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorcoursenotfound', $e->errorcode);
        }
    }

    /**
     * The site front page is never a sync target.
     */
    public function test_site_course_is_refused(): void {
        global $SITE;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        get_course::execute((string) $SITE->id);
    }

    /**
     * Without the capability in that course, the reference is not resolved.
     */
    public function test_requires_capability_in_the_course(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'REMOTE1']);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        get_course::execute('REMOTE1');
    }

    /**
     * Holding the sync permission but not being able to see the course is
     * reported distinctly, because the fix is a different one.
     */
    public function test_sync_permission_without_course_visibility(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'REMOTE1']);
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($course->id);

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'syncnoview']);
        assign_capability('block/coursesync:sync', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);

        $this->setUser($user);

        // Our own permission passes, so the failure comes from require_login()
        // inside validate_context() - which the destination maps to a message
        // about moodle/course:view.
        $this->expectException(\core\exception\require_login_exception::class);
        get_course::execute('REMOTE1');
    }

    /**
     * The capability can be granted for one course only, and that is enough.
     */
    public function test_capability_can_be_granted_per_course(): void {
        $this->resetAfterTest();

        $allowed = $this->getDataGenerator()->create_course(['shortname' => 'ALLOWED']);
        $other = $this->getDataGenerator()->create_course(['shortname' => 'OTHER']);
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'coursesyncservice']);
        $allowedcontext = \context_course::instance($allowed->id);
        assign_capability('block/coursesync:sync', CAP_ALLOW, $roleid, $allowedcontext->id, true);
        // A sync account is never enrolled, so it also needs to be allowed to
        // look at the course - the same capability core requires of its own
        // cross-course web service functions.
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, $allowedcontext->id, true);
        role_assign($roleid, $user->id, $allowedcontext->id);

        $this->setUser($user);

        $result = external_api::clean_returnvalue(
            get_course::execute_returns(),
            get_course::execute('ALLOWED')
        );
        $this->assertSame((int) $allowed->id, $result['id']);

        $this->expectException(\required_capability_exception::class);
        get_course::execute('OTHER');
    }
}
