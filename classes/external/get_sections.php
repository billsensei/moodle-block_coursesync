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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\util as external_util;
use core_external\external_value;

/**
 * Lists the sections of a course on the source site.
 *
 * The destination uses this to give copied activities the same section as they
 * have here, creating the sections they need. Only ordinary sections are
 * listed: a subsection's delegated section is carried by the subsection itself
 * (see get_activity), and numbering it here would let an activity land inside
 * an unrelated subsection.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_sections extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course id on this site.', VALUE_REQUIRED),
        ]);
    }

    /**
     * The course's ordinary sections, in order.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $DB;

        ['courseid' => $courseid] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $course = $DB->get_record('course', ['id' => $courseid]);

        if (!$course) {
            throw new \moodle_exception('errorcoursenotfound', 'block_coursesync');
        }

        // Our own permission first, as in the other read functions.
        $context = \context_course::instance($course->id);
        require_capability('block/coursesync:sync', $context);
        self::validate_context($context);

        $sections = [];

        foreach (get_fast_modinfo($course->id)->get_section_info_all() as $section) {
            // Ordinary sections only. A delegated section has a component.
            if (!empty($section->component)) {
                continue;
            }

            $name = $section->name === null ? '' : trim((string) $section->name);

            $sections[] = [
                'id' => (int) $section->id,
                'section' => (int) $section->section,
                // Empty for a section nobody has named, so the destination
                // keeps its own default name rather than copying "Topic 3".
                'name' => $name === '' ? '' : external_util::format_string($name, $context, true),
                'visible' => (bool) $section->visible,
            ];
        }

        return $sections;
    }

    /**
     * Describes the return value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Section id on the source site.'),
                'section' => new external_value(PARAM_INT, 'Section number on the source site; 0 is the general section.'),
                'name' => new external_value(PARAM_TEXT, 'The name a person gave the section, empty if it has none.'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the section is visible on the source site.'),
            ]),
            'The ordinary sections of the course, in order.'
        );
    }
}
