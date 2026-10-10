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
 * Tests for serving the files of students' assignment submissions.
 *
 * What matters most here is what it refuses: it is the one function that
 * reads a student's file, and it must not be a way to read any other file.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_submission_file::class)]
final class get_submission_file_test extends advanced_testcase {
    use submission_fixtures;

    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass A student, username "sam". */
    protected \stdClass $sam;

    /** @var \stdClass An assignment sam has handed work in to. */
    protected \stdClass $assign;

    /**
     * Sharing on, and sam has handed in a file and some text.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allowsubmissionexport', 1, 'block_coursesync');

        $this->course = $this->getDataGenerator()->create_course();
        $this->sam = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'sam']);
        $this->assign = $this->assignment($this->course);
        $this->submit($this->sam, $this->assign, '<p>text</p>', ['essay.txt' => 'The quick brown fox']);
    }

    /**
     * Read a piece of sam's essay the way the web service layer would.
     *
     * @param array $override parameters to change
     * @return array
     */
    protected function read(array $override = []): array {
        $params = array_merge([
            'cmid' => $this->assign->cmid,
            'username' => 'sam',
            'area' => submissions::AREA_FILES,
            'filepath' => '/',
            'filename' => 'essay.txt',
            'offset' => 0,
            'length' => 1000,
        ], $override);

        return external_api::clean_returnvalue(
            get_submission_file::execute_returns(),
            get_submission_file::execute(
                $params['cmid'],
                $params['username'],
                $params['area'],
                $params['filepath'],
                $params['filename'],
                $params['offset'],
                $params['length']
            )
        );
    }

    /**
     * Assert a read is refused with a Course Sync error.
     *
     * @param string $errorcode
     * @param array $override parameters to change
     * @return void
     */
    protected function assert_refused(string $errorcode, array $override = []): void {
        try {
            $this->read($override);
            $this->fail('The read was not refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * A file comes back in pieces, with the whole file's SHA-1 on each.
     */
    public function test_reads_a_file_in_pieces(): void {
        $first = $this->read(['length' => 9]);

        $this->assertSame(19, $first['filesize']);
        $this->assertSame(9, $first['returned']);
        $this->assertFalse($first['eof']);
        $this->assertSame('The quick', base64_decode($first['content']));
        $this->assertSame(sha1('The quick brown fox'), $first['contenthash']);

        $rest = $this->read(['offset' => 9, 'length' => 1000]);
        $this->assertTrue($rest['eof']);
        $this->assertSame(' brown fox', base64_decode($rest['content']));
    }

    /**
     * A file embedded in online text is served from its own area.
     */
    public function test_reads_a_file_embedded_in_online_text(): void {
        global $DB;

        $submission = $DB->get_record('assign_submission', ['assignment' => $this->assign->id, 'userid' => $this->sam->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($this->assign->cmid)->id,
            'component' => 'assignsubmission_onlinetext',
            'filearea' => submissions::AREA_TEXTFILES,
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => 'pic.png',
        ], 'png-bytes');

        $result = $this->read(['area' => submissions::AREA_TEXTFILES, 'filename' => 'pic.png']);

        $this->assertSame('png-bytes', base64_decode($result['content']));
    }

    /**
     * It is off until an administrator turns it on.
     */
    public function test_switched_off(): void {
        set_config('allowsubmissionexport', 0, 'block_coursesync');

        $this->assert_refused('errorsubmissionexportdisabled');
    }

    /**
     * It needs its own permission, and the sync permission as well.
     */
    public function test_needs_both_permissions(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        $coursecontext = \context_course::instance($this->course->id);
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, $coursecontext->id, true);
        assign_capability('block/coursesync:sync', CAP_ALLOW, $roleid, $coursecontext->id, true);
        role_assign($roleid, $user->id, $coursecontext->id);
        $this->setUser($user);

        try {
            $this->read();
            $this->fail('A file was served without block/coursesync:exportsubmissions.');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(
                get_string('coursesync:exportsubmissions', 'block_coursesync'),
                $e->getMessage()
            );
        }

        assign_capability('block/coursesync:exportsubmissions', CAP_ALLOW, $roleid, $coursecontext->id, true);
        $this->assertSame(19, $this->read()['filesize']);

        assign_capability('block/coursesync:sync', CAP_PREVENT, $roleid, $coursecontext->id, true);
        $this->expectException(\required_capability_exception::class);
        $this->read();
    }

    /**
     * One student's work cannot be read by naming another student: the file
     * has to be in the named student's own submission.
     */
    public function test_a_file_must_be_in_the_named_students_submission(): void {
        $pat = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'pat']);
        $this->submit($pat, $this->assign, 'pat text', ['secret.txt' => 'pat only']);

        // Pat's own file, asked for as pat: fine.
        $this->assertSame(8, $this->read(['username' => 'pat', 'filename' => 'secret.txt'])['filesize']);

        // Pat's file, asked for as sam: not there.
        $this->assert_refused('errorfilenotfound', ['username' => 'sam', 'filename' => 'secret.txt']);
    }

    /**
     * Only students the gradebook lists: a teacher's files, and a suspended
     * student's, are not served even if they somehow hold a submission.
     */
    public function test_only_gradebook_students(): void {
        $gone = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['username' => 'gone'],
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        $this->submit($gone, $this->assign, 'x', ['mine.txt' => 'suspended']);

        $this->assert_refused('errorfilenotallowed', ['username' => 'gone', 'filename' => 'mine.txt']);
        $this->assert_refused('errorfilenotallowed', ['username' => 'nobody']);
    }

    /**
     * A draft is not the student's handed-in work and is not served.
     */
    public function test_drafts_are_not_served(): void {
        $drafts = $this->assignment($this->course, ['submissiondrafts' => 1]);
        $this->submit($this->sam, $drafts, null, ['draft.txt' => 'not yet']);

        $this->assert_refused('errorfilenotallowed', ['cmid' => $drafts->cmid, 'filename' => 'draft.txt']);
    }

    /**
     * Team assignments are never served.
     */
    public function test_team_assignments_are_not_served(): void {
        global $DB;

        // A file really is there, in a submission of the ordinary kind: it is
        // the assignment being a team one that must keep it from being read.
        $this->assertSame(19, $this->read()['filesize']);
        $DB->set_field('assign', 'teamsubmission', 1, ['id' => $this->assign->id]);

        $this->assert_refused('errorfilenotallowed');
    }

    /**
     * A submission plugin that is switched off is not read.
     */
    public function test_a_switched_off_plugin_is_not_read(): void {
        global $DB;

        $DB->set_field('assign_plugin_config', 'value', 0, [
            'assignment' => $this->assign->id,
            'plugin' => 'file',
            'subtype' => 'assignsubmission',
            'name' => 'enabled',
        ]);

        $this->assert_refused('errorfilenotallowed');
    }

    /**
     * It will only look in the two submission file areas of an assignment:
     * not the assignment's own files, not another component's.
     */
    public function test_only_the_submission_areas(): void {
        $this->assert_refused('errorfilenotallowed', ['area' => 'intro']);
        $this->assert_refused('errorfilenotallowed', ['area' => 'introattachment']);
        $this->assert_refused('errorfilenotallowed', ['area' => 'feedback_files']);
    }

    /**
     * Only an assignment: another kind of activity is refused even if it
     * holds files in an area of the same name.
     */
    public function test_only_assignments(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        $this->assert_refused('errorfilenotallowed', ['cmid' => $page->cmid]);
    }

    /**
     * A missing activity, or a missing file, is a plain refusal.
     */
    public function test_missing_things(): void {
        $this->assert_refused('erroractivitynotfound', ['cmid' => 999999]);
        $this->assert_refused('errorfilenotfound', ['filename' => 'nothing.txt']);
        $this->assert_refused('errorfilenotfound', ['filepath' => '/elsewhere/']);
    }

    /**
     * A bad offset or length is refused rather than looping or reading
     * from before the start.
     */
    public function test_bad_ranges(): void {
        $this->expectException(\invalid_parameter_exception::class);
        $this->read(['offset' => -1]);
    }

    /**
     * Reading past the end gives an empty, final piece.
     */
    public function test_reading_past_the_end(): void {
        $result = $this->read(['offset' => 500]);

        $this->assertSame(0, $result['returned']);
        $this->assertTrue($result['eof']);
    }
}
