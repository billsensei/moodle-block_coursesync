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
 * A transport that answers from a script instead of the network.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\http;

/**
 * Replays canned web service responses, so tests need no remote site.
 *
 * Responses are keyed by web service function name. Behat stores them in plugin
 * config, because the step that arranges them runs in a different process from
 * the page being tested; PHPUnit passes them straight in.
 */
class fake_transport implements transport {
    /** @var array Requests this transport was asked to make, for assertions. */
    private array $requests = [];

    /**
     * Constructor.
     *
     * @param array $responses Decoded response bodies keyed by web service function name.
     *                         The key '__download' holds base64 file content for downloads.
     */
    public function __construct(
        /** @var array Decoded response bodies keyed by web service function name. */
        private readonly array $responses,
    ) {
    }

    /**
     * Answers a web service call from the script.
     *
     * @param string $url Absolute URL that would have been posted to.
     * @param string $postdata URL-encoded request body.
     * @return response
     */
    public function post(string $url, string $postdata): response {
        parse_str($postdata, $params);
        $function = (string) ($params['wsfunction'] ?? '');

        $this->requests[] = ['url' => $url, 'function' => $function, 'params' => $params];

        if (!array_key_exists($function, $this->responses)) {
            return new response(json_encode([
                'exception' => 'webservice_access_exception',
                'errorcode' => 'accessexception',
                'message' => 'No canned response for ' . $function,
            ]), 200);
        }

        $canned = $this->responses[$function];

        // A canned entry may describe a transport level failure rather than a body.
        if (is_array($canned) && isset($canned['__httpcode'])) {
            return new response((string) ($canned['__body'] ?? ''), (int) $canned['__httpcode']);
        }

        return new response(json_encode($canned), 200);
    }

    /**
     * Writes the canned file content to the target path.
     *
     * @param string $url Absolute URL that would have been fetched.
     * @param string $targetpath Local file to write.
     * @return response
     */
    public function download(string $url, string $targetpath): response {
        $this->requests[] = ['url' => $url, 'function' => '__download', 'params' => []];

        file_put_contents($targetpath, base64_decode((string) ($this->responses['__download'] ?? '')));

        return new response('', 200);
    }

    /**
     * The requests this transport was asked to make, in order.
     *
     * @return array
     */
    public function get_requests(): array {
        return $this->requests;
    }
}
