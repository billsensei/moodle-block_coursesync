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

/**
 * Reads and writes the record of past sync runs.
 *
 * Every run is written down, including ones that pulled nothing, so the history
 * answers "was this ever tried?" as well as "what did it do?".
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history {
    /** @var string Everything the run set out to do, it did. */
    const STATUS_OK = 'ok';

    /** @var string The run finished, but something needs a person to look at it. */
    const STATUS_REVIEW = 'review';

    /** @var string The run could not finish. */
    const STATUS_FAILED = 'failed';

    /**
     * Write a finished run to the history.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param int $userid who started the run
     * @param int $timestarted
     * @param sync_result $result what the run did
     * @return int the new history row id
     */
    public static function record(
        int $blockinstanceid,
        int $courseid,
        int $userid,
        int $timestarted,
        sync_result $result
    ): int {
        global $DB;

        $pulled = self::items_with_outcome($result, 'created');
        $conflicts = self::items_with_outcome($result, 'conflict');
        $others = array_merge(
            self::items_with_outcome($result, 'skipped'),
            self::items_with_outcome($result, 'failed')
        );

        $record = (object) [
            'blockinstanceid' => $blockinstanceid,
            'courseid' => $courseid,
            'userid' => $userid,
            'timestarted' => $timestarted,
            'timefinished' => time(),
            'status' => self::status_for($result),
            'errorkey' => $result->success ? null : $result->errorkey,
            'since' => $result->since,
            'lastsyncmoved' => $result->lastsyncupdated ? 1 : 0,
            'pulledcount' => count($pulled),
            'conflictcount' => count($conflicts),
            'skippedcount' => $result->count('skipped'),
            'failedcount' => $result->count('failed'),
            'pulled' => json_encode($pulled),
            'conflicts' => json_encode($conflicts),
            'others' => json_encode($others),
        ];

        return (int) $DB->insert_record('block_coursesync_run', $record);
    }

    /**
     * Which overall status describes this run.
     *
     * A run that flagged anything is "review" rather than "ok", so the history
     * list shows at a glance that something is waiting for a person.
     *
     * @param sync_result $result
     * @return string
     */
    protected static function status_for(sync_result $result): string {
        if (!$result->success) {
            return self::STATUS_FAILED;
        }

        if ($result->count('conflict') > 0 || $result->count('failed') > 0) {
            return self::STATUS_REVIEW;
        }

        return self::STATUS_OK;
    }

    /**
     * The items of one outcome, trimmed to what is worth storing.
     *
     * @param sync_result $result
     * @param string $outcome
     * @return array[]
     */
    protected static function items_with_outcome(sync_result $result, string $outcome): array {
        $items = [];

        foreach ($result->items as $item) {
            if ($item['outcome'] !== $outcome) {
                continue;
            }

            $items[] = [
                'name' => $item['name'],
                'modname' => $item['modname'],
                'remotecmid' => $item['remotecmid'],
                'localcmid' => $item['localcmid'],
                'outcome' => $item['outcome'],
                'detail' => $item['detail'],
                'notes' => $item['notes'],
            ];
        }

        return $items;
    }

    /**
     * Past runs for a block instance, most recent first.
     *
     * @param int $blockinstanceid
     * @param int $limit 0 for all of them
     * @return \stdClass[] each with pulled, conflicts and others decoded
     */
    public static function get_runs(int $blockinstanceid, int $limit = 50): array {
        global $DB;

        $records = $DB->get_records(
            'block_coursesync_run',
            ['blockinstanceid' => $blockinstanceid],
            'timestarted DESC, id DESC',
            '*',
            0,
            $limit
        );

        foreach ($records as $record) {
            $record->pulled = self::decode($record->pulled);
            $record->conflicts = self::decode($record->conflicts);
            $record->others = self::decode($record->others);
        }

        return array_values($records);
    }

    /**
     * Was this remote activity pulled into this course by an earlier run?
     *
     * This is what tells a conflict where the local copy came from: an activity
     * this plugin created and has since diverged is a different problem from one
     * a teacher made that happens to carry the same ID number.
     *
     * @param int $blockinstanceid
     * @param int $remotecmid course module id on the source site
     * @param int $localcmid course module id on this site
     * @return bool
     */
    public static function was_pulled_here(int $blockinstanceid, int $remotecmid, int $localcmid): bool {
        global $DB;

        $records = $DB->get_records(
            'block_coursesync_run',
            ['blockinstanceid' => $blockinstanceid],
            'timestarted DESC, id DESC',
            'id, pulled',
            0,
            200
        );

        foreach ($records as $record) {
            foreach (self::decode($record->pulled) as $item) {
                if (
                    (int) ($item['remotecmid'] ?? 0) === $remotecmid
                        && (int) ($item['localcmid'] ?? 0) === $localcmid
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Remove the history belonging to a block instance.
     *
     * @param int $blockinstanceid
     * @return void
     */
    public static function delete_for_block_instance(int $blockinstanceid): void {
        global $DB;

        $DB->delete_records('block_coursesync_run', ['blockinstanceid' => $blockinstanceid]);
    }

    /**
     * Turn a stored JSON list back into an array.
     *
     * @param string|null $json
     * @return array[]
     */
    protected static function decode(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
