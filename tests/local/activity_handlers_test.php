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
#[CoversClass(assign_activity_handler::class)]
#[CoversClass(h5pactivity_activity_handler::class)]
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

    public function test_assign_handler_creates_a_matching_assignment(): void {
        global $DB;

        $course = $this->course();
        $payload = [
            'name' => 'Remote assignment <script>alert(1)</script>',
            'intro' => '<p>Submit your work.</p><script>alert(2)</script>',
            'introformat' => FORMAT_HTML,
            'duedate' => 1893456000,
            'grade' => 100,
            'submissiondrafts' => 1,
            'teamsubmission' => 0,
            'blindmarking' => 0,
            'attemptreopenmethod' => 'manual',
            'maxattempts' => 3,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_onlinetext_wordlimit' => 500,
            'assignsubmission_onlinetext_wordlimitenabled' => 1,
            'assignsubmission_file_enabled' => 0,
            'assignfeedback_comments_enabled' => 1,
            'assignfeedback_comments_commentinline' => 1,
        ];

        $handler = new assign_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-109');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertSame('coursesync-109', $cm->idnumber);
        // Regression test: assign::add_instance() does NOT set course_modules.instance itself.
        $this->assertGreaterThan(0, $cm->instance);

        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script>', $assign->name);
        $this->assertStringNotContainsString('<script>', $assign->intro);
        $this->assertStringContainsString('Submit your work.', $assign->intro);
        $this->assertSame('manual', $assign->attemptreopenmethod);
        $this->assertEquals(3, $assign->maxattempts);

        // Regression test: without this handler explicitly setting each
        // known sub-plugin's "*_enabled" field, assign::add_instance()
        // force-disables every installed submission/feedback sub-plugin -
        // see the handler's docblock. onlinetext and comments should end up
        // enabled (as the payload asked); file should end up disabled.
        $onlinetextenabled = $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assign->id, 'subtype' => 'assignsubmission', 'plugin' => 'onlinetext', 'name' => 'enabled',
        ]);
        $fileenabled = $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assign->id, 'subtype' => 'assignsubmission', 'plugin' => 'file', 'name' => 'enabled',
        ]);
        $commentsenabled = $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assign->id, 'subtype' => 'assignfeedback', 'plugin' => 'comments', 'name' => 'enabled',
        ]);
        $this->assertEquals(1, $onlinetextenabled);
        $this->assertEquals(0, $fileenabled);
        $this->assertEquals(1, $commentsenabled);

        $wordlimit = $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assign->id, 'subtype' => 'assignsubmission', 'plugin' => 'onlinetext', 'name' => 'wordlimit',
        ]);
        $this->assertEquals(500, $wordlimit);
    }

    /**
     * An unrecognised attemptreopenmethod (never sent by
     * assign_activity_exporter, but this plugin never trusts remote data to
     * match what its own exporter would send) falls back to 'untilpass'
     * rather than being stored as an arbitrary string assign's own code
     * branches on.
     */
    public function test_assign_handler_falls_back_to_untilpass_for_an_unknown_reopen_method(): void {
        global $DB;

        $course = $this->course();
        $payload = ['name' => 'Odd assignment', 'intro' => '', 'attemptreopenmethod' => 'not-a-real-method'];

        $handler = new assign_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-110');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('untilpass', $assign->attemptreopenmethod);
    }

    public function test_h5pactivity_handler_creates_a_matching_activity_and_package(): void {
        global $DB;

        // A package file is stashed in a draft area owned by the *current*
        // user before being moved into its final location (see the
        // handler's docblock) - in real use this is always a real logged-in
        // teacher (sync.php requires_login()), so the test needs one too,
        // unlike every other test here that doesn't touch the file API's
        // user-context path.
        $this->setAdminUser();

        $course = $this->course();
        $filecontent = 'fake h5p package bytes';
        $payload = [
            'name' => 'Remote H5P <script>alert(1)</script>',
            'intro' => '<p>Play this.</p><script>alert(2)</script>',
            'introformat' => FORMAT_HTML,
            'grade' => 50,
            'displayoptions' => 0,
            'enabletracking' => 1,
            'grademethod' => 1,
            'reviewmode' => 1,
            'package' => [
                'filename' => 'content.h5p',
                'contentbase64' => base64_encode($filecontent),
            ],
        ];

        $handler = new h5pactivity_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-111');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertSame('coursesync-111', $cm->idnumber);
        $this->assertGreaterThan(0, $cm->instance);

        $h5pactivity = $DB->get_record('h5pactivity', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script>', $h5pactivity->name);
        $this->assertStringNotContainsString('<script>', $h5pactivity->intro);
        $this->assertEquals(50, $h5pactivity->grade);

        $fs = get_file_storage();
        $context = \context_module::instance($cmid);
        $files = $fs->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'sortorder', false);
        $this->assertCount(1, $files);

        $file = reset($files);
        $this->assertSame('content.h5p', $file->get_filename());
        $this->assertSame($filecontent, $file->get_content());
    }

    /**
     * A source activity with no package uploaded yet must still create a
     * (content-less) destination activity, not fail the whole sync.
     */
    public function test_h5pactivity_handler_handles_a_missing_package(): void {
        global $DB;

        $course = $this->course();
        $payload = ['name' => 'Empty H5P', 'intro' => '', 'package' => null];

        $handler = new h5pactivity_activity_handler();
        $cmid = $handler->create_from_remote_data($course->id, 0, $payload, 'coursesync-112');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertGreaterThan(0, $cm->instance);

        $fs = get_file_storage();
        $context = \context_module::instance($cmid);
        $files = $fs->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'sortorder', false);
        $this->assertCount(0, $files);
    }
}
