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

namespace block_coursesync\local\handler;

use advanced_testcase;
use block_coursesync\activity_payload;
use block_coursesync\external\get_activity;
use block_coursesync\external\get_activity_file;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for a book's files, which are the first that are not under one item id.
 *
 * Every other type keeps its files under a single, known item id, so the source
 * site could name that id when it said which areas an activity may be read
 * from. A book cannot: each chapter's images are stored under that chapter's
 * own id. So the book vouches for the area instead, and these tests pin both
 * halves of that - that every chapter's files are found and reported under the
 * right chapter, and that vouching for an area did not turn into vouching for
 * anything else.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(book_handler::class)]
#[CoversClass(get_activity::class)]
#[CoversClass(get_activity_file::class)]
final class book_files_test extends advanced_testcase {
    /**
     * Make a book with a file in each of two chapters.
     *
     * @return array [the book, the first chapter, the second chapter]
     */
    protected function make_book(): array {
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $one = $generator->create_chapter(['bookid' => $book->id, 'pagenum' => 1, 'title' => 'One']);
        $two = $generator->create_chapter(['bookid' => $book->id, 'pagenum' => 2, 'title' => 'Two']);

        $context = \context_module::instance($book->cmid);
        $fs = get_file_storage();

        foreach ([$one->id => 'one.txt', $two->id => 'two.txt'] as $chapterid => $filename) {
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_book',
                'filearea' => 'chapter',
                'itemid' => $chapterid,
                'filepath' => '/',
                'filename' => $filename,
            ], 'contents of ' . $filename);
        }

        return [$book, $one, $two];
    }

    /**
     * Each chapter's files are listed against that chapter, not lumped under a
     * single item id the way every other type's are.
     */
    public function test_every_chapter_file_is_listed_under_its_chapter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$book, $one, $two] = $this->make_book();

        $exported = get_activity::execute($book->cmid);

        $byname = [];

        foreach ($exported['files'] as $file) {
            $byname[$file['filename']] = $file;
        }

        $this->assertCount(2, $byname);
        $this->assertSame((int) $one->id, $byname['one.txt']['itemid']);
        $this->assertSame((int) $two->id, $byname['two.txt']['itemid']);
        $this->assertSame('chapter', $byname['one.txt']['filearea']);
    }

    /**
     * A chapter's file can be read, under whichever chapter id it belongs to.
     */
    public function test_a_chapter_file_can_be_read(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$book, , $two] = $this->make_book();

        $chunk = get_activity_file::execute($book->cmid, 'chapter', (int) $two->id, '/', 'two.txt', 0, 65536);

        $this->assertSame('contents of two.txt', $chunk['content'] === ''
            ? ''
            : base64_decode($chunk['content']));
    }

    /**
     * Vouching for the chapter area does not vouch for any other area. This is
     * the check that keeps the book's looser rule from becoming a way to read
     * whatever else happens to be stored against the activity.
     */
    public function test_another_area_of_the_same_book_is_still_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$book] = $this->make_book();

        $context = \context_module::instance($book->cmid);

        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_book',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'secret.txt',
        ], 'not part of a chapter');

        try {
            get_activity_file::execute($book->cmid, 'intro', 0, '/', 'secret.txt', 0, 1024);
            $this->fail('Expected a moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfilenotallowed', $e->errorcode);
        }
    }

    /**
     * A type whose areas name an item id still only allows that item id, so the
     * book's looser rule did not leak into the other handlers.
     */
    public function test_a_named_item_id_is_still_enforced_elsewhere(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        $context = \context_module::instance($resource->cmid);

        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 99,
            'filepath' => '/',
            'filename' => 'elsewhere.txt',
        ], 'stored under another item id');

        try {
            get_activity_file::execute($resource->cmid, 'content', 99, '/', 'elsewhere.txt', 0, 1024);
            $this->fail('Expected a moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfilenotallowed', $e->errorcode);
        }
    }

    /**
     * A chapter's file ends up against the chapter created for it on this site,
     * whose id is this site's and not the source's.
     */
    public function test_chapter_files_are_refiled_on_the_copy(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$book, $one, $two] = $this->make_book();
        $target = $this->getDataGenerator()->create_course();

        $payload = activity_payload::from_response(get_activity::execute($book->cmid));
        $handler = new book_handler();
        $cm = $handler->create_from_remote_data($target, $payload, 'coursesync-1');

        $local = array_values($DB->get_records('book_chapters', ['bookid' => $cm->instance], 'pagenum ASC'));

        $mapped = [];

        foreach ($payload->files as $file) {
            $mapped[$file['filename']] = $handler->map_file_itemid($payload, $file, $cm);
        }

        $this->assertSame((int) $local[0]->id, $mapped['one.txt']);
        $this->assertSame((int) $local[1]->id, $mapped['two.txt']);

        // The whole point: these are not the ids the files arrived with.
        $this->assertNotSame((int) $one->id, $mapped['one.txt']);
        $this->assertNotSame((int) $two->id, $mapped['two.txt']);
    }
}
