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

use block_coursesync\activity_payload;
use block_coursesync\external\get_activity;
use block_coursesync\external\get_activity_file;
use block_coursesync\external\get_modified_activities;
use block_coursesync\local\handler\handler_registry;
use core\http_client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Copying an activity the whole way within one test site.
 *
 * The activity is exported as the source would export it, rebuilt as the
 * destination would, and its files fetched through file_sync from a "source"
 * that answers each request by running the real web service function on this
 * same site - get_activity_file, and for a whole syncer::run(),
 * get_modified_activities and get_activity too. So the source's
 * listing, its refusal rules, the transfer and the destination's filing and
 * post_files() all run together, as they do between two sites.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait source_on_this_site {
    /** @var string[] Every file the fake source was asked for, as component/area/name. */
    protected array $requested = [];

    /**
     * A client whose "source site" is this test site.
     *
     * @return http_client
     */
    protected function local_source(): http_client {
        return new http_client(['mock' => function (RequestInterface $request) {
            parse_str((string) $request->getBody(), $params);

            try {
                $body = $this->answer((string) ($params['wsfunction'] ?? ''), $params);
            } catch (\moodle_exception $e) {
                $body = ['exception' => get_class($e), 'errorcode' => $e->errorcode, 'message' => $e->getMessage()];
            }

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
        }]);
    }

    /**
     * What this site's own web service function returns for one request.
     *
     * @param string $function
     * @param array $params
     * @return array
     */
    protected function answer(string $function, array $params): array {
        if ($function === 'block_coursesync_get_modified_activities') {
            return get_modified_activities::execute((int) $params['courseid'], (int) ($params['since'] ?? 0));
        }

        if ($function === 'block_coursesync_get_activity') {
            return get_activity::execute((int) $params['cmid']);
        }

        $this->requested[] = ($params['component'] ?? '') . '/' . $params['filearea'] . '/' . $params['filename'];

        return get_activity_file::execute(
            (int) $params['cmid'],
            (string) $params['filearea'],
            (int) $params['itemid'],
            (string) $params['filepath'],
            (string) $params['filename'],
            (int) $params['offset'],
            (int) $params['length'],
            (string) ($params['component'] ?? '')
        );
    }

    /**
     * Copy an activity into another course, files and all, the way
     * syncer::create_copy() does.
     *
     * @param int $cmid the activity on the "source"
     * @param \stdClass $target the course to copy it into
     * @return array [the new course_modules record, the payload, the handler]
     */
    protected function copy(int $cmid, \stdClass $target): array {
        $payload = activity_payload::from_response(get_activity::execute($cmid));
        $handler = handler_registry::get($payload->modname);

        $cm = $handler->create_from_remote_data($target, $payload, 'coursesync-' . $cmid);
        file_sync::copy_files($cm, $handler, $payload, 'https://source.example.edu', 'token', $this->local_source());
        $handler->post_files($cm, $payload);

        return [$cm, $payload, $handler];
    }
}
