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
use block_coursesync\local\marks;
use block_coursesync\local\mark_fixtures;
use block_coursesync\local\submission_fixtures;
use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local/submission_fixtures.php');
require_once(__DIR__ . '/../local/mark_fixtures.php');

/**
 * Tests for describing teachers' marks and written feedback to another site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_marks::class)]
#[CoversClass(get_mark_file::class)]
#[CoversClass(marks::class)]
final class get_marks_test extends advanced_testcase {
    use submission_fixtures;
    use mark_fixtures;

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
        set_config('allowmarksexport', 1, 'block_coursesync');

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
            get_marks::execute_returns(),
            get_marks::execute($this->course->id, $cmids, $usernames)
        );
    }

    /**
     * Read a whole file the way the destination does, in one chunk.
     *
     * @param \stdClass $assign
     * @param string $username
     * @param string $name
     * @return array the raw answer
     */
    protected function file(\stdClass $assign, string $username, string $name): array {
        return get_mark_file::execute((int) $assign->cmid, $username, '/', $name, 0, 1024);
    }

    /**
     * A mark comes back under the student's username with its comment, its
     * files and a fingerprint - and says nothing about who graded.
     */
    public function test_mark_and_comment_by_username(): void {
        $assign = $this->markable($this->course);
        $this->submit($this->sam, $assign, 'My essay');
        $this->give_mark($assign, $this->sam, 72.5, '<p>Well argued</p>', ['notes.txt' => 'see me']);

        $item = $this->call([$assign->cmid])['items'][0];

        $this->assertSame('', $item['reason']);
        $this->assertEquals(100, $item['grademax']);
        $this->assertCount(1, $item['marks']);
        $mark = $item['marks'][0];
        $this->assertSame('sam', $mark['username']);
        $this->assertEquals(72.5, $mark['grade']);
        $this->assertSame('<p>Well argued</p>', $mark['comment']);
        $this->assertSame(0, $mark['attemptnumber']);
        $this->assertSame(['notes.txt'], array_column($mark['files'], 'filename'));
        $this->assertSame(sha1('see me'), $mark['files'][0]['contenthash']);
        $this->assertSame(40, strlen($mark['fingerprint']));
        $this->assertArrayNotHasKey('grader', $mark);
    }

    /**
     * The fingerprint changes when the number, the text or a file does.
     */
    public function test_fingerprint_follows_the_mark(): void {
        $assign = $this->markable($this->course);
        $this->give_mark($assign, $this->sam, 60, 'ok');
        $first = $this->call([$assign->cmid])['items'][0]['marks'][0]['fingerprint'];
        $this->assertSame($first, $this->call([$assign->cmid])['items'][0]['marks'][0]['fingerprint']);

        $this->give_mark($assign, $this->sam, 61, 'ok');
        $number = $this->call([$assign->cmid])['items'][0]['marks'][0]['fingerprint'];
        $this->assertNotSame($first, $number);

        $this->give_mark($assign, $this->sam, 61, 'better');
        $text = $this->call([$assign->cmid])['items'][0]['marks'][0]['fingerprint'];
        $this->assertNotSame($number, $text);

        $this->give_mark($assign, $this->sam, 61, 'better', ['f.txt' => 'x']);
        $this->assertNotSame($text, $this->call([$assign->cmid])['items'][0]['marks'][0]['fingerprint']);
    }

    /**
     * A comment alone is a mark; a placeholder row with neither is not.
     */
    public function test_comment_only_and_placeholder_rows(): void {
        $assign = $this->markable($this->course);
        $pat = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'pat']);

        $this->give_mark($assign, $this->sam, null, 'Resubmit please');
        // Opening the grader makes an empty row for pat; nothing was given.
        $this->assign_object($assign)->get_user_grade((int) $pat->id, true);

        $marks = $this->call([$assign->cmid])['items'][0]['marks'];

        $this->assertCount(1, $marks);
        $this->assertSame('sam', $marks[0]['username']);
        $this->assertNull($marks[0]['grade']);
        $this->assertSame('Resubmit please', $marks[0]['comment']);
    }

    /**
     * Teachers and other people who are not students are never described.
     */
    public function test_only_gradebook_students(): void {
        $assign = $this->markable($this->course);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher', ['username' => 'tess']);
        $this->give_mark($assign, $teacher, 90);

        $this->assertSame([], $this->call([$assign->cmid])['items'][0]['marks']);
    }

    /**
     * Asked about some students, only they are described.
     */
    public function test_usernames_narrow_the_answer(): void {
        $assign = $this->markable($this->course);
        $pat = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'pat']);
        $this->give_mark($assign, $this->sam, 50);
        $this->give_mark($assign, $pat, 60);

        $marks = $this->call([$assign->cmid], 'pat')['items'][0]['marks'];

        $this->assertSame(['pat'], array_column($marks, 'username'));
    }

    /**
     * The mark is for the attempt that was handed in, not a later empty one.
     */
    public function test_mark_follows_the_latest_submitted_attempt(): void {
        global $DB;

        $assign = $this->markable($this->course, ['attemptreopenmethod' => 'manual', 'maxattempts' => -1]);
        $this->submit($this->sam, $assign, 'first go');
        $this->give_mark($assign, $this->sam, 40, 'Try again');
        // The teacher reopens it: a new attempt that has not been handed in.
        $DB->set_field('assign_submission', 'latest', 0, ['assignment' => $assign->id, 'userid' => $this->sam->id]);
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $this->sam->id, 'timecreated' => time(), 'timemodified' => time(),
            'timestarted' => time(), 'status' => 'reopened', 'groupid' => 0, 'attemptnumber' => 1, 'latest' => 1,
        ]);

        $mark = $this->call([$assign->cmid])['items'][0]['marks'][0];

        $this->assertSame(0, $mark['attemptnumber']);
        $this->assertEquals(40, $mark['grade']);
    }

    /**
     * Under a marking workflow only a released mark is described.
     */
    public function test_unreleased_marks_are_not_described(): void {
        $assign = $this->markable($this->course, ['markingworkflow' => 1]);
        $this->give_mark($assign, $this->sam, 77, 'draft comment');

        $this->assertSame([], $this->call([$assign->cmid])['items'][0]['marks']);

        $object = $this->assign_object($assign);
        $flags = $object->get_user_flags((int) $this->sam->id, true);
        $flags->workflowstate = marks::STATE_RELEASED;
        $object->update_user_flags($flags);

        $this->assertCount(1, $this->call([$assign->cmid])['items'][0]['marks']);
    }

    /**
     * Assignments whose marks cannot travel say why, and describe nothing.
     */
    public function test_reasons(): void {
        $team = $this->markable($this->course, ['teamsubmission' => 1]);
        $scale = $this->markable($this->course, ['grade' => -1]);
        $none = $this->markable($this->course, ['grade' => 0]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        $items = [];

        foreach ($this->call([$team->cmid, $scale->cmid, $none->cmid, $page->cmid])['items'] as $item) {
            $items[$item['cmid']] = $item;
        }

        $this->assertSame('team', $items[$team->cmid]['reason']);
        $this->assertSame('scale', $items[$scale->cmid]['reason']);
        $this->assertSame('nograde', $items[$none->cmid]['reason']);
        $this->assertSame('notassign', $items[$page->cmid]['reason']);

        foreach ($items as $item) {
            $this->assertSame([], $item['marks']);
        }
    }

    /**
     * Where the comments plugin is off, a mark travels without a comment.
     */
    public function test_comments_off_sends_the_number_only(): void {
        $assign = $this->assignment($this->course);
        $this->give_mark($assign, $this->sam, 55, 'would be hidden');

        $mark = $this->call([$assign->cmid])['items'][0]['marks'][0];

        $this->assertEquals(55, $mark['grade']);
        $this->assertNull($mark['comment']);
        $this->assertSame([], $mark['files']);
    }

    /**
     * Nothing leaves a site whose switch is off, whatever the permissions.
     */
    public function test_switch_off_refuses_everything(): void {
        $assign = $this->markable($this->course);
        $this->give_mark($assign, $this->sam, 50, 'x', ['a.txt' => 'a']);
        set_config('allowmarksexport', 0, 'block_coursesync');

        try {
            $this->call([$assign->cmid]);
            $this->fail('described marks with the switch off');
        } catch (\moodle_exception $e) {
            $this->assertSame('errormarksexportdisabled', $e->errorcode);
        }

        try {
            $this->file($assign, 'sam', 'a.txt');
            $this->fail('served a file with the switch off');
        } catch (\moodle_exception $e) {
            $this->assertSame('errormarksexportdisabled', $e->errorcode);
        }
    }

    /**
     * The sync permission alone is not enough: sharing marks is its own.
     */
    public function test_needs_its_own_permission(): void {
        $assign = $this->markable($this->course);
        $this->give_mark($assign, $this->sam, 50);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        assign_capability('block/coursesync:sync', CAP_ALLOW, $roleid, $context->id);
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);

        try {
            $this->call([$assign->cmid]);
            $this->fail('described marks without the permission');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString('Let another site read assignment marks', $e->getMessage());
        }

        assign_capability('block/coursesync:exportmarks', CAP_ALLOW, $roleid, $context->id);
        $this->assertCount(1, $this->call([$assign->cmid])['items'][0]['marks']);
    }

    /**
     * A file in a comment is served in pieces, with its hash.
     */
    public function test_a_comment_file_is_served(): void {
        $assign = $this->markable($this->course);
        $this->give_mark($assign, $this->sam, 50, 'see file', ['notes.txt' => 'abcdefghij']);

        $part = get_mark_file::execute((int) $assign->cmid, 'sam', '/', 'notes.txt', 4, 3);

        $this->assertSame('efg', base64_decode($part['content']));
        $this->assertSame(10, $part['filesize']);
        $this->assertFalse($part['eof']);
        $this->assertSame(sha1('abcdefghij'), $part['contenthash']);
    }

    /**
     * A file is only served for a mark that would be described: not for a
     * student with no mark, not for an unreleased one, not a file that is
     * not there, and not from an assignment with the comments off.
     */
    public function test_files_only_where_a_mark_is_described(): void {
        $assign = $this->markable($this->course);
        $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'pat']);
        $this->give_mark($assign, $this->sam, 50, 'x', ['notes.txt' => 'abc']);

        foreach ([['pat', 'notes.txt', 'errorfilenotallowed'], ['sam', 'other.txt', 'errorfilenotfound']] as [$who, $name, $code]) {
            try {
                $this->file($assign, $who, $name);
                $this->fail("served $who/$name");
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        }

        $workflow = $this->markable($this->course, ['markingworkflow' => 1]);
        $this->give_mark($workflow, $this->sam, 50, 'x', ['notes.txt' => 'abc']);

        try {
            $this->file($workflow, 'sam', 'notes.txt');
            $this->fail('served the file of an unreleased mark');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfilenotallowed', $e->errorcode);
        }

        // A teacher is not a student the gradebook lists.
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher', ['username' => 'tess']);
        $this->give_mark($assign, $teacher, 50, 'x', ['t.txt' => 'abc']);

        try {
            $this->file($assign, 'tess', 't.txt');
            $this->fail('served a teacher\'s file');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfilenotallowed', $e->errorcode);
        }
    }

    /**
     * With the comments plugin off for the assignment, a file left in its
     * area is not served.
     */
    public function test_no_files_where_comments_are_off(): void {
        global $DB;

        $assign = $this->markable($this->course);
        $this->give_mark($assign, $this->sam, 50, 'x', ['notes.txt' => 'abc']);
        $DB->set_field('assign_plugin_config', 'value', 0, [
            'assignment' => $assign->id, 'plugin' => 'comments', 'subtype' => 'assignfeedback', 'name' => 'enabled',
        ]);

        try {
            $this->file($assign, 'sam', 'notes.txt');
            $this->fail('served a comment file with comments switched off');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfilenotallowed', $e->errorcode);
        }
    }
}
