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
 * Tests for choosing what a sync copies.
 *
 * Two things are being pinned. The first is that the list a teacher is shown
 * holds only what is actually on offer - activities in the other course that
 * are not in this one - so nothing is presented that a sync would then refuse.
 *
 * The second is what happens to the activities left out. They must not be
 * treated as done: the last synced marker has to stay where it was, or "not
 * this one" would quietly mean "not ever".
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(syncer::class)]
#[CoversClass(sync_candidates::class)]
#[CoversClass(sync_result::class)]
#[CoversClass(history::class)]
final class syncer_selection_test extends advanced_testcase {
    /** @var int The block instance these tests sync into. */
    protected int $instanceid = 0;

    /** @var \stdClass The destination course. */
    protected \stdClass $course;

    /**
     * Put a configured, mapped block instance in a fresh course.
     */
    protected function setUp(): void {
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

        global $DB;
        $instance = $DB->get_record('block_instances', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($this->course->id)->id,
        ], '*', MUST_EXIST);

        $this->instanceid = (int) $instance->id;

        connection::set_url($this->instanceid, $this->course->id, 'https://source.example.edu');
        connection::set_token($this->instanceid, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            $this->instanceid,
            'REMOTE1',
            course_result::success(42, 'REMOTE1', 'Remote Source Course', true, 1)
        );
    }

    /**
     * A client that answers a detection call and then any payload calls.
     *
     * @param array $activities what change detection should report
     * @param array[] $payloads a full payload per activity that gets fetched
     * @return http_client
     */
    protected function mock_source(array $activities, array $payloads = []): http_client {
        $responses = [new Response(200, [], json_encode($activities))];

        foreach ($payloads as $payload) {
            $responses[] = new Response(200, [], json_encode($payload));
        }

        return new http_client(['mock' => new MockHandler($responses)]);
    }

    /**
     * One entry as change detection reports it.
     *
     * @param int $cmid
     * @param string $name
     * @param string $modname
     * @return array
     */
    protected function detected(int $cmid, string $name, string $modname = 'page'): array {
        return [
            'cmid' => $cmid,
            'modname' => $modname,
            'name' => $name,
            'idnumber' => '',
            'timemodified' => 1750000000,
        ];
    }

    /**
     * A full page payload as the source site sends it.
     *
     * @param int $cmid
     * @param string $name
     * @return array
     */
    protected function payload(int $cmid, string $name): array {
        return [
            'cmid' => $cmid,
            'modname' => 'page',
            'name' => $name,
            'idnumber' => '',
            'sectionnum' => 1,
            'visible' => true,
            'intro' => '<p>Intro.</p>',
            'introformat' => FORMAT_HTML,
            'timemodified' => 1750000000,
            'settings' => [
                ['name' => 'content', 'value' => '<p>Body.</p>'],
                ['name' => 'contentformat', 'value' => (string) FORMAT_HTML],
            ],
            'children' => [],
            'files' => [],
        ];
    }

    /**
     * An activity type installed here that this plugin has no handler for.
     *
     * @return string
     */
    protected function unsupported_modname(): string {
        // Every type in standard Moodle has a handler now, so this is a
        // third-party module's name unless the site has one installed. The
        // source only reports the name; it need not be installed here.
        return \block_coursesync\local\handler\handler_registry::first_unsupported_modname() ?? 'thirdpartymodule';
    }

    /**
     * The list holds what is not here yet, and nothing else.
     */
    public function test_candidates_are_sorted_into_three_groups(): void {
        global $DB;

        // One already pulled into this course by an earlier run.
        $existing = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(11), ['id' => $existing->cmid]);

        $candidates = syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([
                $this->detected(11, 'Already here'),
                $this->detected(12, 'Genuinely new'),
                $this->detected(13, 'Cannot be copied', $this->unsupported_modname()),
            ])
        );

        $this->assertTrue($candidates->success);

        // Only the one that is neither here nor unhandleable is new. The one
        // already here is offered too, to copy again; the unhandleable one
        // is never offered.
        $this->assertCount(1, $candidates->new);
        $this->assertSame('Genuinely new', $candidates->new[0]->name);
        $this->assertSame([12, 11], $candidates->offered_cmids());

        $this->assertCount(1, $candidates->unsupported);
        $this->assertSame('Cannot be copied', $candidates->unsupported[0]->name);

        $this->assertTrue($candidates->has_any());
    }

    /**
     * Something carrying a synced activity's identity that this plugin did not
     * put there is called out, not counted quietly among the already-here ones.
     *
     * Before this list existed, that case was reported as a conflict after a
     * run. Filtering it into the ordinary already-here group would have thrown
     * away the warning phase 6 added.
     */
    public function test_a_collision_is_flagged_rather_than_hidden(): void {
        global $DB;

        // Not put here by Course Sync: no history says so.
        $impostor = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(11), ['id' => $impostor->cmid]);

        $candidates = syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11, 'Collides with something local')])
        );

        $this->assertCount(1, $candidates->collisions);
        $this->assertSame('Collides with something local', $candidates->collisions[0]->name);
        $this->assertTrue($candidates->needs_review());

        // Not counted as ordinary housekeeping. It is on offer, but only
        // ever as a separate copy - see syncer_update_test.
        $this->assertSame(0, $candidates->present_count());
        $this->assertSame([11], $candidates->offered_cmids());
    }

    /**
     * One this plugin did pull here before is ordinary: counted, not flagged.
     */
    public function test_something_pulled_here_before_is_not_flagged(): void {
        global $DB;

        $existing = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(11), ['id' => $existing->cmid]);

        // A run that says this plugin put it there.
        $result = new sync_result();
        $result->add_created('Already here', 'page', 11, (int) $existing->cmid);
        history::record($this->instanceid, $this->course->id, 2, time(), $result);

        $candidates = syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11, 'Already here')])
        );

        $this->assertSame(1, $candidates->present_count());
        $this->assertSame([], $candidates->collisions);
        $this->assertFalse($candidates->needs_review());
    }

    /**
     * Listing what is on offer creates nothing. A teacher can look as often as
     * they like without the course changing underneath them.
     */
    public function test_listing_candidates_changes_nothing(): void {
        global $DB;

        $before = $DB->count_records('course_modules', ['course' => $this->course->id]);

        syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(12, 'Genuinely new')])
        );

        $this->assertSame($before, $DB->count_records('course_modules', ['course' => $this->course->id]));
        $this->assertNull(connection::get_last_sync($this->instanceid));
        $this->assertSame(0, $DB->count_records('block_coursesync_run'));
    }

    /**
     * When nothing is new, the list says so, and still offers what is here to
     * copy again.
     */
    public function test_nothing_new_is_reported_as_such(): void {
        global $DB;

        $existing = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(11), ['id' => $existing->cmid]);

        $result = new sync_result();
        $result->add_created('Already here', 'page', 11, (int) $existing->cmid);
        history::record($this->instanceid, $this->course->id, 2, time(), $result);

        $candidates = syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11, 'Already here')])
        );

        $this->assertFalse($candidates->has_any());
        $this->assertSame(1, $candidates->present_count());
        $this->assertSame([11], $candidates->offered_cmids());
    }

    /**
     * A list that could not be built says why instead of pretending to be empty.
     */
    public function test_candidates_report_why_they_could_not_be_listed(): void {
        $candidates = syncer::list_candidates(4242, $this->course->id);

        $this->assertFalse($candidates->success);
        $this->assertSame('errornotmapped', $candidates->errorkey);
        $this->assertFalse($candidates->has_any());
    }

    /**
     * Only the chosen activities are copied; the rest are recorded as left out.
     */
    public function test_only_the_chosen_are_copied(): void {
        global $DB;

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source(
                [$this->detected(11, 'Wanted'), $this->detected(12, 'Not wanted')],
                [$this->payload(11, 'Wanted')]
            ),
            [11]
        );

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->count('created'));
        $this->assertSame(1, $result->count('skipped'));

        $this->assertSame(1, $DB->count_records('course_modules', [
            'course' => $this->course->id,
            'idnumber' => syncer::build_idnumber(11),
        ]));
        $this->assertSame(0, $DB->count_records('course_modules', [
            'course' => $this->course->id,
            'idnumber' => syncer::build_idnumber(12),
        ]));

        $left = array_values(array_filter($result->items, fn($i) => $i['outcome'] === 'skipped'));
        $this->assertSame('Not wanted', $left[0]['name']);
        $this->assertSame('syncskippeddeselected', $left[0]['detail']);
    }

    /**
     * Leaving something out holds the last synced marker, so it is offered
     * again next time rather than quietly passed over forever.
     */
    public function test_leaving_something_out_holds_the_marker(): void {
        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source(
                [$this->detected(11, 'Wanted'), $this->detected(12, 'Not wanted')],
                [$this->payload(11, 'Wanted')]
            ),
            [11]
        );

        $this->assertTrue($result->is_clean());
        $this->assertTrue($result->has_deselected());
        $this->assertFalse($result->lastsyncupdated);
        $this->assertNull(connection::get_last_sync($this->instanceid));
    }

    /**
     * Copying everything on offer moves the marker as it always did.
     */
    public function test_choosing_everything_moves_the_marker(): void {
        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source(
                [$this->detected(11, 'Wanted'), $this->detected(12, 'Also wanted')],
                [$this->payload(11, 'Wanted'), $this->payload(12, 'Also wanted')]
            ),
            [11, 12]
        );

        $this->assertSame(2, $result->count('created'));
        $this->assertFalse($result->has_deselected());
        $this->assertTrue($result->lastsyncupdated);
        $this->assertNotNull(connection::get_last_sync($this->instanceid));
    }

    /**
     * An id that was never offered is not acted on, however it arrived in the
     * request. The chosen set narrows what a run does; it cannot widen it.
     */
    public function test_an_id_that_was_not_offered_is_ignored(): void {
        global $DB;

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11, 'Wanted')], [$this->payload(11, 'Wanted')]),
            [11, 9999]
        );

        // The made-up id simply is not among what the other site reported, so
        // there is nothing for it to name.
        $this->assertSame(1, $result->count('created'));
        $this->assertCount(1, $result->items);
        $this->assertSame(1, $DB->count_records('course_modules', [
            'course' => $this->course->id,
            'idnumber' => syncer::build_idnumber(11),
        ]));
    }

    /**
     * Ticking nothing copies nothing, and is not mistaken for ticking all.
     */
    public function test_choosing_nothing_copies_nothing(): void {
        global $DB;

        $before = $DB->count_records('course_modules', ['course' => $this->course->id]);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11, 'Wanted'), $this->detected(12, 'Also')]),
            []
        );

        $this->assertSame(0, $result->count('created'));
        $this->assertSame(2, $result->count('skipped'));
        $this->assertTrue($result->has_deselected());
        $this->assertFalse($result->lastsyncupdated);
        $this->assertSame($before, $DB->count_records('course_modules', ['course' => $this->course->id]));
    }

    /**
     * A type this plugin cannot handle is skipped for that reason, not reported
     * as something the teacher chose to leave out.
     *
     * It was never on the list, so "you chose not to" would be untrue - and it
     * would hold the last synced marker back forever, waiting on a choice that
     * cannot be made.
     */
    public function test_an_unsupported_type_is_not_called_deselected(): void {
        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source(
                [$this->detected(11, 'Wanted'), $this->detected(13, 'Cannot be copied', $this->unsupported_modname())],
                [$this->payload(11, 'Wanted')]
            ),
            [11]
        );

        $this->assertSame(1, $result->count('created'));
        $this->assertSame(1, $result->count('skipped'));

        $skipped = array_values(array_filter($result->items, fn($i) => $i['outcome'] === 'skipped'));
        $this->assertSame('Cannot be copied', $skipped[0]['name']);
        $this->assertSame('syncskippedtype', $skipped[0]['detail']);

        // Nothing was actually left out by choice, so the marker moves.
        $this->assertFalse($result->has_deselected());
        $this->assertTrue($result->lastsyncupdated);
    }

    /**
     * Something already in this course does not count as a choice either.
     *
     * This is the sharper half of the same rule. A teacher deselects one thing,
     * which holds the marker; next time everything already here is still
     * detected and still not on the list. If those counted as deselected the
     * marker would never move again, however diligently the teacher ticked.
     */
    public function test_something_already_here_does_not_hold_the_marker(): void {
        global $DB;

        $existing = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(11), ['id' => $existing->cmid]);

        $result = new sync_result();
        $result->add_created('Already here', 'page', 11, (int) $existing->cmid);
        history::record($this->instanceid, $this->course->id, 2, time(), $result);

        // The teacher ticks everything that was actually offered, which is the
        // one new activity. The already-present one is not on the list.
        $run = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source(
                [$this->detected(11, 'Already here'), $this->detected(12, 'Genuinely new')],
                [$this->payload(12, 'Genuinely new')]
            ),
            [12]
        );

        $this->assertSame(1, $run->count('created'));

        $skipped = array_values(array_filter($run->items, fn($i) => $i['outcome'] === 'skipped'));
        $this->assertSame('syncskippedpresent', $skipped[0]['detail']);

        // Nothing was left out by choice, so the marker is free to move.
        $this->assertFalse($run->has_deselected());
        $this->assertTrue($run->lastsyncupdated);
    }

    /**
     * Not passing a selection at all still copies everything, which is what the
     * older behaviour was and what a run with no form behind it means.
     */
    public function test_no_selection_means_everything(): void {
        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source(
                [$this->detected(11, 'One'), $this->detected(12, 'Two')],
                [$this->payload(11, 'One'), $this->payload(12, 'Two')]
            )
        );

        $this->assertSame(2, $result->count('created'));
        $this->assertFalse($result->has_deselected());
        $this->assertTrue($result->lastsyncupdated);
    }
}
