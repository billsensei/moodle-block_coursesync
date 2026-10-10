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
use block_coursesync\local\submission_fixtures;
use block_coursesync\local\submissions;
use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local/submission_fixtures.php');

/**
 * Tests for describing students' assignment submissions to another site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_submissions::class)]
#[CoversClass(submissions::class)]
final class get_submissions_test extends advanced_testcase {
    use submission_fixtures;

    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass A student, username "sam". */
    protected \stdClass $sam;

    /**
     * Sharing is switched on for every test unless one says otherwise.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allowsubmissionexport', 1, 'block_coursesync');

        $this->course = $this->getDataGenerator()->create_course();
        $this->sam = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'sam']);
    }

    /**
     * Call the function the way the web service layer would.
     *
     * @param int[] $cmids
     * @param string $usernames
     * @return array
     */
    protected function call(array $cmids, string $usernames = ''): array {
        return external_api::clean_returnvalue(
            get_submissions::execute_returns(),
            get_submissions::execute($this->course->id, $cmids, $usernames)
        );
    }

    /**
     * What a student handed in comes back under their username: the text, the
     * files in a fixed order, and a fingerprint of all of it.
     */
    public function test_text_and_files_by_username(): void {
        $assign = $this->assignment($this->course);
        $this->submit($this->sam, $assign, '<p>My essay</p>', ['b.txt' => 'bee', 'a.txt' => 'ay']);

        $result = $this->call([$assign->cmid]);

        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertSame((int) $assign->cmid, $item['cmid']);
        $this->assertSame('', $item['reason']);
        $this->assertEqualsCanonicalizing(['file', 'onlinetext'], $item['plugins']);

        $this->assertCount(1, $item['submissions']);
        $submission = $item['submissions'][0];
        $this->assertSame('sam', $submission['username']);
        $this->assertSame(0, $submission['attemptnumber']);
        $this->assertSame('<p>My essay</p>', $submission['onlinetext']);
        $this->assertSame((int) FORMAT_HTML, $submission['onlineformat']);
        $this->assertGreaterThan(0, $submission['timemodified']);

        $this->assertSame(['a.txt', 'b.txt'], array_column($submission['files'], 'filename'));
        $this->assertSame(sha1('ay'), $submission['files'][0]['contenthash']);
        $this->assertSame(2, $submission['files'][0]['filesize']);
        $this->assertSame(submissions::AREA_FILES, $submission['files'][0]['area']);
        $this->assertSame('/', $submission['files'][0]['filepath']);
    }

    /**
     * The fingerprint changes when the work does, and only then.
     */
    public function test_fingerprint_follows_the_content(): void {
        $assign = $this->assignment($this->course);
        $this->submit($this->sam, $assign, 'first', ['a.txt' => 'one']);

        $before = $this->call([$assign->cmid])['items'][0]['submissions'][0]['fingerprint'];
        $again = $this->call([$assign->cmid])['items'][0]['submissions'][0]['fingerprint'];
        $this->assertSame($before, $again);
        $this->assertSame(40, strlen($before));

        $this->submit($this->sam, $assign, 'second', ['a.txt' => 'one']);
        $text = $this->call([$assign->cmid])['items'][0]['submissions'][0]['fingerprint'];
        $this->assertNotSame($before, $text);

        $this->submit($this->sam, $assign, 'second', ['a.txt' => 'two']);
        $file = $this->call([$assign->cmid])['items'][0]['submissions'][0]['fingerprint'];
        $this->assertNotSame($text, $file);
    }

    /**
     * Only work that was handed in is described: a draft is not, and a
     * student who has done nothing is not.
     */
    public function test_drafts_and_empty_students_are_left_out(): void {
        $draftsassign = $this->assignment($this->course, ['submissiondrafts' => 1]);
        $pat = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'pat']);
        $this->submit($this->sam, $draftsassign, 'still a draft');

        $this->assertSame([], $this->call([$draftsassign->cmid])['items'][0]['submissions']);

        // Handed in for grading, it counts.
        $this->setUser($this->sam);
        $this->assign_object($draftsassign)->submit_for_grading((object) ['userid' => $this->sam->id], []);
        $this->setAdminUser();

        $submissions = $this->call([$draftsassign->cmid])['items'][0]['submissions'];
        $this->assertSame(['sam'], array_column($submissions, 'username'));
        $this->assertNotContains($pat->username, array_column($submissions, 'username'));
    }

    /**
     * A newer attempt that has not been handed in does not hide the one that
     * was: what counts is the latest the student actually submitted.
     */
    public function test_an_earlier_submitted_attempt_is_not_hidden_by_a_newer_draft(): void {
        global $DB;

        $assign = $this->assignment($this->course);
        $this->submit($this->sam, $assign, 'the real one');

        $first = $DB->get_record('assign_submission', ['assignment' => $assign->id, 'userid' => $this->sam->id]);
        $DB->set_field('assign_submission', 'latest', 0, ['id' => $first->id]);
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id,
            'userid' => $this->sam->id,
            'timecreated' => time(),
            'timemodified' => time(),
            'status' => 'reopened',
            'groupid' => 0,
            'attemptnumber' => 1,
            'latest' => 1,
        ]);

        $submission = submissions::latest_submitted((int) $assign->id, (int) $this->sam->id);
        $this->assertSame((int) $first->id, (int) $submission->id);

        $described = $this->call([$assign->cmid])['items'][0]['submissions'];
        $this->assertSame('the real one', $described[0]['onlinetext']);
        $this->assertSame(0, $described[0]['attemptnumber']);
    }

    /**
     * Only the students asked for are described.
     */
    public function test_only_the_students_asked_for(): void {
        $pat = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'pat']);
        $assign = $this->assignment($this->course);
        $this->submit($this->sam, $assign, 'sam work');
        $this->submit($pat, $assign, 'pat work');

        $this->assertEqualsCanonicalizing(
            ['sam', 'pat'],
            array_column($this->call([$assign->cmid])['items'][0]['submissions'], 'username')
        );
        $this->assertSame(
            ['pat'],
            array_column($this->call([$assign->cmid], "pat\nnobody")['items'][0]['submissions'], 'username')
        );
    }

    /**
     * Only people the gradebook lists are described: not a teacher who
     * somehow has a submission, and not a student whose enrolment is suspended.
     */
    public function test_only_active_students(): void {
        global $DB;

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher', ['username' => 'tess']);
        $gone = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['username' => 'gone'],
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        $assign = $this->assignment($this->course);
        $this->submit($this->sam, $assign, 'sam work');
        $this->submit($gone, $assign, 'suspended work');

        // A teacher cannot normally submit, so the row is written directly.
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id,
            'userid' => $teacher->id,
            'timecreated' => 1,
            'timemodified' => 1,
            'status' => 'submitted',
            'groupid' => 0,
            'attemptnumber' => 0,
            'latest' => 1,
        ]);
        $DB->insert_record('assignsubmission_onlinetext', (object) [
            'assignment' => $assign->id,
            'submission' => $DB->get_field('assign_submission', 'id', ['userid' => $teacher->id]),
            'onlinetext' => 'teacher text',
            'onlineformat' => FORMAT_HTML,
        ]);

        $this->assertSame(['sam'], array_column($this->call([$assign->cmid])['items'][0]['submissions'], 'username'));
    }

    /**
     * A submission plugin switched off for the assignment is not read, and
     * with neither on the assignment says why it has nothing.
     */
    public function test_switched_off_submission_plugins(): void {
        $assign = $this->assignment($this->course);
        $this->submit($this->sam, $assign, 'text', ['a.txt' => 'file']);

        $this->assertEqualsCanonicalizing(['file', 'onlinetext'], $this->call([$assign->cmid])['items'][0]['plugins']);

        global $DB;
        $DB->set_field('assign_plugin_config', 'value', 0, [
            'assignment' => $assign->id,
            'plugin' => 'file',
            'subtype' => 'assignsubmission',
            'name' => 'enabled',
        ]);

        $item = $this->call([$assign->cmid])['items'][0];
        $this->assertSame(['onlinetext'], $item['plugins']);
        $this->assertSame([], $item['submissions'][0]['files']);
        $this->assertSame('text', $item['submissions'][0]['onlinetext']);

        $DB->set_field('assign_plugin_config', 'value', 0, [
            'assignment' => $assign->id,
            'plugin' => 'onlinetext',
            'subtype' => 'assignsubmission',
            'name' => 'enabled',
        ]);

        $item = $this->call([$assign->cmid])['items'][0];
        $this->assertSame(get_submissions::REASON_NOPLUGINS, $item['reason']);
        $this->assertSame([], $item['submissions']);
    }

    /**
     * A team assignment is never described, and the answer says why.
     */
    public function test_team_assignments_are_refused_with_a_reason(): void {
        $assign = $this->assignment($this->course, ['teamsubmission' => 1]);

        $item = $this->call([$assign->cmid])['items'][0];

        $this->assertSame(get_submissions::REASON_TEAM, $item['reason']);
        $this->assertSame([], $item['submissions']);
    }

    /**
     * An activity that is not an assignment says so rather than failing the
     * rest of the request.
     */
    public function test_other_activities_say_so(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $assign = $this->assignment($this->course);
        $this->submit($this->sam, $assign, 'work');

        $items = $this->call([$page->cmid, $assign->cmid])['items'];

        $this->assertSame(get_submissions::REASON_NOTASSIGN, $items[0]['reason']);
        $this->assertSame('', $items[1]['reason']);
        $this->assertCount(1, $items[1]['submissions']);
    }

    /**
     * An activity that is not in this course is left out, not refused.
     */
    public function test_activities_from_other_courses_are_left_out(): void {
        $other = $this->getDataGenerator()->create_course();
        $elsewhere = $this->assignment($other);
        $assign = $this->assignment($this->course);

        $items = $this->call([$elsewhere->cmid, $assign->cmid, 999999])['items'];

        $this->assertSame([(int) $assign->cmid], array_column($items, 'cmid'));
    }

    /**
     * Off until an administrator turns it on, and the answer is the same
     * whatever the caller asks.
     */
    public function test_switched_off(): void {
        $assign = $this->assignment($this->course);
        set_config('allowsubmissionexport', 0, 'block_coursesync');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorsubmissionexportdisabled', 'block_coursesync'));
        get_submissions::execute($this->course->id, [$assign->cmid]);
    }

    /**
     * Sharing grades is not sharing work: the grades switch alone does
     * nothing here.
     */
    public function test_the_grades_switch_is_not_enough(): void {
        $assign = $this->assignment($this->course);
        set_config('allowsubmissionexport', 0, 'block_coursesync');
        set_config('allowgradeexport', 1, 'block_coursesync');

        $this->expectException(\moodle_exception::class);
        get_submissions::execute($this->course->id, [$assign->cmid]);
    }

    /**
     * Needs its own permission, on top of the sync one - and the grades
     * permission does not stand in for it.
     */
    public function test_needs_the_submissions_permission(): void {
        $assign = $this->assignment($this->course);
        $this->submit($this->sam, $assign, 'work');

        $generator = $this->getDataGenerator();
        $syncuser = $generator->create_user();
        $roleid = $generator->create_role(['shortname' => 'coursesyncsource']);
        $coursecontext = \context_course::instance($this->course->id);
        foreach (['block/coursesync:sync', 'block/coursesync:exportgrades', 'moodle/course:view'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $coursecontext->id, true);
        }
        role_assign($roleid, $syncuser->id, $coursecontext->id);
        $this->setUser($syncuser);

        try {
            get_submissions::execute($this->course->id, [$assign->cmid]);
            $this->fail('Submissions were handed out without block/coursesync:exportsubmissions.');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(
                get_string('coursesync:exportsubmissions', 'block_coursesync'),
                $e->getMessage()
            );
        }

        assign_capability('block/coursesync:exportsubmissions', CAP_ALLOW, $roleid, $coursecontext->id, true);

        $this->assertSame(['sam'], array_column($this->call([$assign->cmid])['items'][0]['submissions'], 'username'));
    }

    /**
     * The submissions permission alone is not enough: the sync permission is
     * still required.
     */
    public function test_needs_the_sync_permission(): void {
        $assign = $this->assignment($this->course);

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        $coursecontext = \context_course::instance($this->course->id);
        assign_capability('block/coursesync:exportsubmissions', CAP_ALLOW, $roleid, $coursecontext->id, true);
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, $coursecontext->id, true);
        role_assign($roleid, $user->id, $coursecontext->id);
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        $this->expectExceptionMessage(get_string('coursesync:sync', 'block_coursesync'));
        get_submissions::execute($this->course->id, [$assign->cmid]);
    }

    /**
     * No role holds the permission unless an administrator gives it.
     */
    public function test_no_role_has_it_by_default(): void {
        global $DB;

        $this->assertSame([], $DB->get_records('role_capabilities', ['capability' => 'block/coursesync:exportsubmissions']));
    }

    /**
     * The service and the pre-built service list both carry the two functions.
     */
    public function test_both_functions_are_in_the_service(): void {
        global $CFG;

        $functions = [];
        $services = [];
        require($CFG->dirroot . '/blocks/coursesync/db/services.php');

        foreach (['block_coursesync_get_submissions', 'block_coursesync_get_submission_file'] as $name) {
            $this->assertArrayHasKey($name, $functions);
            $this->assertContains($name, $services['Course Sync']['functions']);
            $this->assertStringContainsString('block/coursesync:exportsubmissions', $functions[$name]['capabilities']);
        }
    }

    /**
     * Too many activities in one request is refused.
     */
    public function test_too_many_activities(): void {
        $this->expectException(\invalid_parameter_exception::class);
        get_submissions::execute($this->course->id, range(1, get_submissions::MAX_CMIDS + 1));
    }

    /**
     * A course that is not here is refused.
     */
    public function test_missing_course(): void {
        $this->expectException(\moodle_exception::class);
        get_submissions::execute(999999, [1]);
    }
}
