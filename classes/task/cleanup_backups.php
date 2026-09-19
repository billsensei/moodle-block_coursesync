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
 * Scheduled cleanup of packaged activity backups.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\task;

use block_coursesync\local\backup_packager;

/**
 * Removes activity backups that were packaged but never collected.
 *
 * A pull normally downloads its backup within seconds, but a run that dies
 * between packaging and downloading would otherwise leave the .mbz sitting in
 * the file area indefinitely.
 */
class cleanup_backups extends \core\task\scheduled_task {
    /**
     * Returns the task name shown in the admin task screens.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:cleanupbackups', 'block_coursesync');
    }

    /**
     * Deletes expired packaged backups.
     */
    public function execute(): void {
        $removed = backup_packager::cleanup();

        if ($removed) {
            mtrace('block_coursesync: removed ' . $removed . ' uncollected activity backup(s).');
        }
    }
}
