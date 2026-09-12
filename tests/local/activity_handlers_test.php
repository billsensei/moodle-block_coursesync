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
 * Tests every v1 activity_handler's create_from_remote_data(): each
 * creates a real course module + instance (and, for resource, a real
 * file) from a synthetic remote payload, following the Phase 4 pattern -
 * see activity_handler.php's docblock for what a new handler must do.
 *
 * Also covers sanitizer's involvement: every handler runs remote-sourced
 * fields through it before the database write (Phase 7), so a script tag
 * in a payload here must never reach the stored row intact.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(page_activity_handler::class)]
#[CoversClass(url_activity_handler::class)]
#[CoversClass(label_activity_handler::class)]
#[CoversClass(resource_activity_handler::class)]
#[CoversClass(forum_activity_handler::class)]
final class activity_handlers_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A course to create activities in.
     *
     * @return \stdClass
     */
    protected function course(): \stdClass {
        return $this->getDataGenerator()->create_course();
    }

    public function test_page_handler_creates_a_matching_page(): void {
        global $DB;

        $course = $this->course();
        $payload = [
            'name' => 'Remote page <script>alert(1)</script>',
            'intro' => '<p>Intro</p><script>alert(2)</script>',
            'introformat' => FORMAT_HTML,
            'content' => '<p>Body content.</p><script>alert(3)</script>',
            'contentformat' => FORMAT_HTML,
            'display' => 0,
            'printintro' => 1,
            'printlastmodified' => 1,
        ];

        $handler = new page_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-101');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertSame('coursesync-101', $cm->idnumber);
        $this->assertGreaterThan(0, $cm->instance);

        $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script>', $page->name);
        $this->assertStringNotContainsString('<script>', $page->intro);
        $this->assertStringNotContainsString('<script>', $page->content);
        $this->assertStringContainsString('Body content.', $page->content);
    }

    public function test_url_handler_creates_a_matching_url(): void {
        global $DB;

        $course = $this->course();
        $payload = [
            'name' => 'Remote link',
            'intro' => 'Intro text.',
            'introformat' => FORMAT_HTML,
            'externalurl' => 'https://example.edu/resource',
            'display' => 0,
            'printintro' => 1,
            'popupwidth' => 620,
            'popupheight' => 450,
        ];

        $handler = new url_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-102');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertSame('coursesync-102', $cm->idnumber);
        // Regression test: url_add_instance() does NOT set course_modules.instance
        // itself (see the handler's docblock) - this was a real Phase 5 bug.
        $this->assertGreaterThan(0, $cm->instance);

        $url = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('Remote link', $url->name);
        $this->assertSame('https://example.edu/resource', $url->externalurl);
    }

    public function test_label_handler_creates_a_matching_label(): void {
        global $DB;

        $course = $this->course();
        $payload = [
            'intro' => '<p>Label content <script>alert(1)</script></p>',
            'introformat' => FORMAT_HTML,
        ];

        $handler = new label_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-103');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertSame('coursesync-103', $cm->idnumber);
        $this->assertGreaterThan(0, $cm->instance);

        $label = $DB->get_record('label', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script>', $label->intro);
        $this->assertStringContainsString('Label content', $label->intro);
    }

    public function test_resource_handler_creates_a_matching_resource_and_file(): void {
        global $DB;

        $course = $this->course();
        $filecontent = "Line one.\nLine two - integrity check.";
        $payload = [
            'name' => 'Remote resource',
            'intro' => 'Intro.',
            'introformat' => FORMAT_HTML,
            'display' => 0,
            'printintro' => 1,
            'files' => [
                [
                    'filename' => 'notes.txt',
                    'filepath' => '/',
                    'mimetype' => 'text/plain',
                    'sortorder' => 1,
                    'contentbase64' => base64_encode($filecontent),
                ],
            ],
        ];

        $handler = new resource_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-104');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertSame('coursesync-104', $cm->idnumber);
        $this->assertGreaterThan(0, $cm->instance);

        $resource = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('Remote resource', $resource->name);

        $fs = get_file_storage();
        $context = \context_module::instance($cmid);
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);
        $this->assertCount(1, $files);

        $file = reset($files);
        $this->assertSame('notes.txt', $file->get_filename());
        $this->assertSame($filecontent, $file->get_content());
    }

    /**
     * A malicious/malformed filename ('../../etc/passwd') must not escape
     * the resource's own file area - sanitizer::filename() strips path
     * separators (PARAM_FILE), so it's stored as a flat, safe name instead.
     */
    public function test_resource_handler_sanitizes_a_path_traversal_filename(): void {
        $course = $this->course();
        $payload = [
            'name' => 'Suspicious file',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'display' => 0,
            'printintro' => 0,
            'files' => [
                [
                    'filename' => '../../../etc/passwd',
                    'filepath' => '/',
                    'mimetype' => 'text/plain',
                    'sortorder' => 1,
                    'contentbase64' => base64_encode('irrelevant'),
                ],
            ],
        ];

        $handler = new resource_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-105');

        $fs = get_file_storage();
        $context = \context_module::instance($cmid);
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);

        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('/', $file->get_filepath());
        // The actual safety property: no path separator survives, so the
        // file can only ever land inside this resource's own file area -
        // there's nothing left to traverse with. PARAM_FILE cleans
        // '../../../etc/passwd' down to '......etcpasswd' (it only rejects
        // a value that's *exactly* '.' or '..', not '..' as a substring of
        // a longer, now-flat name) - which is safe precisely because it
        // has no '/' left in it, not because it has no '.' left in it.
        $this->assertStringNotContainsString('/', $file->get_filename());
        $this->assertNotSame('.', $file->get_filename());
        $this->assertNotSame('..', $file->get_filename());
        $this->assertNotSame('', $file->get_filename());
    }

    public function test_forum_handler_creates_a_matching_general_forum(): void {
        global $DB;

        $course = $this->course();
        $payload = [
            'type' => 'general',
            'name' => 'Remote forum',
            'intro' => 'Forum intro.',
            'introformat' => FORMAT_HTML,
            'maxattachments' => 2,
            'forcesubscribe' => 0,
        ];

        $handler = new forum_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-106');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertSame('coursesync-106', $cm->idnumber);
        // Regression test: forum_add_instance() does NOT set course_modules.instance itself.
        $this->assertGreaterThan(0, $cm->instance);

        $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('general', $forum->type);
        $this->assertSame('Remote forum', $forum->name);
    }

    /**
     * The one deliberate carve-out from Phase 5: a "news" (Announcements)
     * forum must be refused, not created - every course already has one.
     */
    public function test_forum_handler_refuses_a_news_forum(): void {
        $course = $this->course();
        $payload = ['type' => 'news', 'name' => 'Announcements', 'intro' => ''];

        $handler = new forum_activity_handler();

        $this->expectException(\moodle_exception::class);
        $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-107');
    }

    /**
     * An unrecognised forum type (never sent by forum_activity_exporter,
     * but this plugin never trusts remote data to match what its own
     * exporter would send) falls back to 'general' rather than being
     * stored as an arbitrary string forum_add_instance() branches on.
     */
    public function test_forum_handler_falls_back_to_general_for_an_unknown_type(): void {
        global $DB;

        $course = $this->course();
        $payload = ['type' => 'not-a-real-type', 'name' => 'Odd forum', 'intro' => ''];

        $handler = new forum_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-108');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('general', $forum->type);
    }
}
