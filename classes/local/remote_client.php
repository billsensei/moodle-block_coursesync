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
 * REST client for a remote Moodle site's web services.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

use block_coursesync\local\http\transport;
use block_coursesync\local\http\transport_factory;

/**
 * Calls standard web service functions on a remote Moodle site over REST.
 *
 * Authentication uses a static token issued by the remote site's administrator
 * against an external service; Moodle's web services have no OAuth2 flow and
 * deliberately expose no function for creating services or tokens, so the token
 * always arrives here having been pasted in by a human.
 */
class remote_client {
    /**
     * Functions the remote external service must expose for this phase.
     *
     * @var string[]
     */
    public const REQUIRED_FUNCTIONS = [
        'core_webservice_get_site_info',
        'core_course_get_courses_by_field',
    ];

    /** @var string Validated, normalised base URL of the remote site. */
    private string $baseurl;

    /** @var string Web service token. */
    private string $token;

    /** @var transport How requests actually get made. */
    private transport $transport;

    /**
     * Constructor.
     *
     * @param string $baseurl Remote site URL, validated here before any use.
     * @param string $token Web service token issued by the remote site.
     * @param transport|null $transport How to make requests; the real one unless a test says otherwise.
     * @throws invalid_remote_url_exception If the URL is not acceptable.
     */
    public function __construct(string $baseurl, string $token, ?transport $transport = null) {
        $this->baseurl = url_validator::validate($baseurl);
        $this->token = trim($token);
        $this->transport = $transport ?? transport_factory::create();
    }

    /**
     * Fetches the remote site's identity and the functions the token can reach.
     *
     * @return array The core_webservice_get_site_info response.
     * @throws remote_exception If the call fails.
     */
    public function get_site_info(): array {
        return $this->call('core_webservice_get_site_info');
    }

    /**
     * Looks up courses on the remote site by a single field.
     *
     * @param string $field Field to search, for example 'id' or 'shortname'.
     * @param string $value Value to match.
     * @return array The list of matching course records.
     * @throws remote_exception If the call fails.
     */
    public function get_courses_by_field(string $field, string $value): array {
        $response = $this->call('core_course_get_courses_by_field', [
            'field' => $field,
            'value' => $value,
        ]);

        return $response['courses'] ?? [];
    }

    /**
     * Lists the activities in a course on the remote site.
     *
     * @param int $courseid Course id on the remote site.
     * @return array One entry per activity, as described by block_coursesync_list_activities.
     * @throws remote_exception If the call fails.
     */
    public function list_activities(int $courseid): array {
        $response = $this->call('block_coursesync_list_activities', ['courseid' => $courseid]);

        return $response['activities'] ?? [];
    }

    /**
     * Asks the remote site to package one activity as a backup file.
     *
     * @param int $cmid Course module id on the remote site.
     * @return array Download details, as described by block_coursesync_backup_activity.
     * @throws remote_exception If the call fails.
     */
    public function backup_activity(int $cmid): array {
        return $this->call('block_coursesync_backup_activity', ['cmid' => $cmid]);
    }

    /**
     * Downloads a packaged backup to a local path.
     *
     * @param string $downloadpath Path returned by backup_activity(), relative to the remote site.
     * @param string $targetpath Local file to write.
     * @throws remote_exception If the download fails or does not look like a backup.
     */
    public function download_backup(string $downloadpath, string $targetpath): void {
        if (!str_starts_with($downloadpath, '/webservice/pluginfile.php/')) {
            self::log_failure('download', 'unexpected download path: ' . $downloadpath);
            throw new remote_exception('error:invalidresponse');
        }

        $url = $this->baseurl . $downloadpath . '?token=' . rawurlencode($this->token);
        $result = $this->transport->download($url, $targetpath);

        if ($result->failed()) {
            self::log_failure('download', 'transport error: ' . $result->error);
            throw new remote_exception('error:downloadfailed');
        }

        if ($result->httpcode !== 200) {
            self::log_failure('download', 'unexpected HTTP status ' . $result->httpcode);
            throw new remote_exception('error:downloadfailed');
        }

        // An expired token or a missing file comes back as a 200 carrying JSON,
        // so check the body rather than trusting the status. The archive format
        // itself is not asserted here: a .mbz is a gzipped tar on current Moodle
        // but a zip on older ones, and the unpacking step will reject anything
        // that is neither.
        if ($problem = self::describe_error_payload($targetpath)) {
            self::log_failure('download', 'remote served an error instead of a file: ' . $problem);
            throw new remote_exception('error:downloadnotbackup');
        }
    }

    /**
     * Whether a downloaded file is really a JSON error response.
     *
     * @param string $path Local file path.
     * @return string|null A description of the error, or null if this looks like a real file.
     */
    private static function describe_error_payload(string $path): ?string {
        if (!is_readable($path) || filesize($path) === 0) {
            return 'empty response';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 'unreadable response';
        }
        $head = (string) fread($handle, 1024);
        fclose($handle);

        if (substr(ltrim($head), 0, 1) !== '{') {
            return null;
        }

        $decoded = json_decode(file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['exception'])) {
            return null;
        }

        return (string) ($decoded['errorcode'] ?? 'unknown');
    }

    /**
     * Calls a web service function on the remote site.
     *
     * @param string $function Web service function name.
     * @param array $params Function parameters.
     * @return array The decoded response.
     * @throws remote_exception If the call fails or the response is unusable.
     */
    protected function call(string $function, array $params = []): array {
        if ($this->token === '') {
            throw new remote_exception('error:notoken');
        }

        $postdata = http_build_query(array_merge($params, [
            'wstoken' => $this->token,
            'wsfunction' => $function,
            'moodlewsrestformat' => 'json',
        ]), '', '&');

        $result = $this->transport->post($this->baseurl . '/webservice/rest/server.php', $postdata);

        if ($result->failed()) {
            self::log_failure($function, 'transport error: ' . $result->error);
            throw new remote_exception('error:connectionfailed');
        }

        if ($result->httpcode !== 200) {
            self::log_failure($function, 'unexpected HTTP status ' . $result->httpcode);
            throw new remote_exception('error:badhttpstatus');
        }

        $decoded = json_decode($result->body, true);
        if (!is_array($decoded)) {
            self::log_failure($function, 'response was not a JSON object');
            throw new remote_exception('error:invalidresponse');
        }

        if (isset($decoded['exception'])) {
            self::log_failure($function, 'remote returned ' . json_encode($decoded));
            throw new remote_exception(self::map_error_code((string) ($decoded['errorcode'] ?? '')));
        }

        return $decoded;
    }

    /**
     * Maps a remote error code to one of this plugin's user-safe messages.
     *
     * @param string $errorcode The remote site's errorcode value.
     * @return string Language string key in block_coursesync.
     */
    private static function map_error_code(string $errorcode): string {
        return match ($errorcode) {
            'invalidtoken', 'invalidtokenforuser', 'sitemaintenance' => 'error:invalidtoken',
            'accessexception', 'nopermissions', 'requirecorrectaccess' => 'error:accessdenied',
            'enablewsdescription', 'servicenotavailable', 'restnotenabled' => 'error:wsdisabled',
            'accessnotallowed' => 'error:functionnotinservice',
            default => 'error:remoterejected',
        };
    }

    /**
     * Records why a call failed, for developers only.
     *
     * Detail from the remote site is kept out of the thrown exception so it is
     * never rendered back to the person configuring the block.
     *
     * @param string $function The function that was called.
     * @param string $detail What went wrong.
     */
    private static function log_failure(string $function, string $detail): void {
        debugging('block_coursesync call to ' . $function . ' failed: ' . self::redact($detail), DEBUG_DEVELOPER);
    }

    /**
     * Removes anything token-shaped from text on its way to the debug log.
     *
     * Debug output is shown on screen to administrators in developer mode, and
     * both cURL error strings and remote error bodies can quote the URL that
     * was requested, which for a download carries the token in its query.
     *
     * @param string $detail Text about to be logged.
     * @return string The same text with any token value masked.
     */
    private static function redact(string $detail): string {
        return (string) preg_replace('/(token|wstoken)=[^&\s"\']+/i', '$1=REDACTED', $detail);
    }
}
