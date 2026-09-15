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
 * End-to-end round trips for mod_wiki: a group wiki page with an embedded
 * image, and an individual-mode wiki whose personal pages must NOT travel -
 * built with Moodle's own mod_wiki generator on a "source" course, exported
 * with wiki_activity_exporter and recreated with wiki_activity_handler on a
 * fresh "destination" course, through the same JSON encode/decode the real
 * web service transport uses (see quiz_roundtrip_test.php for why that step
 * matters, not just calling export()/create() directly).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(wiki_activity_exporter::class)]
#[CoversClass(wiki_activity_handler::class)]
final class wiki_roundtrip_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    public function test_a_group_page_with_an_embedded_image_survives_the_round_trip(): void {
        global $DB;

        $sourcecourse = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $sourcecourse->id, 'name' => 'Team A',
        ]);
        $sourcewiki = $this->getDataGenerator()->create_module('wiki', [
            'course' => $sourcecourse->id,
            'name' => 'Source wiki',
            'intro' => '<p>Collaborate here.</p>',
            'wikimode' => 'collaborative',
            'defaultformat' => 'html',
            'groupmode' => SEPARATEGROUPS,
        ]);

        /** @var \mod_wiki_generator $wikigenerator */
        $wikigenerator = $this->getDataGenerator()->get_plugin_generator('mod_wiki');
        $wikigenerator->create_content($sourcewiki, [
            'title' => 'Team page',
            'group' => $group->id,
            'content' => '<p>See our diagram: <img src="diagram.png" /></p>',
            'format' => 'html',
        ]);

        $sourcesubwikiid = $DB->get_field('wiki_subwikis', 'id', [
            'wikiid' => $sourcewiki->id, 'groupid' => $group->id, 'userid' => 0,
        ], MUST_EXIST);

        $sourcecontext = \context_module::instance($sourcewiki->cmid);
        $filecontent = 'fake png bytes';
        get_file_storage()->create_file_from_string([
            'contextid' => $sourcecontext->id, 'component' => 'mod_wiki', 'filearea' => 'attachments',
            'itemid' => $sourcesubwikiid, 'filepath' => '/', 'filename' => 'diagram.png',
        ], $filecontent);

        $sourcecm = get_fast_modinfo($sourcecourse)->get_cm($sourcewiki->cmid);

        $exporter = new wiki_activity_exporter();
        $payload = $exporter->export($sourcecm);

        $this->assertSame('Source wiki', $payload['name']);
        $this->assertSame('collaborative', $payload['wikimode']);
        $this->assertCount(1, $payload['subwikis']);
        $this->assertSame('Team A', $payload['subwikis'][0]['groupname']);
        $this->assertCount(1, $payload['subwikis'][0]['pages']);
        $this->assertSame('Team page', $payload['subwikis'][0]['pages'][0]['title']);
        $this->assertStringContainsString('diagram.png', $payload['subwikis'][0]['pages'][0]['content']);
        $this->assertCount(1, $payload['subwikis'][0]['files']);
        $this->assertSame('diagram.png', $payload['subwikis'][0]['files'][0]['filename']);

        // Full production path: JSON encode/decode, same as the real web
        // service transport.
        $decoded = json_decode(json_encode($payload), true);

        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new wiki_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-501');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $wiki = $DB->get_record('wiki', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('Source wiki', $wiki->name);
        $this->assertSame('collaborative', $wiki->wikimode);

        // A same-named group must have been found-or-created on the
        // destination course, and the page placed in ITS subwiki.
        $destgroup = $DB->get_record('groups', [
            'courseid' => $destinationcourse->id, 'name' => 'Team A',
        ], '*', MUST_EXIST);
        $destsubwiki = $DB->get_record('wiki_subwikis', [
            'wikiid' => $wiki->id, 'groupid' => $destgroup->id, 'userid' => 0,
        ], '*', MUST_EXIST);

        $page = $DB->get_record('wiki_pages', ['subwikiid' => $destsubwiki->id, 'title' => 'Team page'], '*', MUST_EXIST);
        $versions = $DB->get_records('wiki_versions', ['pageid' => $page->id], 'version DESC', '*', 0, 1);
        $version = reset($versions);
        $this->assertNotFalse($version);
        $this->assertStringContainsString('diagram.png', $version->content);

        $fs = get_file_storage();
        $context = \context_module::instance($cmid);
        $files = $fs->get_area_files($context->id, 'mod_wiki', 'attachments', $destsubwiki->id, 'sortorder', false);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('diagram.png', $file->get_filename());
        $this->assertSame($filecontent, $file->get_content());
    }

    public function test_individual_mode_syncs_settings_but_no_personal_pages(): void {
        global $DB, $USER;

        $sourcecourse = $this->getDataGenerator()->create_course();
        $sourcewiki = $this->getDataGenerator()->create_module('wiki', [
            'course' => $sourcecourse->id,
            'name' => 'Personal wiki',
            'wikimode' => 'individual',
            'defaultformat' => 'html',
        ]);

        /** @var \mod_wiki_generator $wikigenerator */
        $wikigenerator = $this->getDataGenerator()->get_plugin_generator('mod_wiki');
        // No 'group'/'userid' override -> the generator resolves this to the
        // current user's own subwiki (userid = $USER->id), exactly like a
        // real student's personal wiki page.
        $wikigenerator->create_content($sourcewiki, [
            'title' => 'My page', 'content' => '<p>Private notes.</p>',
        ]);

        // Sanity check: the personal subwiki really was created with a
        // non-zero userid, and is the only subwiki that exists.
        $subwikis = $DB->get_records('wiki_subwikis', ['wikiid' => $sourcewiki->id]);
        $this->assertCount(1, $subwikis);
        $this->assertSame((int) $USER->id, (int) reset($subwikis)->userid);

        $sourcecm = get_fast_modinfo($sourcecourse)->get_cm($sourcewiki->cmid);
        $payload = (new wiki_activity_exporter())->export($sourcecm);

        $this->assertSame('individual', $payload['wikimode']);
        $this->assertSame([], $payload['subwikis'], 'A personal, per-user subwiki must never be exported.');

        $decoded = json_decode(json_encode($payload), true);

        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new wiki_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-502');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $wiki = $DB->get_record('wiki', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('Personal wiki', $wiki->name);
        // The setting itself still carries over...
        $this->assertSame('individual', $wiki->wikimode);
        // ...but no subwiki/page was created, since none was ever exported.
        $this->assertCount(0, $DB->get_records('wiki_subwikis', ['wikiid' => $wiki->id]));
    }
}
