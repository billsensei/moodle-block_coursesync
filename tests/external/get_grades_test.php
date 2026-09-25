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
 * Tests for handing students' grades to another site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_grades::class)]
final class get_grades_test extends advanced_testcase {
    /**
     * Load the gradebook library the tests use directly.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/gradelib.php');
    }

    /**
     * Grade sharing is switched on for every test unless one says otherwise.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('allowgradeexport', 1, 'block_coursesync');
    }

    /**
     * Give a student a gradebook grade in an activity.
     *
     * @param \stdClass $module the module record from the generator
     * @param string $modname
     * @param int $userid
     * @param float|null $grade
     * @param string $feedback
     * @param int $itemnumber
     * @return \grade_item
     */
    protected function grade(
        \stdClass $module,
        string $modname,
        int $userid,
        ?float $grade,
        string $feedback = '',
        int $itemnumber = 0
    ): \grade_item {
        $gradeitem = \grade_item::fetch([
            'courseid' => $module->course,
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $module->id,
            'itemnumber' => $itemnumber,
        ]);
        $gradeitem->update_final_grade($userid, $grade ?? false, 'test', $feedback ?: false, FORMAT_HTML);

        return $gradeitem;
    }

    /**
     * Call the function the way the web service layer would.
     *
     * @param int $courseid
     * @param int[] $cmids
     * @return array
     */
    protected function call(int $courseid, array $cmids): array {
        return external_api::clean_returnvalue(
            get_grades::execute_returns(),
            get_grades::execute($courseid, $cmids)
        );
    }

    /**
     * A grade comes back under the student's username, with what the
     * destination needs to place it: the item's range and the feedback.
     */
    public function test_grade_by_username(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student', ['username' => 'sam']);
        $assign = $generator->create_module('assign', ['course' => $course->id, 'grade' => 50]);

        $this->grade($assign, 'assign', $student->id, 42.5, '<p>Well done</p>');

        $result = $this->call($course->id, [$assign->cmid]);

        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertSame((int) $assign->cmid, $item['cmid']);
        $this->assertSame(0, $item['itemnumber']);
        $this->assertSame(GRADE_TYPE_VALUE, $item['gradetype']);
        $this->assertEquals(0.0, $item['grademin']);
        $this->assertEquals(50.0, $item['grademax']);
        $this->assertSame('', $item['scale']);

        $this->assertCount(1, $item['grades']);
        $grade = $item['grades'][0];
        $this->assertSame('sam', $grade['username']);
        $this->assertEquals(42.5, $grade['grade']);
        $this->assertSame('<p>Well done</p>', $grade['feedback']);
        $this->assertSame((int) FORMAT_HTML, $grade['feedbackformat']);
        $this->assertSame(0, $grade['hidden']);
        $this->assertGreaterThan(0, $grade['timemodified']);
    }

    /**
     * Only people the gradebook itself lists are reported: not a teacher who
     * somehow has a grade, not a student whose enrolment is suspended, and not
     * a student with nothing recorded.
     */
    public function test_only_active_graded_students_with_a_grade(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $graded = $generator->create_and_enrol($course, 'student', ['username' => 'graded']);
        $ungraded = $generator->create_and_enrol($course, 'student', ['username' => 'ungraded']);
        $suspended = $generator->create_and_enrol(
            $course,
            'student',
            ['username' => 'suspended'],
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        $teacher = $generator->create_and_enrol($course, 'editingteacher', ['username' => 'teacher']);
        $assign = $generator->create_module('assign', ['course' => $course->id]);

        $this->grade($assign, 'assign', $graded->id, 70);
        $this->grade($assign, 'assign', $suspended->id, 60);
        $this->grade($assign, 'assign', $teacher->id, 50);

        // A row with neither grade nor feedback, as an opened-but-ungraded
        // activity leaves behind.
        $gradeitem = $this->grade($assign, 'assign', $ungraded->id, 10);
        $gradeitem->update_final_grade($ungraded->id, null, 'test');

        $result = $this->call($course->id, [$assign->cmid]);

        $this->assertSame(['graded'], array_column($result['items'][0]['grades'], 'username'));
    }

    /**
     * Feedback without a grade still travels; the grade is null.
     */
    public function test_feedback_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student', ['username' => 'fb']);
        $assign = $generator->create_module('assign', ['course' => $course->id]);

        $this->grade($assign, 'assign', $student->id, null, 'Resubmit please');

        $grade = $this->call($course->id, [$assign->cmid])['items'][0]['grades'][0];

        $this->assertNull($grade['grade']);
        $this->assertSame('Resubmit please', $grade['feedback']);
    }

    /**
     * A course waiting for its gradebook to be recalculated is recalculated
     * first. Without that, core's list of gradable students refuses to run
     * at all ("gradesneedregrading") - which a teacher changing any grade
     * setting on the source would cause.
     */
    public function test_course_waiting_for_recalculation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student', ['username' => 'regrade']);
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $this->grade($assign, 'assign', $student->id, 64);

        grade_force_full_regrading($course->id);
        $this->assertNotEmpty(grade_needs_regrade_final_grades($course->id));

        $result = $this->call($course->id, [$assign->cmid]);

        $this->assertEquals(64, $result['items'][0]['grades'][0]['grade']);
        $this->assertEmpty(grade_needs_regrade_final_grades($course->id));
    }

    /**
     * Only the activities asked about come back. One from another course, or
     * one that no longer exists, is left out rather than failing the call.
     */
    public function test_only_requested_activities_in_the_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $wanted = $generator->create_module('assign', ['course' => $course->id]);
        $generator->create_module('assign', ['course' => $course->id]);
        $elsewhere = $generator->create_module('assign', ['course' => $other->id]);

        $result = $this->call($course->id, [$wanted->cmid, $elsewhere->cmid, 999999, $wanted->cmid]);

        $this->assertSame([(int) $wanted->cmid], array_column($result['items'], 'cmid'));
    }

    /**
     * An activity without a grade item, such as a page, adds nothing, and an
     * empty request is answered with an empty list.
     */
    public function test_ungraded_activities(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);

        $this->assertSame([], $this->call($course->id, [$page->cmid])['items']);
        $this->assertSame([], $this->call($course->id, [])['items']);
    }

    /**
     * An activity with two grade items reports both, in itemnumber order -
     * the destination matches them by that number.
     */
    public function test_several_grade_items(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student', ['username' => 'ws']);
        $workshop = $generator->create_module('workshop', ['course' => $course->id]);

        $this->grade($workshop, 'workshop', $student->id, 60, '', 0);
        $this->grade($workshop, 'workshop', $student->id, 15, '', 1);

        $items = $this->call($course->id, [$workshop->cmid])['items'];

        $this->assertSame([0, 1], array_column($items, 'itemnumber'));
        $this->assertEquals(60, $items[0]['grades'][0]['grade']);
        $this->assertEquals(15, $items[1]['grades'][0]['grade']);
    }

    /**
     * A scale grade item names its scale items, so the destination can check
     * it means the same thing there.
     */
    public function test_scale(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student', ['username' => 'sc']);
        $scale = $generator->create_scale(['scale' => 'Poor,Fair,Good']);
        $assign = $generator->create_module('assign', ['course' => $course->id, 'grade' => -$scale->id]);

        $this->grade($assign, 'assign', $student->id, 3);

        $item = $this->call($course->id, [$assign->cmid])['items'][0];

        $this->assertSame(GRADE_TYPE_SCALE, $item['gradetype']);
        $this->assertSame('Poor,Fair,Good', $item['scale']);
        $this->assertEquals(3, $item['grades'][0]['grade']);
    }

    /**
     * Hidden flags pass through as stored, so the destination can keep a
     * hidden grade hidden rather than showing it to the student there.
     */
    public function test_hidden_flags(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student', ['username' => 'hid']);
        $assign = $generator->create_module('assign', ['course' => $course->id]);

        $gradeitem = $this->grade($assign, 'assign', $student->id, 80);
        $gradegrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $gradegrade->set_hidden(1);
        $gradeitem->set_hidden(2000000000);

        $item = $this->call($course->id, [$assign->cmid])['items'][0];

        $this->assertSame(2000000000, $item['hidden']);
        $this->assertSame(1, $item['grades'][0]['hidden']);
    }

    /**
     * The sync account set up for copying activities cannot read grades
     * until it is also given the grades permission, and can once it is.
     */
    public function test_needs_the_grades_permission(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student', ['username' => 'perm']);
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $this->grade($assign, 'assign', $student->id, 55);

        $syncuser = $generator->create_user();
        $roleid = $generator->create_role(['shortname' => 'coursesyncsource']);
        $coursecontext = \context_course::instance($course->id);
        assign_capability('block/coursesync:sync', CAP_ALLOW, $roleid, $coursecontext->id, true);
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, $coursecontext->id, true);
        role_assign($roleid, $syncuser->id, $coursecontext->id);
        $this->setUser($syncuser);

        try {
            get_grades::execute($course->id, [$assign->cmid]);
            $this->fail('Grades were handed out without block/coursesync:exportgrades.');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(
                get_string('coursesync:exportgrades', 'block_coursesync'),
                $e->getMessage()
            );
        }

        assign_capability('block/coursesync:exportgrades', CAP_ALLOW, $roleid, $coursecontext->id, true);

        $result = $this->call($course->id, [$assign->cmid]);

        $this->assertSame('perm', $result['items'][0]['grades'][0]['username']);
    }

    /**
     * The grades permission alone is not enough: the sync permission is
     * still required.
     */
    public function test_needs_the_sync_permission(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);

        $user = $generator->create_user();
        $roleid = $generator->create_role();
        $coursecontext = \context_course::instance($course->id);
        assign_capability('block/coursesync:exportgrades', CAP_ALLOW, $roleid, $coursecontext->id, true);
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, $coursecontext->id, true);
        role_assign($roleid, $user->id, $coursecontext->id);
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        $this->expectExceptionMessage(get_string('coursesync:sync', 'block_coursesync'));
        get_grades::execute($course->id, [$assign->cmid]);
    }

    /**
     * A course with separate groups hands over every student's grades. The
     * sync account is authorised by its permission on the whole course and
     * is rarely in any group; core's list of gradable users, limited to the
     * caller's groups, used to leave it with nobody.
     */
    public function test_separate_groups_on_the_source(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $course->id]);
        $sam = $generator->create_and_enrol($course, 'student', ['username' => 'sam']);
        $pat = $generator->create_and_enrol($course, 'student', ['username' => 'pat']);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $sam->id]);
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $this->grade($assign, 'assign', $sam->id, 50);
        $this->grade($assign, 'assign', $pat->id, 60);

        // A sync account as REMOTE_SETUP.md sets it up: no accessallgroups, no group.
        $syncuser = $generator->create_user();
        $roleid = $generator->create_role();
        $context = \context_course::instance($course->id);
        foreach (['block/coursesync:sync', 'block/coursesync:exportgrades', 'moodle/course:view'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $syncuser->id, $context->id);
        $this->setUser($syncuser);

        $result = external_api::clean_returnvalue(
            get_grades::execute_returns(),
            get_grades::execute($course->id, [$assign->cmid])
        );

        $this->assertEqualsCanonicalizing(['sam', 'pat'], array_column($result['items'][0]['grades'], 'username'));
    }

    /**
     * Asked for particular students - the ones the destination can use -
     * only their grades leave this site. Unknown names are ignored, and an
     * empty list, which is what a destination from before this sends, means
     * every student.
     */
    public function test_only_the_students_asked_for(): void {
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);

        foreach (['sam', 'pat', 'lee'] as $username) {
            $user = $generator->create_and_enrol($course, 'student', ['username' => $username]);
            $this->grade($assign, 'assign', $user->id, 50);
        }

        $ask = fn(string $usernames) => array_column(external_api::clean_returnvalue(
            get_grades::execute_returns(),
            get_grades::execute($course->id, [$assign->cmid], $usernames)
        )['items'][0]['grades'], 'username');

        $this->assertEqualsCanonicalizing(['sam', 'lee'], $ask("sam\nlee\nnobody"));
        $this->assertEqualsCanonicalizing(['sam', 'pat', 'lee'], $ask(''));
        $this->assertSame(['pat'], $ask("  PAT \r\n\n"), 'trimmed and cleaned like any username');
    }

    /**
     * No archetype holds the grades permission by default - not even a
     * manager - so an upgrade never hands grades to an existing account.
     */
    public function test_no_role_has_it_by_default(): void {
        global $DB;

        $this->resetAfterTest();

        $holders = $DB->get_records('role_capabilities', ['capability' => 'block/coursesync:exportgrades']);

        $this->assertSame([], $holders);
    }

    /**
     * With grade sharing switched off nobody gets grades - not even an
     * administrator - and nothing about the course is looked at first.
     */
    public function test_switched_off(): void {
        $this->setAdminUser();
        set_config('allowgradeexport', 0, 'block_coursesync');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorgradeexportdisabled', 'block_coursesync'));
        get_grades::execute(999999, []);
    }

    /**
     * A request may name at most get_grades::MAX_CMIDS activities; the
     * destination sends longer lists in batches.
     */
    public function test_too_many_activities(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\invalid_parameter_exception::class);
        get_grades::execute($course->id, range(1, get_grades::MAX_CMIDS + 1));
    }

    /**
     * A course that does not exist is reported as not found.
     */
    public function test_missing_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorcoursenotfound', 'block_coursesync'));
        get_grades::execute(999999, []);
    }
}
