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
 * Deleting and moving course modules on Moodle 5.0 to 5.3.
 *
 * Moodle 5.2 deprecated course_delete_module() and moveto_module() in favour
 * of course format actions, which 5.0 and 5.1 do not have. These use the new
 * actions where they exist and the old functions where they do not, so no
 * version logs a deprecation.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_modules {
    /**
     * Delete a course module.
     *
     * @param int $cmid
     * @param bool $async true to have a task do it later
     * @return void
     */
    public static function delete(int $cmid, bool $async = false): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        if (self::has_format_actions()) {
            $courseid = \context_module::instance($cmid)->get_course_context()->instanceid;
            \core_courseformat\formatactions::cm($courseid)->delete($cmid, $async);
            return;
        }

        \course_delete_module($cmid, $async);
    }

    /**
     * Move a course module to a section, before another module or to the end.
     *
     * @param \cm_info $cm the module to move, as modinfo knows it
     * @param \stdClass $section the section row to move it to
     * @param \stdClass|null $beforemod the module it goes in front of
     * @return void
     */
    public static function move(\cm_info $cm, \stdClass $section, ?\stdClass $beforemod = null): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        if (self::has_format_actions()) {
            $actions = \core_courseformat\formatactions::cm($cm->course);

            if ($beforemod) {
                $actions->move_before((int) $cm->id, (int) $beforemod->id);
            } else {
                $actions->move_end_section((int) $cm->id, (int) $section->id);
            }

            return;
        }

        \moveto_module($cm, $section, $beforemod);
    }

    /**
     * Whether this Moodle has the course format actions for modules (5.2+).
     *
     * @return bool
     */
    protected static function has_format_actions(): bool {
        return method_exists(\core_courseformat\local\cmactions::class, 'move_before');
    }
}
