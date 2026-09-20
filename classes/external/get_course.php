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
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Resolves a course reference on the source site.
 *
 * The destination site lets an administrator type either a numeric course id or
 * a shortname; this turns whichever they typed into the course, so the mapping
 * can be confirmed before it is saved.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseref' => new external_value(
                PARAM_RAW_TRIMMED,
                'A course id or shortname on this site.',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Find the course and confirm the caller may sync it.
     *
     * @param string $courseref a course id or shortname
     * @return array
     */
    public static function execute(string $courseref): array {
        ['courseref' => $courseref] = self::validate_parameters(self::execute_parameters(), [
            'courseref' => $courseref,
        ]);

        $course = self::find_course($courseref);

        if ($course === null) {
            throw new \moodle_exception('errorcoursenotfound', 'block_coursesync');
        }

        // The capability is checked in the course's own context, so an
        // administrator can grant sync rights site-wide or course by course.
        // It is checked before validate_context() so that a missing sync
        // permission is reported as exactly that, rather than as the more
        // confusing "course not accessible" that require_login() would raise.
        $context = \context_course::instance($course->id);
        require_capability('block/coursesync:sync', $context);

        // Note that validate_context() calls require_login() for the course,
        // which is why the sync account also needs moodle/course:view: it is
        // never enrolled in the courses it reads. Keeping this call means a
        // token restricted to a particular context is still honoured.
        self::validate_context($context);

        return [
            'id' => (int) $course->id,
            'shortname' => $course->shortname,
            'fullname' => format_string($course->fullname, true, ['context' => $context]),
            'visible' => (bool) $course->visible,
            'activitycount' => count(get_fast_modinfo($course->id)->get_cms()),
        ];
    }

    /**
     * Look a course up by id, then by shortname.
     *
     * The site front page is never a valid target: it is not a course anyone
     * syncs into or out of.
     *
     * @param string $courseref
     * @return \stdClass|null
     */
    protected static function find_course(string $courseref): ?\stdClass {
        global $DB, $SITE;

        $course = null;

        if ($courseref !== '' && ctype_digit($courseref)) {
            $course = $DB->get_record('course', ['id' => (int) $courseref]);
        }

        if (!$course) {
            $course = $DB->get_record('course', ['shortname' => $courseref]);
        }

        if (!$course || $course->id == $SITE->id) {
            return null;
        }

        return $course;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Course id on the source site.'),
            'shortname' => new external_value(PARAM_TEXT, 'Course shortname.'),
            'fullname' => new external_value(PARAM_TEXT, 'Course full name.'),
            'visible' => new external_value(PARAM_BOOL, 'Whether the course is visible on the source site.'),
            'activitycount' => new external_value(PARAM_INT, 'How many activities the course currently holds.'),
        ]);
    }
}
