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
 * Exception thrown when a remote site URL is not acceptable.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

/**
 * Thrown when a user-supplied remote site URL fails validation.
 *
 * The message is always a translated, user-safe string, so it is safe to show
 * in a form error or an AJAX response.
 */
class invalid_remote_url_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $errorcode Language string key in block_coursesync.
     */
    public function __construct(string $errorcode) {
        parent::__construct($errorcode, 'block_coursesync');
    }
}
