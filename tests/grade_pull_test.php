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

use advanced_testcase;
use core\http_client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local/source_on_this_site.php');

/**
 * Tests for pulling students' grades into this site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(grade_pull::class)]
#[CoversClass(grade_pull_result::class)]
#[CoversClass(grades_result::class)]
final class grade_pull_test extends advanced_testcase {
    use local\source_on_this_site;

    /** @var \stdClass The destination course. */
    protected \stdClass $course;

    /** @var int The Course Sync block in it. */
    protected int $instanceid;

    /** @var \stdClass A student here, username "sam". */
    protected \stdClass $student;

    /** @var array The grade items the fake source reports. */
    protected array $sourceitems = [];

    /** @var array[] The parameters of every call the fake source received. */
    protected array $calls = [];

    /**
     * A course with a mapped Course Sync block and one student.
     */
    protected function setUp(): void {
        global $CFG, $DB;

        parent::setUp();
        require_once($CFG->libdir . '/gradelib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'sam']);

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($this->course->id));
        $page->set_course($this->course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $this->course->format);
        $page->set_url('/course/view.php', ['id' => $this->course->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');

        $this->instanceid = (int) $DB->get_field('block_instances', 'id', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($this->course->id)->id,
        ], MUST_EXIST);

        $this->map_to(42);

        set_config('allowgradepull', 1, 'block_coursesync');
        // Only the round-trip test's own get_grades reads this one.
        set_config('allowgradeexport', 1, 'block_coursesync');
    }

    /**
     * Point the block at a course on the "other site".
     *
     * @param int $remotecourseid
     * @return void
     */
    protected function map_to(int $remotecourseid): void {
        connection::set_url($this->instanceid, $this->course->id, 'https://source.example.edu');
        connection::set_token($this->instanceid, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            $this->instanceid,
            'REMOTE1',
            course_result::success($remotecourseid, 'REMOTE1', 'Remote Source Course', true, 1)
        );
    }

    /**
     * A fake source that answers get_grades with $this->sourceitems, and
     * remembers what it was asked.
     *
     * It is a source from before quiz attempts could be shared: asked for
     * them, it answers as such a site does, that there is no such function.
     *
     * @return http_client
     */
    protected function source(): http_client {
        return new http_client(['mock' => function (RequestInterface $request) {
            parse_str((string) $request->getBody(), $params);
            $this->calls[] = $params;

            $body = $params['wsfunction'] === 'block_coursesync_get_quiz_attempts'
                ? ['exception' => 'dml_missing_record_exception', 'errorcode' => 'invalidrecord']
                : ['items' => $this->sourceitems];

            return Create::promiseFor(new Response(200, [], json_encode($body)));
        }]);
    }

    /**
     * A source that answers every call with the same body.
     *
     * @param array $body
     * @return http_client
     */
    protected function source_saying(array $body): http_client {
        return new http_client(['mock' => function () use ($body) {
            return Create::promiseFor(new Response(200, [], json_encode($body)));
        }]);
    }

    /**
     * One grade item as get_grades reports it.
     *
     * @param int $remotecmid
     * @param array $grades from remote_grade()
     * @param array $overrides
     * @return array
     */
    protected function remote_item(int $remotecmid, array $grades, array $overrides = []): array {
        return array_merge([
            'cmid' => $remotecmid,
            'itemnumber' => 0,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademin' => 0,
            'grademax' => 100,
            'scale' => '',
            'hidden' => 0,
            'grades' => $grades,
        ], $overrides);
    }

    /**
     * One student's grade as get_grades reports it.
     *
     * @param string $username
     * @param float|null $grade
     * @param string $feedback
     * @param int $hidden
     * @return array
     */
    protected function remote_grade(string $username, ?float $grade, string $feedback = '', int $hidden = 0): array {
        return [
            'username' => $username,
            'grade' => $grade,
            'feedback' => $feedback,
            'feedbackformat' => FORMAT_HTML,
            'hidden' => $hidden,
            'timemodified' => 1000,
        ];
    }

    /**
     * An assignment here that Course Sync copied from a remote activity.
     *
     * @param int $remotecmid
     * @param array $record extra generator fields
     * @return \stdClass
     */
    protected function copy_of(int $remotecmid, array $record = []): \stdClass {
        return $this->getDataGenerator()->create_module(
            'assign',
            array_merge(['course' => $this->course->id, 'grade' => 100], $record),
            ['idnumber' => 'coursesync-' . $remotecmid]
        );
    }

    /**
     * The grade item of an activity here.
     *
     * @param \stdClass $module
     * @param string $modname
     * @return \grade_item
     */
    protected function item(\stdClass $module, string $modname = 'assign'): \grade_item {
        return \grade_item::fetch([
            'courseid' => $module->course,
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $module->id,
            'itemnumber' => 0,
        ]);
    }

    /**
     * A student's grade here, fresh from the database.
     *
     * @param \stdClass $module
     * @param int|null $userid defaults to the student
     * @param string $modname
     * @return \grade_grade|false
     */
    protected function grade_here(\stdClass $module, ?int $userid = null, string $modname = 'assign') {
        return \grade_grade::fetch(['itemid' => $this->item($module, $modname)->id, 'userid' => $userid ?? $this->student->id]);
    }

    /**
     * The only entry a pull produced.
     *
     * @param grade_pull_result $result
     * @return \stdClass
     */
    protected function only_entry(grade_pull_result $result): \stdClass {
        $this->assertTrue($result->success, (string) $result->errorkey);
        $this->assertCount(1, $result->entries);

        return $result->entries[0];
    }

    /**
     * A new grade is written as a gradebook override, with its feedback,
     * and remembered as this plugin's.
     */
    public function test_a_new_grade_is_written_as_an_override(): void {
        global $DB;

        $assign = $this->copy_of(777);
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 80, '<p>Good work</p>')])];

        $entry = $this->only_entry(grade_pull::run($this->instanceid, $this->course->id, $this->source()));

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertSame((int) $this->student->id, $entry->userid);
        $this->assertEquals(80, $entry->grade);
        $this->assertNull($entry->localgrade);

        $grade = $this->grade_here($assign);
        $this->assertEquals(80, $grade->finalgrade);
        $this->assertGreaterThan(0, (int) $grade->overridden);
        $this->assertSame('<p>Good work</p>', $grade->feedback);

        $this->assertTrue($DB->record_exists('block_coursesync_grade', [
            'blockinstanceid' => $this->instanceid,
            'userid' => $this->student->id,
            'gradeitemid' => $this->item($assign)->id,
            'remotecmid' => 777,
        ]));

        $history = $DB->get_records('grade_grades_history', ['itemid' => $this->item($assign)->id]);
        $this->assertContains(grade_pull::SOURCE, array_column($history, 'source'));
    }

    /**
     * Only this plugin's copies are asked about, by the source's ids.
     */
    public function test_only_copies_are_asked_about(): void {
        $this->copy_of(777);
        $this->copy_of(778);
        $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id], ['idnumber' => 'mine-1']);
        $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);

        grade_pull::preview($this->instanceid, $this->course->id, $this->source());

        $this->assertCount(1, $this->calls);
        $this->assertSame('block_coursesync_get_grades', $this->calls[0]['wsfunction']);
        $this->assertSame('42', $this->calls[0]['courseid']);
        $this->assertEqualsCanonicalizing(['777', '778'], $this->calls[0]['cmids']);
    }

    /**
     * With no copies here, the other site is not asked at all.
     */
    public function test_nothing_copied_asks_nothing(): void {
        $result = grade_pull::run($this->instanceid, $this->course->id, $this->source());

        $this->assertTrue($result->success);
        $this->assertSame([], $result->entries);
        $this->assertSame([], $this->calls);
    }

    /**
     * A preview reports what would happen and writes nothing.
     */
    public function test_preview_writes_nothing(): void {
        global $DB;

        $assign = $this->copy_of(777);
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 80)])];

        $result = grade_pull::preview($this->instanceid, $this->course->id, $this->source());

        $this->assertTrue($result->preview);
        $this->assertSame(grade_pull_result::ADD, $this->only_entry($result)->outcome);
        $grade = $this->grade_here($assign);
        $this->assertTrue(!$grade || $grade->finalgrade === null);
        $this->assertSame(0, $DB->count_records('block_coursesync_grade'));
    }

    /**
     * A grade out of a different maximum is converted to this site's.
     */
    public function test_a_different_range_is_rescaled(): void {
        $assign = $this->copy_of(777, ['grade' => 100]);
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 40)], ['grademax' => 50])];

        grade_pull::run($this->instanceid, $this->course->id, $this->source());

        $this->assertEquals(80, $this->grade_here($assign)->finalgrade);
    }

    /**
     * A grade somebody gave here is kept, and the difference reported.
     */
    public function test_a_local_grade_is_kept_as_a_conflict(): void {
        global $DB;

        $assign = $this->copy_of(777);
        $this->item($assign)->update_final_grade($this->student->id, 70, 'test');
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 80)])];

        $entry = $this->only_entry(grade_pull::run($this->instanceid, $this->course->id, $this->source()));

        $this->assertSame(grade_pull_result::CONFLICT, $entry->outcome);
        $this->assertEquals(70, $entry->localgrade);
        $this->assertEquals(80, $entry->grade);
        $this->assertEquals(70, $this->grade_here($assign)->finalgrade);
        $this->assertSame(0, $DB->count_records('block_coursesync_grade'));
    }

    /**
     * A grade here that already says the same is not a conflict.
     */
    public function test_the_same_grade_is_not_a_conflict(): void {
        $assign = $this->copy_of(777);
        $this->item($assign)->update_final_grade($this->student->id, 80, 'test');
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 80)])];

        $entry = $this->only_entry(grade_pull::run($this->instanceid, $this->course->id, $this->source()));

        $this->assertSame(grade_pull_result::SAME, $entry->outcome);
    }

    /**
     * A grade an earlier pull wrote, untouched since, follows the source.
     */
    public function test_its_own_grade_is_updated(): void {
        $assign = $this->copy_of(777);
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 80, 'First')])];
        grade_pull::run($this->instanceid, $this->course->id, $this->source());

        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 90, 'Regraded')])];
        $entry = $this->only_entry(grade_pull::run($this->instanceid, $this->course->id, $this->source()));

        $this->assertSame(grade_pull_result::UPDATE, $entry->outcome);
        $this->assertEquals(80, $entry->localgrade);
        $grade = $this->grade_here($assign);
        $this->assertEquals(90, $grade->finalgrade);
        $this->assertSame('Regraded', $grade->feedback);

        // And again: the update is remembered as this plugin's too.
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 95, 'Regraded')])];
        $entry = $this->only_entry(grade_pull::run($this->instanceid, $this->course->id, $this->source()));
        $this->assertSame(grade_pull_result::UPDATE, $entry->outcome);
        $this->assertEquals(95, $this->grade_here($assign)->finalgrade);
    }

    /**
     * Once somebody here changes a pulled grade - mark or feedback - it is
     * theirs, and a later pull leaves it alone.
     */
    public function test_a_pulled_grade_changed_here_is_kept(): void {
        $assign = $this->copy_of(777);
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 80, 'From there')])];
        grade_pull::run($this->instanceid, $this->course->id, $this->source());

        $this->item($assign)->update_final_grade($this->student->id, false, 'test', 'Changed here', FORMAT_HTML);

        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 90, 'From there')])];
        $entry = $this->only_entry(grade_pull::run($this->instanceid, $this->course->id, $this->source()));

        $this->assertSame(grade_pull_result::CONFLICT, $entry->outcome);
        $grade = $this->grade_here($assign);
        $this->assertEquals(80, $grade->finalgrade);
        $this->assertSame('Changed here', $grade->feedback);
    }

    /**
     * The reason for the override: the quiz here has no attempts for the
     * student, and recalculating its grades must not wipe the pulled one.
     */
    public function test_a_quiz_regrade_does_not_wipe_a_pulled_grade(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $quiz = $this->getDataGenerator()->create_module(
            'quiz',
            ['course' => $this->course->id, 'grade' => 10, 'sumgrades' => 10],
            ['idnumber' => 'coursesync-900']
        );
        $this->sourceitems = [$this->remote_item(900, [$this->remote_grade('sam', 7)], ['grademax' => 10])];

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->source());
        $this->assertSame(['attemptsunavailable'], $result->notes, 'this source cannot share attempts');
        $this->assertEquals(7, $this->grade_here($quiz, null, 'quiz')->finalgrade);

        // Nullify-if-none: exactly what the quiz does for a student with no
        // attempts, pushing an empty grade.
        quiz_update_grades($quiz, $this->student->id, true);
        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_all_final_grades();
        quiz_update_grades($quiz);

        $this->assertEquals(7, $this->grade_here($quiz, null, 'quiz')->finalgrade);
    }

    /**
     * Students who cannot be matched are skipped with the reason.
     */
    public function test_unmatched_students_are_skipped(): void {
        $this->copy_of(777);
        $this->getDataGenerator()->create_user(['username' => 'elsewhere']);
        $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['username' => 'paused'],
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        $this->sourceitems = [$this->remote_item(777, [
            $this->remote_grade('nobody', 50),
            $this->remote_grade('elsewhere', 60),
            $this->remote_grade('paused', 70),
        ])];

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->source());

        $reasons = [];

        foreach ($result->entries as $entry) {
            $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
            $reasons[$entry->username] = $entry->reason;
        }

        $this->assertSame([
            'nobody' => 'gradeskipnouser',
            'elsewhere' => 'gradeskipnotenrolled',
            'paused' => 'gradeskipnotenrolled',
        ], $reasons);
    }

    /**
     * A locked grade item, or a locked grade, is not written to.
     */
    public function test_locked_grades_are_skipped(): void {
        $lockeditem = $this->copy_of(777);
        $this->item($lockeditem)->set_locked(1);

        $lockedgrade = $this->copy_of(778);
        $this->item($lockedgrade)->update_final_grade($this->student->id, 10, 'test');
        $this->grade_here($lockedgrade)->set_locked(1);

        $this->sourceitems = [
            $this->remote_item(777, [$this->remote_grade('sam', 80)]),
            $this->remote_item(778, [$this->remote_grade('sam', 90)]),
        ];

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->source());

        $this->assertSame(['gradeskipitemlocked', 'gradeskiplocked'], array_column($result->entries, 'reason'));
        $this->assertEquals(10, $this->grade_here($lockedgrade)->finalgrade);
    }

    /**
     * A scale grade is only taken onto the same scale.
     */
    public function test_scales_must_match(): void {
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor,Fair,Good']);
        $same = $this->copy_of(777, ['grade' => -$scale->id]);
        $this->copy_of(778, ['grade' => -$scale->id]);

        // Spacing around the commas does not make it a different scale.
        $this->sourceitems = [
            $this->remote_item(777, [$this->remote_grade('sam', 3)], [
                'gradetype' => GRADE_TYPE_SCALE,
                'scale' => 'Poor, Fair, Good',
            ]),
            $this->remote_item(778, [$this->remote_grade('sam', 2)], [
                'gradetype' => GRADE_TYPE_SCALE,
                'scale' => 'No,Yes',
            ]),
        ];

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->source());

        $this->assertSame([grade_pull_result::ADD, grade_pull_result::SKIPPED], array_column($result->entries, 'outcome'));
        $this->assertSame('gradeskipscale', $result->entries[1]->reason);
        $this->assertEquals(3, $this->grade_here($same)->finalgrade);
    }

    /**
     * Points there and a scale here (or the reverse) do not mix.
     */
    public function test_grade_types_must_match(): void {
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor,Fair,Good']);
        $this->copy_of(777, ['grade' => -$scale->id]);
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 3)])];

        $entry = $this->only_entry(grade_pull::run($this->instanceid, $this->course->id, $this->source()));

        $this->assertSame('gradeskiptype', $entry->reason);
    }

    /**
     * A grade the source keeps hidden is hidden here too - whether the grade
     * itself or its whole grade item is hidden there.
     */
    public function test_hidden_grades_stay_hidden(): void {
        $hiddengrade = $this->copy_of(777);
        $hiddenitem = $this->copy_of(778);
        $this->sourceitems = [
            $this->remote_item(777, [$this->remote_grade('sam', 80, '', 1)]),
            $this->remote_item(778, [$this->remote_grade('sam', 80)], ['hidden' => 2000000000]),
        ];

        grade_pull::run($this->instanceid, $this->course->id, $this->source());

        $this->assertEquals(1, $this->grade_here($hiddengrade)->hidden);
        $this->assertEquals(2000000000, $this->grade_here($hiddenitem)->hidden);
    }

    /**
     * Feedback without a grade still comes across.
     */
    public function test_feedback_only(): void {
        $assign = $this->copy_of(777);
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', null, 'Please resubmit')])];

        $this->assertSame(
            grade_pull_result::ADD,
            $this->only_entry(grade_pull::run($this->instanceid, $this->course->id, $this->source()))->outcome
        );

        $grade = $this->grade_here($assign);
        $this->assertNull($grade->finalgrade);
        $this->assertSame('Please resubmit', $grade->feedback);
    }

    /**
     * Feedback is cleaned on arrival, like everything else from the source.
     */
    public function test_feedback_is_cleaned(): void {
        $assign = $this->copy_of(777);
        $this->sourceitems = [$this->remote_item(777, [
            $this->remote_grade('sam', 50, '<p>Fine</p><script>alert(1)</script>'),
        ])];

        grade_pull::run($this->instanceid, $this->course->id, $this->source());

        $this->assertStringNotContainsString('<script', $this->grade_here($assign)->feedback);
    }

    /**
     * A copy graded here that the source says nothing about is reported.
     */
    public function test_a_copy_the_source_has_no_grades_for(): void {
        $this->copy_of(777);
        $this->sourceitems = [];

        $entry = $this->only_entry(grade_pull::preview($this->instanceid, $this->course->id, $this->source()));

        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('gradeskipnotonsource', $entry->reason);
    }

    /**
     * With grade pulling switched off here, nothing happens - not even a
     * preview, and the other site is not asked.
     */
    public function test_switched_off_here(): void {
        $this->copy_of(777);
        set_config('allowgradepull', 0, 'block_coursesync');

        $results = [
            grade_pull::preview($this->instanceid, $this->course->id, $this->source()),
            grade_pull::run($this->instanceid, $this->course->id, $this->source()),
        ];

        foreach ($results as $result) {
            $this->assertFalse($result->success);
            $this->assertSame('errorgradepulloff', $result->errorkey);
        }

        $this->assertSame([], $this->calls);
    }

    /**
     * With grade sharing switched off on the other site, the teacher is
     * told that it is the other site's setting.
     */
    public function test_switched_off_there(): void {
        $source = $this->getDataGenerator()->create_course();
        $original = $this->getDataGenerator()->create_module('assign', ['course' => $source->id]);
        $this->copy((int) $original->cmid, $this->course);
        $this->map_to((int) $source->id);
        set_config('allowgradeexport', 0, 'block_coursesync');

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->local_source());

        $this->assertFalse($result->success);
        $this->assertSame('errorgradeexportoff', $result->errorkey);
    }

    /**
     * An editing teacher may pull grades by default; a non-editing teacher
     * may not, and neither may an editing teacher who cannot edit grades.
     */
    public function test_who_may_pull(): void {
        global $DB;

        $this->copy_of(777);
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 80)])];
        $generator = $this->getDataGenerator();
        $editor = $generator->create_and_enrol($this->course, 'editingteacher');
        $marker = $generator->create_and_enrol($this->course, 'teacher');

        $this->setUser($marker);
        $this->assertSame(
            'errornogradepullpermission',
            grade_pull::preview($this->instanceid, $this->course->id, $this->source())->errorkey
        );

        $this->setUser($editor);
        $this->assertTrue(grade_pull::preview($this->instanceid, $this->course->id, $this->source())->success);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('moodle/grade:edit', CAP_PROHIBIT, $roleid, \context_course::instance($this->course->id)->id);

        $this->assertSame(
            'errornogradepullpermission',
            grade_pull::run($this->instanceid, $this->course->id, $this->source())->errorkey
        );
        $this->assertCount(1, $this->calls);
    }

    /**
     * Refused for lack of the grades permission, the teacher is told which
     * permission it is.
     */
    public function test_a_refusal_names_the_grades_permission(): void {
        $this->copy_of(777);

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->source_saying([
            'exception' => 'required_capability_exception',
            'errorcode' => 'nopermissions',
            'message' => 'Sorry',
        ]));

        $this->assertFalse($result->success);
        $this->assertSame('errornogradepermission', $result->errorkey);
    }

    /**
     * A source from before grade sync has no such function; the teacher is
     * told to upgrade it, not that Course Sync is missing there.
     */
    public function test_a_source_too_old_for_grades(): void {
        $this->copy_of(777);

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->source_saying([
            'exception' => 'dml_missing_record_exception',
            'errorcode' => 'invalidrecord',
            'message' => "Can't find data record in database table external_functions.",
        ]));

        $this->assertSame('errorgradesourceoutdated', $result->errorkey);
    }

    /**
     * Anything but the expected shape is refused whole.
     */
    public function test_a_malformed_answer_is_refused(): void {
        $this->copy_of(777);

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->source_saying([
            'items' => [['cmid' => 777, 'gradetype' => 1, 'grades' => [['grade' => 5]]]],
        ]));

        $this->assertFalse($result->success);
        $this->assertSame('errorbadresponse', $result->errorkey);
    }

    /**
     * A pull does not start while a sync of the same block is running.
     */
    public function test_a_running_sync_holds_it_off(): void {
        global $CFG;

        // Postgres advisory locks - the default factory here - are re-entrant
        // within one session, so the test would never see its own lock as
        // held. A file lock is not.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';

        $this->copy_of(777);
        $lock = \core\lock\lock_config::get_lock_factory('block_coursesync')
            ->get_lock('run-' . $this->instanceid, 0, syncer::LOCK_LIFETIME);

        try {
            $result = grade_pull::run($this->instanceid, $this->course->id, $this->source());
        } finally {
            $lock->release();
        }

        $this->assertFalse($result->success);
        $this->assertSame('errorsyncinprogress', $result->errorkey);
        $this->assertSame([], $this->calls);
    }

    /**
     * A pull is written to the sync history as counts per activity - never
     * which students or what grades. A preview is not written at all.
     */
    public function test_a_pull_is_in_the_history_as_counts_only(): void {
        global $DB;

        $assign = $this->copy_of(777, ['name' => 'Essay one']);
        $this->item($assign)->update_final_grade($this->student->id, 10, 'test');
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['username' => 'pat']);
        $this->sourceitems = [$this->remote_item(777, [
            $this->remote_grade('sam', 80),
            $this->remote_grade('pat', 70),
            $this->remote_grade('nobody', 60),
        ])];

        grade_pull::preview($this->instanceid, $this->course->id, $this->source());
        $this->assertSame(0, $DB->count_records('block_coursesync_run'));

        grade_pull::run($this->instanceid, $this->course->id, $this->source());

        $runs = history::get_runs($this->instanceid);
        $this->assertCount(1, $runs);
        $run = $runs[0];
        $this->assertSame(history::KIND_GRADES, $run->kind);
        $this->assertSame(history::STATUS_REVIEW, $run->status);
        $this->assertEquals(1, $run->pulledcount);
        $this->assertEquals(1, $run->conflictcount);
        $this->assertEquals(1, $run->skippedcount);
        $this->assertSame([[
            'kind' => grade_pull_result::KIND_GRADE,
            'cmid' => (int) $assign->cmid,
            'name' => 'Essay one',
            'add' => 1,
            'update' => 0,
            'same' => 0,
            'conflict' => 1,
            'skipped' => 1,
            'released' => 0,
        ]], $run->pulled);

        $stored = $DB->get_record('block_coursesync_run', ['id' => $run->id]);
        foreach (['sam', 'pat', 'nobody', (string) $other->id] as $personal) {
            $this->assertStringNotContainsString('"' . $personal . '"', $stored->pulled . $stored->conflicts . $stored->others);
        }
    }

    /**
     * A pull that could not reach the other site is written down as failed;
     * one the person was never allowed to make is not written at all.
     */
    public function test_what_the_history_records_of_a_failure(): void {
        global $DB;

        $this->copy_of(777);
        $badtoken = $this->source_saying(['exception' => 'x', 'errorcode' => 'invalidtoken']);
        grade_pull::run($this->instanceid, $this->course->id, $badtoken);

        $runs = history::get_runs($this->instanceid);
        $this->assertCount(1, $runs);
        $this->assertSame(history::STATUS_FAILED, $runs[0]->status);
        $this->assertSame('errorbadtoken', $runs[0]->errorkey);

        set_config('allowgradepull', 0, 'block_coursesync');
        grade_pull::run($this->instanceid, $this->course->id, $this->source());
        $this->assertSame(1, $DB->count_records('block_coursesync_run'));
    }

    /**
     * A grade pull lists activities, but it did not copy them: it must never
     * be taken for the run that pulled a copy, which is what tells whether
     * the source has changed it since.
     */
    public function test_a_grade_pull_is_not_where_a_copy_came_from(): void {
        global $DB;

        $assign = $this->copy_of(777);
        $DB->insert_record('block_coursesync_run', (object) [
            'blockinstanceid' => $this->instanceid,
            'courseid' => $this->course->id,
            'userid' => 2,
            'kind' => history::KIND_GRADES,
            'timestarted' => 5000,
            'status' => history::STATUS_OK,
            // Shaped like an activity run's list, which is exactly the risk.
            'pulled' => json_encode([['remotecmid' => 777, 'localcmid' => (int) $assign->cmid]]),
        ]);

        $this->assertNull(history::pulled_at($this->instanceid, 777, (int) $assign->cmid));
    }

    /**
     * Deleting the block forgets what it pulled, but the grades stay.
     */
    public function test_deleting_the_block_keeps_the_grades(): void {
        global $DB;

        $assign = $this->copy_of(777);
        $this->sourceitems = [$this->remote_item(777, [$this->remote_grade('sam', 80)])];
        grade_pull::run($this->instanceid, $this->course->id, $this->source());

        blocks_delete_instance($DB->get_record('block_instances', ['id' => $this->instanceid]));

        $this->assertSame(0, $DB->count_records('block_coursesync_grade'));
        $this->assertEquals(80, $this->grade_here($assign)->finalgrade);
    }

    /**
     * The whole way round within one site: a real copy of a graded
     * assignment, and the source's own get_grades answering.
     */
    public function test_from_a_real_source_course(): void {
        $source = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->student->id, $source->id, 'student');
        $original = $this->getDataGenerator()->create_module('assign', ['course' => $source->id, 'grade' => 20]);
        $this->item($original)->update_final_grade($this->student->id, 15, 'test', 'Well argued', FORMAT_HTML);

        [$cm] = $this->copy((int) $original->cmid, $this->course);
        $this->map_to((int) $source->id);

        $entry = $this->only_entry(grade_pull::run($this->instanceid, $this->course->id, $this->local_source()));

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertSame((int) $cm->id, $entry->cmid);
        $grade = \grade_grade::fetch([
            'itemid' => \grade_item::fetch([
                'courseid' => $this->course->id,
                'itemtype' => 'mod',
                'itemmodule' => 'assign',
                'iteminstance' => $cm->instance,
            ])->id,
            'userid' => $this->student->id,
        ]);
        $this->assertEquals(15, $grade->finalgrade);
        $this->assertSame('Well argued', $grade->feedback);
    }
}
