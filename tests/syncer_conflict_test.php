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
 * Tests that a sync never overwrites or duplicates what is already there.
 *
 * The source site is mocked so a whole run can be driven from a test: the first
 * response is the list of what changed, the second is the full payload for one
 * activity. That is enough to exercise the branch that decides between creating
 * an activity and flagging it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(syncer::class)]
#[CoversClass(history::class)]
final class syncer_conflict_test extends advanced_testcase {
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
     * A client that answers a detection call and then a payload call.
     *
     * @param array $activities what change detection should report
     * @param array|null $payload the full payload for the activity, if one is fetched
     * @return http_client
     */
    protected function mock_source(array $activities, ?array $payload = null): http_client {
        $responses = [new Response(200, [], json_encode($activities))];

        if ($payload !== null) {
            $responses[] = new Response(200, [], json_encode($payload));
        }

        return new http_client(['mock' => new MockHandler($responses)]);
    }

    /**
     * An activity type installed here that this plugin has no handler for.
     *
     * Looked up rather than named: a named one stops being unsupported the day
     * it gains a handler, and the test then looks like a regression.
     *
     * @return string
     */
    protected function unsupported_modname(): string {
        $modname = \block_coursesync\local\handler\handler_registry::first_unsupported_modname();

        $this->assertNotNull($modname, 'Every installed type has a handler; this test needs rethinking');

        return $modname;
    }

    /**
     * One entry as change detection reports it.
     *
     * @param int $cmid
     * @param string $name
     * @param string $modname
     * @return array
     */
    protected function detected(int $cmid, string $name = 'Week 1 Notes', string $modname = 'page'): array {
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
    protected function payload(int $cmid, string $name = 'Week 1 Notes'): array {
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
                ['name' => 'content', 'value' => '<p>Body from the source.</p>'],
                ['name' => 'contentformat', 'value' => (string) FORMAT_HTML],
            ],
            'files' => [],
        ];
    }

    /**
     * An activity that is not here yet is created, not flagged.
     */
    public function test_new_activity_is_created(): void {
        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11)], $this->payload(11))
        );

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->count('created'));
        $this->assertSame(0, $result->count('conflict'));
        $this->assertFalse($result->needs_review());
        $this->assertSame(1, $result->count('created'));
    }

    /**
     * An activity a teacher made that carries the identity a sync would use is
     * flagged, and is left exactly as it was.
     */
    public function test_local_activity_is_flagged_not_overwritten(): void {
        global $DB;

        // A page the teacher made, carrying the marker the sync would claim.
        $local = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id,
            'name' => 'My own hand-made page',
            'content' => '<p>MUST NOT BE OVERWRITTEN</p>',
        ]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(11), ['id' => $local->cmid]);
        rebuild_course_cache($this->course->id, true);

        $before = $DB->get_record('page', ['id' => $local->id], '*', MUST_EXIST);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11)])
        );

        $this->assertSame(0, $result->count('created'));
        $this->assertSame(1, $result->count('conflict'));
        $this->assertTrue($result->needs_review());

        $item = $result->items[0];
        $this->assertSame('conflict', $item['outcome']);
        $this->assertSame('conflictlocalactivity', $item['detail']);
        $this->assertSame((int) $local->cmid, $item['localcmid']);

        // Not overwritten.
        $after = $DB->get_record('page', ['id' => $local->id], '*', MUST_EXIST);
        $this->assertSame($before->name, $after->name);
        $this->assertSame($before->content, $after->content);

        // Not duplicated.
        $this->assertSame(1, $DB->count_records('course_modules', [
            'course' => $this->course->id,
            'idnumber' => syncer::build_idnumber(11),
        ]));
    }

    /**
     * An activity an earlier run copied, which has since changed at the source,
     * is flagged as that rather than as somebody else's activity.
     */
    public function test_changed_upstream_is_flagged_distinctly(): void {
        // First run copies it, which also writes the history that tells the
        // second run where the local copy came from.
        $first = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11)], $this->payload(11))
        );

        $this->assertSame(1, $first->count('created'));

        // It changes at the source and is offered again.
        connection::set_last_sync($this->instanceid, 1);

        $second = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11, 'Week 1 Notes, revised')])
        );

        $this->assertSame(0, $second->count('created'));
        $this->assertSame(1, $second->count('conflict'));
        $this->assertSame('conflictchangedupstream', $second->items[0]['detail']);
    }

    /**
     * An activity type nothing handles is skipped, not flagged: there is no
     * conflict, just nothing this plugin can do with it.
     */
    public function test_unsupported_type_is_skipped(): void {
        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11, 'Something else', $this->unsupported_modname())])
        );

        $this->assertSame(0, $result->count('conflict'));
        $this->assertSame(1, $result->count('skipped'));
        $this->assertSame('syncskippedtype', $result->items[0]['detail']);
    }

    /**
     * Conflicts do not hold the last synced marker back.
     *
     * Holding it would mean every activity copied by an earlier run is detected
     * again on the next run, finds its own copy present, and is flagged too -
     * one conflict turning into a course full of them.
     */
    public function test_conflicts_do_not_hold_the_marker(): void {
        global $DB;

        $local = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(11), ['id' => $local->cmid]);
        rebuild_course_cache($this->course->id, true);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            false,
            $this->mock_source([$this->detected(11)])
        );

        $this->assertSame(1, $result->count('conflict'));
        $this->assertTrue($result->lastsyncupdated, 'A conflict must not hold the marker back.');
        $this->assertNotNull(connection::get_last_sync($this->instanceid));
    }

    /**
     * A failure does hold the marker, so the activity is tried again.
     */
    public function test_a_failure_holds_the_marker(): void {
        // Detection reports an activity, then the payload fetch fails.
        $client = new http_client(['mock' => new MockHandler([
            new Response(200, [], json_encode([$this->detected(11)])),
            new Response(200, [], json_encode([
                'exception' => 'moodle_exception',
                'errorcode' => 'erroractivitynotfound',
                'message' => 'gone',
            ])),
        ])]);

        $result = syncer::run($this->instanceid, $this->course->id, false, $client);

        $this->assertSame(1, $result->count('failed'));
        $this->assertFalse($result->lastsyncupdated, 'A failure must hold the marker.');
        $this->assertNull(connection::get_last_sync($this->instanceid));
    }

    /**
     * Every run is written to the history, including one that did nothing.
     */
    public function test_every_run_is_recorded(): void {
        $empty = syncer::run($this->instanceid, $this->course->id, false, $this->mock_source([]));

        $this->assertGreaterThan(0, $empty->runid);

        $runs = history::get_runs($this->instanceid);

        $this->assertCount(1, $runs);
        $this->assertSame(history::STATUS_OK, $runs[0]->status);
        $this->assertSame(0, (int) $runs[0]->pulledcount);
        $this->assertSame(0, (int) $runs[0]->conflictcount);
    }

    /**
     * A run that flagged something is recorded as needing review, with the
     * flagged activity written down.
     */
    public function test_a_flagged_run_is_recorded_for_review(): void {
        global $DB, $USER;

        $local = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id,
            'name' => 'Existing page',
        ]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(11), ['id' => $local->cmid]);
        rebuild_course_cache($this->course->id, true);

        syncer::run($this->instanceid, $this->course->id, false, $this->mock_source([$this->detected(11)]));

        $runs = history::get_runs($this->instanceid);

        $this->assertCount(1, $runs);
        $this->assertSame(history::STATUS_REVIEW, $runs[0]->status);
        $this->assertSame(1, (int) $runs[0]->conflictcount);
        $this->assertSame((int) $USER->id, (int) $runs[0]->userid);
        $this->assertCount(1, $runs[0]->conflicts);
        $this->assertSame('Week 1 Notes', $runs[0]->conflicts[0]['name']);
        $this->assertSame((int) $local->cmid, (int) $runs[0]->conflicts[0]['localcmid']);
    }

    /**
     * A run that could not start is recorded too, so the history answers
     * "was this tried?" and not only "what did it do?".
     */
    public function test_a_failed_run_is_recorded(): void {
        connection::clear_remote_course($this->instanceid);

        $result = syncer::run($this->instanceid, $this->course->id);

        $this->assertFalse($result->success);

        $runs = history::get_runs($this->instanceid);

        $this->assertCount(1, $runs);
        $this->assertSame(history::STATUS_FAILED, $runs[0]->status);
        $this->assertSame('errornotmapped', $runs[0]->errorkey);
    }
}
