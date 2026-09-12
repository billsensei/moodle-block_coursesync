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
 * Test double for remote_client - returns canned data instead of making a
 * real HTTP call, so sync_runner can be exercised without a live remote
 * site. This is exactly the seam remote_client's own design docblock
 * mentions: sync_runner only ever talks to it through remote_client's
 * public methods, so a subclass overriding those is enough.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stub_remote_client extends remote_client {
    /** @var array List entries to return from get_modified_activities(). */
    protected array $activities;

    /** @var array<int, array> cmid => the payload get_activity_content() should return for it. */
    protected array $contentbyid;

    /**
     * Stores the canned responses this stub should hand back.
     *
     * @param array $activities List entries to return from get_modified_activities().
     * @param array $contentbyid Keyed by cmid - the payload get_activity_content() should return for it.
     */
    public function __construct(array $activities, array $contentbyid) {
        parent::__construct('https://stub.invalid', 'stub-token');
        $this->activities = $activities;
        $this->contentbyid = $contentbyid;
    }

    /**
     * Returns the canned activity list the test configured, ignoring both arguments.
     *
     * @param string $identifier Ignored - the stub always returns the same list.
     * @param int $since Ignored - the caller (activity_lookup, in the real
     *                    thing) is what since-filters; this stub returns
     *                    exactly what the test configured it with.
     * @return array{success: bool, errorcode: string, technical: ?string, data: ?array}
     */
    public function get_modified_activities(string $identifier, int $since): array {
        return [
            'success' => true,
            'errorcode' => 'success',
            'technical' => null,
            'data' => ['activities' => $this->activities],
            'remoteerrorcode' => null,
        ];
    }

    /**
     * Returns the canned content payload configured for this cmid, or a not-found error.
     *
     * @param int $cmid
     * @return array{success: bool, errorcode: string, technical: ?string, data: ?array}
     */
    public function get_activity_content(int $cmid): array {
        if (!isset($this->contentbyid[$cmid])) {
            return [
                'success' => false,
                'errorcode' => 'remote',
                'technical' => 'stub_remote_client: no content configured for cmid ' . $cmid,
                'data' => null,
                'remoteerrorcode' => 'notfound',
            ];
        }

        return [
            'success' => true,
            'errorcode' => 'success',
            'technical' => null,
            'data' => ['contentjson' => json_encode($this->contentbyid[$cmid])],
            'remoteerrorcode' => null,
        ];
    }
}
