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

/**
 * What a page needs to do before it starts work that can take minutes.
 *
 * A sync or a pull runs inside the web request: it asks the other site for a
 * description, then fetches every file in pieces of half a megabyte, up to
 * half a gigabyte a run. PHP's time limit and memory limit are the web
 * server's defaults unless the page raises them, and the person's session
 * stays locked the whole time, so any other Moodle tab they have open waits
 * for it. Called once, after the sesskey has been checked and before the work.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class long_run {
    /**
     * Lift the time and memory limits and release the session lock.
     *
     * Nothing after this may change the session: a notification added to it, or
     * a redirect that carries a message, would be lost.
     *
     * @return void
     */
    public static function begin(): void {
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        \core\session\manager::write_close();
    }
}
