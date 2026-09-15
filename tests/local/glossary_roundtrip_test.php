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
 * End-to-end round trip: a real glossary with a real category, two entries
 * (one approved with a category, an alias, and an attachment; one
 * unapproved), built with Moodle's own mod_glossary generator on a "source"
 * course, exported with glossary_activity_exporter, and recreated with
 * glossary_activity_handler on a fresh "destination" course - the same two
 * classes sync_runner wires together in production, through the same JSON
 * encode/decode the real web service transport uses (see quiz_roundtrip_test.php
 * for why that step matters, not just calling export()/create() directly).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(glossary_activity_exporter::class)]
#[CoversClass(glossary_activity_handler::class)]
final class glossary_roundtrip_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    public function test_a_glossary_with_a_category_alias_and_attachment_survives_the_round_trip(): void {
        global $DB;

        $sourcecourse = $this->getDataGenerator()->create_course();
        $sourceglossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $sourcecourse->id,
            'name' => 'Source glossary',
            'intro' => '<p>Key terms.</p>',
        ]);

        /** @var \mod_glossary_generator $glossarygenerator */
        $glossarygenerator = $this->getDataGenerator()->get_plugin_generator('mod_glossary');

        $approved = $glossarygenerator->create_content($sourceglossary, [
            'concept' => 'Cat', 'definition' => '<p>A small domesticated feline.</p>',
            'definitionformat' => FORMAT_HTML, 'approved' => 1,
        ], ['Kitty', 'Feline']);
        $glossarygenerator->create_category($sourceglossary, ['name' => 'Animals'], [$approved]);

        // An entry still awaiting moderation must not be exported at all.
        $glossarygenerator->create_content($sourceglossary, [
            'concept' => 'Unmoderated', 'definition' => '<p>Not yet approved.</p>', 'approved' => 0,
        ]);

        $sourcecontext = \context_module::instance($sourceglossary->cmid);
        $filecontent = 'fake attachment bytes';
        get_file_storage()->create_file_from_string([
            'contextid' => $sourcecontext->id, 'component' => 'mod_glossary', 'filearea' => 'attachment',
            'itemid' => $approved->id, 'filepath' => '/', 'filename' => 'cat.txt',
        ], $filecontent);

        $sourcecm = get_fast_modinfo($sourcecourse)->get_cm($sourceglossary->cmid);

        $exporter = new glossary_activity_exporter();
        $payload = $exporter->export($sourcecm);

        $this->assertSame('Source glossary', $payload['name']);
        $this->assertCount(1, $payload['categories']);
        $this->assertSame('Animals', $payload['categories'][0]['name']);
        // Only the approved entry travels.
        $this->assertCount(1, $payload['entries']);
        $this->assertSame('Cat', $payload['entries'][0]['concept']);
        $this->assertSame([0], $payload['entries'][0]['categoryindexes']);
        $this->assertEqualsCanonicalizing(['Kitty', 'Feline'], $payload['entries'][0]['aliases']);
        $this->assertCount(1, $payload['entries'][0]['attachments']);

        // Full production path: JSON encode/decode, same as the real web
        // service transport.
        $decoded = json_decode(json_encode($payload), true);

        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new glossary_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-401');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $glossary = $DB->get_record('glossary', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('Source glossary', $glossary->name);

        $entries = $DB->get_records('glossary_entries', ['glossaryid' => $glossary->id]);
        $this->assertCount(1, $entries, 'Only the approved entry must have been recreated.');
        $entry = reset($entries);
        $this->assertSame('Cat', $entry->concept);
        $this->assertStringContainsString('domesticated feline', $entry->definition);

        $category = $DB->get_record('glossary_categories', ['glossaryid' => $glossary->id], '*', MUST_EXIST);
        $this->assertSame('Animals', $category->name);
        $link = $DB->get_record('glossary_entries_categories', ['entryid' => $entry->id], '*', MUST_EXIST);
        $this->assertEquals($category->id, $link->categoryid);

        $aliases = $DB->get_records('glossary_alias', ['entryid' => $entry->id]);
        $this->assertEqualsCanonicalizing(['Kitty', 'Feline'], array_map(fn($a) => $a->alias, $aliases));

        $fs = get_file_storage();
        $context = \context_module::instance($cmid);
        $files = $fs->get_area_files($context->id, 'mod_glossary', 'attachment', $entry->id, 'sortorder', false);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('cat.txt', $file->get_filename());
        $this->assertSame($filecontent, $file->get_content());
    }
}
