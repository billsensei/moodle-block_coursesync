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

namespace block_coursesync\external;

use block_coursesync\local\activity_lookup;
use block_coursesync\local\course_lookup;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * block_coursesync_get_modified_activities external function.
 *
 * Lists course modules in a course modified after a given time. Metadata
 * only (course module id, activity type, name, idnumber, and when it was
 * last modified) - no content bodies. Actually pulling activity content is
 * a later phase.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_modified_activities extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'identifier' => new external_value(PARAM_RAW, 'Course ID or shortname on this (source) site.'),
            'since' => new external_value(PARAM_INT, 'Unix timestamp; only activities modified after this are ' .
                'returned. 0 means everything.', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Resolves the course, then lists its modified activities.
     *
     * @param string $identifier
     * @param int $since
     * @return array{activities: array}
     */
    public static function execute(string $identifier, int $since = 0): array {
        [
            'identifier' => $identifier,
            'since' => $since,
        ] = self::validate_parameters(self::execute_parameters(), [
            'identifier' => $identifier,
            'since' => $since,
        ]);

        global $USER;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('block/coursesync:sync', $context);

        $course = course_lookup::resolve($identifier, $USER->id);

        return [
            'activities' => activity_lookup::get_modified_since((int) $course->id, $since),
        ];
    }

    /**
     * Describes the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'activities' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id on the source site.'),
                    'modname' => new external_value(PARAM_PLUGIN, 'Activity type, e.g. "page".'),
                    'name' => new external_value(PARAM_TEXT, 'Activity name.'),
                    'idnumber' => new external_value(PARAM_RAW, 'Activity idnumber, or an empty string if unset.'),
                    'timemodified' => new external_value(PARAM_INT, 'Unix timestamp of the activity\'s own last edit.'),
                ])
            ),
        ]);
    }
}
