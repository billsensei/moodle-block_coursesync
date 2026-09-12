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
 * Resolves a "course ID or shortname" identifier to a real course on this
 * (source) site, and checks the calling user can access it.
 *
 * Shared by block_coursesync_check_course and
 * block_coursesync_get_modified_activities so both agree on exactly what
 * "the mapped course" means and enforce the same access check.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_lookup {
    /**
     * Resolves and access-checks a course identifier.
     *
     * @param string $identifier Numeric course ID, or a shortname.
     * @param int $userid The user the access check is against (the web service token's owner).
     * @return \stdClass The course record.
     * @throws \moodle_exception errorcode one of emptycourseidentifier, coursenotfound, coursenotaccessible.
     */
    public static function resolve(string $identifier, int $userid): \stdClass {
        global $DB;

        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new \moodle_exception('emptycourseidentifier', 'block_coursesync');
        }

        if (ctype_digit($identifier)) {
            $course = $DB->get_record('course', ['id' => (int) $identifier]);
        } else {
            $course = $DB->get_record('course', ['shortname' => $identifier]);
        }

        if (!$course) {
            throw new \moodle_exception('coursenotfound', 'block_coursesync');
        }

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        if (!can_access_course($course, $user)) {
            throw new \moodle_exception('coursenotaccessible', 'block_coursesync');
        }

        return $course;
    }

    /**
     * Resolves and access-checks a course module, by id, on this (source) site.
     *
     * @param int $cmid
     * @param int $userid The user the access check is against (the web service token's owner).
     * @return \cm_info
     * @throws \moodle_exception errorcode one of coursemodulenotfound, coursenotaccessible.
     */
    public static function resolve_cm(int $cmid, int $userid): \cm_info {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course', IGNORE_MISSING);
        if (!$cm) {
            throw new \moodle_exception('coursemodulenotfound', 'block_coursesync');
        }

        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        if (!can_access_course($course, $user)) {
            throw new \moodle_exception('coursenotaccessible', 'block_coursesync');
        }

        // The row above confirms the course module exists at all; get_cm()
        // itself throws (a core, not block_coursesync, exception) in the rare
        // case it exists in the DB but modinfo somehow doesn't know about it.
        return get_fast_modinfo($course)->get_cm($cmid);
    }
}
