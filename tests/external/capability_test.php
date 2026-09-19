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

/**
 * Tests that the provider-side web services refuse callers without permission.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\external;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Capability enforcement on the two functions a remote site exposes.
 *
 * These are the functions reachable with a web service token, so the checks
 * they make are the only thing between a token and the course content behind
 * it. Each is tested from an account that lacks the capability as well as one
 * that has it.
 */
#[CoversClass(list_activities::class)]
#[CoversClass(backup_activity::class)]
final class capability_test extends \advanced_testcase {
    /** @var \stdClass The course holding the activity. */
    private \stdClass $course;

    /** @var \stdClass A page activity in that course. */
    private \stdClass $page;

    /**
     * Builds a course with one activity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
    }

    /**
     * Enrols a new user in the course with the given role.
     *
     * @param string $archetype Role shortname, for example 'student' or 'editingteacher'.
     * @return \stdClass The user.
     */
    private function enrolled_user(string $archetype): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $archetype);
        $this->setUser($user);

        return $user;
    }

    /**
     * A student cannot list the activities in a course for syncing.
     */
    public function test_list_activities_refuses_a_student(): void {
        $this->enrolled_user('student');

        $this->expectException(\required_capability_exception::class);
        list_activities::execute($this->course->id);
    }

    /**
     * A teacher can list them.
     */
    public function test_list_activities_allows_a_teacher(): void {
        $this->enrolled_user('editingteacher');

        $result = list_activities::execute($this->course->id);
        $result = \core_external\external_api::clean_returnvalue(list_activities::execute_returns(), $result);

        $this->assertSame((int) $this->course->id, $result['courseid']);
        $cmids = array_column($result['activities'], 'cmid');
        $this->assertContains((int) $this->page->cmid, $cmids);
    }

    /**
     * Someone with no access to the course at all cannot list it.
     */
    public function test_list_activities_refuses_an_outsider(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\require_login_exception::class);
        list_activities::execute($this->course->id);
    }

    /**
     * A student cannot have an activity packaged up for download.
     */
    public function test_backup_activity_refuses_a_student(): void {
        $this->enrolled_user('student');

        $this->expectException(\required_capability_exception::class);
        backup_activity::execute($this->page->cmid);
    }

    /**
     * Holding the backup capability but not the download one is still refused.
     */
    public function test_backup_activity_requires_the_download_capability(): void {
        global $DB;

        $user = $this->enrolled_user('editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(
            'moodle/backup:downloadfile',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($this->course->id),
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        backup_activity::execute($this->page->cmid);
    }

    /**
     * A teacher gets a real backup file back, described well enough to fetch it.
     */
    public function test_backup_activity_allows_a_teacher(): void {
        $this->enrolled_user('editingteacher');

        $result = backup_activity::execute($this->page->cmid);
        $result = \core_external\external_api::clean_returnvalue(backup_activity::execute_returns(), $result);

        $this->assertSame((int) $this->page->cmid, $result['cmid']);
        $this->assertGreaterThan(0, $result['filesize']);
        $this->assertStringEndsWith('.mbz', $result['filename']);
        $this->assertStringStartsWith('/webservice/pluginfile.php/', $result['downloadpath']);

        // The file must land in this plugin's own area, which is served only
        // through its pluginfile callback and never from the web root.
        $this->assertSame('activitybackup', $result['filearea']);
        $stored = get_file_storage()->get_file(
            $result['contextid'],
            'block_coursesync',
            $result['filearea'],
            0,
            '/',
            $result['filename']
        );
        $this->assertNotFalse($stored);
    }

    /**
     * An activity that is hidden is not offered to an account that cannot see hidden ones.
     */
    public function test_hidden_activities_are_not_listed_without_the_capability(): void {
        global $DB;

        $DB->set_field('course_modules', 'visible', 0, ['id' => $this->page->cmid]);
        rebuild_course_cache($this->course->id, true);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, \context_system::instance(), true);
        assign_capability(
            'moodle/backup:backupactivity',
            CAP_ALLOW,
            $roleid,
            \context_course::instance($this->course->id),
            true
        );
        role_assign($roleid, $user->id, \context_course::instance($this->course->id));
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $result = list_activities::execute($this->course->id);

        $this->assertNotContains((int) $this->page->cmid, array_column($result['activities'], 'cmid'));
    }
}
