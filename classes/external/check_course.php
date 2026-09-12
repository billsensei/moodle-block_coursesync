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

use block_coursesync\local\course_lookup;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * block_coursesync_check_course external function.
 *
 * Confirms a course identifier resolves to a real course on this (source)
 * site and that the token's user can access it. Used to validate course
 * mapping when it's set up on the destination site, and to resolve a
 * shortname to a stable numeric course ID for later calls.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_course extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'identifier' => new external_value(PARAM_RAW, 'Course ID or shortname on this (source) site.'),
        ]);
    }

    /**
     * Resolves and access-checks the course.
     *
     * @param string $identifier
     * @return array{courseid: int, fullname: string, shortname: string}
     */
    public static function execute(string $identifier): array {
        ['identifier' => $identifier] = self::validate_parameters(self::execute_parameters(), [
            'identifier' => $identifier,
        ]);

        global $USER;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('block/coursesync:sync', $context);

        $course = course_lookup::resolve($identifier, $USER->id);

        return [
            'courseid' => (int) $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
        ];
    }

    /**
     * Describes the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'The course id.'),
            'fullname' => new external_value(PARAM_TEXT, 'The course full name.'),
            'shortname' => new external_value(PARAM_TEXT, 'The course shortname.'),
        ]);
    }
}
