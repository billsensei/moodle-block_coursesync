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
use block_coursesync\external\get_sections;
use block_coursesync\remote_client;
use block_coursesync\syncer;
use core\http_client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/source_on_this_site.php');

/**
 * Tests for giving copied activities the same sections as on the source.
 *
 * What is pinned: activities land in the section that stands for their source
 * section, creating the sections that are missing in source order; a course
 * that has been syncing by section number keeps doing so rather than getting a
 * second set of sections; the pairing survives sections being moved here;
 * sections a person named are left alone; and a source that cannot list its
 * sections changes nothing.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(section_map::class)]
#[CoversClass(get_sections::class)]
#[CoversClass(remote_client::class)]
#[CoversClass(syncer::class)]
final class section_sync_test extends advanced_testcase {
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
     * Name a section of a course.
     *
     * @param \stdClass $course
     * @param int $number
     * @param string $name
     * @return void
     */
    protected function name_section(\stdClass $course, int $number, string $name): void {
        $info = self::modinfo($course->id)->get_section_info($number);
        \core_courseformat\formatactions::section($course)->update($info, ['name' => $name]);
    }

    /**
     * Show or hide a section of a course, and what is in it.
     *
     * Through update(), which every supported Moodle has; set_visibility()
     * arrived in 5.2.
     *
     * @param \stdClass $course
     * @param int $number
     * @param bool $visible
     * @return void
     */
    protected function set_visible(\stdClass $course, int $number, bool $visible): void {
        $info = self::modinfo($course->id)->get_section_info($number);
        \core_courseformat\formatactions::section($course)->update($info, ['visible' => $visible ? 1 : 0]);
    }

    /**
     * Move a section of the target course to just after the general section.
     *
     * Through move_after() where there is one (5.2 on), since move_section_to()
     * is deprecated there.
     *
     * @param int $number the section to move
     * @return void
     */
    protected function move_section_to_front(int $number): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        if (method_exists(\core_courseformat\local\sectionactions::class, 'move_after')) {
            $modinfo = self::modinfo($this->target->id);
            \core_courseformat\formatactions::section($this->target)->move_after(
                $modinfo->get_section_info($number),
                $modinfo->get_section_info(0)
            );

            return;
        }

        move_section_to($this->target, $number, 1);
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
     * The ordinary sections of the target course, keyed by number.
     *
     * @return \stdClass[]
     */
    protected function ordinary_sections(): array {
        global $DB;

        return $DB->get_records_select(
            'course_sections',
            'course = ? AND component IS NULL',
            [$this->target->id],
            'section ASC',
            'section, id, name, visible, sequence'
        );
    }

    /**
     * Which section of the target a copy of a source activity is in.
     *
     * @param int $remotecmid
     * @return int the section number
     */
    protected function copy_is_in(int $remotecmid): int {
        $cmid = syncer::find_existing($this->target->id, $remotecmid);
        $this->assertGreaterThan(0, $cmid, 'The activity was not copied.');

        return (int) self::modinfo($this->target->id)->get_cm($cmid)->sectionnum;
    }

    /**
     * Run a sync of chosen activities from the fake source.
     *
     * @param int[] $remotecmids
     * @return \block_coursesync\sync_result
     */
    protected function sync(array $remotecmids): \block_coursesync\sync_result {
        return syncer::run($this->instanceid, $this->target->id, true, $this->local_source(), $remotecmids);
    }

    /**
     * Activities go in the same section as on the source, and the sections that
     * were missing are made, in order, with the source's names.
     */
    public function test_missing_sections_are_created_to_match(): void {
        $this->name_section($this->source, 1, 'Alpha');
        $this->name_section($this->source, 2, 'Beta');
        $this->name_section($this->source, 3, 'Gamma');
        $this->name_section($this->source, 4, 'Delta');

        $three = $this->page_in('Third', 3);
        $four = $this->page_in('Fourth', 4);

        $result = $this->sync([$three, $four]);

        $this->assertSame(2, $result->count('created'));
        $this->assertSame(3, $result->sectionscreated);
        $this->assertSame(3, $this->copy_is_in($three));
        $this->assertSame(4, $this->copy_is_in($four));

        $names = array_column($this->ordinary_sections(), 'name', 'section');
        $this->assertSame('Alpha', $names[1]);
        $this->assertSame('Beta', $names[2]);
        $this->assertSame('Gamma', $names[3]);
        $this->assertSame('Delta', $names[4]);
        $this->assertStringContainsString('3', $result->get_message());
    }

    /**
     * A section nobody named is not given a default name copied from the source.
     */
    public function test_an_unnamed_source_section_leaves_the_new_one_unnamed(): void {
        $three = $this->page_in('Third', 3);

        $this->sync([$three]);

        $sections = $this->ordinary_sections();
        $this->assertCount(4, $sections);
        $this->assertEmpty($sections[3]->name);
    }

    /**
     * The regression that matters most: a course that has been syncing by
     * section number keeps its sections. Section 2 here is already in use and
     * has a name of its own, so the next activity from source section 2 goes
     * into it - no new section, and its name is left as it is.
     */
    public function test_a_course_already_synced_by_number_keeps_its_sections(): void {
        global $DB;

        $this->getDataGenerator()->create_module('page', ['course' => $this->target->id, 'section' => 0]);
        \core_courseformat\formatactions::section($this->target)->create_if_missing([1, 2, 3]);
        $this->name_section($this->target, 2, 'My own name');
        $this->name_section($this->source, 2, 'Their name');

        $mine = $this->getDataGenerator()->create_module('page', [
            'course' => $this->target->id,
            'name' => 'Already here',
            'section' => 2,
        ]);

        $page = $this->page_in('New one', 2);
        $result = $this->sync([$page]);

        $this->assertSame(0, $result->sectionscreated);
        $this->assertCount(4, $this->ordinary_sections());
        $this->assertSame(2, $this->copy_is_in($page));
        $this->assertSame('My own name', $this->ordinary_sections()[2]->name);
        // The activity that was already there is still in the same section.
        $this->assertSame(
            (int) $this->ordinary_sections()[2]->id,
            (int) $DB->get_field('course_modules', 'section', ['id' => $mine->cmid])
        );
        // Source sections 1 and 2 are paired, up to the one asked about.
        $this->assertSame(2, $DB->count_records('block_coursesync_section'));
    }

    /**
     * The pairing is by section, not by number: when a teacher moves sections
     * around here, a later activity still goes into the section that stands for
     * its source section.
     */
    public function test_the_pairing_survives_sections_being_moved(): void {
        $first = $this->page_in('First', 2);
        $this->sync([$first]);

        $pairedid = (int) $this->ordinary_sections()[2]->id;

        // The teacher moves section 2 to the front.
        $this->move_section_to_front(2);
        $this->assertSame($pairedid, (int) $this->ordinary_sections()[1]->id);

        $second = $this->page_in('Second', 2);
        $this->sync([$second]);

        $this->assertSame(1, $this->copy_is_in($second));
        $this->assertSame(1, $this->copy_is_in($first));
    }

    /**
     * Two source sections can never share one section here, even when the
     * numbers would suggest it.
     */
    public function test_a_section_here_is_never_paired_twice(): void {
        global $DB;

        $first = $this->page_in('First', 2);
        $this->sync([$first]);

        // Swap which source section the number 2 refers to: section 1 now takes
        // the place of what was section 2, as if sections were reordered there.
        $pairs = $DB->get_records('block_coursesync_section');
        $this->assertCount(2, $pairs);

        $second = $this->page_in('Second', 1);
        $this->sync([$second]);

        $localids = $DB->get_fieldset_select('block_coursesync_section', 'localsectionid', '1 = 1');
        $this->assertSame(count($localids), count(array_unique($localids)));
    }

    /**
     * A paired section that has since been deleted is dropped, and the source
     * section is matched afresh.
     */
    public function test_a_deleted_section_is_paired_again(): void {
        global $DB;

        $first = $this->page_in('First', 3);
        $this->sync([$first]);
        $before = $DB->count_records('block_coursesync_section');

        $victim = $this->ordinary_sections()[3];
        \core_courseformat\formatactions::section($this->target)->delete(
            self::modinfo($this->target->id)->get_section_info(3),
            true
        );

        $second = $this->page_in('Second', 3);
        $this->sync([$second]);

        $this->assertSame(3, $this->copy_is_in($second));
        $this->assertNotEquals($victim->id, $this->ordinary_sections()[3]->id);
        $this->assertSame($before, $DB->count_records('block_coursesync_section'));
    }

    /**
     * A source section that is hidden gives a hidden section here - after the
     * activities are in - and showing it again brings its activities back as
     * they were.
     */
    public function test_a_hidden_source_section_is_hidden_here(): void {
        global $DB;

        $this->name_section($this->source, 3, 'Hidden one');
        $visible = $this->page_in('Shown activity', 3);
        $this->set_visible($this->source, 3, false);

        $this->sync([$visible]);

        $section = $this->ordinary_sections()[3];
        $this->assertSame(0, (int) $section->visible);

        // Hidden with its section, but remembering it was visible.
        $cmid = syncer::find_existing($this->target->id, $visible);
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $cmid]));
        $this->assertSame(1, (int) $DB->get_field('course_modules', 'visibleold', ['id' => $cmid]));

        // Showing it brings the activity back, as it was.
        $this->set_visible($this->target, 3, true);

        $this->assertSame(1, (int) $DB->get_field('course_sections', 'visible', ['id' => $section->id]));
        $this->assertSame(1, (int) $DB->get_field('course_modules', 'visible', ['id' => $cmid]));
    }

    /**
     * Sections here that are visible are not hidden because the source's is.
     */
    public function test_an_existing_section_is_never_hidden(): void {
        $page = $this->page_in('Page', 1);
        $this->set_visible($this->source, 1, false);

        $this->sync([$page]);

        $this->assertSame(1, (int) $this->ordinary_sections()[1]->visible);
    }

    /**
     * New sections are ordinary and go in before a subsection's delegated one,
     * which keeps its place and its contents.
     */
    public function test_new_sections_go_before_delegated_ones(): void {
        global $DB;

        $subsection = $this->getDataGenerator()->create_module('subsection', [
            'course' => $this->target->id,
            'name' => 'Local subsection',
            'section' => 1,
        ]);
        $inside = $this->getDataGenerator()->create_module('page', [
            'course' => $this->target->id,
            'name' => 'Inside the subsection',
            'section' => $DB->get_field('course_sections', 'section', [
                'component' => 'mod_subsection',
                'itemid' => $subsection->id,
            ]),
        ]);

        $page = $this->page_in('Page', 3);
        $this->sync([$page]);

        $sections = $this->ordinary_sections();
        $this->assertCount(4, $sections);
        $this->assertSame(3, $this->copy_is_in($page));

        $delegated = $DB->get_record('course_sections', [
            'course' => $this->target->id,
            'component' => 'mod_subsection',
            'itemid' => $subsection->id,
        ], '*', MUST_EXIST);
        $this->assertGreaterThan(3, (int) $delegated->section);
        $this->assertSame(
            (int) $delegated->section,
            (int) self::modinfo($this->target->id)->get_cm($inside->cmid)->sectionnum
        );
    }

    /**
     * A source that cannot list its sections changes nothing: the activity goes
     * in by number as it always did, the run still succeeds, and the teacher is
     * told.
     */
    public function test_a_source_that_cannot_list_sections_falls_back(): void {
        global $DB;

        $page = $this->page_in('Page', 1);

        $listing = [[
            'cmid' => $page,
            'modname' => 'page',
            'name' => 'Page',
            'idnumber' => '',
            'timemodified' => time(),
        ]];

        $payload = \block_coursesync\external\get_activity::execute($page);

        $client = new http_client(['mock' => new MockHandler([
            new Response(200, [], json_encode($listing)),
            // Not a list: what an older source's answer looks like once decoded.
            new Response(200, [], json_encode('not a list')),
            new Response(200, [], json_encode($payload)),
        ])]);

        $result = syncer::run($this->instanceid, $this->target->id, true, $client, [$page]);

        $this->assertSame(1, $result->count('created'));
        $this->assertSame(0, $result->sectionscreated);
        $this->assertTrue($result->sectionsunavailable);
        $this->assertSame(1, $this->copy_is_in($page));
        $this->assertSame(0, $DB->count_records('block_coursesync_section'));
        $this->assertStringContainsString(get_string('syncsectionsunavailable', 'block_coursesync'), $result->get_message());
    }

    /**
     * Replacing or copying beside an activity makes no sections. An activity an
     * earlier version put in the wrong section is replaced where it stands, so
     * nothing about sections is needed - and a teacher who ticked one row to
     * refresh it must not find new empty sections and a message about them.
     */
    public function test_replacing_an_activity_does_not_create_sections(): void {
        global $DB;

        $page = $this->page_in('Clamped page', 3);
        $listing = [[
            'cmid' => $page,
            'modname' => 'page',
            'name' => 'Clamped page',
            'idnumber' => '',
            'timemodified' => time(),
        ]];

        // First copy as an older version would have: the source cannot list its
        // sections, so the page is clamped into the only section here.
        $older = new http_client(['mock' => new MockHandler([
            new Response(200, [], json_encode($listing)),
            new Response(200, [], json_encode('not a list')),
            new Response(200, [], json_encode(\block_coursesync\external\get_activity::execute($page))),
        ])]);
        syncer::run($this->instanceid, $this->target->id, true, $older, [$page]);

        $this->assertSame(1, $this->copy_is_in($page));
        $before = count($this->ordinary_sections());

        // It changes on the source, and this time the source can list its sections.
        $instance = get_coursemodule_from_id('page', $page, $this->source->id)->instance;
        $DB->update_record('page', (object) ['id' => $instance, 'name' => 'Clamped page, edited', 'timemodified' => time() + 100]);

        $replaced = $this->sync([$page]);
        $this->assertSame(1, $replaced->count('updated'));
        $this->assertSame(0, $replaced->sectionscreated);
        $this->assertSame($before, count($this->ordinary_sections()));
        $this->assertSame(1, $this->copy_is_in($page));
        $this->assertSame(0, $DB->count_records('block_coursesync_section'));
        $this->assertStringNotContainsString('Sections added', $replaced->get_message());

        // Ticking it again with nothing changed copies beside it or replaces it:
        // either way, still no sections.
        $again = $this->sync([$page]);
        $this->assertSame(0, $again->sectionscreated);
        $this->assertSame($before, count($this->ordinary_sections()));
    }

    /**
     * A course format with no sections is left alone.
     */
    public function test_a_format_without_sections_is_left_alone(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'singleactivity']);

        $this->assertFalse(section_map::prime($this->instanceid, $course, [
            ['id' => 11, 'section' => 1, 'name' => 'A', 'visible' => true],
        ]));
        $this->assertNull(section_map::local_number($course, 1));
    }

    /**
     * Nothing outside what a run was given is touched: section 0, and numbers
     * the source did not list, fall back.
     */
    public function test_unlisted_and_general_sections_fall_back(): void {
        $this->assertTrue(section_map::prime($this->instanceid, $this->target, [
            ['id' => 11, 'section' => 0, 'name' => '', 'visible' => true],
            ['id' => 12, 'section' => 1, 'name' => '', 'visible' => true],
        ]));

        $this->assertNull(section_map::local_number($this->target, 0));
        $this->assertNull(section_map::local_number($this->target, 7));
        $this->assertSame(1, section_map::local_number($this->target, 1));

        section_map::release((int) $this->target->id);
        $this->assertNull(section_map::local_number($this->target, 1));
    }

    /**
     * The source lists its ordinary sections, in order, and nothing else.
     */
    public function test_the_source_lists_ordinary_sections_only(): void {
        $this->name_section($this->source, 2, 'Named');
        $this->getDataGenerator()->create_module('subsection', [
            'course' => $this->source->id,
            'name' => 'A subsection',
            'section' => 1,
        ]);
        $this->set_visible($this->source, 3, false);

        $listed = get_sections::execute((int) $this->source->id);

        $this->assertSame([0, 1, 2, 3, 4], array_column($listed, 'section'));
        $byname = array_column($listed, 'name', 'section');
        $this->assertSame('Named', $byname[2]);
        $this->assertSame('', $byname[1]);
        $this->assertSame([true, true, true, false, true], array_column($listed, 'visible'));
    }

    /**
     * A sync account without the sync permission cannot read a course's sections.
     */
    public function test_listing_sections_needs_the_sync_permission(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        get_sections::execute((int) $this->source->id);
    }

    /**
     * A course that does not exist is reported as such.
     */
    public function test_listing_sections_of_a_missing_course_fails(): void {
        $this->expectException(\moodle_exception::class);
        get_sections::execute(99999999);
    }

    /**
     * The client reads a good answer, and treats anything else as not available.
     */
    public function test_the_client_reads_sections_and_tolerates_rubbish(): void {
        $good = new http_client(['mock' => new MockHandler([new Response(200, [], json_encode([
            ['id' => 5, 'section' => 1, 'name' => 'One', 'visible' => true],
            ['id' => 6, 'section' => 2, 'name' => '', 'visible' => false],
        ]))])]);

        $this->assertSame([
            ['id' => 5, 'section' => 1, 'name' => 'One', 'visible' => true],
            ['id' => 6, 'section' => 2, 'name' => '', 'visible' => false],
        ], remote_client::get_sections('https://source.example.edu', 'abcdef0123456789abcdef0123456789', 1, $good));

        $malformed = new http_client(['mock' => new MockHandler([new Response(200, [], json_encode([['name' => 'No ids']]))])]);
        $this->assertNull(
            remote_client::get_sections('https://source.example.edu', 'abcdef0123456789abcdef0123456789', 1, $malformed)
        );
    }

    /**
     * Removing the block removes its pairings.
     */
    public function test_pairings_go_with_the_block(): void {
        global $DB;

        $this->sync([$this->page_in('Page', 2)]);
        $this->assertGreaterThan(0, $DB->count_records('block_coursesync_section'));

        section_map::delete_for_block_instance($this->instanceid);

        $this->assertSame(0, $DB->count_records('block_coursesync_section'));
    }
}
