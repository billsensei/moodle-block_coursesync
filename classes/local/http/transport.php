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
 * How the plugin reaches a remote site.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\http;

/**
 * The seam between this plugin's logic and the network.
 *
 * Everything above this interface is about what to ask a remote site and what
 * to do with the answer; everything below it is about cURL and the checks that
 * stop a request going somewhere it should not. Tests substitute their own
 * implementation so no test needs a live site.
 */
interface transport {
    /**
     * Posts a web service request.
     *
     * @param string $url Absolute URL to post to.
     * @param string $postdata URL-encoded request body.
     * @return response
     */
    public function post(string $url, string $postdata): response;

    /**
     * Downloads a file to a local path.
     *
     * @param string $url Absolute URL to fetch.
     * @param string $targetpath Local file to write.
     * @return response
     */
    public function download(string $url, string $targetpath): response;
}
