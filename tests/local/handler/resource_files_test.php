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
 * Tests for carrying a resource's uploaded files between sites.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(resource_handler::class)]
#[CoversClass(get_activity_file::class)]
final class resource_files_test extends advanced_testcase {
    /**
     * Put a file into a resource's content area.
     *
     * @param \stdClass $resource the module record from the generator
     * @param string $filename
     * @param string $content
     * @param string $filepath
     * @return \stored_file
     */
    protected function add_file(
        \stdClass $resource,
        string $filename,
        string $content,
        string $filepath = '/'
    ): \stored_file {
        $fs = get_file_storage();
        $context = \context_module::instance($resource->cmid);

        return $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => $filepath,
            'filename' => $filename,
            'sortorder' => 1,
        ], $content);
    }

    /**
     * The payload lists the resource's files, with the hash needed to check them.
     */
    public function test_files_are_listed_in_the_payload(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        $content = "Handbook contents\nSecond line\n";
        $this->add_file($resource, 'handbook.txt', $content);

        $exported = get_activity::execute($resource->cmid);
        $payload = activity_payload::from_response($exported);

        $this->assertTrue($payload->has_files());

        $ours = null;

        foreach ($payload->files as $file) {
            if ($file['filename'] === 'handbook.txt') {
                $ours = $file;
            }
        }

        $this->assertNotNull($ours, 'The uploaded file was not listed.');
        $this->assertSame('content', $ours['filearea']);
        $this->assertSame(0, (int) $ours['itemid']);
        $this->assertSame(strlen($content), (int) $ours['filesize']);
        $this->assertSame(sha1($content), $ours['contenthash']);
    }

    /**
     * A file comes back in one piece when it fits in a single chunk.
     */
    public function test_whole_file_in_one_chunk(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        $content = str_repeat('abcdefghij', 100);
        $this->add_file($resource, 'notes.txt', $content);

        $chunk = get_activity_file::execute($resource->cmid, 'content', 0, '/', 'notes.txt', 0, 65536);

        $this->assertSame(strlen($content), $chunk['filesize']);
        $this->assertTrue($chunk['eof']);
        $this->assertSame($content, base64_decode($chunk['content']));
        $this->assertSame(sha1($content), $chunk['contenthash']);
    }

    /**
     * A file larger than one chunk is reassembled correctly across reads, with
     * no bytes lost or repeated at the boundaries.
     */
    public function test_file_reassembles_across_chunks(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        // Deliberately not a round multiple of the chunk size.
        $content = random_bytes(5000) . 'tail';
        $this->add_file($resource, 'big.bin', $content);

        $assembled = '';
        $offset = 0;
        $reads = 0;

        do {
            $chunk = get_activity_file::execute($resource->cmid, 'content', 0, '/', 'big.bin', $offset, 1024);
            $assembled .= base64_decode($chunk['content']);
            $offset += $chunk['returned'];
            $reads++;
            $this->assertLessThan(20, $reads, 'The read did not terminate.');
        } while (!$chunk['eof']);

        $this->assertGreaterThan(1, $reads, 'The file should have taken more than one chunk.');
        $this->assertSame(strlen($content), strlen($assembled));
        $this->assertSame($content, $assembled);
        $this->assertSame(sha1($content), sha1($assembled));
    }

    /**
     * Reading past the end returns nothing and says it is finished, rather than
     * looping forever.
     */
    public function test_reading_past_the_end(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $this->add_file($resource, 'short.txt', 'tiny');

        $chunk = get_activity_file::execute($resource->cmid, 'content', 0, '/', 'short.txt', 9999, 1024);

        $this->assertSame(0, $chunk['returned']);
        $this->assertTrue($chunk['eof']);
        $this->assertSame('', base64_decode($chunk['content']));
    }

    /**
     * A file area the handler never declared cannot be read through this
     * function, which is what stops it being a general file reader.
     */
    public function test_undeclared_area_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $this->add_file($resource, 'handbook.txt', 'contents');

        // The "intro" area is a real Moodle file area, but not one the handler declares.
        try {
            get_activity_file::execute($resource->cmid, 'intro', 0, '/', 'handbook.txt', 0, 1024);
            $this->fail('Expected the undeclared area to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfilenotallowed', $e->errorcode);
        }
    }

    /**
     * An activity type with no file areas at all refuses every file request.
     */
    public function test_activity_without_files_refuses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->expectException(\moodle_exception::class);
        get_activity_file::execute($page->cmid, 'content', 0, '/', 'anything.txt', 0, 1024);
    }

    /**
     * A file that is not there is reported rather than returning empty content.
     */
    public function test_missing_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        try {
            get_activity_file::execute($resource->cmid, 'content', 0, '/', 'nothere.txt', 0, 1024);
            $this->fail('Expected a missing file to be reported');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfilenotfound', $e->errorcode);
        }
    }

    /**
     * The sync permission is required before any bytes are returned.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $this->add_file($resource, 'handbook.txt', 'contents');

        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        get_activity_file::execute($resource->cmid, 'content', 0, '/', 'handbook.txt', 0, 1024);
    }

    /**
     * A chunk request may not ask for more than the cap allows.
     */
    public function test_chunk_size_is_capped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $this->add_file($resource, 'handbook.txt', str_repeat('x', 1000));

        $chunk = get_activity_file::execute(
            $resource->cmid,
            'content',
            0,
            '/',
            'handbook.txt',
            0,
            get_activity_file::MAX_CHUNK * 10
        );

        $this->assertSame(1000, $chunk['returned']);
    }

    /**
     * A negative offset is rejected rather than silently treated as zero.
     */
    public function test_negative_offset_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $this->add_file($resource, 'handbook.txt', 'contents');

        $this->expectException(\invalid_parameter_exception::class);
        get_activity_file::execute($resource->cmid, 'content', 0, '/', 'handbook.txt', -1, 1024);
    }
}
