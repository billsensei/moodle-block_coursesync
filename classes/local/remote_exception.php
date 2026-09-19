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
 * Exception thrown when a call to a remote Moodle site fails.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

/**
 * Thrown when a web service call to the remote site cannot be completed.
 *
 * The message is always one of this plugin's own translated strings. Detail
 * from the remote site is deliberately never included, so that remote error
 * bodies are not echoed back to the person configuring the block; that detail
 * goes to debugging() instead.
 */
class remote_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $errorcode Language string key in block_coursesync.
     */
    public function __construct(string $errorcode) {
        parent::__construct($errorcode, 'block_coursesync');
    }
}
