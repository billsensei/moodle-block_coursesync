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
 * Records and retrieves block_coursesync_synclog rows - one per "Sync now"
 * run, including runs that failed before ever calling the remote site (a
 * precondition failure is still something the teacher asked for and got a
 * result from, so it's still logged).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_history {
    /**
     * Records one sync run.
     *
     * @param int $blockinstanceid
     * @param int $courseid Destination course id.
     * @param int $userid Who triggered the sync.
     * @param array $result From sync_runner::run(), or block_coursesync::sync_precondition_failure().
     * @return int The new block_coursesync_synclog row id.
     */
    public static function record(int $blockinstanceid, int $courseid, int $userid, array $result): int {
        global $DB;

        $record = new \stdClass();
        $record->blockinstanceid = $blockinstanceid;
        $record->courseid = $courseid;
        $record->userid = $userid;
        $record->timecreated = time();
        $record->success = $result['success'] ? 1 : 0;
        $record->errorcode = $result['success'] ? null : $result['errorcode'];
        $record->createdcount = count($result['created']);
        $record->conflictcount = count($result['conflicts']);
        $record->failedcount = count($result['failed']);
        $record->unsupportedcount = count($result['unsupported']);
        $record->createdjson = json_encode($result['created']);
        $record->conflictsjson = json_encode($result['conflicts']);
        $record->failedjson = json_encode($result['failed']);
        $record->unsupportedjson = json_encode($result['unsupported']);

        return $DB->insert_record('block_coursesync_synclog', $record);
    }

    /**
     * Lists sync runs for one block instance, most recent first.
     *
     * @param int $blockinstanceid
     * @param int $limit
     * @return array<int, \stdClass>
     */
    public static function get_for_instance(int $blockinstanceid, int $limit = 50): array {
        global $DB;

        return $DB->get_records(
            'block_coursesync_synclog',
            ['blockinstanceid' => $blockinstanceid],
            'timecreated DESC',
            '*',
            0,
            $limit
        );
    }
}
