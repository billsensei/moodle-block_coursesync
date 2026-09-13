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

use block_coursesync\local\activity_exporter_registry;
use block_coursesync\local\course_lookup;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * block_coursesync_get_activity_content external function.
 *
 * Returns the full settings/content payload needed to recreate one course
 * module locally - for whichever activity types this plugin currently
 * supports (see activity_exporter_registry; Page, URL, Label, Resource, and
 * Forum as of Phase 5, Assignment and H5P as of Phase 9, and Quiz as of Phase 10).
 *
 * The type-specific payload travels as a JSON string (contentjson) rather
 * than a fixed external_single_structure, because each activity type's
 * payload has a different shape and this function's own return structure
 * can't change per call. It's built by that type's activity_exporter and
 * meant to be read back only by that same type's activity_handler.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_activity_content extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id on this (source) site.'),
        ]);
    }

    /**
     * Resolves the course module and exports its content.
     *
     * @param int $cmid
     * @return array{cmid: int, modname: string, idnumber: string, contentjson: string}
     */
    public static function execute(int $cmid): array {
        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        global $USER;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('block/coursesync:sync', $context);

        $cm = course_lookup::resolve_cm($cmid, $USER->id);

        if (!activity_exporter_registry::is_supported($cm->modname)) {
            throw new \moodle_exception('activitytypenotsupported', 'block_coursesync', '', $cm->modname);
        }

        $data = activity_exporter_registry::get_exporter($cm->modname)->export($cm);

        return [
            'cmid' => (int) $cm->id,
            'modname' => $cm->modname,
            'idnumber' => (string) ($cm->idnumber ?? ''),
            'contentjson' => json_encode($data),
        ];
    }

    /**
     * Describes the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module id on the source site.'),
            'modname' => new external_value(PARAM_PLUGIN, 'Activity type, e.g. "page".'),
            'idnumber' => new external_value(PARAM_RAW, 'The source activity\'s own idnumber, or an empty string.'),
            'contentjson' => new external_value(PARAM_RAW, 'JSON-encoded, activity-type-specific settings/content.'),
        ]);
    }
}
