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
 * End-to-end round trip: a real book with a chapter embedding an image, a
 * subchapter, and a hidden chapter, built with Moodle's own mod_book
 * generator on a "source" course, exported with book_activity_exporter,
 * and recreated with book_activity_handler on a fresh "destination" course
 * - the same two classes sync_runner wires together in production, through
 * the same JSON encode/decode the real web service transport uses (see
 * quiz_roundtrip_test.php for why that step matters).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(book_activity_exporter::class)]
#[CoversClass(book_activity_handler::class)]
final class book_roundtrip_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Builds a source book with an embedded-image chapter, a subchapter,
     * and a hidden chapter - the fixture for the round-trip test below.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: string} [source course, source book cm, chapter1's embedded file content]
     */
    protected function build_source(): array {
        $sourcecourse = $this->getDataGenerator()->create_course();
        $sourcebook = $this->getDataGenerator()->create_module('book', [
            'course' => $sourcecourse->id,
            'name' => 'Field guide <script>alert(1)</script>',
            'intro' => '<p>A short guide.</p>',
        ]);

        /** @var \mod_book_generator $bookgenerator */
        $bookgenerator = $this->getDataGenerator()->get_plugin_generator('mod_book');

        // Explicit ascending pagenum: create_chapter() defaults pagenum to
        // 1 and shifts anything already there out of the way, so relying
        // on call order alone would NOT produce the intended 1/2/3 sequence.
        $chapter1 = $bookgenerator->create_chapter([
            'bookid' => $sourcebook->id,
            'pagenum' => 1,
            'title' => 'Introduction',
            'content' => '<p>See <img src="@@PLUGINFILE@@/diagram.png" alt="diagram"></p>',
            'contentformat' => FORMAT_HTML,
        ]);
        $bookgenerator->create_chapter([
            'bookid' => $sourcebook->id,
            'pagenum' => 2,
            'title' => 'A sub-point',
            'content' => '<p>More detail.</p>',
            'contentformat' => FORMAT_HTML,
            'subchapter' => 1,
        ]);
        $bookgenerator->create_chapter([
            'bookid' => $sourcebook->id,
            'pagenum' => 3,
            'title' => 'Draft notes <script>alert(2)</script>',
            'content' => '<p>Not ready yet.</p>',
            'contentformat' => FORMAT_HTML,
            'hidden' => 1,
        ]);

        $sourcecontext = \context_module::instance($sourcebook->cmid);
        $filecontent = 'fake png bytes';
        get_file_storage()->create_file_from_string([
            'contextid' => $sourcecontext->id, 'component' => 'mod_book', 'filearea' => 'chapter',
            'itemid' => $chapter1->id, 'filepath' => '/', 'filename' => 'diagram.png',
        ], $filecontent);

        return [$sourcecourse, $sourcebook, $filecontent];
    }

    public function test_a_book_with_an_embedded_image_subchapter_and_hidden_chapter_survives_the_round_trip(): void {
        global $DB;

        [$sourcecourse, $sourcebook, $filecontent] = $this->build_source();
        $sourcecm = get_fast_modinfo($sourcecourse)->get_cm($sourcebook->cmid);

        $exporter = new book_activity_exporter();
        $payload = $exporter->export($sourcecm);

        $this->assertSame('Field guide <script>alert(1)</script>', $payload['name']);
        $this->assertCount(3, $payload['chapters']);
        [$exported1, $exportedsub1, $exportedhidden1] = $payload['chapters'];
        $this->assertSame('Introduction', $exported1['title']);
        $this->assertSame(0, $exported1['subchapter']);
        $this->assertCount(1, $exported1['files']);
        $this->assertSame('diagram.png', $exported1['files'][0]['filename']);
        $this->assertSame(1, $exportedsub1['subchapter']);
        $this->assertSame(1, $exportedhidden1['hidden']);

        // Full production path: JSON encode/decode, same as the real web
        // service transport.
        $decoded = json_decode(json_encode($payload), true);

        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new book_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-401');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertSame('coursesync-401', $cm->idnumber);
        $this->assertGreaterThan(0, $cm->instance);

        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script>', $book->name);
        $this->assertStringContainsString('Field guide', $book->name);

        $chapters = $DB->get_records('book_chapters', ['bookid' => $book->id], 'pagenum ASC');
        $this->assertCount(3, $chapters);
        [$destchapter1, $destsub1, $desthidden1] = array_values($chapters);

        $this->assertSame('Introduction', $destchapter1->title);
        $this->assertEquals(0, $destchapter1->subchapter);
        // Pagenum was renumbered to a clean 1..N sequence, matching creation order.
        $this->assertEquals(1, $destchapter1->pagenum);
        $this->assertEquals(2, $destsub1->pagenum);
        $this->assertEquals(3, $desthidden1->pagenum);

        $this->assertEquals(1, $destsub1->subchapter);
        $this->assertStringNotContainsString('<script>', $desthidden1->title);
        $this->assertStringContainsString('Draft notes', $desthidden1->title);
        $this->assertEquals(1, $desthidden1->hidden);
        // The @@PLUGINFILE@@ token survives verbatim - no rewriting needed,
        // it's itemid-agnostic (see the handler's docblock).
        $this->assertStringContainsString('@@PLUGINFILE@@/diagram.png', $destchapter1->content);

        $fs = get_file_storage();
        $context = \context_module::instance($cmid);
        $files = $fs->get_area_files($context->id, 'mod_book', 'chapter', $destchapter1->id, 'sortorder', false);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('diagram.png', $file->get_filename());
        $this->assertSame($filecontent, $file->get_content());

        // The subchapter's own (file-less) chapter must not have picked up
        // chapter1's file under its own (different) itemid.
        $subfiles = $fs->get_area_files($context->id, 'mod_book', 'chapter', $destsub1->id, 'sortorder', false);
        $this->assertCount(0, $subfiles);
    }

    /**
     * A book with no chapters yet must still create a (content-less)
     * destination activity, not fail the whole sync - same "empty is fine"
     * principle as glossary's/wiki's own no-content cases.
     */
    public function test_book_handler_handles_no_chapters(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $payload = ['name' => 'Empty book', 'intro' => '', 'chapters' => []];

        $handler = new book_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-402');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertGreaterThan(0, $cm->instance);
        $this->assertCount(0, $DB->get_records('book_chapters', ['bookid' => $cm->instance]));
    }

    /**
     * A chapter's embedded file larger than sanitizer::MAX_EMBEDDED_FILE_BYTES
     * is skipped - same per-file leniency as a malformed one (see
     * book_activity_handler::store_chapter_files()) - rather than storing an
     * implausibly large file straight from a remote response with no
     * upload-time maxbytes check to catch it.
     */
    public function test_oversized_embedded_file_is_skipped(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $payload = [
            'name' => 'Big file test',
            'intro' => '',
            'chapters' => [[
                'subchapter' => 0,
                'title' => 'Chapter',
                'content' => '<p>Text.</p>',
                'contentformat' => FORMAT_HTML,
                'hidden' => 0,
                'files' => [[
                    'filename' => 'huge.bin',
                    'filepath' => '/',
                    'mimetype' => 'application/octet-stream',
                    'sortorder' => 0,
                    'contentbase64' => base64_encode(str_repeat('a', sanitizer::MAX_EMBEDDED_FILE_BYTES + 1)),
                ]],
            ]],
        ];

        $handler = new book_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-403');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $chapter = $DB->get_record('book_chapters', ['bookid' => $cm->instance], '*', MUST_EXIST);

        $fs = get_file_storage();
        $context = \context_module::instance($cmid);
        $files = $fs->get_area_files($context->id, 'mod_book', 'chapter', $chapter->id, 'sortorder', false);
        $this->assertCount(0, $files);
    }
}
