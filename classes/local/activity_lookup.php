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
 * Lists course modules modified after a given time, across any activity
 * type - metadata only, no content. Phase 3 has no per-type activity
 * handlers yet, so this deliberately doesn't need one either.
 *
 * Moodle has no central "last modified" record for a course module -
 * course_modules itself only tracks when it was added, not edited - and
 * not every activity table even has a timemodified column. This looks for
 * one generically on whichever table the activity's own modname owns,
 * and falls back to the course module's added time if there isn't one.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_lookup {
    /**
     * Lists activities in a course modified after $since.
     *
     * @param int $courseid
     * @param int $since Unix timestamp; 0 means "everything".
     * @return array<int, array{cmid: int, modname: string, name: string, idnumber: string, timemodified: int}>
     */
    public static function get_modified_since(int $courseid, int $since): array {
        $modinfo = get_fast_modinfo($courseid);

        $results = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }

            $timemodified = self::get_timemodified($cm->modname, $cm->instance) ?? (int) $cm->added;
            if ($timemodified <= $since) {
                continue;
            }

            $results[] = [
                'cmid' => (int) $cm->id,
                'modname' => $cm->modname,
                'name' => $cm->get_formatted_name(),
                'idnumber' => (string) ($cm->idnumber ?? ''),
                'timemodified' => $timemodified,
            ];
        }

        usort($results, fn($a, $b) => $a['timemodified'] <=> $b['timemodified']);

        return $results;
    }

    /**
     * Looks up an activity's own timemodified column, if its table has one.
     *
     * @param string $modname
     * @param int $instanceid
     * @return int|null Null if that activity type's table has no timemodified column.
     */
    protected static function get_timemodified(string $modname, int $instanceid): ?int {
        global $DB;

        $columns = $DB->get_columns($modname);
        if (!isset($columns['timemodified'])) {
            return null;
        }

        $value = $DB->get_field($modname, 'timemodified', ['id' => $instanceid]);

        return $value === false ? null : (int) $value;
    }
}
