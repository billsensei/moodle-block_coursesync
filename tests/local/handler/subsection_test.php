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
use block_coursesync\connection;
use block_coursesync\course_result;
use block_coursesync\external\get_activity;
use block_coursesync\history;
use block_coursesync\local\source_on_this_site;
use block_coursesync\syncer;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../source_on_this_site.php');

/**
 * Tests for subsections, and for placing activities that are inside one.
 *
 * A subsection is a module that owns a "delegated" section, numbered after
 * the course's ordinary ones, and holding its activities. So what is pinned
 * is where things land: a copied subsection gets its own section here, what
 * is inside it on the source goes inside the copy, nothing lands in some
 * other subsection's section by accident of numbering - and a changed
 * subsection is renamed where it stands, never replaced, since replacing
 * one deletes everything in it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(subsection_handler::class)]
#[CoversClass(activity_handler::class)]
#[CoversClass(get_activity::class)]
#[CoversClass(syncer::class)]
final class subsection_test extends advanced_testcase {
    use source_on_this_site;

    /** @var \stdClass The course activities are copied from. */
    protected \stdClass $source;

    /** @var \stdClass The course they are copied into. */
    protected \stdClass $target;

    /** @var int The Course Sync block in the target course. */
    protected int $instanceid = 0;

    /**
     * Two courses, the second with a Course Sync block pointed at the first -
     * on this same site, answered by source_on_this_site.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->source = $this->getDataGenerator()->create_course(['numsections' => 2, 'shortname' => 'SRC']);
        $this->target = $this->getDataGenerator()->create_course(['numsections' => 2]);

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
    }

    /**
     * A subsection in the source course, in the given ordinary section.
     *
     * @param string $name
     * @param int $section
     * @return \stdClass the generator's record, with cmid
     */
    protected function make_subsection(string $name, int $section): \stdClass {
        return $this->getDataGenerator()->create_module('subsection', [
            'course' => $this->source->id,
            'name' => $name,
            'section' => $section,
        ]);
    }

    /**
     * The course's modinfo as it is now, not as cached earlier in the test.
     *
     * get_fast_modinfo()'s third argument only resets the cache - it returns
     * null - so the reset and the fetch are two calls.
     *
     * @param int $courseid
     * @return \course_modinfo
     */
    protected static function fresh_modinfo(int $courseid): \course_modinfo {
        get_fast_modinfo($courseid, 0, true);

        return get_fast_modinfo($courseid);
    }

    /**
     * The section number of a subsection's own, delegated section.
     *
     * @param int $courseid
     * @param int $cmid the subsection
     * @return int
     */
    protected function delegated_section(int $courseid, int $cmid): int {
        $modinfo = self::fresh_modinfo($courseid);

        return (int) $modinfo->get_section_info_by_component('mod_subsection', $modinfo->get_cm($cmid)->instance)->section;
    }

    /**
     * The section number an activity sits in.
     *
     * @param int $courseid
     * @param int $cmid
     * @return int
     */
    protected function section_of(int $courseid, int $cmid): int {
        return (int) self::fresh_modinfo($courseid)->get_cm($cmid)->sectionnum;
    }

    /**
     * An activity inside a subsection is exported as being in that subsection,
     * and in the ordinary section the subsection is in - not by the number of
     * the delegated section, which means nothing to another site.
     */
    public function test_an_activity_in_a_subsection_says_which(): void {
        $subsection = $this->make_subsection('Extra reading', 2);
        $inside = $this->getDataGenerator()->create_module('page', [
            'course' => $this->source->id,
            'section' => $this->delegated_section($this->source->id, (int) $subsection->cmid),
        ]);
        $outside = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id, 'section' => 1]);

        $exported = get_activity::execute((int) $inside->cmid);
        $this->assertSame(2, $exported['sectionnum']);
        $this->assertSame((int) $subsection->cmid, $exported['subsectioncmid']);

        $exported = get_activity::execute((int) $outside->cmid);
        $this->assertSame(1, $exported['sectionnum']);
        $this->assertSame(0, $exported['subsectioncmid']);
    }

    /**
     * An activity is never placed in a subsection's section just because its
     * number is the highest in the course - the bug this replaces.
     */
    public function test_an_ordinary_placement_never_lands_in_a_subsection(): void {
        // The target course has sections 0-2 and one subsection, whose own
        // section is 3.
        $this->getDataGenerator()->create_module('subsection', ['course' => $this->target->id, 'section' => 1]);

        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id, 'section' => 2]);
        $payload = activity_payload::from_response(['sectionnum' => 5] + get_activity::execute((int) $page->cmid));

        $cm = (new page_handler())->create_from_remote_data($this->target, $payload, 'coursesync-1');

        $this->assertSame(2, $this->section_of($this->target->id, (int) $cm->id));
    }

    /**
     * A subsection and what is inside it, ticked in the same sync, arrive
     * together: the subsection with its own section, the activity inside the
     * copy. Subsections are handled first, even when what is inside one
     * changed longer ago.
     */
    public function test_a_subsection_and_its_contents_arrive_together(): void {
        global $DB;

        $subsection = $this->make_subsection('Extra reading', 2);
        $inside = $this->getDataGenerator()->create_module('page', [
            'course' => $this->source->id,
            'name' => 'Further notes',
            'section' => $this->delegated_section($this->source->id, (int) $subsection->cmid),
        ]);

        // The page is the older change, so it would otherwise go first.
        $DB->set_field('page', 'timemodified', time() - 100, ['id' => $inside->id]);
        $DB->set_field('subsection', 'timemodified', time() - 10, ['id' => $subsection->id]);

        $result = syncer::run(
            $this->instanceid,
            $this->target->id,
            true,
            $this->local_source(),
            [(int) $subsection->cmid, (int) $inside->cmid]
        );

        $this->assertSame(2, $result->count('created'));

        $subsectioncopy = syncer::find_existing($this->target->id, (int) $subsection->cmid);
        $insidecopy = syncer::find_existing($this->target->id, (int) $inside->cmid);

        $this->assertSame('Extra reading', $DB->get_field('subsection', 'name', [
            'id' => self::fresh_modinfo($this->target->id)->get_cm($subsectioncopy)->instance,
        ]));
        $this->assertSame(2, $this->section_of($this->target->id, $subsectioncopy));
        $this->assertSame(
            $this->delegated_section($this->target->id, $subsectioncopy),
            $this->section_of($this->target->id, $insidecopy),
            'the page should be inside the copied subsection'
        );
    }

    /**
     * Without its subsection here, an activity goes in the ordinary section
     * the subsection is in, and says so.
     */
    public function test_without_its_subsection_an_activity_goes_where_the_subsection_is(): void {
        $subsection = $this->make_subsection('Extra reading', 2);
        $inside = $this->getDataGenerator()->create_module('page', [
            'course' => $this->source->id,
            'section' => $this->delegated_section($this->source->id, (int) $subsection->cmid),
        ]);

        $result = syncer::run($this->instanceid, $this->target->id, true, $this->local_source(), [(int) $inside->cmid]);

        $copy = syncer::find_existing($this->target->id, (int) $inside->cmid);
        $this->assertSame(2, $this->section_of($this->target->id, $copy));

        $item = current(array_filter($result->items, fn($item) => $item['outcome'] === 'created'));
        $this->assertContains('syncsubsectionmissing', $item['notes']);
    }

    /**
     * A changed subsection is renamed where it stands: same subsection, same
     * section, and what is inside it untouched - never replaced, which would
     * delete everything inside.
     */
    public function test_a_changed_subsection_is_renamed_not_replaced(): void {
        global $DB;

        $subsection = $this->make_subsection('Extra reading', 2);
        $inside = $this->getDataGenerator()->create_module('page', [
            'course' => $this->source->id,
            'section' => $this->delegated_section($this->source->id, (int) $subsection->cmid),
        ]);

        syncer::run(
            $this->instanceid,
            $this->target->id,
            true,
            $this->local_source(),
            [(int) $subsection->cmid, (int) $inside->cmid]
        );
        $subsectioncopy = syncer::find_existing($this->target->id, (int) $subsection->cmid);
        $insidecopy = syncer::find_existing($this->target->id, (int) $inside->cmid);

        // That run was a while ago; since then the source renamed it by its
        // section heading - which, through preprocess_section_name(), renames
        // the subsection too and moves its timemodified on.
        $DB->execute('UPDATE {block_coursesync_run} SET timestarted = timestarted - 100');
        $DB->set_field('subsection', 'timemodified', time() - 1000, ['id' => $subsection->id]);
        $sourcesection = self::fresh_modinfo($this->source->id)->get_section_info_by_component(
            'mod_subsection',
            (int) $subsection->id
        );
        \core_courseformat\formatactions::section($this->source->id)->update(
            $sourcesection,
            ['name' => 'Optional reading']
        );
        rebuild_course_cache($this->source->id, true);

        $candidates = syncer::list_candidates($this->instanceid, $this->target->id, true, $this->local_source());
        $this->assertTrue($candidates->is_in_place((int) $subsection->cmid));

        $result = syncer::run($this->instanceid, $this->target->id, true, $this->local_source(), [(int) $subsection->cmid]);

        $this->assertSame(1, $result->count('updated'));
        $updated = current(array_filter($result->items, fn($item) => $item['outcome'] === 'updated'));
        $this->assertSame('syncupdatedinplace', $updated['detail']);

        // The same subsection, renamed, with its section and contents intact.
        $this->assertSame($subsectioncopy, syncer::find_existing($this->target->id, (int) $subsection->cmid));
        $instance = self::fresh_modinfo($this->target->id)->get_cm($subsectioncopy)->instance;
        $this->assertSame('Optional reading', $DB->get_field('subsection', 'name', ['id' => $instance]));
        $this->assertSame('Optional reading', $DB->get_field('course_sections', 'name', [
            'component' => 'mod_subsection',
            'itemid' => $instance,
        ]));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $insidecopy, 'deletioninprogress' => 0]));
        $this->assertSame(
            $this->delegated_section($this->target->id, $subsectioncopy),
            $this->section_of($this->target->id, $insidecopy)
        );

        // And it is up to date now.
        $this->assertTrue(history::was_pulled_here($this->instanceid, (int) $subsection->cmid, $subsectioncopy));
    }

    /**
     * An in-place update that throws fails that one activity. It used to
     * escape the run altogether: no result page, no history row.
     */
    public function test_an_in_place_update_that_throws_fails_only_that_activity(): void {
        global $DB;

        $subsection = $this->make_subsection('Extra reading', 2);
        syncer::run($this->instanceid, $this->target->id, true, $this->local_source(), [(int) $subsection->cmid]);
        $copy = syncer::find_existing($this->target->id, (int) $subsection->cmid);
        $DB->execute('UPDATE {block_coursesync_run} SET timestarted = timestarted - 100');
        $runs = $DB->count_records('block_coursesync_run');

        // Longer than any activity name may be, so the rename throws.
        $name = str_repeat('x', 1400);
        $source = new \core\http_client(['mock' => new MockHandler([
            new Response(200, [], json_encode([[
                'cmid' => (int) $subsection->cmid,
                'modname' => 'subsection',
                'name' => 'Extra reading',
                'idnumber' => '',
                'timemodified' => time(),
            ]])),
            new Response(200, [], json_encode([
                'cmid' => (int) $subsection->cmid,
                'modname' => 'subsection',
                'name' => $name,
                'idnumber' => '',
                'sectionnum' => 2,
                'visible' => true,
                'intro' => '',
                'introformat' => FORMAT_HTML,
                'timemodified' => time(),
                'settings' => [],
                'children' => [],
                'files' => [],
            ])),
        ])]);

        $result = syncer::run($this->instanceid, $this->target->id, true, $source, [(int) $subsection->cmid]);
        $this->assertDebuggingCalled();

        $this->assertSame(1, $result->count('failed'));
        $this->assertSame('errorupdatefailed', $result->items[0]['detail']);
        $this->assertFalse($result->lastsyncupdated);
        $this->assertSame($runs + 1, $DB->count_records('block_coursesync_run'));
        $this->assertSame($copy, syncer::find_existing($this->target->id, (int) $subsection->cmid));
    }
}
