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

namespace block_coursesync;

use core\http_client;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the remote source site.
 *
 * Every failure is turned into one of this plugin's own error identifiers so the
 * user is shown a sentence they can act on, rather than a transport error or a
 * Moodle exception code. The underlying detail is kept for the debug log only.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_client {
    /** @var int How long to wait for the remote site, in seconds. */
    public const TIMEOUT = 20;

    /**
     * Call block_coursesync_ping on the remote site.
     *
     * @param string $baseurl normalised base URL of the remote site
     * @param string $token the remote site's web service token
     * @param \core\http_client|null $client injected only by tests
     * @return ping_result
     */
    public static function ping(string $baseurl, string $token, ?http_client $client = null): ping_result {
        $outcome = self::call($baseurl, $token, 'block_coursesync_ping', [], $client);

        if ($outcome['errorkey'] !== null) {
            return ping_result::failure($outcome['errorkey']);
        }

        $data = $outcome['data'];

        if (!is_array($data) || empty($data['status'])) {
            return ping_result::failure('errorbadresponse');
        }

        // Everything here is displayed to a user on this site, so it is cleaned
        // where it arrives rather than at each of the places it is shown.
        return ping_result::success(
            clean_param((string) ($data['sitename'] ?? ''), PARAM_TEXT),
            clean_param((string) ($data['release'] ?? ''), PARAM_TEXT),
            clean_param($data['pluginversion'] ?? 0, PARAM_INT)
        );
    }

    /**
     * Resolve a course id or shortname on the remote site.
     *
     * @param string $baseurl normalised base URL of the remote site
     * @param string $token the remote site's web service token
     * @param string $courseref a course id or shortname as typed by the administrator
     * @param \core\http_client|null $client injected only by tests
     * @return course_result
     */
    public static function resolve_course(
        string $baseurl,
        string $token,
        string $courseref,
        ?http_client $client = null
    ): course_result {
        $outcome = self::call($baseurl, $token, 'block_coursesync_get_course', [
            'courseref' => $courseref,
        ], $client);

        if ($outcome['errorkey'] !== null) {
            return course_result::failure($outcome['errorkey']);
        }

        $data = $outcome['data'];

        if (!is_array($data) || !isset($data['id'], $data['shortname'], $data['fullname'])) {
            return course_result::failure('errorbadresponse');
        }

        return course_result::success(
            clean_param($data['id'], PARAM_INT),
            clean_param((string) $data['shortname'], PARAM_TEXT),
            clean_param((string) $data['fullname'], PARAM_TEXT),
            (bool) ($data['visible'] ?? true),
            clean_param($data['activitycount'] ?? 0, PARAM_INT)
        );
    }

    /**
     * List activities in the mapped remote course modified since a given time.
     *
     * @param string $baseurl normalised base URL of the remote site
     * @param string $token the remote site's web service token
     * @param int $courseid the resolved remote course id
     * @param int $since unix time; 0 asks for everything
     * @param \core\http_client|null $client injected only by tests
     * @return activities_result
     */
    public static function get_modified_activities(
        string $baseurl,
        string $token,
        int $courseid,
        int $since,
        ?http_client $client = null
    ): activities_result {
        $outcome = self::call($baseurl, $token, 'block_coursesync_get_modified_activities', [
            'courseid' => $courseid,
            'since' => $since,
        ], $client);

        if ($outcome['errorkey'] !== null) {
            return activities_result::failure($outcome['errorkey']);
        }

        $data = $outcome['data'];

        if (!is_array($data)) {
            return activities_result::failure('errorbadresponse');
        }

        $activities = [];

        foreach ($data as $row) {
            if (!is_array($row) || !isset($row['cmid'], $row['modname'], $row['name'])) {
                return activities_result::failure('errorbadresponse');
            }

            $activities[] = new activity(
                clean_param($row['cmid'], PARAM_INT),
                clean_param((string) $row['modname'], PARAM_PLUGIN),
                clean_param((string) $row['name'], PARAM_TEXT),
                clean_param((string) ($row['idnumber'] ?? ''), PARAM_TEXT),
                clean_param($row['timemodified'] ?? 0, PARAM_INT)
            );
        }

        return activities_result::success($activities);
    }

    /**
     * Fetch everything needed to rebuild one activity.
     *
     * @param string $baseurl normalised base URL of the remote site
     * @param string $token the remote site's web service token
     * @param int $cmid the course module id on the source site
     * @param \core\http_client|null $client injected only by tests
     * @return activity_result
     */
    public static function get_activity(
        string $baseurl,
        string $token,
        int $cmid,
        ?http_client $client = null
    ): activity_result {
        $outcome = self::call($baseurl, $token, 'block_coursesync_get_activity', [
            'cmid' => $cmid,
        ], $client);

        if ($outcome['errorkey'] !== null) {
            return activity_result::failure($outcome['errorkey']);
        }

        $data = $outcome['data'];

        if (!is_array($data) || !isset($data['cmid'], $data['modname'], $data['name'])) {
            return activity_result::failure('errorbadresponse');
        }

        return activity_result::success(activity_payload::from_response($data));
    }

    /**
     * Fetch students' gradebook grades for some of the mapped course's activities.
     *
     * @param string $baseurl normalised base URL of the remote site
     * @param string $token the remote site's web service token
     * @param int $courseid the resolved remote course id
     * @param int[] $cmids course module ids on the source site
     * @param \core\http_client|null $client injected only by tests
     * @param string[] $usernames the only students wanted (never empty: an
     *      empty list means every student to the source)
     * @return grades_result
     */
    public static function get_grades(
        string $baseurl,
        string $token,
        int $courseid,
        array $cmids,
        ?http_client $client = null,
        array $usernames = []
    ): grades_result {
        $items = [];

        // In batches the source accepts; the answers are put back together.
        foreach (array_chunk(array_values(array_map('intval', $cmids)), external\get_grades::MAX_CMIDS) as $batch) {
            $outcome = self::call_for_students($baseurl, $token, 'block_coursesync_get_grades', [
                'courseid' => $courseid,
                'cmids' => $batch,
            ], $usernames, $client);

            if ($outcome['errorkey'] === 'errornopermission') {
                // The account may well hold the sync permission: what it
                // lacks here is the separate one for grades, so say that.
                return grades_result::failure('errornogradepermission');
            }

            if ($outcome['errorkey'] === 'errorpluginmissing') {
                // The source answered as Course Sync (the connection test
                // passed), so what it lacks is this one function: a version
                // from before grade sync.
                return grades_result::failure('errorgradesourceoutdated');
            }

            if ($outcome['errorkey'] !== null) {
                return grades_result::failure($outcome['errorkey']);
            }

            $answer = grades_result::from_response($outcome['data']);

            if (!$answer->success) {
                return $answer;
            }

            $items = array_merge($items, $answer->items);
        }

        return grades_result::success($items);
    }

    /**
     * Fetch students' finished quiz attempts, as marks, for some of the
     * mapped course's quizzes.
     *
     * @param string $baseurl normalised base URL of the remote site
     * @param string $token the remote site's web service token
     * @param int $courseid the resolved remote course id
     * @param int[] $cmids course module ids of quizzes on the source site
     * @param \core\http_client|null $client injected only by tests
     * @param string[] $usernames the only students wanted (never empty: an
     *      empty list means every student to the source)
     * @return quiz_attempts_result
     */
    public static function get_quiz_attempts(
        string $baseurl,
        string $token,
        int $courseid,
        array $cmids,
        ?http_client $client = null,
        array $usernames = []
    ): quiz_attempts_result {
        // Refused for the same permission as grades; and a source that
        // answers as Course Sync but lacks this function is from before it.
        $translate = [
            'errornopermission' => 'errornogradepermission',
            'errorpluginmissing' => 'errorattemptsourceoutdated',
        ];
        $answers = [];

        // In batches the source accepts; the answers are put back together.
        foreach (array_chunk(array_values(array_map('intval', $cmids)), external\get_quiz_attempts::MAX_CMIDS) as $batch) {
            $outcome = self::call_for_students($baseurl, $token, 'block_coursesync_get_quiz_attempts', [
                'courseid' => $courseid,
                'cmids' => $batch,
            ], $usernames, $client);

            if ($outcome['errorkey'] !== null) {
                return quiz_attempts_result::failure($translate[$outcome['errorkey']] ?? $outcome['errorkey']);
            }

            $answer = quiz_attempts_result::from_response($outcome['data']);

            if (!$answer->success) {
                return $answer;
            }

            $answers[] = $answer;
        }

        return quiz_attempts_result::merge($answers);
    }

    /**
     * Fetch one chunk of one of an activity's files.
     *
     * @param string $baseurl normalised base URL of the remote site
     * @param string $token the remote site's web service token
     * @param int $cmid course module id on the source site
     * @param string $filearea
     * @param int $itemid
     * @param string $filepath
     * @param string $filename
     * @param int $offset byte to start at
     * @param int $length how many bytes to ask for
     * @param \core\http_client|null $client injected only by tests
     * @param string $component the file area's component, when it is not the activity's own
     * @return file_chunk
     */
    public static function get_activity_file(
        string $baseurl,
        string $token,
        int $cmid,
        string $filearea,
        int $itemid,
        string $filepath,
        string $filename,
        int $offset,
        int $length,
        ?http_client $client = null,
        string $component = ''
    ): file_chunk {
        $params = [
            'cmid' => $cmid,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $filename,
            'offset' => $offset,
            'length' => $length,
        ];

        if ($component !== '') {
            $params['component'] = $component;
        }

        $outcome = self::call($baseurl, $token, 'block_coursesync_get_activity_file', $params, $client);

        if ($outcome['errorkey'] !== null) {
            return file_chunk::failure($outcome['errorkey']);
        }

        $data = $outcome['data'];

        if (!is_array($data) || !isset($data['content'], $data['returned'], $data['eof'])) {
            return file_chunk::failure('errorbadresponse');
        }

        $content = base64_decode((string) $data['content'], true);

        if ($content === false || strlen($content) !== (int) $data['returned']) {
            // The chunk did not survive the trip intact.
            return file_chunk::failure('errorfiletransfer');
        }

        return file_chunk::success(
            $content,
            (int) $data['returned'],
            (bool) $data['eof'],
            (int) ($data['filesize'] ?? 0),
            (string) ($data['contenthash'] ?? '')
        );
    }

    /**
     * Call a grade function for only some students - and, if the source is
     * from before it could be told which, again for all of them.
     *
     * The list goes as one parameter, one username per line: see
     * local\gradebook::only(). A source that does not know the parameter
     * refuses the whole call, as Moodle refuses any parameter it does not
     * expect ("Unexpected keys"), so it is asked once more without it -
     * exactly the call it answered before this parameter existed.
     *
     * @param string $baseurl
     * @param string $token
     * @param string $function
     * @param array $params
     * @param string[] $usernames the only students wanted; none sends no list
     * @param http_client|null $client
     * @return array{errorkey: string|null, data: mixed}
     */
    protected static function call_for_students(
        string $baseurl,
        string $token,
        string $function,
        array $params,
        array $usernames,
        ?http_client $client
    ): array {
        if (!$usernames) {
            return self::call($baseurl, $token, $function, $params, $client);
        }

        $outcome = self::call($baseurl, $token, $function, $params + ['usernames' => implode("\n", $usernames)], $client);

        if ($outcome['errorkey'] === 'errorbadrequest') {
            $outcome = self::call($baseurl, $token, $function, $params, $client);
        }

        return $outcome;
    }

    /**
     * Make one web service call and turn anything that went wrong into one of
     * this plugin's error identifiers.
     *
     * @param string $baseurl normalised base URL of the remote site
     * @param string $token the remote site's web service token
     * @param string $function the external function name
     * @param array $params parameters for the function
     * @param \core\http_client|null $client injected only by tests
     * @return array{errorkey: string|null, data: mixed}
     */
    protected static function call(
        string $baseurl,
        string $token,
        string $function,
        array $params,
        ?http_client $client = null
    ): array {
        $endpoint = remote_url::webservice_endpoint($baseurl);

        // Checked here, not only when the address was saved. A host name can
        // resolve to something different by the time it is used, and this is the
        // moment that matters. Moodle's own curl_security_helper runs as well,
        // inside http_client; this does not replace it.
        $checked = remote_url::check_and_pin($endpoint);
        $blocked = $checked['error'];

        if ($blocked !== null) {
            debugging(
                'block_coursesync: refused to call ' . $function . ': address not permitted',
                DEBUG_DEVELOPER
            );

            return ['errorkey' => $blocked, 'data' => null];
        }

        try {
            $client = $client ?? new http_client();

            // These belong on the request rather than the client so that they
            // hold however the client was built - including the mock client the
            // tests inject.
            $response = $client->post($endpoint, [
                RequestOptions::TIMEOUT => self::TIMEOUT,
                RequestOptions::CONNECT_TIMEOUT => 10,
                // Read the body ourselves instead of letting Guzzle throw, so a
                // 404 can be told apart from a refused connection.
                RequestOptions::HTTP_ERRORS => false,
                // Certificates are verified normally. There is no bypass here and
                // there should never be one: a site whose certificate cannot be
                // verified is exactly the case this check exists to catch.
                RequestOptions::VERIFY => true,
                // A redirect is refused rather than followed. The endpoint is
                // a fixed path that never legitimately moves, and following
                // one would re-send the token (a 307/308 keeps the body) to
                // an address nobody checked - possibly plain http.
                RequestOptions::ALLOW_REDIRECTS => false,
                // Connect to the address that was just checked, not to
                // whatever the name resolves to a moment from now.
                'curl' => $checked['resolve'] === null ? [] : [CURLOPT_RESOLVE => [$checked['resolve']]],
                RequestOptions::FORM_PARAMS => array_merge([
                    'wstoken' => $token,
                    'wsfunction' => $function,
                    'moodlewsrestformat' => 'json',
                ], $params),
            ]);
        } catch (\Throwable $e) {
            // Guzzle wraps DNS failures, refused connections, timeouts and TLS
            // verification failures. They are all "we could not talk to it".
            debugging(
                'block_coursesync: transport failure calling ' . $function . ': '
                    . self::redact($e->getMessage()),
                DEBUG_DEVELOPER
            );

            return ['errorkey' => self::classify_transport_error($e), 'data' => null];
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 300 && $status < 400) {
            // Almost always an address typed slightly differently from the
            // site's own (www., a path, http for https).
            return ['errorkey' => 'errorredirected', 'data' => null];
        }

        if ($status === 404) {
            // Something answered, but the web service endpoint is not there.
            return ['errorkey' => 'errornotmoodle', 'data' => null];
        }

        if ($status !== 200) {
            debugging("block_coursesync: {$function} got HTTP {$status}", DEBUG_DEVELOPER);

            return ['errorkey' => 'errorunreachable', 'data' => null];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            // An HTML login page, a holding page, a proxy notice - anything but
            // a Moodle web service. Almost always the wrong URL.
            return ['errorkey' => 'errornotmoodle', 'data' => null];
        }

        if (isset($decoded['exception']) || isset($decoded['errorcode'])) {
            return [
                'errorkey' => self::classify_moodle_error((string) ($decoded['errorcode'] ?? '')),
                'data' => null,
            ];
        }

        return ['errorkey' => null, 'data' => $decoded];
    }

    /**
     * Remove anything token-shaped from a message before it is written anywhere.
     *
     * The token travels in the request body rather than the URL, so it should
     * never appear in an exception message. This is here so that "should never"
     * does not have to be relied on: a client library that decides to quote the
     * request it was given still cannot put a working token into a log.
     *
     * @param string $message
     * @return string
     */
    protected static function redact(string $message): string {
        return preg_replace('/\b[a-f0-9]{32}\b/i', '[token removed]', $message);
    }

    /**
     * Decide which message fits a transport-level failure.
     *
     * @param \Throwable $e
     * @return string language string identifier
     */
    public static function classify_transport_error(\Throwable $e): string {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return 'errorcertificate';
        }

        // Moodle blocks requests that fail this site's own outgoing request
        // rules before they are ever sent.
        if (str_contains($message, 'blocked')) {
            return 'errorblocked';
        }

        return 'errorunreachable';
    }

    /**
     * Map a Moodle web service error code to one of our messages.
     *
     * @param string $errorcode the errorcode from the remote site's JSON response
     * @return string language string identifier
     */
    public static function classify_moodle_error(string $errorcode): string {
        $map = [
            // The token itself is not usable.
            'invalidtoken' => 'errorbadtoken',
            'invalidtokensession' => 'errorbadtoken',
            'expiredtoken' => 'errorbadtoken',

            'sitemaintenance' => 'errormaintenance',

            // The token is real, but the service behind it is not available.
            'enablewsdescription' => 'errorserviceunavailable',
            'servicenotavailable' => 'errorserviceunavailable',
            'accessexception' => 'errorserviceunavailable',

            // The account behind the token lacks the capability.
            'nopermissions' => 'errornopermission',
            'requirecapability' => 'errornopermission',

            // No such function over there, so the plugin is probably missing.
            'invalidrecord' => 'errorpluginmissing',
            'unknownfunction' => 'errorpluginmissing',

            // The sync account holds the sync permission but cannot see the
            // course, because it is neither enrolled nor allowed to view courses
            // without participating.
            'requireloginerror' => 'errorcoursenotvisible',

            // Raised by this plugin's own functions on the source site.
            'errorcoursenotfound' => 'errorcoursenotfound',
            'erroractivitynotfound' => 'erroractivitynotfound',
            'errorunsupportedtype' => 'errorunsupportedtype',
            'errorfilenotfound' => 'errorfilenotfound',
            'errorfilenotallowed' => 'errorfilenotallowed',
            'errorgradeexportdisabled' => 'errorgradeexportoff',
            'invalidparameter' => 'errorbadrequest',
            'invalidrecordunknown' => 'errorcoursenotfound',
        ];

        if (isset($map[$errorcode])) {
            return $map[$errorcode];
        }

        debugging('block_coursesync: unmapped remote errorcode ' . $errorcode, DEBUG_DEVELOPER);

        return 'errorremoterefused';
    }
}
