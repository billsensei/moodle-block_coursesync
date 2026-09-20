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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests that a payload from another site is cleaned where it arrives.
 *
 * from_response() is the single place a payload is built, so it is the single
 * place that has to be right. Everything downstream depends on it having
 * already dealt with whatever the other site sent.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(activity_payload::class)]
final class activity_payload_test extends advanced_testcase {
    /**
     * A response with the given fields merged over sensible defaults.
     *
     * @param array $overrides
     * @return array
     */
    protected function response(array $overrides = []): array {
        return array_merge([
            'cmid' => 11,
            'modname' => 'page',
            'name' => 'Week 1 Notes',
            'idnumber' => '',
            'sectionnum' => 1,
            'visible' => true,
            'intro' => '<p>Intro.</p>',
            'introformat' => FORMAT_HTML,
            'timemodified' => 1750000000,
            'settings' => [],
            'files' => [],
        ], $overrides);
    }

    /**
     * Markup in an activity name is stripped, because the name is shown as text.
     */
    public function test_name_is_reduced_to_text(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response([
            'name' => 'Notes <script>alert(1)</script>',
        ]));

        // PARAM_TEXT removes the tags. What is left is inert characters: the
        // word "alert" as text in a name is harmless, a <script> element is not.
        $this->assertStringNotContainsString('<script', $payload->name);
        $this->assertStringNotContainsString('</script', $payload->name);
        $this->assertStringNotContainsString('<', $payload->name);
        $this->assertStringContainsString('Notes', $payload->name);
    }

    /**
     * Script is removed from a description while ordinary markup survives.
     */
    public function test_intro_is_cleaned_but_not_emptied(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response([
            'intro' => '<p>Read <strong>chapter one</strong>.</p><script>alert(1)</script>',
        ]));

        $this->assertStringContainsString('chapter one', $payload->intro);
        $this->assertStringContainsString('<strong>', $payload->intro);
        $this->assertStringNotContainsString('<script', $payload->intro);
    }

    /**
     * An event handler attribute is removed as well as a script tag.
     */
    public function test_event_handlers_are_removed(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response([
            'intro' => '<p onmouseover="alert(1)">Hover me</p>',
        ]));

        $this->assertStringNotContainsString('onmouseover', $payload->intro);
        $this->assertStringContainsString('Hover me', $payload->intro);
    }

    /**
     * A text format this site does not implement falls back rather than being
     * stored as an unknown number.
     */
    public function test_unknown_text_format_falls_back(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response(['introformat' => 9999]));

        $this->assertSame((int) FORMAT_HTML, $payload->introformat);
    }

    /**
     * Numbers arrive as numbers, and a negative one is floored rather than
     * reaching a query as-is.
     */
    public function test_numbers_are_cleaned(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response([
            'cmid' => '11abc',
            'sectionnum' => -5,
            'timemodified' => -1,
        ]));

        $this->assertSame(11, $payload->cmid);
        $this->assertSame(0, $payload->sectionnum);
        $this->assertSame(0, $payload->timemodified);
    }

    /**
     * An activity type that is not a plugin name does not survive.
     */
    public function test_modname_is_held_to_a_plugin_name(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response([
            'modname' => '../../etc/passwd',
        ]));

        $this->assertStringNotContainsString('/', $payload->modname);
        $this->assertStringNotContainsString('.', $payload->modname);
    }

    /**
     * A setting name that is not a plain identifier is dropped, so it cannot
     * address anything a handler did not expect.
     */
    public function test_odd_setting_names_are_dropped(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response([
            'settings' => [
                ['name' => 'content', 'value' => 'kept'],
                ['name' => 'a name with spaces', 'value' => 'dropped'],
                ['name' => '', 'value' => 'dropped'],
            ],
        ]));

        $this->assertSame('kept', $payload->setting('content'));
        $this->assertSame('', $payload->setting('a name with spaces'));
    }

    /**
     * A javascript address in a setting does not survive being read as a URL.
     *
     * mod_url renders its address as a link, so this is the difference between
     * a link and script running on this site.
     */
    public function test_setting_url_rejects_dangerous_addresses(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response([
            'settings' => [
                ['name' => 'bad', 'value' => 'javascript:alert(1)'],
                ['name' => 'alsobad', 'value' => 'data:text/html;base64,PHNjcmlwdD4='],
                ['name' => 'good', 'value' => 'https://example.edu/reading'],
            ],
        ]));

        $this->assertSame('', $payload->setting_url('bad'));
        $this->assertSame('', $payload->setting_url('alsobad'));
        $this->assertSame('https://example.edu/reading', $payload->setting_url('good'));
    }

    /**
     * An HTML setting is cleaned the same way a description is.
     */
    public function test_setting_html_is_cleaned(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response([
            'settings' => [
                ['name' => 'content', 'value' => '<p>Body</p><script>alert(1)</script>'],
            ],
        ]));

        $cleaned = $payload->setting_html('content', FORMAT_HTML);

        $this->assertStringContainsString('Body', $cleaned);
        $this->assertStringNotContainsString('<script', $cleaned);
    }

    /**
     * A file path that tries to climb out of its area does not survive.
     */
    public function test_file_metadata_is_cleaned(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response([
            'files' => [
                [
                    'filearea' => 'content',
                    'itemid' => -3,
                    'filepath' => '/../../secrets/',
                    'filename' => 'handbook.txt',
                    'filesize' => 100,
                    'mimetype' => 'text/plain',
                    'sortorder' => 1,
                    'timemodified' => 1750000000,
                    'contenthash' => str_repeat('a', 40),
                ],
            ],
        ]));

        $this->assertCount(1, $payload->files);

        $file = $payload->files[0];

        $this->assertStringNotContainsString('..', $file['filepath']);
        $this->assertSame(0, $file['itemid']);
        $this->assertSame('handbook.txt', $file['filename']);
    }

    /**
     * A file with no usable name is dropped rather than guessed at.
     */
    public function test_unusable_files_are_dropped(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response($this->response([
            'files' => [
                ['filearea' => 'content', 'filename' => '', 'filepath' => '/'],
                ['filearea' => '', 'filename' => 'orphan.txt', 'filepath' => '/'],
                'not an array',
            ],
        ]));

        $this->assertSame([], $payload->files);
        $this->assertFalse($payload->has_files());
    }

    /**
     * Content that points at files which were not copied is reported, so the
     * sync can say so rather than creating something quietly broken.
     */
    public function test_embedded_file_references_are_detected(): void {
        $this->resetAfterTest();

        $plain = activity_payload::from_response($this->response());
        $withfile = activity_payload::from_response($this->response([
            'settings' => [
                ['name' => 'content', 'value' => '<img src="@@PLUGINFILE@@/diagram.png" alt="x">'],
            ],
        ]));

        $this->assertFalse($plain->references_files());
        $this->assertTrue($withfile->references_files());
    }

    /**
     * A response missing everything optional still produces a usable payload.
     */
    public function test_sparse_response(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response(['cmid' => 11, 'modname' => 'page', 'name' => 'Bare']);

        $this->assertSame(11, $payload->cmid);
        $this->assertSame('Bare', $payload->name);
        $this->assertSame('', $payload->intro);
        $this->assertSame([], $payload->files);
        $this->assertSame('fallback', $payload->setting('missing', 'fallback'));
        $this->assertSame(7, $payload->setting_int('missing', 7));
    }
}
