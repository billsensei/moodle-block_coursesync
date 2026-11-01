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
use block_coursesync\connection;
use block_coursesync\course_result;
use block_coursesync\external\get_section_file;
use block_coursesync\syncer;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/source_on_this_site.php');

/**
 * Tests for what comes with a section: its description and its files, the
 * order of the activities in it, and bringing sections already here into line.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(section_map::class)]
#[CoversClass(get_section_file::class)]
#[CoversClass(syncer::class)]
final class section_content_test extends advanced_testcase {
    use source_on_this_site;

    /** @var \stdClass The course activities are copied from. */
    protected \stdClass $source;

    /** @var \stdClass The course they are copied into. */
    protected \stdClass $target;

    /** @var int The Course Sync block in the target course. */
    protected int $instanceid = 0;

    /**
     * A source with four sections, and a target with one, with a Course Sync
     * block pointed at the source - on this same site.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->source = $this->getDataGenerator()->create_course(['numsections' => 4, 'shortname' => 'SRC']);
        $this->target = $this->getDataGenerator()->create_course(['numsections' => 1]);

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($this->target->id));
        $page->set_course($this->target);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $this->target->format);
        $page->set_url('/course/view.php', ['id' => $this->target->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');

        $this->instanceid = (int) $DB->get_field('block_instances', 'id', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($this->target->id)->id,
        ], MUST_EXIST);

        connection::set_url($this->instanceid, $this->target->id, 'https://source.example.edu');
        connection::set_token($this->instanceid, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            $this->instanceid,
            'SRC',
            course_result::success((int) $this->source->id, 'SRC', 'Source', true, 1)
        );

        section_map::release((int) $this->target->id);
    }

    /**
     * A page in a section of the source course.
     *
     * @param string $name
     * @param int $section
     * @return int its course module id
     */
    protected function page_in(string $name, int $section): int {
        return (int) $this->getDataGenerator()->create_module('page', [
            'course' => $this->source->id,
            'name' => $name,
            'section' => $section,
        ])->cmid;
    }

    /**
     * The course's modinfo as it is now, not as cached earlier in the test.
     *
     * @param int $courseid
     * @return \course_modinfo
     */
    protected static function modinfo(int $courseid): \course_modinfo {
        get_fast_modinfo($courseid, 0, true);

        return get_fast_modinfo($courseid);
    }

    /**
     * Change fields of a section of a course.
     *
     * @param \stdClass $course
     * @param int $number
     * @param array $fields
     * @return void
     */
    protected function change_section(\stdClass $course, int $number, array $fields): void {
        $info = self::modinfo($course->id)->get_section_info($number);
        \core_courseformat\formatactions::section($course)->update($info, $fields);
    }

    /**
     * A section of a course, as it is now.
     *
     * @param \stdClass $course
     * @param int $number
     * @return \section_info
     */
    protected static function section(\stdClass $course, int $number): \section_info {
        return self::modinfo($course->id)->get_section_info($number);
    }

    /**
     * Run a sync of chosen activities from the fake source.
     *
     * @param int[] $remotecmids
     * @param bool $updatesections whether to bring existing sections into line
     * @return \block_coursesync\sync_result
     */
    protected function sync(array $remotecmids, bool $updatesections = false): \block_coursesync\sync_result {
        return syncer::run(
            $this->instanceid,
            $this->target->id,
            true,
            $this->local_source(),
            $remotecmids,
            null,
            $updatesections
        );
    }

    /**
     * The names, in order, of the activities in a section of the target.
     *
     * @param int $number
     * @return string[]
     */
    protected function names_in(int $number): array {
        $modinfo = self::modinfo($this->target->id);
        $names = [];

        foreach ($modinfo->get_sections()[$number] ?? [] as $cmid) {
            $names[] = $modinfo->get_cm($cmid)->name;
        }

        return $names;
    }

    /**
     * Put a file in a source section's description.
     *
     * @param int $number the section number on the source
     * @param string $filename
     * @param string $content
     * @return void
     */
    protected function add_section_file(int $number, string $filename, string $content): void {
        $section = self::section($this->source, $number);

        get_file_storage()->create_file_from_string([
            'contextid' => \context_course::instance($this->source->id)->id,
            'component' => 'course',
            'filearea' => 'section',
            'itemid' => $section->id,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * A section made here gets the source's description, and the files in it.
     */
    public function test_a_new_section_gets_the_description_and_its_files(): void {
        $this->change_section($this->source, 3, [
            'summary' => '<p>Read this first</p><img src="@@PLUGINFILE@@/map.png" alt="map">',
            'summaryformat' => FORMAT_HTML,
        ]);
        $this->add_section_file(3, 'map.png', 'not really a picture');
        $three = $this->page_in('Third', 3);

        $result = $this->sync([$three]);

        $this->assertSame(0, $result->sectionfilesfailed);
        $section = self::section($this->target, 3);
        $this->assertStringContainsString('Read this first', $section->summary);
        $this->assertStringContainsString('@@PLUGINFILE@@/map.png', $section->summary);

        $file = get_file_storage()->get_file(
            \context_course::instance($this->target->id)->id,
            'course',
            'section',
            $section->id,
            '/',
            'map.png'
        );
        $this->assertNotFalse($file);
        $this->assertSame('not really a picture', $file->get_content());
    }

    /**
     * Script in a description does not survive the trip.
     */
    public function test_a_description_is_cleaned(): void {
        $this->change_section($this->source, 2, [
            'summary' => '<p>Hello</p><script>alert(1)</script>',
            'summaryformat' => FORMAT_HTML,
        ]);

        $this->sync([$this->page_in('Second', 2)]);

        $summary = self::section($this->target, 2)->summary;
        $this->assertStringContainsString('Hello', $summary);
        $this->assertStringNotContainsString('<script', $summary);
    }

    /**
     * A description somebody wrote here is not replaced when a section is adopted,
     * but an empty one is filled.
     */
    public function test_an_adopted_section_keeps_its_own_description(): void {
        $this->change_section($this->source, 1, ['summary' => '<p>Theirs</p>']);
        $this->change_section($this->source, 2, ['summary' => '<p>Theirs two</p>']);
        $this->change_section($this->target, 1, ['summary' => '<p>Mine</p>']);
        $this->getDataGenerator()->create_course_section(['course' => $this->target->id, 'section' => 2]);

        $this->sync([$this->page_in('One', 1), $this->page_in('Two', 2)]);

        $this->assertStringContainsString('Mine', self::section($this->target, 1)->summary);
        $this->assertStringContainsString('Theirs two', self::section($this->target, 2)->summary);
    }

    /**
     * A source from before descriptions were sent leaves them alone.
     */
    public function test_a_source_that_sends_no_description_changes_nothing(): void {
        $this->change_section($this->target, 1, ['summary' => '<p>Mine</p>']);

        $old = new \core\http_client(['mock' => function (\Psr\Http\Message\RequestInterface $request) {
            parse_str((string) $request->getBody(), $params);

            if (($params['wsfunction'] ?? '') === 'block_coursesync_get_sections') {
                $rows = array_map(
                    static fn(array $row): array => array_intersect_key($row, array_flip(['id', 'section', 'name', 'visible'])),
                    \block_coursesync\external\get_sections::execute((int) $params['courseid'])
                );

                return \GuzzleHttp\Promise\Create::promiseFor(
                    new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($rows))
                );
            }

            return \GuzzleHttp\Promise\Create::promiseFor(
                $this->local_source()->post('https://source.example.edu/webservice/rest/server.php', [
                    'form_params' => $params,
                ])
            );
        }]);

        $one = $this->page_in('One', 1);
        $result = syncer::run($this->instanceid, $this->target->id, true, $old, [$one], null, true);

        $this->assertSame(1, $result->count('created'));
        $this->assertStringContainsString('Mine', self::section($this->target, 1)->summary);
    }

    /**
     * Activities end up in the source's order whatever order they were copied in.
     */
    public function test_activities_keep_the_order_they_have_on_the_source(): void {
        $a = $this->page_in('A', 1);
        $b = $this->page_in('B', 1);
        $c = $this->page_in('C', 1);
        $d = $this->page_in('D', 1);

        $this->sync([$c]);
        $this->sync([$a]);
        $this->sync([$d]);
        $this->sync([$b]);

        $this->assertSame(['A', 'B', 'C', 'D'], $this->names_in(1));
    }

    /**
     * Something a teacher put in the section here is not moved to make room.
     */
    public function test_an_arrangement_made_here_is_not_disturbed(): void {
        $this->getDataGenerator()->create_module('page', ['course' => $this->target->id, 'name' => 'Mine', 'section' => 1]);

        $a = $this->page_in('A', 1);
        $b = $this->page_in('B', 1);
        $this->sync([$b]);
        $this->sync([$a]);

        $names = $this->names_in(1);
        $this->assertSame('Mine', $names[0]);
        $this->assertLessThan(array_search('B', $names, true), array_search('A', $names, true));
    }

    /**
     * Without the request, a section already here keeps its name and description.
     */
    public function test_existing_sections_are_left_alone_unless_asked(): void {
        $this->change_section($this->source, 1, ['name' => 'Their name', 'summary' => '<p>Their words</p>']);
        $this->change_section($this->target, 1, ['name' => 'My name', 'summary' => '<p>My words</p>']);

        $result = $this->sync([$this->page_in('One', 1)]);

        $this->assertSame(0, $result->sectionsupdated);
        $this->assertSame('My name', self::section($this->target, 1)->name);
        $this->assertStringContainsString('My words', self::section($this->target, 1)->summary);
    }

    /**
     * Asked to, sections here take the source's name and description, with no
     * activity needing to be copied, and visibility is left alone.
     */
    public function test_asking_updates_names_and_descriptions(): void {
        $this->change_section($this->source, 1, ['name' => 'Their name', 'summary' => '<p>Their words</p>']);
        $this->change_section($this->source, 2, ['name' => 'Second', 'visible' => 0]);
        $this->change_section($this->target, 1, ['name' => 'My name', 'summary' => '<p>My words</p>']);

        $result = $this->sync([], true);

        $this->assertGreaterThan(0, $result->sectionsupdated);
        $this->assertSame('Their name', self::section($this->target, 1)->name);
        $this->assertStringContainsString('Their words', self::section($this->target, 1)->summary);
        $this->assertSame('Second', self::section($this->target, 2)->name);
        $this->assertStringContainsString((string) $result->sectionsupdated, $result->get_message());
    }

    /**
     * Only a section that was already here counts as updated: those made or
     * adopted by the same run already have the source's name and description.
     */
    public function test_only_sections_already_here_count_as_updated(): void {
        foreach ([1 => 'Alpha', 2 => 'Beta', 3 => 'Gamma', 4 => 'Delta'] as $number => $name) {
            $this->change_section($this->source, $number, ['name' => $name, 'summary' => '<p>About ' . $name . '</p>']);
        }
        $this->change_section($this->target, 1, ['name' => 'Mine']);

        $result = $this->sync([], true);

        $this->assertSame(3, $result->sectionscreated);
        $this->assertSame(1, $result->sectionsupdated);
        $this->assertSame('Alpha', self::section($this->target, 1)->name);
        $this->assertSame('Delta', self::section($this->target, 4)->name);
        $this->assertStringContainsString('About Gamma', self::section($this->target, 3)->summary);
    }

    /**
     * A plain-text description is kept as plain text and is escaped on display;
     * a format the site does not have is read as HTML, and cleaned.
     */
    public function test_a_description_format_is_held_to_the_known_ones(): void {
        $this->change_section($this->source, 1, [
            'summary' => '<p>Hello</p><script>alert(1)</script>',
            'summaryformat' => FORMAT_PLAIN,
        ]);
        $this->change_section($this->source, 2, [
            'summary' => '<p>Hi</p><script>alert(2)</script>',
            'summaryformat' => 99,
        ]);

        $this->change_section($this->source, 3, [
            'summary' => "Some *words*\n\n<script>alert(3)</script>",
            'summaryformat' => FORMAT_MARKDOWN,
        ]);

        $this->sync([$this->page_in('One', 1), $this->page_in('Two', 2), $this->page_in('Three', 3)]);

        $markdown = self::section($this->target, 3);
        $this->assertSame((int) FORMAT_HTML, (int) $markdown->summaryformat);
        $this->assertStringContainsString('<em>words</em>', $markdown->summary);
        $this->assertStringNotContainsString('<script', $markdown->summary);

        $plain = self::section($this->target, 1);
        $this->assertSame((int) FORMAT_PLAIN, (int) $plain->summaryformat);
        $this->assertStringNotContainsString('<script', format_text($plain->summary, $plain->summaryformat));

        $odd = self::section($this->target, 2);
        $this->assertSame((int) FORMAT_HTML, (int) $odd->summaryformat);
        $this->assertStringNotContainsString('<script', $odd->summary);
    }

    /**
     * A source section with no name does not blank one a person gave it here.
     */
    public function test_asking_never_blanks_a_name(): void {
        $this->change_section($this->target, 1, ['name' => 'Keep me']);

        $this->sync([], true);

        $this->assertSame('Keep me', self::section($this->target, 1)->name);
    }

    /**
     * The description file function serves a section's file in pieces.
     */
    public function test_the_section_file_function_serves_a_file(): void {
        $this->add_section_file(2, 'notes.txt', 'abcdefghij');
        $section = self::section($this->source, 2);

        $first = get_section_file::execute((int) $this->source->id, (int) $section->id, '/', 'notes.txt', 0, 4);
        $this->assertSame('abcd', base64_decode($first['content']));
        $this->assertFalse($first['eof']);

        $last = get_section_file::execute((int) $this->source->id, (int) $section->id, '/', 'notes.txt', 4, 100);
        $this->assertSame('efghij', base64_decode($last['content']));
        $this->assertTrue($last['eof']);
    }

    /**
     * A section of another course cannot be reached by naming this one.
     */
    public function test_the_section_file_function_refuses_another_courses_section(): void {
        $this->add_section_file(2, 'notes.txt', 'abc');
        $section = self::section($this->source, 2);

        $this->expectException(\moodle_exception::class);
        get_section_file::execute((int) $this->target->id, (int) $section->id, '/', 'notes.txt', 0, 100);
    }

    /**
     * The sync permission is needed to read a section's file.
     */
    public function test_the_section_file_function_needs_the_sync_permission(): void {
        $this->add_section_file(2, 'notes.txt', 'abc');
        $section = self::section($this->source, 2);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        get_section_file::execute((int) $this->source->id, (int) $section->id, '/', 'notes.txt', 0, 100);
    }

    /**
     * The Choose page learns how the source arranges its activities.
     */
    public function test_the_source_layout_lists_activities_by_section(): void {
        $this->change_section($this->source, 2, ['name' => 'Second']);
        $a = $this->page_in('A', 2);
        $b = $this->page_in('B', 2);
        $c = $this->page_in('C', 1);

        $layout = syncer::source_layout($this->instanceid, $this->local_source());

        $this->assertSame([$c], $layout[1]['cmids']);
        $this->assertSame([$a, $b], $layout[2]['cmids']);
        $this->assertSame('Second', $layout[2]['name']);
    }

    /**
     * A source that cannot say leaves the page with no layout, not an error.
     */
    public function test_the_source_layout_is_null_when_the_source_cannot_say(): void {
        $client = new \core\http_client(['mock' => new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(500, [], 'down'),
        ])]);

        $this->assertNull(syncer::source_layout($this->instanceid, $client));
        $this->assertDebuggingCalled();
    }
}
