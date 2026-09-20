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
 * Handles mod_resource, the uploaded-file activity.
 *
 * This is the first handler whose activity carries files of its own. It does
 * not fetch them: it declares its file area in get_file_areas(), and the syncer
 * hands the transfer to block_coursesync\local\file_sync once the activity
 * exists and has a context to store them against.
 *
 * The order matters. A file area is addressed by context id, and a module's
 * context does not exist until the course module does, so files can only be
 * written after create_from_remote_data() has returned.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'resource';
    }

    /**
     * A resource keeps its uploaded files in one area.
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'content', 'itemid' => 0],
        ];
    }

    /**
     * SOURCE SIDE. How the resource is presented.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the resource table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $displayoptions = [];

        if (!empty($instance->displayoptions)) {
            $unpacked = @unserialize($instance->displayoptions, ['allowed_classes' => false]);

            if (is_array($unpacked)) {
                $displayoptions = $unpacked;
            }
        }

        return [
            'display' => (string) $instance->display,
            'printintro' => (string) ($displayoptions['printintro'] ?? 0),
            'popupwidth' => (string) ($displayoptions['popupwidth'] ?? 620),
            'popupheight' => (string) ($displayoptions['popupheight'] ?? 450),
            'showsize' => (string) ($displayoptions['showsize'] ?? 0),
            'showtype' => (string) ($displayoptions['showtype'] ?? 0),
            'showdate' => (string) ($displayoptions['showdate'] ?? 0),
            'filterfiles' => (string) ($instance->filterfiles ?? 0),
        ];
    }

    /**
     * DESTINATION SIDE. Build the resource in a local course.
     *
     * The activity is created empty. Its files are written afterwards by the
     * syncer, which then calls post_files() to mark which one the resource
     * actually points at.
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

        require_once($CFG->dirroot . '/mod/resource/lib.php');
        require_once($CFG->dirroot . '/mod/resource/locallib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);
        $data->display = $payload->setting_int('display', RESOURCELIB_DISPLAY_AUTO);
        $data->printintro = $payload->setting_int('printintro', 0);
        $data->popupwidth = $payload->setting_int('popupwidth', 620);
        $data->popupheight = $payload->setting_int('popupheight', 450);
        $data->showsize = $payload->setting_int('showsize', 0);
        $data->showtype = $payload->setting_int('showtype', 0);
        $data->showdate = $payload->setting_int('showdate', 0);
        $data->filterfiles = $payload->setting_int('filterfiles', 0);
        $data->tobemigrated = 0;
        $data->legacyfiles = 0;
        $data->legacyfileslast = null;
        $data->revision = 1;
        // Moodle reads this as a draft area id. There is no
        // form and no draft area here, so it is told there is nothing to move;
        // the files are written straight into the real area afterwards.
        $data->files = 0;

        $instanceid = \resource_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Mark which of the stored files the resource opens.
     *
     * mod_resource decides this by sort order: the file with sort order 1 is
     * the one it serves. Called by the syncer once the files are in place.
     *
     * @param \stdClass $cm the course module
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    public function post_files(\stdClass $cm, activity_payload $payload): void {
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);

        if ($files === []) {
            return;
        }

        // Prefer whichever file the source site had marked as its main one, and
        // fall back to the first if the source did not say.
        $main = null;

        foreach ($payload->files as $file) {
            if ((int) ($file['sortorder'] ?? 0) === 1) {
                $main = $file;
                break;
            }
        }

        if ($main === null) {
            $first = reset($files);
            $main = [
                'filepath' => $first->get_filepath(),
                'filename' => $first->get_filename(),
            ];
        }

        file_set_sortorder(
            $context->id,
            'mod_resource',
            'content',
            0,
            (string) $main['filepath'],
            (string) $main['filename'],
            1
        );
    }
}
