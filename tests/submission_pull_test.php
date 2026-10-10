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
use block_coursesync\local\submission_fixtures;
use block_coursesync\local\submission_writer;
use block_coursesync\local\submissions;
use core\http_client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local/source_on_this_site.php');
require_once(__DIR__ . '/local/submission_fixtures.php');

/**
 * Tests for pulling students' assignment submissions into this site.
 *
 * The "other site" is this site: a source course holds the work, a
 * destination course holds the copy of the assignment, and every call between
 * them runs the real source functions, so the description, the transfer and the
 * writing are exercised together. A few tests tamper with what the source
 * says, to see how the destination copes with a source that is wrong.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(submission_pull::class)]
#[CoversClass(submissions_result::class)]
#[CoversClass(submission_writer::class)]
final class submission_pull_test extends advanced_testcase {
    use local\source_on_this_site;
    use submission_fixtures;

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

        $this->there = $this->assignment($this->source, ['name' => 'Essay']);
        $this->here = $this->assignment($this->course, ['name' => 'Essay'], ['idnumber' => 'coursesync-' . $this->there->cmid]);

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

        set_config('allowsubmissionpull', 1, 'block_coursesync');
        set_config('allowsubmissionexport', 1, 'block_coursesync');
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
        return submission_pull::preview($this->instanceid, $this->course->id, $this->source());
    }

    /**
     * Pull for real.
     *
     * @return grade_pull_result
     */
    protected function pull(): grade_pull_result {
        return submission_pull::run($this->instanceid, $this->course->id, $this->source());
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
     * The assign_submission row of a student here, or null.
     *
     * @param int|null $userid
     * @return \stdClass|null
     */
    protected function row(?int $userid = null): ?\stdClass {
        global $DB;

        $rows = $DB->get_records('assign_submission', [
            'assignment' => $this->here->id,
            'userid' => $userid ?? $this->sam->id,
        ], 'attemptnumber DESC');

        return $rows ? reset($rows) : null;
    }

    /**
     * The online text a student here has handed in.
     *
     * @param int|null $userid
     * @return string|null
     */
    protected function text_here(?int $userid = null): ?string {
        global $DB;

        $row = $this->row($userid);
        $text = $row ? $DB->get_field('assignsubmission_onlinetext', 'onlinetext', ['submission' => $row->id]) : false;

        return $text === false ? null : $text;
    }

    /**
     * Hand work in on the source.
     *
     * @param string|null $text
     * @param string[] $files
     * @return void
     */
    protected function hand_in(?string $text, array $files = []): void {
        $this->submit($this->sam, $this->there, $text, $files);
    }

    /**
     * Hand work in to the copy here, as the student would have.
     *
     * @param string|null $text
     * @param string[] $files
     * @return void
     */
    protected function hand_in_here(?string $text, array $files = []): void {
        $this->submit($this->sam, $this->here, $text, $files);
    }

    /**
     * A preview shows what would happen and writes nothing.
     */
    public function test_a_preview_writes_nothing(): void {
        global $DB;

        $this->hand_in('My essay', ['a.txt' => 'file']);

        $entry = $this->only_entry($this->preview());

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertSame('sam', $entry->username);
        $this->assertSame(1, $entry->files);
        $this->assertNull($this->row());
        $this->assertSame(0, $DB->count_records('block_coursesync_submission'));
        $this->assertSame(0, $DB->count_records('block_coursesync_run'));
    }

    /**
     * A pull brings the text and the files, as work handed in, and says so
     * in the history without naming anyone.
     */
    public function test_a_pull_brings_text_and_files(): void {
        global $DB;

        $this->hand_in('<p>My essay</p>', ['b.txt' => 'bee', 'a.txt' => 'ay']);
        $there = $DB->get_record('assign_submission', ['assignment' => $this->there->id, 'userid' => $this->sam->id]);

        $result = $this->pull();
        $entry = $this->only_entry($result);

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertNull($entry->reason);

        $row = $this->row();
        $this->assertSame('submitted', $row->status);
        $this->assertSame(1, (int) $row->latest);
        $this->assertSame(0, (int) $row->attemptnumber);
        $this->assertSame(0, (int) $row->groupid);
        $this->assertEquals($there->timecreated, $row->timecreated);
        $this->assertEquals($there->timemodified, $row->timemodified);

        $this->assertSame('<p>My essay</p>', $this->text_here());
        $this->assertEquals(FORMAT_HTML, $DB->get_field('assignsubmission_onlinetext', 'onlineformat', ['submission' => $row->id]));
        $this->assertEquals(2, $DB->get_field('assignsubmission_file', 'numfiles', ['submission' => $row->id]));
        $this->assertSame(
            ['a.txt' => 'ay', 'b.txt' => 'bee'],
            $this->submission_files($this->here, (int) $this->sam->id, 'submission_files')
        );

        // The assignment itself sees it as handed in.
        $assign = $this->assign_object($this->here);
        $this->assertSame(1, $assign->count_submissions_with_status('submitted'));

        $ledger = $DB->get_record('block_coursesync_submission', ['assignid' => $this->here->id, 'userid' => $this->sam->id]);
        $this->assertEquals($row->id, $ledger->submissionid);
        $this->assertEquals($this->there->cmid, $ledger->remotecmid);
        $this->assertSame(40, strlen($ledger->fingerprint));
        $this->assertSame(40, strlen($ledger->localfingerprint));

        $run = $DB->get_record('block_coursesync_run', ['blockinstanceid' => $this->instanceid], '*', MUST_EXIST);
        $this->assertSame(history::KIND_SUBMISSIONS, $run->kind);
        $this->assertEquals(1, $run->pulledcount);
        // Counts per assignment, never who or what.
        $this->assertStringNotContainsString('"sam"', $run->pulled);
        $this->assertStringNotContainsString('My essay', $run->pulled);
        $this->assertStringContainsString('"kind":"submission"', $run->pulled);
    }

    /**
     * Files embedded in online text come across with the text.
     */
    public function test_files_embedded_in_online_text_come_across(): void {
        global $DB;

        $this->hand_in('<img src="@@PLUGINFILE@@/pic.png">');
        $submission = $DB->get_record('assign_submission', ['assignment' => $this->there->id, 'userid' => $this->sam->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($this->there->cmid)->id,
            'component' => 'assignsubmission_onlinetext',
            'filearea' => submissions::AREA_TEXTFILES,
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => 'pic.png',
        ], 'png-bytes');

        $this->pull();

        $this->assertSame(
            ['pic.png' => 'png-bytes'],
            $this->submission_files($this->here, (int) $this->sam->id, submissions::AREA_TEXTFILES)
        );
    }

    /**
     * Text alone, or files alone, comes across too.
     */
    public function test_text_only_and_files_only(): void {
        $pat = $this->getDataGenerator()->create_and_enrol($this->source, 'student', ['username' => 'pat']);
        $this->getDataGenerator()->enrol_user($pat->id, $this->course->id, 'student');
        $this->hand_in('just text');
        $this->submit($pat, $this->there, null, ['only.txt' => 'just a file']);

        $result = $this->pull();

        $this->assertCount(2, $result->with_outcome(grade_pull_result::ADD));
        $this->assertSame('just text', $this->text_here());
        $this->assertSame([], $this->submission_files($this->here, (int) $this->sam->id, 'submission_files'));
        $this->assertNull($this->text_here((int) $pat->id));
        $this->assertSame(
            ['only.txt' => 'just a file'],
            $this->submission_files($this->here, (int) $pat->id, 'submission_files')
        );
    }

    /**
     * A second pull with nothing new changes nothing.
     */
    public function test_a_second_pull_changes_nothing(): void {
        global $DB;

        $this->hand_in('My essay', ['a.txt' => 'file']);
        $this->pull();
        $before = $DB->get_record('block_coursesync_submission', ['userid' => $this->sam->id]);

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::SAME, $entry->outcome);
        $this->assertEquals($before, $DB->get_record('block_coursesync_submission', ['userid' => $this->sam->id]));
        $this->assertSame(1, $DB->count_records('assign_submission', ['assignment' => $this->here->id]));
    }

    /**
     * Work that changed on the source, and has not been touched here, is
     * replaced - files included, so a file that is gone there is gone here.
     */
    public function test_an_untouched_copy_is_updated(): void {
        $this->hand_in('first', ['a.txt' => 'one', 'b.txt' => 'two']);
        $this->pull();
        $id = $this->row()->id;

        $this->hand_in('second', ['a.txt' => 'one-changed']);

        $this->assertSame(grade_pull_result::UPDATE, $this->only_entry($this->preview())->outcome);
        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::UPDATE, $entry->outcome);
        $this->assertSame($id, $this->row()->id);
        $this->assertSame('second', $this->text_here());
        $this->assertSame(
            ['a.txt' => 'one-changed'],
            $this->submission_files($this->here, (int) $this->sam->id, 'submission_files')
        );

        // And now it is up to date.
        $this->assertSame(grade_pull_result::SAME, $this->only_entry($this->pull())->outcome);
    }

    /**
     * Work changed here since it was brought across is never replaced, even
     * when the source has changed too.
     */
    public function test_a_copy_changed_here_is_kept(): void {
        $this->hand_in('first');
        $this->pull();

        // The student, or someone for them, edits it here.
        $this->hand_in_here('edited here');
        $this->hand_in('second');

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::CONFLICT, $entry->outcome);
        $this->assertSame('subreasonchanged', $entry->reason);
        $this->assertSame('edited here', $this->text_here());
    }

    /**
     * A change made here is not flagged again on every pull while the source
     * has nothing new: there is nothing to bring.
     */
    public function test_a_change_here_is_not_flagged_when_the_source_is_unchanged(): void {
        $this->hand_in('first');
        $this->pull();
        $this->hand_in_here('edited here');

        $this->assertSame(grade_pull_result::SAME, $this->only_entry($this->pull())->outcome);
        $this->assertSame('edited here', $this->text_here());
    }

    /**
     * A student who already has different work here is left exactly as they
     * are, and nothing is recorded as having been brought.
     */
    public function test_existing_work_is_never_overwritten(): void {
        global $DB;

        $this->hand_in('from the other site', ['theirs.txt' => 'there']);
        $this->hand_in_here('already here', ['mine.txt' => 'here']);

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::CONFLICT, $entry->outcome);
        $this->assertSame('subreasonexists', $entry->reason);
        $this->assertSame('already here', $this->text_here());
        $this->assertSame(['mine.txt' => 'here'], $this->submission_files($this->here, (int) $this->sam->id, 'submission_files'));
        $this->assertSame(0, $DB->count_records('block_coursesync_submission'));
    }

    /**
     * Work here that is a draft is in the way too.
     */
    public function test_a_draft_here_is_kept(): void {
        $draftsonly = $this->assignment($this->course, ['name' => 'Drafts', 'submissiondrafts' => 1], [
            'idnumber' => 'coursesync-' . $this->there->cmid,
        ]);
        // Two copies claim the same identity; only this one is looked at.
        course_delete_module($this->here->cmid, true);
        $this->here = $draftsonly;
        $this->hand_in('from the other site');
        $this->hand_in_here('half finished');

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::CONFLICT, $entry->outcome);
        $this->assertSame('half finished', $this->text_here());
        $this->assertSame('draft', $this->row()->status);
    }

    /**
     * Identical work already here is nothing to do, and is not a conflict.
     */
    public function test_identical_work_here_is_the_same(): void {
        $this->hand_in('same words', ['a.txt' => 'same file']);
        $this->hand_in_here('same words', ['a.txt' => 'same file']);

        $this->assertSame(grade_pull_result::SAME, $this->only_entry($this->pull())->outcome);
    }

    /**
     * Text from the other site is cleaned before it is stored, and a second
     * pull of it is still "the same" - the cleaning must not make every later
     * pull look like a change.
     */
    public function test_online_text_from_the_other_site_is_cleaned(): void {
        global $DB;

        $this->hand_in('My essay');
        $this->tamper['block_coursesync_get_submissions'] = static function (array $body): array {
            $body['items'][0]['submissions'][0]['onlinetext'] =
                '<p>My essay</p><script>alert(1)</script><img src=x onerror=alert(2)>';

            return $body;
        };

        $this->assertSame(grade_pull_result::ADD, $this->only_entry($this->pull())->outcome);
        $stored = $this->text_here();
        $this->assertStringContainsString('My essay', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);

        $before = $DB->get_record('block_coursesync_submission', ['userid' => $this->sam->id]);
        $this->assertSame(grade_pull_result::SAME, $this->only_entry($this->pull())->outcome);
        $this->assertEquals($before, $DB->get_record('block_coursesync_submission', ['userid' => $this->sam->id]));
    }

    /**
     * Work here that is the same once the other site's text is cleaned is the
     * same work, not a conflict.
     */
    public function test_identical_work_here_is_the_same_after_cleaning(): void {
        $this->hand_in('<p>Same words</p>');
        $this->hand_in_here('<p>Same words</p>');
        $this->tamper['block_coursesync_get_submissions'] = static function (array $body): array {
            // The source fingerprints what it holds, script included, so its
            // fingerprint is not that of the cleaned text.
            $body['items'][0]['submissions'][0]['onlinetext'] = '<p>Same words</p><script>alert(1)</script>';
            $body['items'][0]['submissions'][0]['fingerprint'] = sha1('with the script');

            return $body;
        };

        $this->assertSame(grade_pull_result::SAME, $this->only_entry($this->pull())->outcome);
    }

    /**
     * A student who only opened the assignment here has a row with no work
     * in it: the work goes into that row, and there is still just the one.
     */
    public function test_an_opened_but_empty_submission_is_reused(): void {
        global $DB;

        $this->hand_in('work');
        $this->setUser($this->sam);
        $opened = $this->assign_object($this->here)->get_user_submission($this->sam->id, true);
        $this->setAdminUser();
        $this->assertSame('new', $opened->status);

        $this->assertSame(grade_pull_result::ADD, $this->only_entry($this->pull())->outcome);

        $this->assertSame(1, $DB->count_records('assign_submission', ['assignment' => $this->here->id]));
        $this->assertEquals($opened->id, $this->row()->id);
        $this->assertSame('submitted', $this->row()->status);
    }

    /**
     * Work a teacher removed here after it was brought is not brought back.
     */
    public function test_a_removed_submission_is_not_brought_back(): void {
        global $DB;

        $this->hand_in('work');
        $this->pull();

        $this->assign_object($this->here)->remove_submission($this->sam->id);

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('subreasondeleted', $entry->reason);
        $this->assertNull($this->text_here());
        $this->assertSame(1, $DB->count_records('block_coursesync_submission'));
    }

    /**
     * A student who is not in this course is not asked about, written, or
     * mentioned.
     */
    public function test_students_not_here_are_not_involved(): void {
        $stranger = $this->getDataGenerator()->create_and_enrol($this->source, 'student', ['username' => 'stranger']);
        $this->submit($stranger, $this->there, 'stranger work');
        $this->hand_in('sam work');

        $result = $this->pull();

        $this->assertSame(['sam'], array_column($result->entries, 'username'));
        $this->assertNull($this->row((int) $stranger->id));

        // And the source was told only about sam.
        $asked = array_values(array_filter($this->calls, static fn($c) => $c['wsfunction'] === 'block_coursesync_get_submissions'));
        $this->assertSame('sam', trim($asked[0]['usernames']));
    }

    /**
     * With no students here the source is not called at all: an empty list
     * would mean everyone.
     */
    public function test_with_no_students_the_source_is_not_asked(): void {
        global $DB;

        $DB->delete_records('user_enrolments', ['userid' => $this->sam->id, 'enrolid' => $DB->get_field('enrol', 'id', [
            'courseid' => $this->course->id,
            'enrol' => 'manual',
        ])]);
        $this->hand_in('work');

        $result = $this->pull();

        $this->assertTrue($result->success);
        $this->assertSame([], $result->entries);
        $this->assertSame([], $this->calls);
    }

    /**
     * Something here that Course Sync did not copy is not part of it.
     */
    public function test_activities_not_copied_by_course_sync_are_ignored(): void {
        $this->assignment($this->course, ['name' => 'Hand made']);
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id], ['idnumber' => 'coursesync-12345']);
        $this->hand_in('work');

        $result = $this->pull();

        $this->assertCount(1, $result->entries);
        $this->assertSame('Essay', $result->entries[0]->activity);
    }

    /**
     * An assignment here that does not take the kind of work that arrived is
     * skipped with a reason, and the kind it does take still comes.
     */
    public function test_a_submission_type_switched_off_here(): void {
        global $DB;

        $pat = $this->getDataGenerator()->create_and_enrol($this->source, 'student', ['username' => 'pat']);
        $this->getDataGenerator()->enrol_user($pat->id, $this->course->id, 'student');
        $this->hand_in('sam text', ['a.txt' => 'file']);
        $this->submit($pat, $this->there, 'pat text');

        $DB->set_field('assign_plugin_config', 'value', 0, [
            'assignment' => $this->here->id,
            'plugin' => 'file',
            'subtype' => 'assignsubmission',
            'name' => 'enabled',
        ]);

        $result = $this->pull();
        $byname = [];

        foreach ($result->entries as $entry) {
            $byname[$entry->username] = $entry;
        }

        $this->assertSame(grade_pull_result::SKIPPED, $byname['sam']->outcome);
        $this->assertSame('subreasonplugin', $byname['sam']->reason);
        $this->assertNull($this->row());
        $this->assertSame(grade_pull_result::ADD, $byname['pat']->outcome);
        $this->assertSame('pat text', $this->text_here((int) $pat->id));
    }

    /**
     * A file bigger than the course allows is skipped, not truncated.
     */
    public function test_a_file_over_the_course_limit_is_skipped(): void {
        global $DB;

        $DB->set_field('course', 'maxbytes', 10, ['id' => $this->course->id]);
        $this->hand_in('text', ['big.txt' => 'this is more than ten bytes']);

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('subreasonfilesize', $entry->reason);
        $this->assertNull($this->row());
    }

    /**
     * A pull stops bringing file data at its limit; the rest wait.
     */
    public function test_the_run_limit_leaves_the_rest_for_next_time(): void {
        global $CFG;

        // No site or course limit, so it is the pull's own that is reached.
        $CFG->maxbytes = 0;
        $this->hand_in('text', ['a.txt' => 'file']);
        $this->tamper['block_coursesync_get_submissions'] = static function (array $body): array {
            $body['items'][0]['submissions'][0]['files'][0]['filesize'] = submission_pull::RUN_BYTES + 1;

            return $body;
        };

        $entry = $this->only_entry($this->preview());

        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('subreasonrunlimit', $entry->reason);
    }

    /**
     * A file that arrives different from what the source described is
     * refused, and nothing is written for the student.
     */
    public function test_a_corrupt_transfer_writes_nothing(): void {
        global $DB;

        $this->hand_in('text', ['a.txt' => 'file']);
        $this->tamper['block_coursesync_get_submissions'] = static function (array $body): array {
            $body['items'][0]['submissions'][0]['files'][0]['contenthash'] = sha1('not the file');

            return $body;
        };

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('subreasoncorrupt', $entry->reason);
        $this->assertNull($this->row());
        $this->assertSame(0, $DB->count_records('block_coursesync_submission'));
        $this->assertSame(0, $DB->count_records('assignsubmission_onlinetext', ['assignment' => $this->here->id]));
    }

    /**
     * A transfer that fails leaves work brought earlier exactly as it was.
     */
    public function test_a_failed_update_leaves_the_earlier_work(): void {
        $this->hand_in('first', ['a.txt' => 'one']);
        $this->pull();

        $this->hand_in('second', ['a.txt' => 'two']);
        $this->tamper['block_coursesync_get_submission_file'] = static function (): array {
            throw new \moodle_exception('errorfilenotfound', 'block_coursesync');
        };

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('subreasontransfer', $entry->reason);
        $this->assertSame('first', $this->text_here());
        $this->assertSame(['a.txt' => 'one'], $this->submission_files($this->here, (int) $this->sam->id, 'submission_files'));
    }

    /**
     * Team assignments are skipped with the reason, on either side.
     */
    public function test_team_assignments_are_skipped(): void {
        global $DB;

        $DB->set_field('assign', 'teamsubmission', 1, ['id' => $this->there->id]);
        $this->assertSame('subreasonteam', $this->only_entry($this->pull())->reason);

        $DB->set_field('assign', 'teamsubmission', 0, ['id' => $this->there->id]);
        $DB->set_field('assign', 'teamsubmission', 1, ['id' => $this->here->id]);
        $this->hand_in('work');
        $this->assertSame('subreasonteamlocal', $this->only_entry($this->pull())->reason);
        $this->assertNull($this->row());
    }

    /**
     * An assignment the source no longer has is skipped with a reason.
     */
    public function test_an_assignment_gone_from_the_source(): void {
        course_delete_module($this->there->cmid, true);

        $entry = $this->only_entry($this->pull());

        $this->assertSame(grade_pull_result::SKIPPED, $entry->outcome);
        $this->assertSame('subreasongone', $entry->reason);
        $this->assertSame('', $entry->username);
    }

    /**
     * Nothing happens until an administrator turns pulling on.
     */
    public function test_off_until_switched_on(): void {
        global $DB;

        set_config('allowsubmissionpull', 0, 'block_coursesync');
        $this->hand_in('work');

        foreach ([$this->preview(), $this->pull()] as $result) {
            $this->assertFalse($result->success);
            $this->assertSame('errorsubmissionpulloff', $result->errorkey);
        }

        $this->assertSame([], $this->calls);
        $this->assertNull($this->row());
        $this->assertSame(0, $DB->count_records('block_coursesync_run'));
    }

    /**
     * The person pulling needs the pull permission and the right to work with
     * submissions here, and one without them gets nothing, not even a preview.
     */
    public function test_needs_permission(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->hand_in('work');
        $this->setUser($teacher);

        // A non-editing teacher cannot grade or edit submissions.
        $this->assertSame('errornosubmissionpullpermission', $this->preview()->errorkey);

        $coursecontext = \context_course::instance($this->course->id);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('block/coursesync:pullsubmissions', CAP_ALLOW, $roleid, $coursecontext->id, true);
        assign_capability('mod/assign:grade', CAP_ALLOW, $roleid, $coursecontext->id, true);
        role_assign($roleid, $teacher->id, $coursecontext->id);
        // Still missing the right to edit others' submissions.
        $this->assertSame('errornosubmissionpullpermission', $this->preview()->errorkey);

        assign_capability('mod/assign:editothersubmission', CAP_ALLOW, $roleid, $coursecontext->id, true);
        $this->assertTrue($this->preview()->success);
    }

    /**
     * A pull in progress holds the block's lock; a second is refused rather
     * than running alongside it.
     */
    public function test_a_pull_waits_for_a_sync_in_progress(): void {
        global $CFG;

        // Postgres advisory locks - the default factory here - are re-entrant
        // within one session, so the test would never see its own lock as
        // held. A file lock is not.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';

        $lock = \core\lock\lock_config::get_lock_factory('block_coursesync')
            ->get_lock('run-' . $this->instanceid, 0, syncer::LOCK_LIFETIME);
        $this->hand_in('work');

        try {
            $result = $this->pull();
        } finally {
            $lock->release();
        }

        $this->assertFalse($result->success);
        $this->assertSame('errorsyncinprogress', $result->errorkey);
        $this->assertNull($this->row());
        $this->assertSame([], $this->calls);
    }

    /**
     * What the source says about its own state comes through as a plain
     * explanation: switched off there, no permission there, too old.
     */
    public function test_what_the_source_refuses_is_explained(): void {
        set_config('allowsubmissionexport', 0, 'block_coursesync');
        $this->assertSame('errorsubmissionexportoff', $this->pull()->errorkey);

        set_config('allowsubmissionexport', 1, 'block_coursesync');
        $this->tamper['block_coursesync_get_submissions'] = static function (): array {
            throw new \moodle_exception('nopermissions', 'error');
        };
        $this->assertSame('errornosubmissionpermission', $this->pull()->errorkey);

        $this->tamper['block_coursesync_get_submissions'] = static function (): array {
            throw new \moodle_exception('invalidrecord', 'error');
        };
        $this->assertSame('errorsubmissionsourceoutdated', $this->pull()->errorkey);

        $this->tamper['block_coursesync_get_submissions'] = static fn(): array => ['nothing' => 'useful'];
        $this->assertSame('errorbadresponse', $this->pull()->errorkey);
    }

    /**
     * Handing in work counts for completion, as it would have if the
     * student had done it here.
     */
    public function test_completion_by_handing_in_work(): void {
        global $DB;

        $DB->set_field('course', 'enablecompletion', 1, ['id' => $this->course->id]);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_AUTOMATIC, ['id' => $this->here->cmid]);
        $DB->set_field('assign', 'completionsubmit', 1, ['id' => $this->here->id]);
        rebuild_course_cache($this->course->id, true);
        $this->hand_in('work');

        $this->assertSame(grade_pull_result::ADD, $this->only_entry($this->pull())->outcome);

        $course = get_course($this->course->id);
        $cm = get_fast_modinfo($course)->get_cm($this->here->cmid);
        $data = (new \completion_info($course))->get_data($cm, false, $this->sam->id);
        $this->assertEquals(COMPLETION_COMPLETE, $data->completionstate);
    }

    /**
     * Deleting the block forgets what it brought across; the work stays.
     */
    public function test_deleting_the_block_forgets_the_record_not_the_work(): void {
        global $DB;

        $this->hand_in('work');
        $this->pull();
        $this->assertSame(1, $DB->count_records('block_coursesync_submission'));

        submission_pull::delete_for_block_instance($this->instanceid);

        $this->assertSame(0, $DB->count_records('block_coursesync_submission'));
        $this->assertSame('work', $this->text_here());
    }

    /**
     * What the source sends is cleaned on arrival: a file that names
     * somewhere it should not, or is not described properly, is refused, and
     * the whole answer with it. A good description is accepted, so the refusals
     * are about what was changed.
     *
     * @param array $changes what to change in a good file description
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafe_files')]
    public function test_unsafe_file_descriptions_are_refused(array $changes): void {
        $good = [
            'area' => submissions::AREA_FILES,
            'filepath' => '/',
            'filename' => 'a.txt',
            'filesize' => 4,
            'contenthash' => sha1('file'),
            'timemodified' => 1,
        ];
        $answer = static fn(array $file): array => ['items' => [[
            'cmid' => 1,
            'reason' => '',
            'plugins' => ['file'],
            'submissions' => [[
                'username' => 'sam',
                'attemptnumber' => 0,
                'timecreated' => 1,
                'timemodified' => 1,
                'onlinetext' => null,
                'onlineformat' => FORMAT_HTML,
                'fingerprint' => sha1('x'),
                'files' => [$file],
            ]],
        ]]];

        $this->assertTrue(submissions_result::from_response($answer($good))->success);
        $this->assertSame('errorbadresponse', submissions_result::from_response($answer($changes + $good))->errorkey);
    }

    /**
     * Descriptions that must be refused.
     *
     * @return array[]
     */
    public static function unsafe_files(): array {
        return [
            'climbing path' => [['filepath' => '/../../']],
            'path without a leading slash' => [['filepath' => 'x/']],
            'path without a trailing slash' => [['filepath' => '/x']],
            'name with a slash' => [['filename' => '../evil.php']],
            'name changed by cleaning' => [['filename' => "a\0.txt"]],
            'empty name' => [['filename' => '']],
            'another area' => [['area' => 'intro']],
            'short hash' => [['contenthash' => 'abc']],
            'hash with junk' => [['contenthash' => str_repeat('z!', 20)]],
        ];
    }

    /**
     * A second assignment, copied the same way, that Sam has also handed work in to.
     *
     * @return array [the one on the source, the copy here]
     */
    protected function second_assignment(): array {
        $there = $this->assignment($this->source, ['name' => 'Essay two']);
        $here = $this->assignment($this->course, ['name' => 'Essay two'], ['idnumber' => 'coursesync-' . $there->cmid]);
        $this->submit($this->sam, $there, 'Second essay');
        $this->hand_in('First essay');

        return [$there, $here];
    }

    /**
     * Only the ticked assignments are pulled; the other is left for later, and
     * the source is not even asked about it.
     */
    public function test_only_the_chosen_assignments_are_pulled(): void {
        global $DB;

        [$there2, $here2] = $this->second_assignment();

        $result = submission_pull::run($this->instanceid, $this->course->id, $this->source(), [$there2->cmid]);

        $this->assertTrue($result->success);
        $this->assertSame([$here2->cmid], array_values(array_unique(array_column($result->entries, 'cmid'))));
        $this->assertNull($this->row());
        $this->assertSame(1, $DB->count_records('assign_submission', ['assignment' => $here2->id]));

        $asked = array_merge(...array_map(fn($call) => $call['cmids'] ?? [], array_filter(
            $this->calls,
            fn($call) => ($call['wsfunction'] ?? '') === 'block_coursesync_get_submissions'
        )));
        $this->assertSame([$there2->cmid], array_map('intval', $asked));

        // The one left out is still there to pull.
        $this->assertSame(grade_pull_result::ADD, $this->only_entry(
            submission_pull::preview($this->instanceid, $this->course->id, $this->source(), [$this->there->cmid])
        )->outcome);
    }

    /**
     * Ticking nothing pulls nothing, and an id that is not one of this
     * course's copies matches nothing - a selection can only narrow a pull.
     */
    public function test_a_selection_can_only_narrow_a_pull(): void {
        global $DB;

        $this->second_assignment();

        foreach ([[], [999999]] as $only) {
            $result = submission_pull::run($this->instanceid, $this->course->id, $this->source(), $only);

            $this->assertTrue($result->success);
            $this->assertSame([], $result->entries);
        }

        $this->assertSame(0, $DB->count_records('block_coursesync_submission'));
        $this->assertNull($this->row());
    }

    /**
     * Without a selection, everything is pulled as before.
     */
    public function test_no_selection_pulls_everything(): void {
        [, $here2] = $this->second_assignment();

        $result = $this->pull();

        $this->assertEqualsCanonicalizing(
            [$this->here->cmid, $here2->cmid],
            array_unique(array_column($result->entries, 'cmid'))
        );
    }
}
