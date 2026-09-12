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
 * Talks to a remote (source) site's block_coursesync web services over
 * Moodle's REST protocol.
 *
 * Every call returns a uniform result array so the caller can show a
 * distinct, non-technical message for each outcome (bad token, wrong URL,
 * unreachable, remote-side error, success) without needing to know
 * anything about HTTP or the REST response format.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_client {
    /** @var string Remote site base URL, e.g. https://source.example.edu. */
    protected string $remoteurl;

    /** @var string Plaintext web service token. Never logged or persisted by this class. */
    protected string $token;

    /**
     * Creates a client for one remote site.
     *
     * @param string $remoteurl Remote site base URL.
     * @param string $token Plaintext web service token.
     */
    public function __construct(string $remoteurl, string $token) {
        $this->remoteurl = rtrim($remoteurl, '/');
        $this->token = $token;
    }

    /**
     * Calls block_coursesync_ping on the remote site.
     *
     * @return array{success: bool, errorcode: string, technical: ?string, data: ?array}
     */
    public function ping(): array {
        return $this->call('block_coursesync_ping', []);
    }

    /**
     * Calls block_coursesync_check_course on the remote site.
     *
     * @param string $identifier Course ID or shortname on the remote site.
     * @return array{success: bool, errorcode: string, technical: ?string, data: ?array}
     */
    public function check_course(string $identifier): array {
        return $this->call('block_coursesync_check_course', ['identifier' => $identifier]);
    }

    /**
     * Calls block_coursesync_get_modified_activities on the remote site.
     *
     * @param string $identifier Course ID or shortname on the remote site.
     * @param int $since Unix timestamp; 0 means everything.
     * @return array{success: bool, errorcode: string, technical: ?string, data: ?array}
     */
    public function get_modified_activities(string $identifier, int $since): array {
        return $this->call('block_coursesync_get_modified_activities', ['identifier' => $identifier, 'since' => $since]);
    }

    /**
     * Calls block_coursesync_get_activity_content on the remote site.
     *
     * @param int $cmid Course module id on the remote site.
     * @return array{success: bool, errorcode: string, technical: ?string, data: ?array}
     */
    public function get_activity_content(int $cmid): array {
        return $this->call('block_coursesync_get_activity_content', ['cmid' => $cmid]);
    }

    /**
     * Makes one REST web service call and normalises the outcome.
     *
     * errorcode is one of:
     *  - 'success'     call worked, data holds the decoded response.
     *  - 'badtoken'    the remote site rejected the token.
     *  - 'wrongurl'    got a reply, but it wasn't a Moodle web service
     *                  response (wrong address, service not enabled, etc).
     *  - 'unreachable' couldn't connect at all (network/DNS/timeout).
     *  - 'remote'      the remote site returned some other error; technical
     *                  holds its message, and remoteerrorcode holds the
     *                  remote site's own errorcode (e.g. 'coursenotfound')
     *                  for callers that want to handle specific ones.
     *
     * @param string $wsfunction
     * @param array $params
     * @return array{success: bool, errorcode: string, technical: ?string, data: ?array, remoteerrorcode: ?string}
     */
    protected function call(string $wsfunction, array $params): array {
        global $CFG;

        // The \curl class lives in filelib.php, which core only autoloads
        // on some request paths - not guaranteed on every page this class
        // might be used from.
        require_once($CFG->libdir . '/filelib.php');

        $endpoint = $this->remoteurl . '/webservice/rest/server.php';

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 15, 'CURLOPT_CONNECTTIMEOUT' => 10]);

        $postdata = array_merge($params, [
            'wstoken' => $this->token,
            'wsfunction' => $wsfunction,
            'moodlewsrestformat' => 'json',
        ]);

        $response = $curl->post($endpoint, $postdata);

        if ($curl->get_errno()) {
            return [
                'success' => false, 'errorcode' => 'unreachable', 'technical' => $curl->error,
                'data' => null, 'remoteerrorcode' => null,
            ];
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            // Not JSON at all: wrong URL, a non-Moodle site, or the REST
            // protocol isn't enabled there.
            return [
                'success' => false,
                'errorcode' => 'wrongurl',
                'technical' => substr((string) $response, 0, 200),
                'data' => null,
                'remoteerrorcode' => null,
            ];
        }

        if (array_key_exists('exception', $decoded)) {
            $remoteerrorcode = (string) ($decoded['errorcode'] ?? '');
            if (in_array($remoteerrorcode, ['invalidtoken', 'accessexception', 'invalidiptoken'], true)) {
                return [
                    'success' => false,
                    'errorcode' => 'badtoken',
                    'technical' => $decoded['message'] ?? $remoteerrorcode,
                    'data' => null,
                    'remoteerrorcode' => $remoteerrorcode,
                ];
            }
            return [
                'success' => false,
                'errorcode' => 'remote',
                'technical' => $decoded['message'] ?? $remoteerrorcode,
                'data' => null,
                'remoteerrorcode' => $remoteerrorcode,
            ];
        }

        return [
            'success' => true, 'errorcode' => 'success', 'technical' => null,
            'data' => $decoded, 'remoteerrorcode' => null,
        ];
    }
}
