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

use advanced_testcase;
use block_coursesync\activity_payload;
use block_coursesync\local\handler\resource_handler;
use core\http_client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for bringing an activity's files across.
 *
 * The transport is mocked so the reassembly and the integrity check can be
 * exercised without a second site, including the cases a live test cannot
 * easily produce on demand - a truncated chunk, or content that does not match
 * what the source said it was sending.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(file_sync::class)]
final class file_sync_test extends advanced_testcase {
    /**
     * A client that answers file chunk requests with the given responses.
     *
     * @param Response[] $responses
     * @return http_client
     */
    protected function mock_client(array $responses): http_client {
        return new http_client(['mock' => new MockHandler($responses)]);
    }

    /**
     * One chunk response, shaped the way the source site sends them.
     *
     * @param string $content the bytes this chunk carries
     * @param bool $eof
     * @param int $filesize
     * @param string|null $contenthash what the source claims the whole file hashes to
     * @return Response
     */
    protected function chunk(string $content, bool $eof, int $filesize, ?string $contenthash = null): Response {
        return new Response(200, [], json_encode([
            'filesize' => $filesize,
            'offset' => 0,
            'returned' => strlen($content),
            'eof' => $eof,
            'contenthash' => $contenthash ?? sha1($content),
            'content' => base64_encode($content),
        ]));
    }

    /**
     * A payload describing one file.
     *
     * @param string $content
     * @param string|null $claimedhash
     * @return activity_payload
     */
    protected function payload(string $content, ?string $claimedhash = null): activity_payload {
        return new activity_payload(99, 'resource', 'Course handbook', '', 0, true, '', FORMAT_HTML, 0, [], [
            [
                'filearea' => 'content',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => 'handbook.txt',
                'filesize' => strlen($content),
                'mimetype' => 'text/plain',
                'sortorder' => 1,
                'timemodified' => 1750000000,
                'contenthash' => $claimedhash ?? sha1($content),
            ],
        ]);
    }

    /**
     * A resource to store files against.
     *
     * @return \stdClass the course module record
     */
    protected function make_resource(): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        return $DB->get_record('course_modules', ['id' => $resource->cmid], '*', MUST_EXIST);
    }

    /**
     * A file that arrives in one piece is stored where the activity expects it.
     */
    public function test_single_chunk_file_is_stored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->make_resource();
        $content = "The handbook.\n";

        $stored = file_sync::copy_files(
            $cm,
            new resource_handler(),
            $this->payload($content),
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            $this->mock_client([$this->chunk($content, true, strlen($content))])
        );

        $this->assertSame(1, $stored);

        $fs = get_file_storage();
        $file = $fs->get_file(
            \context_module::instance($cm->id)->id,
            'mod_resource',
            'content',
            0,
            '/',
            'handbook.txt'
        );

        $this->assertNotFalse($file);
        $this->assertSame($content, $file->get_content());
        $this->assertSame(sha1($content), sha1($file->get_content()));
    }

    /**
     * A file split across chunks is put back together in order.
     */
    public function test_multi_chunk_file_is_reassembled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->make_resource();
        $part1 = str_repeat('A', 100);
        $part2 = str_repeat('B', 100);
        $part3 = 'tail';
        $whole = $part1 . $part2 . $part3;
        $size = strlen($whole);

        file_sync::copy_files(
            $cm,
            new resource_handler(),
            $this->payload($whole),
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            $this->mock_client([
                $this->chunk($part1, false, $size, sha1($whole)),
                $this->chunk($part2, false, $size, sha1($whole)),
                $this->chunk($part3, true, $size, sha1($whole)),
            ])
        );

        $file = get_file_storage()->get_file(
            \context_module::instance($cm->id)->id,
            'mod_resource',
            'content',
            0,
            '/',
            'handbook.txt'
        );

        $this->assertSame($whole, $file->get_content());
    }

    /**
     * Content that does not match what the source said it was sending is
     * refused, and nothing is written.
     */
    public function test_corrupted_content_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->make_resource();
        $content = 'what actually arrived';

        try {
            file_sync::copy_files(
                $cm,
                new resource_handler(),
                // The payload claims a hash for different content.
                $this->payload($content, sha1('what was promised')),
                'https://source.example.edu',
                'abcdef0123456789abcdef0123456789',
                $this->mock_client([$this->chunk($content, true, strlen($content), sha1('what was promised'))])
            );
            $this->fail('Expected the mismatch to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfilecorrupt', $e->errorcode);
        }

        $file = get_file_storage()->get_file(
            \context_module::instance($cm->id)->id,
            'mod_resource',
            'content',
            0,
            '/',
            'handbook.txt'
        );

        $this->assertFalse($file, 'Nothing should have been stored.');
    }

    /**
     * A chunk that returns nothing without reaching the end is treated as a
     * failed transfer rather than looping forever.
     */
    public function test_stalled_transfer_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->make_resource();

        try {
            file_sync::copy_files(
                $cm,
                new resource_handler(),
                $this->payload('some content'),
                'https://source.example.edu',
                'abcdef0123456789abcdef0123456789',
                $this->mock_client([$this->chunk('', false, 100, sha1('some content'))])
            );
            $this->fail('Expected the stalled transfer to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfiletransfer', $e->errorcode);
        }
    }

    /**
     * A chunk whose byte count disagrees with its content is refused, which is
     * what catches a response truncated in transit.
     */
    public function test_truncated_chunk_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->make_resource();

        $lying = new Response(200, [], json_encode([
            'filesize' => 20,
            'offset' => 0,
            // Claims 20 bytes but carries 5.
            'returned' => 20,
            'eof' => true,
            'contenthash' => sha1('short'),
            'content' => base64_encode('short'),
        ]));

        try {
            file_sync::copy_files(
                $cm,
                new resource_handler(),
                $this->payload('short'),
                'https://source.example.edu',
                'abcdef0123456789abcdef0123456789',
                $this->mock_client([$lying])
            );
            $this->fail('Expected the truncated chunk to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfiletransfer', $e->errorcode);
        }
    }

    /**
     * Re-copying over an existing file replaces it rather than failing.
     */
    public function test_existing_file_is_replaced(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->make_resource();
        $context = \context_module::instance($cm->id);

        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'handbook.txt',
        ], 'the old contents');

        $content = 'the new contents';

        file_sync::copy_files(
            $cm,
            new resource_handler(),
            $this->payload($content),
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            $this->mock_client([$this->chunk($content, true, strlen($content))])
        );

        $file = get_file_storage()->get_file(
            $context->id,
            'mod_resource',
            'content',
            0,
            '/',
            'handbook.txt'
        );

        $this->assertSame($content, $file->get_content());
    }
}
