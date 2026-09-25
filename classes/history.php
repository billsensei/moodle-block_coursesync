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
    public const STATUS_OK = 'ok';

    /** @var string The run finished, but something needs a person to look at it. */
    public const STATUS_REVIEW = 'review';

    /** @var string The run could not finish. */
    public const STATUS_FAILED = 'failed';

    /** @var string A run that copied activities. */
    public const KIND_ACTIVITIES = 'activities';

    /** @var string A run that pulled students' grades. */
    public const KIND_GRADES = 'grades';

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

        // An update leaves a new local copy behind just as a create does, and
        // is what was_pulled_here() and pulled_at() must find from then on.
        $pulled = array_merge(
            self::items_with_outcome($result, 'created'),
            self::items_with_outcome($result, 'updated')
        );
        $conflicts = self::items_with_outcome($result, 'conflict');
        $others = array_merge(
            self::items_with_outcome($result, 'skipped'),
            self::items_with_outcome($result, 'failed')
        );

        $record = (object) [
            'blockinstanceid' => $blockinstanceid,
            'courseid' => $courseid,
            'userid' => $userid,
            'kind' => self::KIND_ACTIVITIES,
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
     * Write a finished grade pull to the history.
     *
     * Only counts, per activity, are kept - never which students or what
     * grades. The results page shows those at the time; keeping them here
     * would put a second copy of students' grades where the gradebook's own
     * history and privacy handling do not reach.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param int $userid who started the pull
     * @param int $timestarted
     * @param grade_pull_result $result
     * @return int the new history row id
     */
    public static function record_grade_pull(
        int $blockinstanceid,
        int $courseid,
        int $userid,
        int $timestarted,
        grade_pull_result $result
    ): int {
        global $DB;

        $counts = $result->counts();

        if (!$result->success) {
            $status = self::STATUS_FAILED;
        } else if ($counts[grade_pull_result::CONFLICT] > 0) {
            $status = self::STATUS_REVIEW;
        } else {
            $status = self::STATUS_OK;
        }

        $record = (object) [
            'blockinstanceid' => $blockinstanceid,
            'courseid' => $courseid,
            'userid' => $userid,
            'kind' => self::KIND_GRADES,
            'timestarted' => $timestarted,
            'timefinished' => time(),
            'status' => $status,
            'errorkey' => $result->success ? null : $result->errorkey,
            'since' => 0,
            'lastsyncmoved' => 0,
            'pulledcount' => $counts[grade_pull_result::ADD] + $counts[grade_pull_result::UPDATE],
            'conflictcount' => $counts[grade_pull_result::CONFLICT],
            'skippedcount' => $counts[grade_pull_result::SKIPPED],
            'failedcount' => 0,
            // One row per activity; see grade_pull_result::by_activity().
            'pulled' => json_encode(array_values($result->by_activity())),
            'conflicts' => json_encode([]),
            'others' => json_encode([]),
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
        return self::pulled_at($blockinstanceid, $remotecmid, $localcmid) !== null;
    }

    /**
     * When did the run that pulled this local copy start?
     *
     * That is what a copy is compared against to tell whether the source has
     * changed since: the run asked the source for it after this time, so
     * anything modified later is newer than the copy.
     *
     * @param int $blockinstanceid
     * @param int $remotecmid course module id on the source site
     * @param int $localcmid course module id on this site
     * @return int|null null when no run pulled it
     */
    public static function pulled_at(int $blockinstanceid, int $remotecmid, int $localcmid): ?int {
        global $DB;

        // Grade pulls list activities too, but they did not copy them.
        $records = $DB->get_records(
            'block_coursesync_run',
            ['blockinstanceid' => $blockinstanceid, 'kind' => self::KIND_ACTIVITIES],
            'timestarted DESC, id DESC',
            'id, timestarted, pulled',
            0,
            200
        );

        foreach ($records as $record) {
            foreach (self::decode($record->pulled) as $item) {
                if (
                    (int) ($item['remotecmid'] ?? 0) === $remotecmid
                        && (int) ($item['localcmid'] ?? 0) === $localcmid
                ) {
                    return (int) $record->timestarted;
                }
            }
        }

        return null;
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
