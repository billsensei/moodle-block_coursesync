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
 * The real network transport.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\http;

use block_coursesync\local\curl_security_helper;

/**
 * Makes requests with Moodle's cURL wrapper, under this plugin's URL rules.
 *
 * A fresh security helper is attached to every single request, not just to the
 * first one: it re-validates the scheme and re-resolves the host each time, and
 * pins the addresses that passed so the name cannot resolve to something else
 * between the check and the connection. Redirects are never followed, so a
 * remote site cannot bounce a request somewhere this plugin never approved.
 */
class curl_transport implements transport {
    /** @var int Seconds to wait for the connection to be established. */
    private const CONNECT_TIMEOUT = 5;

    /** @var int Seconds to wait for a web service call. */
    private const REQUEST_TIMEOUT = 10;

    /** @var int Seconds to wait for a backup download, which is far larger. */
    private const DOWNLOAD_TIMEOUT = 300;

    /**
     * Posts a web service request.
     *
     * @param string $url Absolute URL to post to.
     * @param string $postdata URL-encoded request body.
     * @return response
     */
    public function post(string $url, string $postdata): response {
        $curl = $this->prepare(self::REQUEST_TIMEOUT);
        $body = $curl->post($url, $postdata);

        return $this->describe($curl, (string) $body);
    }

    /**
     * Downloads a file to a local path.
     *
     * @param string $url Absolute URL to fetch.
     * @param string $targetpath Local file to write.
     * @return response
     */
    public function download(string $url, string $targetpath): response {
        $curl = $this->prepare(self::DOWNLOAD_TIMEOUT);
        $curl->download_one($url, null, ['filepath' => $targetpath]);

        return $this->describe($curl, '');
    }

    /**
     * Builds a cURL handle with this plugin's guards in place.
     *
     * @param int $timeout Seconds allowed for the whole request.
     * @return \curl
     */
    private function prepare(int $timeout): \curl {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl(['securityhelper' => new curl_security_helper()]);
        $curl->setopt([
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXREDIRS' => 0,
        ]);

        return $curl;
    }

    /**
     * Turns a finished cURL handle into a transport-neutral response.
     *
     * @param \curl $curl The handle just used.
     * @param string $body The response body, where there is one.
     * @return response
     */
    private function describe(\curl $curl, string $body): response {
        return new response(
            $body,
            (int) ($curl->get_info()['http_code'] ?? 0),
            (int) $curl->get_errno(),
            (string) $curl->error
        );
    }
}
