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
use core\http_client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for recognising activities already in the course that Course Sync
 * did not put there - built by hand, restored or imported - by type and name.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(local\existing_match::class)]
#[CoversClass(syncer::class)]
final class syncer_match_test extends advanced_testcase {
    /** @var \stdClass */
    protected \stdClass $course;

    /** @var int */
    protected int $instanceid;

    /**
     * A course with a mapped Course Sync block.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['numsections' => 3]);

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($this->course->id));
        $page->set_course($this->course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $this->course->format);
        $page->set_url('/course/view.php', ['id' => $this->course->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');

        $this->instanceid = (int) $DB->get_field('block_instances', 'id', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($this->course->id)->id,
        ], MUST_EXIST);

        connection::set_url($this->instanceid, $this->course->id, 'https://source.example.edu');
        connection::set_token($this->instanceid, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            $this->instanceid,
            'REMOTE1',
            course_result::success(42, 'REMOTE1', 'Remote Source Course', true, 1)
        );
    }

    /**
     * What the source's change detection reports, one activity per entry,
     * as [cmid, name, modname].
     *
     * @param array[] $activities
     * @return http_client
     */
    protected function source_listing(array $activities): http_client {
        $rows = array_map(static fn(array $a) => [
            'cmid' => $a[0],
            'name' => $a[1],
            'modname' => $a[2] ?? 'page',
            'idnumber' => '',
            'timemodified' => 1750000000,
        ], $activities);

        return new http_client(['mock' => new MockHandler([new Response(200, [], json_encode($rows))])]);
    }

    /**
     * A source that lists activities and then answers payload requests.
     *
     * @param array[] $activities see source_listing(); a fourth element sets timemodified
     * @param array[] $payloads full payloads, in the order they will be fetched
     * @return http_client
     */
    protected function source_with_payloads(array $activities, array $payloads = []): http_client {
        $rows = array_map(static fn(array $a) => [
            'cmid' => $a[0],
            'name' => $a[1],
            'modname' => $a[2] ?? 'page',
            'idnumber' => '',
            'timemodified' => $a[3] ?? 1750000000,
        ], $activities);
        $responses = [new Response(200, [], json_encode($rows))];

        foreach ($payloads as $payload) {
            $responses[] = new Response(200, [], json_encode($payload));
        }

        return new http_client(['mock' => new MockHandler($responses)]);
    }

    /**
     * A page's full payload, as the source sends it.
     *
     * @param int $cmid
     * @param string $name
     * @return array
     */
    protected function page_payload(int $cmid, string $name): array {
        return [
            'cmid' => $cmid,
            'modname' => 'page',
            'name' => $name,
            'idnumber' => '',
            'sectionnum' => 1,
            'visible' => true,
            'intro' => '<p>From the source.</p>',
            'introformat' => FORMAT_HTML,
            'timemodified' => 1750000000,
            'settings' => [
                ['name' => 'content', 'value' => '<p>The original.</p>'],
                ['name' => 'contentformat', 'value' => (string) FORMAT_HTML],
            ],
            'children' => [],
            'files' => [],
        ];
    }

    /**
     * An activity's ID number here, fresh from the database.
     *
     * @param int $cmid
     * @return string
     */
    protected function idnumber_of(int $cmid): string {
        global $DB;

        return (string) $DB->get_field('course_modules', 'idnumber', ['id' => $cmid]);
    }

    /**
     * An activity already here, as a teacher or a restore would have made it.
     *
     * @param string $name
     * @param string $modname
     * @param string $idnumber
     * @return \stdClass the module record
     */
    protected function here(string $name, string $modname = 'page', string $idnumber = ''): \stdClass {
        return $this->getDataGenerator()->create_module(
            $modname,
            ['course' => $this->course->id, 'name' => $name],
            $idnumber === '' ? [] : ['idnumber' => $idnumber]
        );
    }

    /**
     * Candidates for the given source listing.
     *
     * @param array[] $activities see source_listing()
     * @return sync_candidates
     */
    protected function candidates(array $activities): sync_candidates {
        $candidates = syncer::list_candidates($this->instanceid, $this->course->id, true, $this->source_listing($activities));
        $this->assertTrue($candidates->success, (string) $candidates->errorkey);

        return $candidates;
    }

    /**
     * The remote cmids in a list of activities.
     *
     * @param activity[] $activities
     * @return int[]
     */
    protected function cmids(array $activities): array {
        return array_map(static fn(activity $a) => (int) $a->cmid, $activities);
    }

    /**
     * An activity of the same type and name is already here: listed as
     * matched, not offered as new - whatever the case or spacing.
     */
    public function test_the_same_type_and_name_is_already_here(): void {
        $page = $this->here('Week 1 Notes');

        $candidates = $this->candidates([[11, 'week 1   notes '], [12, 'Week 2 Notes']]);

        $this->assertSame([11], $this->cmids($candidates->matched));
        $this->assertSame((int) $page->cmid, $candidates->matched_local_cmid(11));
        $this->assertFalse($candidates->is_matched_but_owned(11));
        $this->assertSame([12], $this->cmids($candidates->new));
        $this->assertFalse($candidates->is_ambiguous(12));
    }

    /**
     * The name alone is not enough: a page and a URL both called "Intro" are
     * different things.
     */
    public function test_the_type_must_match_too(): void {
        $this->here('Intro', 'page');

        $candidates = $this->candidates([[11, 'Intro', 'url']]);

        $this->assertSame([], $candidates->matched);
        $this->assertSame([11], $this->cmids($candidates->new));
    }

    /**
     * The source sends names formatted for display ("Q&amp;A"); what is
     * stored here is the raw name ("Q&A"). They are the same name.
     */
    public function test_names_are_compared_as_text(): void {
        $this->here('Q&A <b>session</b>');

        $candidates = $this->candidates([[11, 'Q&amp;A session']]);

        $this->assertSame([11], $this->cmids($candidates->matched));
    }

    /**
     * Two activities of that type and name here: no guess. It stays new,
     * marked ambiguous, and nothing is matched.
     */
    public function test_more_than_one_here_is_not_guessed(): void {
        $this->here('Notes');
        $this->here('Notes');

        $candidates = $this->candidates([[11, 'Notes']]);

        $this->assertSame([], $candidates->matched);
        $this->assertSame([11], $this->cmids($candidates->new));
        $this->assertTrue($candidates->is_ambiguous(11));
    }

    /**
     * Two of that type and name on the source: no guess either, for both.
     */
    public function test_more_than_one_there_is_not_guessed(): void {
        $this->here('Notes');

        $candidates = $this->candidates([[11, 'Notes'], [12, 'Notes']]);

        $this->assertSame([], $candidates->matched);
        $this->assertSame([11, 12], $this->cmids($candidates->new));
        $this->assertTrue($candidates->is_ambiguous(11));
        $this->assertTrue($candidates->is_ambiguous(12));
    }

    /**
     * An activity Course Sync already recognises by its marker is not also
     * matched by name to something else.
     */
    public function test_a_marked_copy_is_not_matched_again(): void {
        $this->here('Notes', 'page', 'coursesync-5');

        $candidates = $this->candidates([[5, 'Notes'], [9, 'Notes']]);

        // Cmid 5 is a copy recognised by its marker (not pulled here, so a
        // collision); cmid 9 has nothing unmarked to match, and is new -
        // though, with two "Notes" on the source, not guessed at either.
        $this->assertSame([5], $this->cmids($candidates->collisions));
        $this->assertSame([], $candidates->matched);
        $this->assertSame([9], $this->cmids($candidates->new));
    }

    /**
     * A match that has its own ID number here is listed as already here, but
     * cannot be linked: its ID number is never overwritten.
     */
    public function test_a_match_with_its_own_idnumber(): void {
        $this->here('Notes', 'page', 'MY-ID');

        $candidates = $this->candidates([[11, 'Notes']]);

        $this->assertSame([11], $this->cmids($candidates->matched));
        $this->assertTrue($candidates->is_matched_but_owned(11));
    }

    /**
     * Looking is only looking: listing writes nothing to the matched activity.
     */
    public function test_listing_changes_nothing(): void {
        global $DB;

        $page = $this->here('Notes');

        $this->candidates([[11, 'Notes']]);

        $this->assertSame('', (string) $DB->get_field('course_modules', 'idnumber', ['id' => $page->cmid]));
        $this->assertSame(0, $DB->count_records('block_coursesync_run'));
    }

    /**
     * Pressing sync links a match - even left unticked: it gets Course
     * Sync's marker, the link is in the history, nothing is copied, and the
     * run is clean.
     */
    public function test_a_sync_links_a_match(): void {
        $page = $this->here('Notes');

        $result = syncer::run($this->instanceid, $this->course->id, true, $this->source_with_payloads([[11, 'Notes']]), []);

        $this->assertSame('coursesync-11', $this->idnumber_of((int) $page->cmid));
        $this->assertSame(1, $result->count('linked'));
        $this->assertSame(0, $result->count('created'));
        $this->assertTrue(history::was_pulled_here($this->instanceid, 11, (int) $page->cmid));
        $this->assertTrue($result->lastsyncupdated, 'a link is not a deselection');
        $this->assertStringContainsString(
            get_string('synclinkedsummary', 'block_coursesync', 1),
            $result->get_message()
        );
    }

    /**
     * A match with an ID number of its own is left exactly as it is - and
     * being left is not a deselection that holds the marker back.
     */
    public function test_a_match_with_its_own_idnumber_is_not_linked(): void {
        $page = $this->here('Notes', 'page', 'MY-ID');

        $result = syncer::run($this->instanceid, $this->course->id, true, $this->source_with_payloads([[11, 'Notes']]), []);

        $this->assertSame('MY-ID', $this->idnumber_of((int) $page->cmid));
        $this->assertSame(0, $result->count('linked'));
        $this->assertSame(['syncskippedowned'], array_column($result->items, 'detail'));
        $this->assertTrue($result->lastsyncupdated);
    }

    /**
     * An ambiguous name links nothing.
     */
    public function test_an_ambiguous_name_links_nothing(): void {
        $one = $this->here('Notes');
        $two = $this->here('Notes');

        syncer::run($this->instanceid, $this->course->id, true, $this->source_with_payloads([[11, 'Notes']]), []);

        $this->assertSame('', $this->idnumber_of((int) $one->cmid));
        $this->assertSame('', $this->idnumber_of((int) $two->cmid));
    }

    /**
     * Once linked, a later change on the source is offered as an update -
     * measured from the link, not from when the activity was made here.
     */
    public function test_changes_after_the_link_are_offered(): void {
        $this->here('Notes');
        syncer::run($this->instanceid, $this->course->id, true, $this->source_with_payloads([[11, 'Notes']]), []);

        $unchanged = syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            true,
            $this->source_with_payloads([[11, 'Notes', 'page', time() - DAYSECS]])
        );
        $this->assertSame([11], $this->cmids($unchanged->present));
        $this->assertSame([], $unchanged->matched, 'linked now, so recognised by its marker');

        $changed = syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            true,
            $this->source_with_payloads([[11, 'Notes', 'page', time() + 60]])
        );
        $this->assertSame([11], $this->cmids($changed->changed));
    }

    /**
     * Ticking a match is copying it again, as for anything already here:
     * with nobody's work in it, it is replaced by the original, which carries
     * the link from then on.
     */
    public function test_ticking_a_match_replaces_it(): void {
        global $DB;

        $page = $this->here('Notes');

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->source_with_payloads([[11, 'Notes']], [$this->page_payload(11, 'Notes')]),
            [11]
        );

        $this->assertSame(1, $result->count('updated'), json_encode($result->items));
        $copy = syncer::find_existing($this->course->id, 11);
        $this->assertNotSame((int) $page->cmid, $copy);
        $this->assertSame('<p>The original.</p>', $DB->get_field('page', 'content', [
            'id' => $DB->get_field('course_modules', 'instance', ['id' => $copy]),
        ]));
    }

    /**
     * Ticking a match with its own ID number adds the original beside it,
     * as a separate copy; the one here is not touched.
     */
    public function test_ticking_an_owned_match_adds_a_copy(): void {
        $page = $this->here('Notes', 'page', 'MY-ID');

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->source_with_payloads([[11, 'Notes']], [$this->page_payload(11, 'Notes')]),
            [11]
        );

        $this->assertSame(1, $result->count('created'), json_encode($result->items));
        $this->assertSame('MY-ID', $this->idnumber_of((int) $page->cmid));
        $this->assertCount(2, get_fast_modinfo($this->course->id)->get_instances_of('page'));
    }

    /**
     * A run without a choice links matches too, and never copies them again.
     */
    public function test_a_run_without_a_choice_links_and_copies_nothing(): void {
        $page = $this->here('Notes');

        $result = syncer::run($this->instanceid, $this->course->id, true, $this->source_with_payloads([[11, 'Notes']]));

        $this->assertSame('coursesync-11', $this->idnumber_of((int) $page->cmid));
        $this->assertSame(0, $result->count('created'));
        $this->assertSame(0, $result->count('conflict'));
        $this->assertCount(1, get_fast_modinfo($this->course->id)->get_instances_of('page'));
    }

    /**
     * A linked activity is one of Course Sync's copies from then on - which
     * is what grade and quiz attempt pulls look for.
     */
    public function test_a_linked_activity_is_one_grades_are_pulled_into(): void {
        $page = $this->here('Notes');
        syncer::run($this->instanceid, $this->course->id, true, $this->source_with_payloads([[11, 'Notes']]), []);

        $copies = grade_pull::local_copies($this->course->id);

        $this->assertArrayHasKey(11, $copies);
        $this->assertSame((int) $page->cmid, (int) $copies[11]->id);
    }

    /**
     * On the choose page a match can now be ticked, and says what ticking
     * it does.
     */
    public function test_a_match_is_on_offer(): void {
        $this->here('Notes');
        $this->here('Kept', 'page', 'MY-ID');

        $candidates = $this->candidates([[11, 'Notes'], [12, 'Kept']]);

        $this->assertEqualsCanonicalizing([11, 12], $candidates->offered_cmids());
    }
}
