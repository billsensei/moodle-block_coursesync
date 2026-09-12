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

namespace block_coursesync\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests history_renderer - in particular the "most recent run starts
 * expanded" behaviour added in Phase 8 to fix a real bug: render_runs()
 * originally tested the loop's array key against 0 to find the first run,
 * but $DB->get_records() (what sync_history::get_for_instance() returns)
 * keys its result by record id, not sequentially from 0, so that check was
 * false for every real result set and no run ever auto-expanded. A Behat
 * scenario asserting the pulled-in activity's name was visible in the
 * history page caught it; this locks the fix in at the unit level too.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(history_renderer::class)]
final class history_renderer_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Only the first run in the (already newest-first-ordered) array is
     * rendered with the "open" attribute - regardless of what its array key
     * is, which is exactly what a real $DB->get_records() result looks like
     * (keyed by id, not 0, 1, 2, ...).
     */
    public function test_only_the_first_run_is_rendered_open(): void {
        $newest = $this->run_row(['id' => 42, 'name' => 'Newest run']);
        $older = $this->run_row(['id' => 7, 'name' => 'Older run']);

        // Keyed by id, like a real $DB->get_records() result - neither key is 0.
        $runs = [42 => $newest, 7 => $older];

        $html = history_renderer::render_runs($runs);

        // Split into individual <details>...</details> blocks so each run's
        // own open/collapsed state can be checked on its own, rather than
        // risking a regex matching "open" on one block and the name on
        // another.
        preg_match_all('#<details[^>]*>.*?</details>#s', $html, $matches);
        $this->assertCount(2, $matches[0], 'expected exactly one <details> block per run');

        $newestblock = $matches[0][0];
        $olderblock = $matches[0][1];

        $this->assertStringContainsString('Newest run', $newestblock);
        $this->assertStringContainsString('open="open"', $newestblock);

        $this->assertStringContainsString('Older run', $olderblock);
        $this->assertStringNotContainsString('open="open"', $olderblock);
    }

    /**
     * No runs at all: a plain message, not an empty or broken loop.
     */
    public function test_no_runs_shows_the_empty_message(): void {
        $html = history_renderer::render_runs([]);

        $this->assertStringContainsString(get_string('nosynchistory', 'block_coursesync'), $html);
        $this->assertStringNotContainsString('<details', $html);
    }

    /**
     * A minimal, valid block_coursesync_synclog-shaped row, with the
     * "Newest run"/"Older run" style name baked into its createdjson so the
     * rendered <details> block can be told apart in assertions above -
     * everything else is realistic filler.
     *
     * @param array $overrides
     * @return \stdClass
     */
    protected function run_row(array $overrides): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $name = $overrides['name'] ?? 'A run';

        $row = (object) [
            'id' => $overrides['id'] ?? 1,
            'blockinstanceid' => 1,
            'userid' => $user->id,
            'timecreated' => time(),
            'success' => 1,
            'errorcode' => null,
            'createdcount' => 1,
            'conflictcount' => 0,
            'failedcount' => 0,
            'createdjson' => json_encode([['cmid' => 1, 'modname' => 'page', 'name' => $name]]),
            'conflictsjson' => '[]',
            'failedjson' => '[]',
        ];

        return $row;
    }
}
