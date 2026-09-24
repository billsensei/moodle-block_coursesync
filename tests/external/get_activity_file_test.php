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
 * Tests for serving a file a piece at a time.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_activity_file::class)]
final class get_activity_file_test extends advanced_testcase {
    /**
     * Pieces read at offsets put back together are the file, and reading at
     * or past the end returns nothing and says so.
     */
    public function test_pieces_reassemble_into_the_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        // Bigger than one piece, and not a multiple of it.
        $content = random_bytes(get_activity_file::MAX_CHUNK * 2 + 12345);

        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($page->cmid)->id,
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'big.bin',
        ], $content);

        $assembled = '';
        $offset = 0;

        do {
            $piece = external_api::clean_returnvalue(
                get_activity_file::execute_returns(),
                get_activity_file::execute((int) $page->cmid, 'intro', 0, '/', 'big.bin', $offset)
            );

            $this->assertSame($offset, $piece['offset']);
            $assembled .= base64_decode($piece['content']);
            $offset += $piece['returned'];
        } while (!$piece['eof']);

        $this->assertSame(strlen($content), $offset);
        $this->assertSame(sha1($content), sha1($assembled));

        $past = get_activity_file::execute((int) $page->cmid, 'intro', 0, '/', 'big.bin', strlen($content));
        $this->assertSame(0, $past['returned']);
        $this->assertTrue($past['eof']);
    }
}
