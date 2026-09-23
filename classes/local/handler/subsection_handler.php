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

namespace block_coursesync\local\handler;

use block_coursesync\activity_payload;

/**
 * Handles mod_subsection: a section inside a section.
 *
 * A subsection is a module with little more than a name, which owns a
 * "delegated" course section; the activities inside it live in that section.
 * subsection_add_instance() creates both, as it does when a teacher adds one.
 *
 * The subsection only brings itself. The activities inside it are copied as
 * activities in their own right, and each is placed in this course's copy of
 * its subsection - see activity_handler::target_section(). A run copies any
 * subsections before anything else, so ticking a subsection and what is in it
 * in the same sync puts everything where it belongs.
 *
 * A changed subsection is updated in place, never replaced. Deleting a
 * subsection deletes its section and everything in it (subsection_delete_instance()
 * forces it), and the check for people's work only looks at the subsection
 * itself, which never holds any - so a replacement would quietly take every
 * activity inside with it. Its name is all there is to update anyway.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subsection_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'subsection';
    }

    /**
     * SOURCE SIDE. Nothing beyond the common envelope: the name is the
     * subsection.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the subsection table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        return [];
    }

    /**
     * DESTINATION SIDE. Create the subsection and its section.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload what the source site sent
     * @param string $idnumber the ID number that marks this as synced
     * @return \stdClass the new course_modules record
     */
    public function create_from_remote_data(
        \stdClass $course,
        activity_payload $payload,
        string $idnumber
    ): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/subsection/lib.php');

        // A subsection is never inside another, so this is always an
        // ordinary section.
        $sectionnum = $this->target_section($course, $payload);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        $instanceid = \subsection_add_instance($data);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * A subsection is updated in place; see the class comment.
     *
     * @return bool
     */
    public function updates_in_place(): bool {
        return true;
    }

    /**
     * DESTINATION SIDE. Rename this course's copy.
     *
     * Through Moodle's own rename, as a teacher's would go: it renames the
     * subsection, and mod_subsection's after_cm_name_edited hook renames its
     * section to match - the section's name being the one shown
     * (subsection_get_coursemodule_info()). Renaming the section instead goes
     * the other way round (sectiondelegatemodule::preprocess_section_name()),
     * so on the source either path updates the subsection's timemodified and
     * change detection sees it.
     *
     * @param \stdClass $cm the copy here
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    public function update_in_place(\stdClass $cm, activity_payload $payload): void {
        \core_courseformat\formatactions::cm($cm->course)->rename((int) $cm->id, $payload->name);
    }
}
