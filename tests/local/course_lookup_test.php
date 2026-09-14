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
 * Tests course_lookup - the sole enforcement point behind
 * block_coursesync_check_course and block_coursesync_get_modified_activities
 * (via resolve()) and block_coursesync_get_activity_content (via
 * resolve_cm()). Every one of those external functions require_capability()s
 * block/coursesync:sync at CONTEXT_SYSTEM only - can_access_course() here is
 * the *entire* remaining defence against a token holder reading a course or
 * activity they aren't actually enrolled in (see DEVELOPER_NOTES.md and
 * db/access.php's comment on the capability). A regression in this class -
 * e.g. the can_access_course() check being dropped or short-circuited -
 * would be a straight cross-course data leak with nothing else to catch it,
 * so this exists to make that failure loud.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(course_lookup::class)]
final class course_lookup_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Asserts a callable throws a moodle_exception with the given errorcode,
     * the same shape every course_lookup caller relies on to build its
     * error response.
     *
     * @param string $expectederrorcode
     * @param callable $callback
     */
    protected function assert_throws_errorcode(string $expectederrorcode, callable $callback): void {
        try {
            $callback();
            $this->fail("Expected a moodle_exception with errorcode '$expectederrorcode', none was thrown.");
        } catch (\moodle_exception $e) {
            $this->assertSame($expectederrorcode, $e->errorcode);
        }
    }

    public function test_resolve_rejects_empty_identifier(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assert_throws_errorcode('emptycourseidentifier', function () use ($user) {
            course_lookup::resolve('   ', $user->id);
        });
    }

    public function test_resolve_rejects_unknown_id_and_shortname(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assert_throws_errorcode('coursenotfound', function () use ($user) {
            course_lookup::resolve('999999', $user->id);
        });
        $this->assert_throws_errorcode('coursenotfound', function () use ($user) {
            course_lookup::resolve('no-such-shortname', $user->id);
        });
    }

    /**
     * The core enforcement this class exists for: a user with no enrolment
     * in the course (and no capability override) must be refused, by both
     * id and shortname lookup - not merely told the course doesn't exist,
     * so a caller can't distinguish "wrong id" from "no access" and probe
     * for valid course ids.
     */
    public function test_resolve_rejects_a_user_not_enrolled_in_the_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_user();

        $this->assert_throws_errorcode('coursenotaccessible', function () use ($course, $outsider) {
            course_lookup::resolve((string) $course->id, $outsider->id);
        });
        $this->assert_throws_errorcode('coursenotaccessible', function () use ($course, $outsider) {
            course_lookup::resolve($course->shortname, $outsider->id);
        });
    }

    /**
     * The mirror image: an enrolled user succeeds, by both id and shortname -
     * confirming rejection above is really about enrolment, not some other
     * accident (a broken query, a wrong course lookup) rejecting everyone.
     */
    public function test_resolve_accepts_an_enrolled_user(): void {
        $course = $this->getDataGenerator()->create_course();
        $enrolled = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($enrolled->id, $course->id, 'student');

        $byid = course_lookup::resolve((string) $course->id, $enrolled->id);
        $this->assertSame($course->id, $byid->id);

        $byshortname = course_lookup::resolve($course->shortname, $enrolled->id);
        $this->assertSame($course->id, $byshortname->id);
    }

    public function test_resolve_cm_rejects_unknown_cmid(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assert_throws_errorcode('coursemodulenotfound', function () use ($user) {
            course_lookup::resolve_cm(999999, $user->id);
        });
    }

    /**
     * The same enrolment enforcement as resolve(), one level down: knowing
     * a real cmid must not be enough to read it without access to its course.
     */
    public function test_resolve_cm_rejects_a_user_not_enrolled_in_the_owning_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $outsider = $this->getDataGenerator()->create_user();

        $this->assert_throws_errorcode('coursenotaccessible', function () use ($page, $outsider) {
            course_lookup::resolve_cm((int) $page->cmid, $outsider->id);
        });
    }

    public function test_resolve_cm_accepts_an_enrolled_user(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $enrolled = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($enrolled->id, $course->id, 'student');

        $cm = course_lookup::resolve_cm((int) $page->cmid, $enrolled->id);
        $this->assertSame((int) $page->cmid, (int) $cm->id);
        $this->assertSame('page', $cm->modname);
    }
}
