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
 * Tests for updating an activity this plugin copied earlier, once the source
 * has changed it.
 *
 * Three things are pinned. A copy counts as changed only when the source
 * modified it after the run that pulled it. An update the teacher ticks
 * replaces the copy when nobody has anything in it, and otherwise leaves it
 * alone and adds a new edition beside it. And nothing is ever updated that
 * was not ticked.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(syncer::class)]
#[CoversClass(copy_update::class)]
#[CoversClass(sync_candidates::class)]
#[CoversClass(history::class)]
final class syncer_update_test extends advanced_testcase {
    /** @var int The block instance these tests sync into. */
    protected int $instanceid = 0;

    /** @var \stdClass The destination course. */
    protected \stdClass $course;

    /** @var int When the earlier run that pulled the copy started. */
    protected int $pulledat = 0;

    /**
     * Put a configured, mapped block instance in a fresh course.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['numsections' => 3, 'enablecompletion' => 1]);

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($this->course->id));
        $page->set_course($this->course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $this->course->format);
        $page->set_url('/course/view.php', ['id' => $this->course->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');

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

        $this->pulledat = time() - DAYSECS;
    }

    /**
     * A page this plugin copied here in an earlier run.
     *
     * @param int $remotecmid
     * @param array $options extra generator options
     * @return \stdClass the course_modules record
     */
    protected function earlier_copy(int $remotecmid, array $options = []): \stdClass {
        global $DB;

        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $this->course->id, 'name' => 'Original'] + $options
        );
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber($remotecmid), ['id' => $page->cmid]);

        $result = new sync_result();
        $result->add_created('Original', 'page', $remotecmid, (int) $page->cmid);
        history::record($this->instanceid, $this->course->id, 2, $this->pulledat, $result);

        return $DB->get_record('course_modules', ['id' => $page->cmid], '*', MUST_EXIST);
    }

    /**
     * Someone has completed a page, which is recorded against it.
     *
     * @param int $cmid
     * @return void
     */
    protected function someone_completed(int $cmid): void {
        global $DB;

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $cmid,
            'userid' => $student->id,
            'completionstate' => 1,
            'timemodified' => time(),
        ]);
    }

    /**
     * A client that answers a detection call and then any payload calls.
     *
     * @param array $activities what change detection should report
     * @param Response[] $payloads one response per activity that gets fetched
     * @return http_client
     */
    protected function mock_source(array $activities, array $payloads = []): http_client {
        return new http_client(['mock' => new MockHandler(array_merge(
            [new Response(200, [], json_encode($activities))],
            $payloads
        ))]);
    }

    /**
     * One entry as change detection reports it.
     *
     * @param int $cmid
     * @param int $timemodified
     * @return array
     */
    protected function detected(int $cmid, int $timemodified): array {
        return [
            'cmid' => $cmid,
            'modname' => 'page',
            'name' => 'Updated title',
            'idnumber' => '',
            'timemodified' => $timemodified,
        ];
    }

    /**
     * The changed page as the source site sends it.
     *
     * @param int $cmid
     * @return Response
     */
    protected function payload(int $cmid): Response {
        return new Response(200, [], json_encode([
            'cmid' => $cmid,
            'modname' => 'page',
            'name' => 'Updated title',
            'idnumber' => '',
            'sectionnum' => 1,
            'visible' => true,
            'intro' => '<p>Intro.</p>',
            'introformat' => FORMAT_HTML,
            'timemodified' => time(),
            'settings' => [
                ['name' => 'content', 'value' => '<p>Updated body.</p>'],
                ['name' => 'contentformat', 'value' => (string) FORMAT_HTML],
            ],
            'children' => [],
            'files' => [],
        ]));
    }

    /**
     * A copy is changed only when the source modified it after the run that pulled it.
     */
    public function test_changed_is_measured_from_the_run_that_pulled_the_copy(): void {
        $this->earlier_copy(11);
        $this->earlier_copy(12);
        $completed = $this->earlier_copy(13);
        $this->someone_completed((int) $completed->id);

        $candidates = syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([
                $this->detected(11, $this->pulledat - 10),
                $this->detected(12, $this->pulledat + 10),
                $this->detected(13, $this->pulledat + 10),
            ])
        );

        $this->assertSame([11], array_map(fn(activity $a): int => $a->cmid, $candidates->present));
        $this->assertSame([12, 13], array_map(fn(activity $a): int => $a->cmid, $candidates->changed));

        // Changed ones are on offer, and it is known up front which of them
        // would become a new edition. The unchanged one is on offer too, to
        // copy again.
        $this->assertSame([12, 13, 11], $candidates->offered_cmids());
        $this->assertFalse($candidates->recopy_adds_copy(11));
        $this->assertTrue($candidates->has_any());
        $this->assertFalse($candidates->is_new_edition(12));
        $this->assertTrue($candidates->is_new_edition(13));
    }

    /**
     * A copy nobody has anything in is replaced where it stands, keeping what
     * this course set up around it.
     */
    public function test_an_untouched_copy_is_replaced_in_place(): void {
        global $DB;

        $old = $this->earlier_copy(11, ['section' => 2]);
        set_coursemodule_visible($old->id, 0);
        $after = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'section' => 2]);

        // Another activity is only available once the copy is complete.
        $availability = json_encode([
            'op' => '&',
            'c' => [['type' => 'completion', 'cm' => (int) $old->id, 'e' => 1]],
            'showc' => [true],
        ]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $after->cmid]);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, time())], [$this->payload(11)]),
            [11]
        );

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->count('updated'));
        $this->assertSame('syncupdatedreplaced', $result->items[0]['detail']);

        // The old copy is gone, the new one carries the identity.
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $old->id]));
        $newcmid = syncer::find_existing($this->course->id, 11);
        $this->assertSame($result->items[0]['localcmid'], $newcmid);

        $new = $DB->get_record('course_modules', ['id' => $newcmid], '*', MUST_EXIST);
        $this->assertSame('<p>Updated body.</p>', $DB->get_field('page', 'content', ['id' => $new->instance]));

        // Same section, same place in it, still hidden as this course had it.
        $this->assertSame((int) $old->section, (int) $new->section);
        $sequence = explode(',', $DB->get_field('course_sections', 'sequence', ['id' => $new->section]));
        $this->assertSame([(string) $newcmid, (string) $after->cmid], $sequence);
        $this->assertSame(0, (int) $new->visible);

        // What pointed at the old copy now points at the new one.
        $this->assertStringContainsString(
            '"cm":' . $newcmid,
            $DB->get_field('course_modules', 'availability', ['id' => $after->cmid])
        );

        // And the history knows this copy was pulled here, so it is ordinary from now on.
        $this->assertTrue(history::was_pulled_here($this->instanceid, 11, $newcmid));
        $this->assertTrue($result->lastsyncupdated);
    }

    /**
     * A copy somebody has something in is left alone, and the update arrives
     * beside it as a new edition that is tracked from then on.
     */
    public function test_a_copy_with_peoples_data_gets_a_new_edition(): void {
        global $DB;

        $old = $this->earlier_copy(11, ['section' => 2]);
        $this->someone_completed((int) $old->id);
        $after = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'section' => 2]);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, time())], [$this->payload(11)]),
            [11]
        );

        $this->assertSame(1, $result->count('updated'));
        $this->assertSame('syncupdatednewedition', $result->items[0]['detail']);
        $this->assertSame('Updated title (New edition)', $result->items[0]['name']);

        // The old copy is untouched apart from no longer being the tracked one.
        $stillthere = $DB->get_record('course_modules', ['id' => $old->id], '*', MUST_EXIST);
        $this->assertSame('', (string) $stillthere->idnumber);
        $this->assertSame('Original', $DB->get_field('page', 'name', ['id' => $old->instance]));
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $old->id]));

        // The new edition sits straight after it and carries the identity.
        $newcmid = syncer::find_existing($this->course->id, 11);
        $this->assertNotSame((int) $old->id, $newcmid);
        $new = $DB->get_record('course_modules', ['id' => $newcmid], '*', MUST_EXIST);
        $this->assertSame('Updated title (New edition)', $DB->get_field('page', 'name', ['id' => $new->instance]));

        $sequence = explode(',', $DB->get_field('course_sections', 'sequence', ['id' => $old->section]));
        $this->assertSame([(string) $old->id, (string) $newcmid, (string) $after->cmid], $sequence);
    }

    /**
     * A changed activity left unticked is not updated, and is offered again.
     */
    public function test_an_unticked_change_is_left_and_offered_again(): void {
        global $DB;

        $old = $this->earlier_copy(11);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, time())]),
            []
        );

        $this->assertSame(0, $result->count('updated'));
        $this->assertSame('syncskippeddeselected', $result->items[0]['detail']);
        $this->assertFalse($result->lastsyncupdated);
        $this->assertSame('Original', $DB->get_field('page', 'name', ['id' => $old->instance]));
    }

    /**
     * A run nobody made a choice for never updates anything. That is what a
     * sync always did with a copy already here, and it still is.
     */
    public function test_a_run_without_a_choice_does_not_update(): void {
        global $DB;

        $old = $this->earlier_copy(11);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, time())])
        );

        $this->assertSame(0, $result->count('updated'));
        $this->assertSame(1, $result->count('conflict'));
        $this->assertSame((int) $old->id, syncer::find_existing($this->course->id, 11));
    }

    /**
     * When the updated version cannot be fetched, the copy here is left as it was.
     */
    public function test_a_failed_update_leaves_the_copy_alone(): void {
        global $DB;

        $old = $this->earlier_copy(11);
        $before = $DB->count_records('course_modules', ['course' => $this->course->id]);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, time())], [new Response(500, [], '')]),
            [11]
        );
        $this->assertDebuggingCalled();

        $this->assertSame(1, $result->count('failed'));
        $this->assertFalse($result->lastsyncupdated);
        $this->assertSame((int) $old->id, syncer::find_existing($this->course->id, 11));
        $this->assertSame($before, $DB->count_records('course_modules', ['course' => $this->course->id]));
    }

    /**
     * An unchanged copy nobody has anything in, ticked anyway, is replaced
     * by a fresh copy where it stands.
     */
    public function test_an_unchanged_untouched_copy_ticked_again_is_replaced(): void {
        global $DB;

        $old = $this->earlier_copy(11, ['section' => 2]);
        set_coursemodule_visible($old->id, 0);

        $candidates = syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, $this->pulledat - 10)])
        );
        $this->assertSame([11], array_map(fn(activity $a): int => $a->cmid, $candidates->present));
        $this->assertFalse($candidates->recopy_adds_copy(11));

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, $this->pulledat - 10)], [$this->payload(11)]),
            [11]
        );

        $this->assertSame(1, $result->count('updated'));
        $this->assertSame('syncrecopiedreplaced', $result->items[0]['detail']);

        $this->assertFalse($DB->record_exists('course_modules', ['id' => $old->id]));
        $newcmid = syncer::find_existing($this->course->id, 11);
        $new = $DB->get_record('course_modules', ['id' => $newcmid], '*', MUST_EXIST);
        $this->assertSame('Updated title', $DB->get_field('page', 'name', ['id' => $new->instance]));
        $this->assertSame((int) $old->section, (int) $new->section);
        $this->assertSame(0, (int) $new->visible);
        $this->assertTrue(history::was_pulled_here($this->instanceid, 11, $newcmid));
    }

    /**
     * An unchanged copy somebody has something in, ticked anyway, is left
     * alone, and a fresh copy named "(copy)" is added after it and tracked.
     */
    public function test_an_unchanged_copy_with_peoples_data_ticked_again_gets_a_copy(): void {
        global $DB;

        $old = $this->earlier_copy(11, ['section' => 2]);
        $this->someone_completed((int) $old->id);

        $candidates = syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, $this->pulledat - 10)])
        );
        $this->assertTrue($candidates->recopy_adds_copy(11));

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, $this->pulledat - 10)], [$this->payload(11)]),
            [11]
        );

        // Nothing was updated - something unchanged was copied again.
        $this->assertSame(1, $result->count('created'));
        $this->assertSame(0, $result->count('updated'));
        $this->assertSame('syncrecopiedcopy', $result->items[0]['detail']);
        $this->assertSame('Updated title (copy)', $result->items[0]['name']);

        $stillthere = $DB->get_record('course_modules', ['id' => $old->id], '*', MUST_EXIST);
        $this->assertSame('', (string) $stillthere->idnumber);
        $this->assertSame('Original', $DB->get_field('page', 'name', ['id' => $old->instance]));
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $old->id]));

        $newcmid = syncer::find_existing($this->course->id, 11);
        $this->assertNotSame((int) $old->id, $newcmid);
        $sequence = explode(',', $DB->get_field('course_sections', 'sequence', ['id' => $old->section]));
        $this->assertSame([(string) $old->id, (string) $newcmid], $sequence);
        $this->assertTrue(history::was_pulled_here($this->instanceid, 11, $newcmid));
    }

    /**
     * Copying again beside an earlier "(copy)" numbers the next one rather
     * than leaving two with the same name.
     */
    public function test_a_second_copy_is_numbered(): void {
        global $DB;

        $old = $this->earlier_copy(11);
        $this->someone_completed((int) $old->id);

        $source = fn(): http_client => $this->mock_source(
            [$this->detected(11, $this->pulledat - 10)],
            [$this->payload(11)]
        );

        syncer::run($this->instanceid, $this->course->id, true, $source(), [11]);
        $first = syncer::find_existing($this->course->id, 11);
        $this->someone_completed($first);

        $result = syncer::run($this->instanceid, $this->course->id, true, $source(), [11]);

        $this->assertSame('syncrecopiedcopy', $result->items[0]['detail']);
        $this->assertSame('Updated title (copy 2)', $result->items[0]['name']);

        $firstcm = get_coursemodule_from_id('page', $first, 0, false, MUST_EXIST);
        $this->assertSame('Updated title (copy)', $DB->get_field('page', 'name', ['id' => $firstcm->instance]));
    }

    /**
     * Something here carrying the identity that this plugin did not put here
     * is never touched. Ticked, a separate untracked "(copy)" goes after it,
     * and it is still flagged for review.
     */
    public function test_a_collision_ticked_gets_a_separate_untracked_copy(): void {
        global $DB;

        $local = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Local']);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(11), ['id' => $local->cmid]);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, $this->pulledat - 10)], [$this->payload(11)]),
            [11]
        );

        $this->assertSame(1, $result->count('created'));
        $this->assertSame('syncrecopiedbeside', $result->items[0]['detail']);
        $this->assertSame('Updated title (copy)', $result->items[0]['name']);

        // What is here is exactly as it was, identity and all.
        $this->assertSame((int) $local->cmid, syncer::find_existing($this->course->id, 11));
        $this->assertSame('Local', $DB->get_field('page', 'name', ['id' => $local->id]));

        // The copy is in the course, after it, with no identity.
        $copy = $DB->get_record('course_modules', ['id' => $result->items[0]['localcmid']], '*', MUST_EXIST);
        $this->assertSame('', (string) $copy->idnumber);
        $sequence = explode(',', $DB->get_field('course_sections', 'sequence', ['id' => $copy->section]));
        $this->assertSame(
            array_search((string) $local->cmid, $sequence, true) + 1,
            array_search((string) $copy->id, $sequence, true)
        );

        $candidates = syncer::list_candidates(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, $this->pulledat - 10)])
        );
        $this->assertTrue($candidates->needs_review());
    }

    /**
     * An unchanged copy left unticked is left alone, and does not hold the
     * last synced marker - it was offered, but never needed a choice.
     */
    public function test_an_unticked_unchanged_copy_is_left_alone(): void {
        global $DB;

        $old = $this->earlier_copy(11);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->detected(11, $this->pulledat - 10)]),
            []
        );

        $this->assertSame('syncskippedpresent', $result->items[0]['detail']);
        $this->assertTrue($result->lastsyncupdated);
        $this->assertSame((int) $old->id, syncer::find_existing($this->course->id, 11));
        $this->assertSame('Original', $DB->get_field('page', 'name', ['id' => $old->instance]));
    }

    /**
     * A grade entered in the gradebook counts as people's data, even where
     * the activity itself holds nothing about anyone.
     */
    public function test_a_gradebook_grade_counts_as_peoples_data(): void {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $this->assertFalse(copy_update::has_people_data((int) $assign->cmid));

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $item = \grade_item::fetch([
            'courseid' => $this->course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
            'itemnumber' => 0,
        ]);
        $item->update_final_grade($student->id, 50, 'gradebook');

        $this->assertTrue(copy_update::has_people_data((int) $assign->cmid));
    }

    /**
     * What counts as people having something in an activity comes from the
     * activity's own privacy provider, so it needs nothing per type.
     */
    public function test_peoples_data_is_read_from_the_privacy_provider(): void {
        global $DB;

        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->assertFalse(copy_update::has_people_data((int) $page->cmid));

        $choice = $this->getDataGenerator()->create_module('choice', [
            'course' => $this->course->id,
            'option' => ['Yes', 'No'],
        ]);
        $this->assertFalse(copy_update::has_people_data((int) $choice->cmid));

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $option = array_key_first($DB->get_records('choice_options', ['choiceid' => $choice->id], 'id ASC', 'id'));
        $DB->insert_record('choice_answers', (object) [
            'choiceid' => $choice->id,
            'userid' => $student->id,
            'optionid' => $option,
            'timemodified' => time(),
        ]);
        $this->assertTrue(copy_update::has_people_data((int) $choice->cmid));
    }

    /**
     * A question bank has something in it worth keeping once a question was
     * added here rather than synced.
     */
    public function test_a_question_bank_with_local_questions_counts_as_used(): void {
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $this->course->id]);
        $this->assertFalse(copy_update::has_people_data((int) $qbank->cmid));

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $qgen->create_question('truefalse', null, ['category' => $category->id]);

        $this->assertTrue(copy_update::has_people_data((int) $qbank->cmid));
    }
}
