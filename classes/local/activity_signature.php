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
 * Change signals used to tell whether an activity has been edited.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

/**
 * Derives a value that changes when an activity's content changes.
 *
 * Moodle has no universal content hash across module types, so this uses two
 * methods and records which one produced a given value:
 *
 * - timemodified, when the module's own table has a non-empty column of that
 *   name. Almost every core module maintains one, and it is cheap and exact.
 * - hash, otherwise: a sha1 over the module instance record with the columns
 *   that change without the content changing removed. The hash is only ever
 *   compared with an earlier hash taken on the same site, so it does not need
 *   to be stable across sites or Moodle versions.
 *
 * Both signals describe the module instance only. Course-module level changes
 * such as hiding an activity or moving it between sections are deliberately
 * not part of the signal: including them would make an ordinary local tidy-up
 * look like an edit and raise conflicts for changes that carry no content.
 */
class activity_signature {
    /** @var string The signal is the module's own timemodified column. */
    public const METHOD_TIMEMODIFIED = 'timemodified';

    /** @var string The signal is a hash of the module instance record. */
    public const METHOD_HASH = 'hash';

    /**
     * Columns excluded from the hash because they move without the content moving.
     *
     * @var string[]
     */
    private const VOLATILE_COLUMNS = ['id', 'course', 'timecreated', 'timemodified'];

    /**
     * Returns the change signal for a course module.
     *
     * @param string $modname Activity module name, for example 'quiz'.
     * @param int $instanceid Id in that module's own table.
     * @return array With keys 'signal' and 'method'; an empty signal means the instance is gone.
     */
    public static function for_instance(string $modname, int $instanceid): array {
        global $DB;

        $record = $DB->get_record($modname, ['id' => $instanceid]);
        if (!$record) {
            return ['signal' => '', 'method' => self::METHOD_HASH];
        }

        if (!empty($record->timemodified)) {
            return [
                'signal' => (string) $record->timemodified,
                'method' => self::METHOD_TIMEMODIFIED,
            ];
        }

        return [
            'signal' => self::hash_record($record),
            'method' => self::METHOD_HASH,
        ];
    }

    /**
     * Returns the change signal for a local course module id.
     *
     * @param int $cmid Course module id on this site.
     * @return array|null Keys 'signal' and 'method', or null if the module no longer exists.
     */
    public static function for_local_cmid(int $cmid): ?array {
        global $DB;

        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.instance, m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid AND cm.deletioninprogress = 0",
            ['cmid' => $cmid]
        );

        if (!$cm) {
            return null;
        }

        return self::for_instance($cm->modname, (int) $cm->instance);
    }

    /**
     * Hashes a module instance record.
     *
     * @param \stdClass $record Row from the module's own table.
     * @return string A sha1 hash.
     */
    private static function hash_record(\stdClass $record): string {
        $fields = (array) $record;
        foreach (self::VOLATILE_COLUMNS as $column) {
            unset($fields[$column]);
        }
        ksort($fields);

        return sha1(serialize($fields));
    }
}
