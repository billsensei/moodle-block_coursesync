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
 * Handles mod_folder, a set of files shown together.
 *
 * A folder is a resource with more than one file in it. It keeps them all in
 * one area under a single item id, and unlike mod_resource none of them is
 * special, so there is nothing to do once they have been copied. Sub-folders
 * come across on their own, because a file's path is part of its identity and
 * is carried in the payload alongside its name.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class folder_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'folder';
    }

    /**
     * A folder keeps everything it holds in one area.
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'content', 'itemid' => 0],
        ];
    }

    /**
     * SOURCE SIDE. How the folder is presented.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the folder table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        return [
            'display' => (string) ($instance->display ?? 0),
            'showexpanded' => (string) ($instance->showexpanded ?? 1),
            'showdownloadfolder' => (string) ($instance->showdownloadfolder ?? 1),
            'forcedownload' => (string) ($instance->forcedownload ?? 1),
        ];
    }

    /**
     * DESTINATION SIDE. Build the folder in a local course.
     *
     * The folder is created empty and the syncer writes its files afterwards,
     * for the same reason mod_resource does: a file area is addressed by
     * context id, and the module's context only exists once the module does.
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

        require_once($CFG->dirroot . '/mod/folder/lib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);
        $data->display = $payload->setting_int('display', 0);
        $data->showexpanded = $payload->setting_int('showexpanded', 1);
        $data->showdownloadfolder = $payload->setting_int('showdownloadfolder', 1);
        $data->forcedownload = $payload->setting_int('forcedownload', 1);
        $data->revision = 1;
        // Read as a draft area id to move files from. There is no form and no
        // draft area here, so it is told there is nothing to move and the files
        // are written straight into the real area afterwards.
        $data->files = 0;

        $instanceid = \folder_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }
}
