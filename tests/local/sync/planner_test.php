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
 * Tests for the sync planner's classification rules.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \block_coursesync\local\sync\planner.
 *
 * The planner is the part that decides whether an activity is safe to
 * overwrite, so each rule is pinned down here rather than left to an
 * end-to-end run.
 */
#[CoversClass(planner::class)]
final class planner_test extends \basic_testcase {
    /**
     * Builds a remote activity row as list_activities would return it.
     *
     * @param int $cmid Remote course module id.
     * @param string $signal Change signal.
     * @param array $overrides Any other fields to set.
     * @return array
     */
    private function remote(int $cmid, string $signal, array $overrides = []): array {
        return $overrides + [
            'cmid' => $cmid,
            'modname' => 'page',
            'name' => 'Week 1 notes',
            'sectionnum' => 1,
            'idnumber' => '',
            'signal' => $signal,
            'signalmethod' => 'timemodified',
            'backupsupported' => true,
        ];
    }

    /**
     * Builds a ledger row as it would look after a successful pull.
     *
     * @param int $localcmid Local course module id.
     * @param string $remotesignal Remote signal recorded at the last pull.
     * @param string $localsignal Local signal recorded at the last pull.
     * @return \stdClass
     */
    private function ledgerrow(int $localcmid, string $remotesignal, string $localsignal): \stdClass {
        return (object) [
            'localcmid' => $localcmid,
            'remotesignal' => $remotesignal,
            'localsignal' => $localsignal,
            'status' => pull_ledger::STATUS_SYNCED,
        ];
    }

    /**
     * An activity that has never been pulled is new.
     */
    public function test_unknown_activity_is_new(): void {
        $plan = (new planner())->plan([$this->remote(11, '100')], [], []);

        $this->assertCount(1, $plan);
        $this->assertSame(plan_item::ACTION_NEW, $plan[0]->action);
        $this->assertNull($plan[0]->localcmid);
    }

    /**
     * A module type that cannot be backed up is skipped rather than attempted.
     */
    public function test_module_without_backup_support_is_skipped(): void {
        $plan = (new planner())->plan([$this->remote(11, '100', ['backupsupported' => false])], [], []);

        $this->assertSame(plan_item::ACTION_SKIPPED, $plan[0]->action);
        $this->assertSame(plan_item::SKIP_NO_BACKUP_SUPPORT, $plan[0]->reason);
    }

    /**
     * An unchanged remote signal means there is nothing to do.
     */
    public function test_unchanged_remote_is_left_alone(): void {
        $plan = (new planner())->plan(
            [$this->remote(11, '100')],
            [11 => $this->ledgerrow(55, '100', 'L1')],
            [55 => 'L1']
        );

        $this->assertSame(plan_item::ACTION_UNCHANGED, $plan[0]->action);
        $this->assertSame(55, $plan[0]->localcmid);
    }

    /**
     * A local edit alone does not cause a pull or a conflict.
     */
    public function test_local_edit_without_remote_change_is_left_alone(): void {
        $plan = (new planner())->plan(
            [$this->remote(11, '100')],
            [11 => $this->ledgerrow(55, '100', 'L1')],
            [55 => 'L2-edited-locally']
        );

        $this->assertSame(plan_item::ACTION_UNCHANGED, $plan[0]->action);
    }

    /**
     * A remote change is pulled when the local copy is untouched.
     */
    public function test_remote_change_over_untouched_local_is_an_update(): void {
        $plan = (new planner())->plan(
            [$this->remote(11, '200')],
            [11 => $this->ledgerrow(55, '100', 'L1')],
            [55 => 'L1']
        );

        $this->assertSame(plan_item::ACTION_UPDATE, $plan[0]->action);
        $this->assertSame(55, $plan[0]->localcmid);
    }

    /**
     * When both sides changed, nothing is overwritten.
     */
    public function test_change_on_both_sides_is_a_conflict(): void {
        $plan = (new planner())->plan(
            [$this->remote(11, '200')],
            [11 => $this->ledgerrow(55, '100', 'L1')],
            [55 => 'L2-edited-locally']
        );

        $this->assertSame(plan_item::ACTION_CONFLICT, $plan[0]->action);
        $this->assertSame(plan_item::CONFLICT_BOTH_CHANGED, $plan[0]->reason);
        $this->assertSame(55, $plan[0]->localcmid);
    }

    /**
     * A local copy that has been deleted is pulled again.
     */
    public function test_deleted_local_copy_is_pulled_again(): void {
        $plan = (new planner())->plan(
            [$this->remote(11, '200')],
            [11 => $this->ledgerrow(55, '100', 'L1')],
            []
        );

        $this->assertSame(plan_item::ACTION_NEW, $plan[0]->action);
    }

    /**
     * An incoming activity must not quietly shadow one this block did not create.
     */
    public function test_name_collision_with_unmanaged_activity_is_a_conflict(): void {
        $plan = (new planner())->plan(
            [$this->remote(11, '100', ['name' => 'Week 1 Notes'])],
            [],
            [],
            ['week 1 notes' => 77]
        );

        $this->assertSame(plan_item::ACTION_CONFLICT, $plan[0]->action);
        $this->assertSame(plan_item::CONFLICT_NAME_COLLISION, $plan[0]->reason);
        $this->assertSame(77, $plan[0]->localcmid);
    }

    /**
     * An id number collision counts the same way as a name collision.
     */
    public function test_idnumber_collision_is_a_conflict(): void {
        $plan = (new planner())->plan(
            [$this->remote(11, '100', ['name' => 'Something else', 'idnumber' => 'BIO-1'])],
            [],
            [],
            [],
            ['BIO-1' => 88]
        );

        $this->assertSame(plan_item::ACTION_CONFLICT, $plan[0]->action);
        $this->assertSame(plan_item::CONFLICT_NAME_COLLISION, $plan[0]->reason);
        $this->assertSame(88, $plan[0]->localcmid);
    }

    /**
     * An activity this block already manages does not collide with itself.
     */
    public function test_managed_activity_does_not_collide_with_its_own_name(): void {
        $plan = (new planner())->plan(
            [$this->remote(11, '200')],
            [11 => $this->ledgerrow(55, '100', 'L1')],
            [55 => 'L1'],
            ['week 1 notes' => 55]
        );

        $this->assertSame(plan_item::ACTION_UPDATE, $plan[0]->action);
    }

    /**
     * Each activity is judged independently within one run.
     */
    public function test_mixed_run_classifies_each_activity_separately(): void {
        $plan = (new planner())->plan(
            [
                $this->remote(11, '100', ['name' => 'Untouched']),
                $this->remote(12, '250', ['name' => 'Changed remotely']),
                $this->remote(13, '250', ['name' => 'Changed on both sides']),
                $this->remote(14, '100', ['name' => 'Brand new']),
            ],
            [
                11 => $this->ledgerrow(55, '100', 'L1'),
                12 => $this->ledgerrow(56, '100', 'L2'),
                13 => $this->ledgerrow(57, '100', 'L3'),
            ],
            [55 => 'L1', 56 => 'L2', 57 => 'L3-edited']
        );

        $actions = array_map(fn(plan_item $item): string => $item->action, $plan);

        $this->assertSame([
            plan_item::ACTION_UNCHANGED,
            plan_item::ACTION_UPDATE,
            plan_item::ACTION_CONFLICT,
            plan_item::ACTION_NEW,
        ], $actions);
    }
}
