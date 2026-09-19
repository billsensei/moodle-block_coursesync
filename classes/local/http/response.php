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
 * What came back from an outbound request.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\http;

/**
 * The result of one request, independent of how it was made.
 */
class response {
    /**
     * Constructor.
     *
     * @param string $body Response body; empty for downloads, which go to a file.
     * @param int $httpcode HTTP status, or 0 if the request never completed.
     * @param int $errno Transport error number, 0 when the request itself worked.
     * @param string $error Transport error message, if any.
     */
    public function __construct(
        /** @var string Response body; empty for downloads, which go to a file. */
        public readonly string $body,
        /** @var int HTTP status, or 0 if the request never completed. */
        public readonly int $httpcode,
        /** @var int Transport error number, 0 when the request itself worked. */
        public readonly int $errno = 0,
        /** @var string Transport error message, if any. */
        public readonly string $error = '',
    ) {
    }

    /**
     * Whether the request failed before a response was received.
     *
     * @return bool
     */
    public function failed(): bool {
        return $this->errno !== 0;
    }
}
