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
use block_coursesync\local\mark_fixtures;
use block_coursesync\local\mark_writer;
use block_coursesync\local\marks;
use block_coursesync\local\submission_fixtures;
use core\http_client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local/source_on_this_site.php');
require_once(__DIR__ . '/local/submission_fixtures.php');
require_once(__DIR__ . '/local/mark_fixtures.php');

/**
 * Tests for pulling teachers' marks and written feedback into this site.
 *
 * The "other site" is this site: a source course holds the marks, a
 * destination course holds the copy of the assignment, and every call between
 * them runs the real source functions, so the description, the transfer and the
 * writing are exercised together.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(marks_pull::class)]
#[CoversClass(marks_result::class)]
#[CoversClass(mark_writer::class)]
final class marks_pull_test extends advanced_testcase {
    use local\source_on_this_site;
    use submission_fixtures;
    use mark_fixtures;

    /** @var \stdClass The course the work was handed in to. */
    protected \stdClass $source;

    /** @var \stdClass The destination course. */
    protected \stdClass $course;

    /** @var int The Course Sync block in the destination. */
    protected int $instanceid;

    /** @var \stdClass A student in both courses, username "sam". */
    protected \stdClass $sam;

    /** @var \stdClass The assignment on the source. */
    protected \stdClass $there;

    /** @var \stdClass The copy of it here. */
    protected \stdClass $here;

    /** @var callable[] Changes to what the source says, by function name. */
    protected array $tamper = [];

    /** @var array[] Parameters of every call the source received. */
    protected array $calls = [];

    /**
     * A source course with an assignment, a destination course with its copy,
     * a block mapped between them, and a student in both.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $this->source = $generator->create_course();
        $this->course = $generator->create_course();
        $this->sam = $generator->create_and_enrol($this->source, 'student', ['username' => 'sam']);
        $generator->enrol_user($this->sam->id, $this->course->id, 'student');

        $this->there = $this->markable($this->source, ['name' => 'Essay']);
        $this->here = $this->markable($this->course, ['name' => 'Essay'], ['idnumber' => 'coursesync-' . $this->there->cmid]);

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

        connection::set_url($this->instanceid, $this->course->id, 'https://source.example.edu');
        connection::set_token($this->instanceid, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            $this->instanceid,
            'REMOTE1',
            course_result::success((int) $this->source->id, 'REMOTE1', 'Remote Source Course', true, 1)
        );

        set_config('allowmarkspull', 1, 'block_coursesync');
        set_config('allowmarksexport', 1, 'block_coursesync');
    }

    /**
     * The source: this site's own functions, which a test can make lie.
     *
     * @return http_client
     */
    protected function source(): http_client {
        return new http_client(['mock' => function (RequestInterface $request) {
            parse_str((string) $request->getBody(), $params);
            $this->calls[] = $params;
            $function = (string) ($params['wsfunction'] ?? '');

            // The source answers as its own sync account, not as whoever is
            // pulling here.
            $puller = $GLOBALS['USER'];
            $this->setAdminUser();

            try {
                $body = $this->answer($function, $params);

                if (isset($this->tamper[$function])) {
                    $body = ($this->tamper[$function])($body, $params);
                }
            } catch (\moodle_exception $e) {
                $body = ['exception' => get_class($e), 'errorcode' => $e->errorcode, 'message' => $e->getMessage()];
            } finally {
                $this->setUser($puller);
            }

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
        }]);
    }

    /**
     * Preview a pull.
     *
     * @return grade_pull_result
     */
    protected function preview(): grade_pull_result {
        return marks_pull::preview($this->instanceid, $this->course->id, $this->source());
    }

    /**
     * Pull for real.
     *
     * @return grade_pull_result
     */
    protected function pull(): grade_pull_result {
        return marks_pull::run($this->instanceid, $this->course->id, $this->source());
    }

    /**
     * The one entry a pull produced.
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
     * Mark the student on the source.
     *
     * @param float|null $grade
     * @param string|null $comment
     * @param string[] $files
     * @return void
     */
    protected function mark_there(?float $grade, ?string $comment = null, array $files = []): void {
        $this->give_mark($this->there, $this->sam, $grade, $comment, $files);
    }

    /**
     * Mark the student here, as a teacher here would.
     *
     * @param float|null $grade
     * @param string|null $comment
     * @return void
     */
    protected function mark_here(?float $grade, ?string $comment = null): void {
        $this->give_mark($this->here, $this->sam, $grade, $comment);
    }

    /**
     * The assign_grades row of the student here, or null.
     *
     * @return \stdClass|null
     */
    protected function grade_here(): ?\stdClass {
        return mark_writer::local_grade((int) $this->here->id, (int) $this->sam->id);
    }

    /**
     * The comment text of the student's mark here.
     *
     * @return string|null
     */
    protected function comment_here(): ?string {
        global $DB;

        $grade = $this->grade_here();
        $text = $grade ? $DB->get_field('assignfeedback_comments', 'commenttext', ['grade' => $grade->id]) : false;

        return $text === false ? null : $text;
    }

    /**
     * What the gradebook shows for the student here.
     *
     * @return float|null
     */
    protected function gradebook_here(): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'assign', $this->here->id, [$this->sam->id]);
        $final = $grades->items[0]->grades[$this->sam->id]->grade ?? null;

        return $final === null ? null : (float) $final;
    }

    /**
     * A preview shows what would happen and writes nothing.
     */
    public function test_a_preview_writes_nothing(): void {
        global $DB;

        $this->mark_there(80, 'Good');

        $entry = $this->only_entry($this->preview());

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertSame('sam', $entry->username);
        $this->assertEquals(80, $entry->grade);
        $this->assertNull($entry->localgrade);
        $this->assertNull($this->grade_here());
        $this->assertSame(0, $DB->count_records('block_coursesync_mark'));
        $this->assertSame(0, $DB->count_records('block_coursesync_run'));
    }

    /**
     * A pull writes a real assign grade and its comment, so the grader screen
     * and the gradebook agree; the person pulling is the grader.
     */
    public function test_a_pull_writes_the_grade_and_the_comment(): void {
        global $DB, $USER;

        $this->mark_there(80, '<p>Good work</p>', ['notes.txt' => 'see me']);

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertNull($entry->reason);

        $grade = $this->grade_here();
        $this->assertEquals(80, $grade->grade);
        $this->assertEquals($USER->id, $grade->grader);
        $this->assertSame('<p>Good work</p>', $this->comment_here());
        $this->assertEquals(80, $this->gradebook_here());

        $files = get_file_storage()->get_area_files(
            \context_module::instance($this->here->cmid)->id,
            marks::COMPONENT,
            marks::AREA,
            $grade->id,
            'filename',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame('see me', reset($files)->get_content());

        $ledger = $DB->get_record('block_coursesync_mark', ['assignid' => $this->here->id, 'userid' => $this->sam->id]);
        $this->assertEquals($grade->id, $ledger->gradeid);
        $this->assertEquals($this->there->cmid, $ledger->remotecmid);
        $this->assertSame(40, strlen($ledger->fingerprint));
        $this->assertSame(40, strlen($ledger->localfingerprint));

        $run = $DB->get_record('block_coursesync_run', ['blockinstanceid' => $this->instanceid], '*', MUST_EXIST);
        $this->assertSame(history::KIND_MARKS, $run->kind);
        $this->assertEquals(1, $run->pulledcount);
        // Counts per assignment, never who or what.
        $this->assertStringNotContainsString('"sam"', $run->pulled);
        $this->assertStringNotContainsString('Good work', $run->pulled);
        $this->assertStringContainsString('"kind":"mark"', $run->pulled);
    }

    /**
     * A comment on its own comes across, with no number.
     */
    public function test_a_comment_only_mark(): void {
        $this->mark_there(null, 'Please resubmit');

        $this->assertSame(grade_pull_result::ADD, $this->only_entry($this->pull())->outcome);
        $this->assertSame('Please resubmit', $this->comment_here());
        $this->assertFalse(marks::is_marked($this->grade_here()));
        $this->assertNull($this->gradebook_here());
    }

    /**
     * A second pull of the same mark does nothing.
     */
    public function test_pulling_twice_changes_nothing(): void {
        global $DB;

        $this->mark_there(80, 'Good');
        $this->pull();
        $before = $this->grade_here();

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::SAME, $entry->outcome);
        $this->assertSame(1, $DB->count_records('assign_grades', ['assignment' => $this->here->id]));
        $this->assertSame(1, $DB->count_records('block_coursesync_mark'));
        $this->assertEquals($before->timemodified, $this->grade_here()->timemodified);
    }

    /**
     * A mark changed on the source replaces the one an earlier pull wrote, as
     * long as nobody here has touched it.
     */
    public function test_an_untouched_mark_is_updated(): void {
        $this->mark_there(60, 'Fine');
        $this->pull();
        $this->mark_there(75, 'Better now');

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::UPDATE, $entry->outcome);
        $this->assertEquals(75, $this->grade_here()->grade);
        $this->assertSame('Better now', $this->comment_here());
        $this->assertEquals(75, $this->gradebook_here());
    }

    /**
     * A mark someone here changed is kept, however the source changes.
     */
    public function test_a_mark_changed_here_is_kept(): void {
        $this->mark_there(60, 'Fine');
        $this->pull();
        $this->mark_here(65, 'My own view');
        $this->mark_there(75, 'Better now');

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::CONFLICT, $entry->outcome);
        $this->assertSame('markreasonchanged', $entry->reason);
        $this->assertEquals(65, $this->grade_here()->grade);
        $this->assertSame('My own view', $this->comment_here());
    }

    /**
     * A comment edited here counts as a change even when the number is the same.
     */
    public function test_a_comment_edited_here_is_kept(): void {
        $this->mark_there(60, 'Fine');
        $this->pull();
        $this->mark_here(60, 'Reworded by me');
        $this->mark_there(70, 'Fine');

        $this->assertSame(grade_pull_result::CONFLICT, $this->only_entry($this->pull())->outcome);
        $this->assertSame('Reworded by me', $this->comment_here());
    }

    /**
     * A mark that was here before, and that Course Sync did not write, is
     * kept - unless it already says the same.
     */
    public function test_an_existing_mark_is_kept_unless_identical(): void {
        $this->mark_there(80, 'Good');
        $this->mark_here(55, 'Mine');

        $entry = $this->only_entry($this->pull());
        $this->assertSame(grade_pull_result::CONFLICT, $entry->outcome);
        $this->assertSame('markreasonexists', $entry->reason);
        $this->assertEquals(55, $this->grade_here()->grade);

        $this->mark_here(80, 'Good');
        $this->assertSame(grade_pull_result::SAME, $this->only_entry($this->pull())->outcome);
    }

    /**
     * A mark that was pulled and then removed here is not brought back.
     */
    public function test_a_removed_mark_is_not_brought_back(): void {
        global $DB;

        $this->mark_there(80, 'Good');
        $this->pull();
        $grade = $this->grade_here();
        $DB->delete_records('assignfeedback_comments', ['grade' => $grade->id]);
        $row = $this->assign_object($this->here)->get_user_grade((int) $this->sam->id, false);
        $row->grade = -1;
        $this->assign_object($this->here)->update_grade($row);

        $this->mark_there(90, 'Even better');
        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('markreasondeleted', $entry->reason);
        $this->assertFalse(marks::is_marked($this->grade_here()));
    }

    /**
     * A mark is put into the range of the assignment it lands in.
     */
    public function test_marks_are_scaled_to_the_local_maximum(): void {
        global $DB;

        // The copy of the 100-point assignment here is out of 50.
        $DB->set_field('assign', 'grade', 50, ['id' => $this->here->id]);

        $this->mark_there(80, 'Good');
        $entry = $this->only_entry($this->pull());

        $this->assertEquals(40, $entry->grade);
        $this->assertEquals(40, $this->grade_here()->grade);
    }

    /**
     * Under a marking workflow, only a released mark is pulled, and it lands
     * released.
     */
    public function test_marking_workflow(): void {
        global $DB;

        $source = $this->markable($this->source, ['name' => 'Flow', 'markingworkflow' => 1]);
        $copy = $this->markable(
            $this->course,
            ['name' => 'Flow', 'markingworkflow' => 1],
            ['idnumber' => 'coursesync-' . $source->cmid]
        );
        $this->give_mark($source, $this->sam, 70, 'draft');

        $result = $this->pull();
        $entries = array_filter($result->entries, fn($e) => $e->cmid == $copy->cmid);
        $this->assertSame([], $entries);

        $object = $this->assign_object($source);
        $flags = $object->get_user_flags((int) $this->sam->id, true);
        $flags->workflowstate = marks::STATE_RELEASED;
        $object->update_user_flags($flags);

        $this->pull();

        $this->assertSame(
            marks::STATE_RELEASED,
            $DB->get_field('assign_user_flags', 'workflowstate', ['assignment' => $copy->id, 'userid' => $this->sam->id])
        );
        $this->assertEquals(70, $DB->get_field('assign_grades', 'grade', ['assignment' => $copy->id, 'userid' => $this->sam->id]));
    }

    /**
     * Every reason a mark cannot be written is said, and nothing is written.
     */
    public function test_reasons_a_mark_is_not_written(): void {
        global $DB;

        $this->mark_there(80, 'Good');

        // Comments off here.
        $DB->set_field('assign_plugin_config', 'value', 0, [
            'assignment' => $this->here->id, 'plugin' => 'comments', 'subtype' => 'assignfeedback', 'name' => 'enabled',
        ]);
        $entry = $this->only_entry($this->pull());
        $this->assertSame('markreasonnocomments', $entry->reason);
        $this->assertNull($this->grade_here());
        $DB->set_field('assign_plugin_config', 'value', 1, [
            'assignment' => $this->here->id, 'plugin' => 'comments', 'subtype' => 'assignfeedback', 'name' => 'enabled',
        ]);

        // Graded on a scale here.
        $DB->set_field('assign', 'grade', -1, ['id' => $this->here->id]);
        $this->assertSame('markreasonlocalgrade', $this->only_entry($this->pull())->reason);
        $DB->set_field('assign', 'grade', 100, ['id' => $this->here->id]);

        // A team assignment here.
        $DB->set_field('assign', 'teamsubmission', 1, ['id' => $this->here->id]);
        $this->assertSame('subreasonteamlocal', $this->only_entry($this->pull())->reason);
        $DB->set_field('assign', 'teamsubmission', 0, ['id' => $this->here->id]);

        // Locked in the gradebook here.
        $item = $this->grade_item_here();
        $item->set_locked(time());
        $this->assertSame('markreasonlocked', $this->only_entry($this->pull())->reason);
        $item->set_locked(0);

        // And with every obstacle gone it goes through.
        $this->assertSame(grade_pull_result::ADD, $this->only_entry($this->pull())->outcome);
    }

    /**
     * The source's own reasons come through: a scale there, a team there.
     */
    public function test_the_sources_reasons(): void {
        global $DB;

        $this->mark_there(80, 'Good');
        $DB->set_field('assign', 'grade', -1, ['id' => $this->there->id]);

        $this->assertSame('markreasonscale', $this->only_entry($this->pull())->reason);

        $DB->set_field('assign', 'grade', 100, ['id' => $this->there->id]);
        $DB->set_field('assign', 'teamsubmission', 1, ['id' => $this->there->id]);
        $this->assertSame('markreasonteam', $this->only_entry($this->pull())->reason);
    }

    /**
     * An assignment deleted on the source is reported, not guessed at.
     */
    public function test_a_deleted_original_is_reported(): void {
        $this->mark_there(80, 'Good');
        course_delete_module($this->there->cmid);

        $entry = $this->only_entry($this->pull());

        $this->assertSame('subreasongone', $entry->reason);
        $this->assertNull($this->grade_here());
    }

    /**
     * A grade sync override that nobody has touched gives way to the real
     * grade; one a teacher here set does not.
     */
    public function test_a_grade_sync_override_is_released(): void {
        global $DB;

        $this->mark_there(80, 'Good');
        $item = $this->grade_item_here();
        $this->grade_sync_wrote($item, 70);
        $this->assertEquals(70, $this->gradebook_here());

        $result = $this->pull();

        $this->assertCount(1, $result->with_outcome(grade_pull_result::RELEASED));
        $this->assertEquals(80, $this->gradebook_here());
        $this->assertSame(0, $DB->count_records('block_coursesync_grade'));
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->sam->id]);
        $this->assertFalse($grade->is_overridden());
    }

    /**
     * An override a teacher changed after grade sync wrote it is theirs: the
     * real grade is written, the override stays.
     */
    public function test_an_override_changed_here_stays(): void {
        $this->mark_there(80, 'Good');
        $item = $this->grade_item_here();
        $this->grade_sync_wrote($item, 70);
        $item->update_final_grade($this->sam->id, 66, 'editgrade');

        $result = $this->pull();

        $this->assertSame([], $result->with_outcome(grade_pull_result::RELEASED));
        $this->assertEquals(80, $this->grade_here()->grade);
        $this->assertEquals(66, $this->gradebook_here());
    }

    /**
     * Once a marks pull has written a student's mark, grade sync leaves that
     * student's grade alone.
     */
    public function test_grade_sync_leaves_a_pulled_mark_alone(): void {
        $this->mark_there(80, 'Good');
        set_config('allowgradepull', 1, 'block_coursesync');
        set_config('allowgradeexport', 1, 'block_coursesync');
        $this->pull();

        $result = grade_pull::preview($this->instanceid, $this->course->id, $this->source());

        $entries = array_filter($result->entries, fn($e) => $e->cmid == $this->here->cmid && $e->username === 'sam');
        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
            $this->assertSame('gradeskipmarks', $entry->reason);
        }
    }

    /**
     * Pulling is refused when the site's switch is off, or the person may not.
     */
    public function test_switches_and_permission(): void {
        $this->mark_there(80, 'Good');

        set_config('allowmarkspull', 0, 'block_coursesync');
        $this->assertSame('errormarkspulloff', $this->pull()->errorkey);
        $this->assertNull($this->grade_here());
        set_config('allowmarkspull', 1, 'block_coursesync');

        set_config('allowmarksexport', 0, 'block_coursesync');
        $this->assertSame('errormarksexportoff', $this->pull()->errorkey);
        set_config('allowmarksexport', 1, 'block_coursesync');

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);
        $this->assertSame('errornomarkspullpermission', $this->pull()->errorkey);
        $this->assertNull($this->grade_here());
    }

    /**
     * Only the students this person may reach are asked about, and the others
     * are not mentioned at all.
     */
    public function test_unknown_students_are_not_mentioned(): void {
        $pat = $this->getDataGenerator()->create_and_enrol($this->source, 'student', ['username' => 'pat']);
        $this->give_mark($this->there, $pat, 90, 'Pat only exists there');
        $this->mark_there(80, 'Good');

        $result = $this->pull();

        $this->assertSame(['sam'], array_column($result->entries, 'username'));
        $this->assertNotContains('pat', array_column($this->calls, 'usernames'));
    }

    /**
     * A file that does not arrive intact stops the student's mark, and writes
     * nothing for them.
     */
    public function test_a_corrupt_file_writes_nothing(): void {
        global $DB;

        $this->mark_there(80, 'Good', ['notes.txt' => 'abcdef']);
        $this->tamper['block_coursesync_get_mark_file'] = function (array $body): array {
            $body['content'] = base64_encode('XXXXXX');

            return $body;
        };

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('subreasoncorrupt', $entry->reason);
        $this->assertNull($this->grade_here());
        $this->assertSame(0, $DB->count_records('block_coursesync_mark'));
    }

    /**
     * A source that lies about a file's name is not believed.
     */
    public function test_a_dishonest_file_description_is_refused(): void {
        $this->mark_there(80, 'Good', ['notes.txt' => 'abc']);
        $this->tamper['block_coursesync_get_marks'] = function (array $body): array {
            $body['items'][0]['marks'][0]['files'][0]['filepath'] = '/../../';

            return $body;
        };

        $this->assertSame('errorbadresponse', $this->pull()->errorkey);
        $this->assertNull($this->grade_here());
    }

    /**
     * The grade item of the assignment here.
     *
     * @return \grade_item
     */
    protected function grade_item_here(): \grade_item {
        return \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $this->here->id,
            'itemnumber' => 0,
        ]);
    }

    /**
     * Only the grade_sync side: write the override grade sync would, with its record.
     *
     * @param \grade_item $item
     * @param float $grade
     * @return void
     */
    protected function grade_sync_wrote(\grade_item $item, float $grade): void {
        global $DB;

        $item->update_final_grade($this->sam->id, $grade, grade_pull::SOURCE, 'sync feedback', FORMAT_HTML);
        $stored = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->sam->id]);
        $DB->insert_record('block_coursesync_grade', (object) [
            'blockinstanceid' => $this->instanceid,
            'courseid' => $this->course->id,
            'userid' => $this->sam->id,
            'gradeitemid' => $item->id,
            'remotecmid' => $this->there->cmid,
            'itemnumber' => 0,
            'finalgrade' => $stored->finalgrade,
            'feedbackhash' => sha1((string) $stored->feedback),
            'remotetime' => time(),
            'timepulled' => time(),
        ]);
    }

    /**
     * A comment on its own, with no number, is still a mark here: it is not
     * written over.
     */
    public function test_a_comment_only_mark_here_is_in_the_way(): void {
        $this->mark_there(80, 'Good');
        $this->mark_here(null, 'I wrote this');

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::CONFLICT, $entry->outcome);
        $this->assertSame('I wrote this', $this->comment_here());
        $this->assertFalse(marks::is_marked($this->grade_here()));
    }
}
