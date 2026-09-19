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
 * Tests for how conflicts are presented.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\output;

use block_coursesync\local\sync\plan_item;
use block_coursesync\local\sync\pull_ledger;
use block_coursesync\sync_fixtures;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sync_fixtures.php');

/**
 * Tests for \block_coursesync\output\conflicts_page.
 */
#[CoversClass(conflicts_page::class)]
final class conflicts_page_test extends \advanced_testcase {
    use sync_fixtures;

    /**
     * Writes a conflicted ledger row.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid Remote course module id.
     * @param string $reason One of the plan_item conflict reasons.
     * @param int|null $localcmid The local copy, where this block has one.
     */
    private function record_conflict(int $blockinstanceid, int $remotecmid, string $reason, ?int $localcmid): void {
        global $DB;

        $DB->insert_record('block_coursesync_pulls', (object) [
            'blockinstanceid' => $blockinstanceid,
            'remotecmid' => $remotecmid,
            'localcmid' => $localcmid,
            'remotesignal' => '2000',
            'remotesignalmethod' => 'timemodified',
            'localsignal' => $localcmid ? 'signal-at-pull-time' : null,
            'localsignalmethod' => $localcmid ? 'timemodified' : null,
            'status' => pull_ledger::STATUS_CONFLICT,
            'conflictreason' => $reason,
            'timepulled' => $localcmid ? time() - DAYSECS : 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Builds the page's template context.
     *
     * @param int $blockinstanceid Block instance id.
     * @return array
     */
    private function context(int $blockinstanceid): array {
        global $PAGE;

        $page = new conflicts_page(
            $blockinstanceid,
            new \moodle_url('/blocks/coursesync/conflicts.php', ['id' => $blockinstanceid])
        );

        return $page->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Activities this course has no copy of are listed before the ones it has.
     */
    public function test_activities_not_in_this_course_are_listed_first(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        // Written in the order the old page would have shown them, lowest
        // remote course module id first.
        $this->record_conflict($blockid, 11, plan_item::CONFLICT_BOTH_CHANGED, (int) $page->cmid);
        $this->record_conflict($blockid, 21, plan_item::CONFLICT_NAME_COLLISION, null);

        $context = $this->context($blockid);

        $this->assertTrue($context['hasconflicts']);
        $this->assertSame([21, 11], array_column($context['conflicts'], 'remotecmid'));
        $this->assertFalse($context['conflicts'][0]['haslocal']);
        $this->assertTrue($context['conflicts'][1]['haslocal']);
    }

    /**
     * Taking the remote version is offered for every conflict, and flagged where it would
     * delete an activity this block did not create.
     */
    public function test_replacing_someone_elses_activity_is_offered_with_a_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        $this->record_conflict($blockid, 21, plan_item::CONFLICT_NAME_COLLISION, null);
        $this->record_conflict($blockid, 11, plan_item::CONFLICT_BOTH_CHANGED, (int) $page->cmid);

        $context = $this->context($blockid);

        $this->assertTrue($context['conflicts'][0]['willreplaceforeign']);
        $this->assertFalse($context['conflicts'][1]['willreplaceforeign']);
    }

    /**
     * With nothing in conflict there is nothing to decide.
     */
    public function test_a_block_with_no_conflicts_has_nothing_to_show(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $context = $this->context($this->create_configured_block());

        $this->assertFalse($context['hasconflicts']);
        $this->assertSame([], $context['conflicts']);
    }
}
