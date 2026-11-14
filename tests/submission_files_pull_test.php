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
 * Tests for choosing which of a submission's uploaded files are pulled.
 *
 * The "other site" is this site, as in submission_pull_test. A person ticks files on the preview; the
 * pull brings those and no others, keeps what is already here when more are added later, and never
 * lets the ticks do more than narrow what a pull would do anyway.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(submission_pull::class)]
#[CoversClass(submission_writer::class)]
#[CoversClass(submissions_result::class)]
final class submission_files_pull_test extends advanced_testcase {
    use local\source_on_this_site;
    use submission_fixtures;

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
     * A source course and a destination course with an assignment that allows
     * as many attempts as there are, a block mapped between them, a student in
     * both, and every switch on.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $source = $generator->create_course();
        $this->course = $generator->create_course();
        $this->sam = $generator->create_and_enrol($source, 'student', ['username' => 'sam']);
        $generator->enrol_user($this->sam->id, $this->course->id, 'student');

        $attempts = ['attemptreopenmethod' => 'manual', 'maxattempts' => -1];
        $this->there = $this->assignment($source, ['name' => 'Essay'] + $attempts);
        $this->here = $this->assignment(
            $this->course,
            ['name' => 'Essay'] + $attempts,
            ['idnumber' => 'coursesync-' . $this->there->cmid]
        );

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
            course_result::success((int) $source->id, 'REMOTE1', 'Remote Source Course', true, 1)
        );

        foreach (['allowsubmissionpull', 'allowsubmissionexport', 'allowattemptspull', 'allowattemptsexport'] as $switch) {
            set_config($switch, 1, 'block_coursesync');
        }
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
     * @param bool $all every attempt
     * @return grade_pull_result
     */
    protected function preview(bool $all = false): grade_pull_result {
        return submission_pull::preview($this->instanceid, $this->course->id, $this->source(), null, $all);
    }

    /**
     * Pull for real, with the files ticked that the test names.
     *
     * @param bool[]|null $files the file keys ticked; null is every file
     * @param bool $all every attempt
     * @return grade_pull_result
     */
    protected function pull(?array $files, bool $all = false): grade_pull_result {
        return submission_pull::run($this->instanceid, $this->course->id, $this->source(), null, $all, null, $files);
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
     * The tick keys of the named files of an entry, as the page would post them.
     *
     * @param \stdClass $entry an entry of a preview
     * @param string[] $names file names
     * @return bool[]
     */
    protected function keys(\stdClass $entry, array $names): array {
        $set = [];

        foreach ($entry->filelist as $file) {
            if (in_array($file->name, $names, true)) {
                $set[$file->key] = true;
            }
        }

        $this->assertCount(count($names), $set);

        return $set;
    }

    /**
     * The uploaded files the student has here, by name.
     *
     * @return string[]
     */
    protected function files_here(): array {
        return $this->submission_files($this->here, (int) $this->sam->id, submissions::AREA_FILES);
    }

    /**
     * Hand in work with three files on the source.
     *
     * @param string $text
     * @return void
     */
    protected function three_files(string $text = '<p>My essay</p>'): void {
        $this->submit($this->sam, $this->there, $text, ['a.txt' => 'ay', 'b.txt' => 'bee', 'c.txt' => 'see']);
    }

    /**
     * The preview names each uploaded file, with its size, for the page to put a tick box beside.
     */
    public function test_the_preview_lists_each_uploaded_file(): void {
        $this->three_files();

        $entry = $this->only_entry($this->preview());

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertSame(['a.txt', 'b.txt', 'c.txt'], array_column($entry->filelist, 'name'));
        $this->assertSame([2, 3, 3], array_column($entry->filelist, 'size'));
        $this->assertSame([false, false, false], array_column($entry->filelist, 'here'));
        $this->assertCount(3, array_unique(array_column($entry->filelist, 'key')));
        $this->assertNull($this->row());
    }

    /**
     * Only the ticked files come across, with the text and a record that says so.
     */
    public function test_only_the_ticked_files_are_pulled(): void {
        global $DB;

        $this->three_files();
        $keys = $this->keys($this->only_entry($this->preview()), ['b.txt']);

        $entry = $this->only_entry($this->pull($keys));

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertSame(['b.txt' => 'bee'], $this->files_here());
        $this->assertSame('<p>My essay</p>', $DB->get_field('assignsubmission_onlinetext', 'onlinetext', [
            'submission' => $this->row()->id,
        ]));
        $this->assertEquals(1, $DB->get_field('assignsubmission_file', 'numfiles', ['submission' => $this->row()->id]));
        $this->assertSame(1, $DB->count_records('block_coursesync_submission'));
    }

    /**
     * Files left out are offered on a later pull; adding one keeps the one already here, and the
     * record is the same record.
     */
    public function test_files_left_out_are_offered_and_adding_one_keeps_the_others(): void {
        global $DB;

        $this->three_files();
        $this->pull($this->keys($this->only_entry($this->preview()), ['b.txt']));
        $ledger = $DB->get_record('block_coursesync_submission', [], '*', MUST_EXIST);

        // Nothing changed on the source, yet the two left out are on offer, and the one here is shown as here.
        $entry = $this->only_entry($this->preview());
        $this->assertSame(grade_pull_result::UPDATE, $entry->outcome);
        $this->assertSame('subreasonmorefiles', $entry->reason);
        $this->assertSame([true], array_column(array_filter($entry->filelist, static fn($f) => $f->name === 'b.txt'), 'here'));
        $this->assertSame(['a.txt', 'b.txt', 'c.txt'], array_column($entry->filelist, 'name'));

        // Tick a: it comes, b stays, c is still left out.
        $entry = $this->only_entry($this->pull($this->keys($entry, ['a.txt'])));
        $this->assertSame(grade_pull_result::UPDATE, $entry->outcome);
        $this->assertSame(['a.txt' => 'ay', 'b.txt' => 'bee'], $this->files_here());
        $this->assertEquals(2, $DB->get_field('assignsubmission_file', 'numfiles', ['submission' => $this->row()->id]));
        $this->assertSame(1, $DB->count_records('block_coursesync_submission'));
        $this->assertEquals($ledger->id, $DB->get_field('block_coursesync_submission', 'id', []));

        // The file c is offered still, and ticking nothing new changes nothing.
        $entry = $this->only_entry($this->preview());
        $this->assertSame('subreasonmorefiles', $entry->reason);
        $unticked = $this->only_entry($this->pull([]));
        $this->assertSame('skipped:subreasonnotchosen', $unticked->outcome . ':' . $unticked->reason);
        $this->assertSame(['a.txt' => 'ay', 'b.txt' => 'bee'], $this->files_here());

        // Everything ticked: all three, and then there is nothing more to offer.
        $this->pull(null);
        $this->assertSame(['a.txt' => 'ay', 'b.txt' => 'bee', 'c.txt' => 'see'], $this->files_here());
        $this->assertSame(grade_pull_result::SAME, $this->only_entry($this->preview())->outcome);
    }

    /**
     * A copy someone has changed since is not offered more files: the usual rule, that what a person
     * did here is not touched, still holds.
     */
    public function test_a_copy_changed_here_is_not_offered_more_files(): void {
        $this->three_files();
        $this->pull($this->keys($this->only_entry($this->preview()), ['b.txt']));
        $row = $this->row();
        get_file_storage()->delete_area_files(
            \context_module::instance($this->here->cmid)->id,
            'assignsubmission_file',
            submissions::AREA_FILES,
            $row->id
        );

        $this->assertSame(grade_pull_result::SAME, $this->only_entry($this->preview())->outcome);
    }

    /**
     * Pulling every file leaves nothing to offer afterwards.
     */
    public function test_pulling_every_file_leaves_nothing_to_offer(): void {
        $this->three_files();
        $this->pull(null);

        $this->assertSame(grade_pull_result::SAME, $this->only_entry($this->preview())->outcome);
        $this->assertSame(['a.txt' => 'ay', 'b.txt' => 'bee', 'c.txt' => 'see'], $this->files_here());
    }

    /**
     * With no file ticked, the text comes across alone; with no text either, nothing is written and
     * the work is left for next time.
     */
    public function test_with_no_file_ticked_the_text_alone_or_nothing(): void {
        global $DB;

        $this->three_files();
        $entry = $this->only_entry($this->pull([]));

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertSame([], $this->files_here());
        $this->assertFalse($DB->record_exists('assignsubmission_file', ['submission' => $this->row()->id]));
        $this->assertSame('<p>My essay</p>', $DB->get_field('assignsubmission_onlinetext', 'onlinetext', [
            'submission' => $this->row()->id,
        ]));
    }

    /**
     * Files alone with none ticked: nothing to write.
     */
    public function test_files_alone_with_none_ticked_write_nothing(): void {
        global $DB;

        $this->submit($this->sam, $this->there, null, ['a.txt' => 'ay']);

        $entry = $this->only_entry($this->pull([]));

        $this->assertSame('skipped:subreasonnotchosen', $entry->outcome . ':' . $entry->reason);
        $this->assertNull($this->row());
        $this->assertSame(0, $DB->count_records('block_coursesync_submission'));
    }

    /**
     * A key that names no file of the submission does nothing: ticks only narrow.
     */
    public function test_a_key_that_names_no_file_does_nothing(): void {
        $this->three_files();

        $this->pull(['0123456789abcdef0123456789abcdef01234567' => true, 'x' => true]);

        $this->assertSame([], $this->files_here());
        $this->assertSame('<p>My essay</p>', $this->text_of_row());
    }

    /**
     * The text of the student's row here.
     *
     * @return string|null
     */
    protected function text_of_row(): ?string {
        global $DB;

        $text = $DB->get_field('assignsubmission_onlinetext', 'onlinetext', ['submission' => $this->row()->id]);

        return $text === false ? null : $text;
    }

    /**
     * The student's latest row here.
     *
     * @return \stdClass|null
     */
    protected function row(): ?\stdClass {
        global $DB;

        $rows = $DB->get_records('assign_submission', [
            'assignment' => $this->here->id,
            'userid' => $this->sam->id,
        ], 'attemptnumber DESC');

        return $rows ? reset($rows) : null;
    }

    /**
     * Only what is fetched counts against the pull's limit for file data: files left out do not.
     */
    public function test_files_left_out_do_not_use_the_run_limit(): void {
        global $CFG;

        // No site or course limit, so it is the pull's own that is reached.
        $CFG->maxbytes = 0;
        $this->three_files();
        $keys = $this->keys($this->only_entry($this->preview()), ['b.txt']);
        $this->tamper['block_coursesync_get_submissions'] = static function (array $body): array {
            foreach ($body['items'] as &$item) {
                $item['submissions'][0]['files'][0]['filesize'] = submission_pull::RUN_BYTES - 1;
            }

            return $body;
        };

        $entry = $this->only_entry($this->pull($keys));

        $this->assertSame(grade_pull_result::ADD, $entry->outcome);
        $this->assertSame(['b.txt' => 'bee'], $this->files_here());
    }

    /**
     * The images inside online text always travel with the text, whichever files are ticked.
     */
    public function test_images_in_the_text_come_with_it(): void {
        global $DB;

        $this->submit($this->sam, $this->there, '<img src="@@PLUGINFILE@@/pic.png">', ['a.txt' => 'ay']);
        $submission = $DB->get_record('assign_submission', ['assignment' => $this->there->id, 'userid' => $this->sam->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($this->there->cmid)->id,
            'component' => 'assignsubmission_onlinetext',
            'filearea' => submissions::AREA_TEXTFILES,
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => 'pic.png',
        ], 'png-bytes');
        $entry = $this->only_entry($this->preview());

        // Only the uploaded file is listed to be ticked.
        $this->assertSame(['a.txt'], array_column($entry->filelist, 'name'));

        $this->pull([]);

        $this->assertSame([], $this->files_here());
        $this->assertSame(
            ['pic.png' => 'png-bytes'],
            $this->submission_files($this->here, (int) $this->sam->id, submissions::AREA_TEXTFILES)
        );
    }

    /**
     * With every attempt, an attempt with nothing left to write holds back the later ones, as an unticked one does.
     */
    public function test_an_attempt_with_nothing_left_holds_back_the_later_ones(): void {
        global $DB;

        $this->submit($this->sam, $this->there, 'first', ['a.txt' => 'one']);
        $this->reopen($this->there, $this->sam);
        $this->submit($this->sam, $this->there, null, ['b.txt' => 'two']);
        $this->reopen($this->there, $this->sam);
        $this->submit($this->sam, $this->there, 'third', ['c.txt' => 'three']);

        $keys = [];

        foreach ($this->preview(true)->entries as $entry) {
            // Every attempt's file but the second's, which has no text to fall back on.
            $keys += $this->keys($entry, array_column($entry->filelist, 'name') === ['b.txt'] ? [] : [$entry->filelist[0]->name]);
        }

        $found = [];

        foreach ($this->pull($keys, true)->entries as $entry) {
            $found[$entry->attempt] = $entry->outcome . ':' . $entry->reason;
        }

        $this->assertSame(
            [0 => 'add:', 1 => 'skipped:subreasonnotchosen', 2 => 'skipped:subreasonearlierskipped'],
            $found
        );
        $this->assertSame(1, $DB->count_records('block_coursesync_submission'));
    }

    /**
     * The file names come from the other site: the page's list escapes them, and a file already here has no
     * tick box, since an update keeps it.
     */
    public function test_the_page_list_escapes_names_and_has_no_box_for_files_here(): void {
        $entry = (object) ['filelist' => [
            (object) ['key' => 'k1', 'name' => '<script>x</script>.txt', 'size' => 10, 'here' => false],
            (object) ['key' => 'k2', 'name' => 'kept.txt', 'size' => 20, 'here' => true],
        ]];

        $html = local\student_sections::file_list($entry, ['data-toggle' => 'target', 'data-togglegroup' => 'g-']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;.txt', $html);
        $this->assertSame(1, substr_count($html, 'name="pickfile[]"'));
        $this->assertStringContainsString('value="k1"', $html);
        $this->assertStringNotContainsString('value="k2"', $html);
        $this->assertStringContainsString('kept.txt', $html);

        // A line that cannot be ticked lists the names and no boxes.
        $this->assertStringNotContainsString('pickfile', local\student_sections::file_list($entry, null));
        $this->assertSame('', local\student_sections::file_list((object) ['filelist' => []], null));
    }
}
