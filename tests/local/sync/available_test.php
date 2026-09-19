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

/**
 * Tests for the list of what a sync would bring in.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

use block_coursesync\local\activity_signature;
use block_coursesync\sync_fixtures;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/sync_fixtures.php');

/**
 * Tests for \block_coursesync\local\sync\available.
 *
 * A check has to report exactly what a run would carry over, and has to leave
 * the course as it found it, so both are asserted here against a scripted
 * remote site. No test here touches the network.
 */
#[CoversClass(available::class)]
final class available_test extends \advanced_testcase {
    use sync_fixtures;

    /**
     * Where a sync started from the list would return to.
     *
     * @return \moodle_url
     */
    private function return_url(): \moodle_url {
        return new \moodle_url('/course/view.php', ['id' => $this->course->id]);
    }

    /**
     * Removes the scripted transport so it cannot leak into another test.
     */
    protected function tearDown(): void {
        $this->stop_faking_the_network();
        parent::tearDown();
    }

    /**
     * An activity this course does not hold yet is offered as new, and nothing is pulled.
     */
    public function test_check_offers_activities_the_course_does_not_have(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'name' => 'Week 1 reading', 'signal' => '1000'],
                ['cmid' => 12, 'name' => 'Week 2 reading', 'signal' => '1000'],
            ]),
        ]);

        $context = available::refresh($blockid, (int) get_admin()->id, $this->return_url());

        $this->assertTrue($context['checked']);
        $this->assertTrue($context['hasitems']);
        $this->assertSame(2, $context['count']);
        $this->assertSame('Week 1 reading', $context['items'][0]['name']);
        $this->assertTrue($context['items'][0]['isnew']);
        $this->assertSame(get_string('modulename', 'mod_page'), $context['items'][0]['modname']);

        // Each entry has to say which remote activity its checkbox stands for,
        // and the list has to know where to post the choice.
        $this->assertSame(11, $context['items'][0]['remotecmid']);
        $this->assertSame(12, $context['items'][1]['remotecmid']);
        $this->assertSame($blockid, $context['blockid']);
        $this->assertStringContainsString('/blocks/coursesync/sync.php', $context['syncurl']);

        // A check is a question, not an action: nothing may have been pulled,
        // recorded in the ledger, or written to the history.
        $this->assertSame(0, $DB->count_records('block_coursesync_pulls', ['blockinstanceid' => $blockid]));
        $this->assertSame(0, $DB->count_records('block_coursesync_log', ['blockinstanceid' => $blockid]));
    }

    /**
     * An activity that changed remotely is offered as a change, not as new.
     */
    public function test_check_offers_a_remotely_changed_activity_as_changed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $local = activity_signature::for_local_cmid((int) $page->cmid);

        $this->record_previous_pull($blockid, 11, (int) $page->cmid, '1000', $local['signal']);
        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'name' => 'Week 1 reading', 'signal' => '2000'],
            ]),
        ]);

        $context = available::refresh($blockid, (int) get_admin()->id, $this->return_url());

        $this->assertSame(1, $context['count']);
        $this->assertFalse($context['items'][0]['isnew']);
        $this->assertSame(get_string('available:changed', 'block_coursesync'), $context['items'][0]['actionlabel']);
    }

    /**
     * Nothing a run would leave alone is offered: not an unchanged activity, nor one in conflict.
     */
    public function test_check_offers_nothing_a_run_would_not_carry_over(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $unchanged = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $edited = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        $this->record_previous_pull(
            $blockid,
            11,
            (int) $unchanged->cmid,
            '1000',
            activity_signature::for_local_cmid((int) $unchanged->cmid)['signal']
        );
        // A local signal that no longer matches the copy makes this one a conflict.
        $this->record_previous_pull($blockid, 12, (int) $edited->cmid, '1000', 'stale-local-signal');

        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'name' => 'Untouched', 'signal' => '1000'],
                ['cmid' => 12, 'name' => 'Edited on both sides', 'signal' => '2000'],
            ]),
        ]);

        $context = available::refresh($blockid, (int) get_admin()->id, $this->return_url());

        $this->assertTrue($context['checked']);
        $this->assertFalse($context['hasitems']);
        $this->assertSame(0, $context['count']);
    }

    /**
     * The last check is remembered between page loads, and thrown away once a run has happened.
     */
    public function test_a_check_is_remembered_until_a_run_makes_it_stale(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Nothing has changed remotely, so the run this test ends with transfers
        // nothing: what is being asserted is how long a check is trusted for,
        // not what it found.
        $blockid = $this->create_configured_block();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $local = activity_signature::for_local_cmid((int) $page->cmid);

        $this->record_previous_pull($blockid, 11, (int) $page->cmid, '1000', $local['signal']);
        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'name' => 'Week 1 reading', 'signal' => '1000'],
            ]),
        ]);

        available::refresh($blockid, (int) get_admin()->id, $this->return_url());
        $this->assertTrue(available::context($blockid, $this->return_url())['checked']);

        engine::run($blockid, (int) get_admin()->id);

        $this->assertFalse(available::context($blockid, $this->return_url())['checked']);
    }

    /**
     * Without a check, the block says so rather than claiming there is nothing to sync.
     */
    public function test_a_block_that_has_never_been_checked_says_so(): void {
        $this->resetAfterTest();

        $blockid = $this->create_configured_block();

        $context = available::context($blockid, $this->return_url());

        $this->assertFalse($context['checked']);
        $this->assertFalse($context['hasitems']);
        $this->assertSame('', $context['error']);
    }

    /**
     * Activities this course has no copy of come before ones it already has.
     */
    public function test_activities_not_in_this_course_are_listed_first(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $local = activity_signature::for_local_cmid((int) $page->cmid);

        // The remote course lists the one this course already has first.
        $this->record_previous_pull($blockid, 11, (int) $page->cmid, '1000', $local['signal']);
        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'name' => 'Already here, changed', 'signal' => '2000'],
                ['cmid' => 12, 'name' => 'Not here at all', 'signal' => '1000'],
                ['cmid' => 13, 'name' => 'Also not here', 'signal' => '1000'],
            ]),
        ]);

        $context = available::refresh($blockid, (int) get_admin()->id, $this->return_url());

        $this->assertSame(
            ['Not here at all', 'Also not here', 'Already here, changed'],
            array_column($context['items'], 'name')
        );

        // Within the new ones, the remote course's own order is kept.
        $this->assertSame([12, 13, 11], array_column($context['items'], 'remotecmid'));
    }
}
