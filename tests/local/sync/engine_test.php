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
 * Tests for the sync run against real ledger state.
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
 * Tests for \block_coursesync\local\sync\engine.
 *
 * The planner is covered on its own with plain arrays; these tests drive the
 * same decisions through real block_coursesync_pulls rows, real course
 * modules and a scripted remote, so that the wiring between them is exercised
 * too. No test here touches the network.
 */
#[CoversClass(engine::class)]
final class engine_test extends \advanced_testcase {
    use sync_fixtures;

    /**
     * Removes the scripted transport so it cannot leak into another test.
     */
    protected function tearDown(): void {
        $this->stop_faking_the_network();
        parent::tearDown();
    }

    /**
     * An activity neither side has touched is reported as unchanged.
     */
    public function test_untouched_activity_is_unchanged(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $local = activity_signature::for_local_cmid((int) $page->cmid);

        $this->record_previous_pull($blockid, 11, (int) $page->cmid, '1000', $local['signal']);
        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'signal' => '1000'],
            ]),
        ]);

        $result = engine::run($blockid, (int) get_admin()->id);

        $this->assertSame(1, $result->unchanged);
        $this->assertSame(0, $result->conflicts);
        $this->assertSame(0, $result->errors);
        $this->assertSame(audit_log::OUTCOME_UNCHANGED, $this->log_rows($blockid)[11]->outcome);
    }

    /**
     * When both sides changed, nothing is transferred and the divergence is flagged.
     */
    public function test_independent_local_edit_produces_a_conflict(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        // The local signal recorded at the pull differs from the copy's signal
        // now, which is what an independent local edit looks like.
        $this->record_previous_pull($blockid, 11, (int) $page->cmid, '1000', 'signal-at-pull-time');
        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'signal' => '2000'],
            ]),
        ]);

        $result = engine::run($blockid, (int) get_admin()->id);

        $this->assertSame(1, $result->conflicts);
        $this->assertSame(0, $result->updated);

        $ledger = $this->ledger_row($blockid, 11);
        $this->assertSame(pull_ledger::STATUS_CONFLICT, $ledger->status);
        $this->assertSame(plan_item::CONFLICT_BOTH_CHANGED, $ledger->conflictreason);

        // The reference points from the last good pull must survive, or the next
        // run would treat the divergence as settled.
        $this->assertSame('1000', $ledger->remotesignal);
        $this->assertSame('signal-at-pull-time', $ledger->localsignal);

        $log = $this->log_rows($blockid)[11];
        $this->assertSame(audit_log::OUTCOME_CONFLICT, $log->outcome);
        $this->assertSame(plan_item::CONFLICT_BOTH_CHANGED, $log->message);
    }

    /**
     * An incoming activity must not shadow one this block did not create.
     */
    public function test_name_collision_produces_a_conflict(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $existing = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $this->course->id, 'name' => 'Week 1 reading']
        );

        // Nothing in the ledger: this remote activity has never been pulled,
        // but the course already has something of that name.
        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 21, 'name' => 'Week 1 reading', 'signal' => '3000'],
            ]),
        ]);

        $result = engine::run($blockid, (int) get_admin()->id);

        $this->assertSame(1, $result->conflicts);
        $this->assertSame(0, $result->new);

        $ledger = $this->ledger_row($blockid, 21);
        $this->assertSame(pull_ledger::STATUS_CONFLICT, $ledger->status);
        $this->assertSame(plan_item::CONFLICT_NAME_COLLISION, $ledger->conflictreason);

        // The colliding activity belongs to someone else, so it must not be
        // recorded as a copy this block owns.
        $this->assertNull($ledger->localcmid);
        $this->assertNotEquals($existing->cmid, $ledger->localcmid);
    }

    /**
     * A collision is only a collision against activities this block did not create.
     */
    public function test_an_activity_this_block_owns_does_not_collide_with_itself(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $this->course->id, 'name' => 'Week 1 reading']
        );
        $local = activity_signature::for_local_cmid((int) $page->cmid);

        $this->record_previous_pull($blockid, 11, (int) $page->cmid, '1000', $local['signal']);
        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'name' => 'Week 1 reading', 'signal' => '1000'],
            ]),
        ]);

        $result = engine::run($blockid, (int) get_admin()->id);

        $this->assertSame(1, $result->unchanged);
        $this->assertSame(0, $result->conflicts);
    }

    /**
     * A module type that cannot be backed up is skipped rather than attempted.
     */
    public function test_module_without_backup_support_is_skipped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 31, 'name' => 'Odd module', 'backupsupported' => false],
            ]),
        ]);

        $result = engine::run($blockid, (int) get_admin()->id);

        $this->assertSame(1, $result->skipped);
        $this->assertSame(pull_ledger::STATUS_SKIPPED, $this->ledger_row($blockid, 31)->status);
    }

    /**
     * A remote change over an untouched copy is carried through to a transfer.
     */
    public function test_remote_change_over_untouched_local_attempts_a_transfer(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $local = activity_signature::for_local_cmid((int) $page->cmid);

        $this->record_previous_pull($blockid, 11, (int) $page->cmid, '1000', $local['signal']);

        // The remote refuses to package it, so the transfer fails; what matters
        // here is that the run decided to transfer at all, and recorded the
        // failure against that one activity instead of falling over.
        $transport = $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'signal' => '2000'],
            ]),
        ]);

        $result = engine::run($blockid, (int) get_admin()->id);

        // The refusal is reported to developers, not to the person syncing.
        $this->assertDebuggingCalled();

        $this->assertSame(1, $result->errors);
        $this->assertSame(0, $result->updated);
        $this->assertSame(audit_log::OUTCOME_ERROR, $this->log_rows($blockid)[11]->outcome);

        $functions = array_column($transport->get_requests(), 'function');
        $this->assertContains('block_coursesync_backup_activity', $functions);

        // The ledger still describes the last good state.
        $ledger = $this->ledger_row($blockid, 11);
        $this->assertSame(pull_ledger::STATUS_SYNCED, $ledger->status);
        $this->assertSame('1000', $ledger->remotesignal);
    }

    /**
     * One activity failing does not stop the others in the same run.
     */
    public function test_a_failure_does_not_abort_the_rest_of_the_run(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();

        // One copy the remote has changed, which will fail to transfer, and one
        // that neither side has touched.
        $failing = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $steady = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        $this->record_previous_pull(
            $blockid,
            11,
            (int) $failing->cmid,
            '1000',
            activity_signature::for_local_cmid((int) $failing->cmid)['signal']
        );
        $this->record_previous_pull(
            $blockid,
            12,
            (int) $steady->cmid,
            '500',
            activity_signature::for_local_cmid((int) $steady->cmid)['signal']
        );

        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'signal' => '2000'],
                ['cmid' => 12, 'name' => 'Steady activity', 'signal' => '500'],
                ['cmid' => 13, 'name' => 'Unsupported', 'backupsupported' => false],
            ]),
        ]);

        $result = engine::run($blockid, (int) get_admin()->id);

        $this->assertDebuggingCalled();

        // The first one failed, and the run still dealt with the other two.
        $this->assertSame(1, $result->errors);
        $this->assertSame(1, $result->unchanged);
        $this->assertSame(1, $result->skipped);
        $this->assertCount(3, $this->log_rows($blockid));
    }

    /**
     * A block that was never configured does not reach the network at all.
     */
    public function test_an_unconfigured_block_fails_without_calling_out(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block(['lastvalidated' => 0, 'tokenciphertext' => '']);
        $transport = $this->use_fake_transport([]);

        $result = engine::run($blockid, (int) get_admin()->id);

        $this->assertSame(1, $result->errors);
        $this->assertSame([], $transport->get_requests());
    }
}
