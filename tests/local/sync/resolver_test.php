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
 * Tests for resolving conflicts.
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
 * Tests for \block_coursesync\local\sync\resolver.
 *
 * Resolving a conflict changes course content, so the capability is checked
 * here as well as on the page that offers the choices.
 */
#[CoversClass(resolver::class)]
final class resolver_test extends \advanced_testcase {
    use sync_fixtures;

    /** @var int The block instance under test. */
    private int $blockid;

    /** @var \stdClass The local copy in conflict. */
    private \stdClass $page;

    /**
     * Puts one activity into conflict.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->blockid = $this->create_configured_block();
        $this->page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        $this->record_previous_pull($this->blockid, 11, (int) $this->page->cmid, '1000', 'signal-at-pull-time');

        $this->use_fake_transport([
            'block_coursesync_list_activities' => $this->remote_listing([
                ['cmid' => 11, 'signal' => '2000'],
            ]),
        ]);

        engine::run($this->blockid, (int) get_admin()->id);
        $this->assertSame(pull_ledger::STATUS_CONFLICT, $this->ledger_row($this->blockid, 11)->status);
    }

    /**
     * Removes the scripted transport so it cannot leak into another test.
     */
    protected function tearDown(): void {
        $this->stop_faking_the_network();
        parent::tearDown();
    }

    /**
     * Creates a user enrolled in the course with the given role.
     *
     * @param string $archetype Role shortname.
     * @return \stdClass
     */
    private function enrolled_user(string $archetype): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $archetype);

        return $user;
    }

    /**
     * Someone without the capability cannot resolve anything, whichever choice they send.
     */
    public function test_a_student_cannot_resolve_a_conflict(): void {
        $student = $this->enrolled_user('student');

        foreach (resolver::actions() as $action) {
            try {
                resolver::resolve($this->blockid, 11, $action, (int) $student->id);
                $this->fail('Expected ' . $action . ' to be refused for a student');
            } catch (\required_capability_exception $e) {
                $this->assertStringContainsString(
                    get_string('coursesync:trigger', 'block_coursesync'),
                    $e->getMessage()
                );
            }
        }

        // Nothing moved.
        $this->assertSame(pull_ledger::STATUS_CONFLICT, $this->ledger_row($this->blockid, 11)->status);
    }

    /**
     * A made-up action is refused before anything else happens.
     */
    public function test_an_unknown_action_is_refused(): void {
        $this->expectException(\moodle_exception::class);
        resolver::resolve($this->blockid, 11, 'deleteeverything', (int) get_admin()->id);
    }

    /**
     * Deciding later leaves the conflict alone but records the decision.
     */
    public function test_deferring_leaves_the_conflict_in_place(): void {
        resolver::resolve($this->blockid, 11, resolver::ACTION_DEFER, (int) get_admin()->id);

        $this->assertSame(pull_ledger::STATUS_CONFLICT, $this->ledger_row($this->blockid, 11)->status);
        $this->assertSame(audit_log::OUTCOME_DEFERRED, $this->latest_log()->outcome);
    }

    /**
     * Keeping the local copy settles the conflict without touching the content.
     */
    public function test_keeping_the_local_copy_settles_it_and_moves_the_baseline(): void {
        global $DB;

        $before = $DB->get_field('page', 'content', ['id' => $this->page->id]);

        resolver::resolve($this->blockid, 11, resolver::ACTION_KEEP_LOCAL, (int) get_admin()->id);

        $ledger = $this->ledger_row($this->blockid, 11);
        $this->assertSame(pull_ledger::STATUS_SYNCED, $ledger->status);
        $this->assertNull($ledger->conflictreason);

        // The baseline now describes what is actually on both sides, so the
        // next run has nothing to report.
        $this->assertSame('2000', $ledger->remotesignal);
        $this->assertSame(
            activity_signature::for_local_cmid((int) $this->page->cmid)['signal'],
            $ledger->localsignal
        );

        $this->assertSame($before, $DB->get_field('page', 'content', ['id' => $this->page->id]));
        $this->assertSame(audit_log::OUTCOME_KEPT_LOCAL, $this->latest_log()->outcome);
    }

    /**
     * After keeping the local copy, a further run no longer reports it.
     */
    public function test_a_later_run_does_not_reopen_a_settled_conflict(): void {
        resolver::resolve($this->blockid, 11, resolver::ACTION_KEEP_LOCAL, (int) get_admin()->id);

        $result = engine::run($this->blockid, (int) get_admin()->id);

        $this->assertSame(0, $result->conflicts);
        $this->assertSame(1, $result->unchanged);
    }

    /**
     * A conflict that someone else has already settled cannot be settled twice.
     */
    public function test_resolving_a_settled_conflict_is_refused(): void {
        resolver::resolve($this->blockid, 11, resolver::ACTION_KEEP_LOCAL, (int) get_admin()->id);

        $this->expectException(\moodle_exception::class);
        resolver::resolve($this->blockid, 11, resolver::ACTION_DEFER, (int) get_admin()->id);
    }

    /**
     * Returns the most recently written log row.
     *
     * @return \stdClass
     */
    private function latest_log(): \stdClass {
        global $DB;

        return $DB->get_record_sql(
            'SELECT * FROM {block_coursesync_log} WHERE blockinstanceid = ? ORDER BY id DESC',
            [$this->blockid],
            IGNORE_MULTIPLE
        );
    }
}
