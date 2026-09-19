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
 * Background task that runs a sync.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\task;

use block_coursesync\local\sync\engine;

/**
 * Pulls a block instance's remote activities away from the web request.
 *
 * Backup and restore of several activities is far too slow to hold a page
 * open, so the block queues this and reports progress instead.
 */
class sync_course extends \core\task\adhoc_task {
    /**
     * Returns the task name shown in the admin task screens.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:synccourse', 'block_coursesync');
    }

    /**
     * Runs the sync.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $blockinstanceid = (int) ($data->blockinstanceid ?? 0);
        $userid = (int) $this->get_userid();

        if (!$blockinstanceid || !$userid) {
            mtrace('block_coursesync: task has no block instance or user, nothing to do.');
            return;
        }

        $result = engine::run($blockinstanceid, $userid);

        mtrace(sprintf(
            'block_coursesync: run %s for block %d - %d new, %d updated, %d unchanged, %d conflicts, %d skipped, %d errors.',
            $result->runid,
            $blockinstanceid,
            $result->new,
            $result->updated,
            $result->unchanged,
            $result->conflicts,
            $result->skipped,
            $result->errors
        ));
    }
}
