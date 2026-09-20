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
 * Handles mod_page, the first activity type Course Sync supports.
 *
 * This is the worked example for the pattern described in activity_handler.
 * A page is deliberately the simplest useful case: a name, a description and a
 * body of HTML, with no grades, no attempts and no subplugins.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'page';
    }

    /**
     * SOURCE SIDE. What a page needs beyond the common envelope.
     *
     * mod_page keeps its display preferences in a serialized blob. That is an
     * implementation detail of one version of one module, so it is unpacked
     * here and sent as plain values; the destination lets page_add_instance()
     * repack them in whatever shape its own version expects.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the page table
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
            'content' => (string) $instance->content,
            'contentformat' => (string) $instance->contentformat,
            'display' => (string) $instance->display,
            'printintro' => (string) ($displayoptions['printintro'] ?? 0),
            'printlastmodified' => (string) ($displayoptions['printlastmodified'] ?? 1),
            'popupwidth' => (string) ($displayoptions['popupwidth'] ?? 620),
            'popupheight' => (string) ($displayoptions['popupheight'] ?? 450),
        ];
    }

    /**
     * DESTINATION SIDE. Build the page in a local course.
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

        require_once($CFG->dirroot . '/mod/page/lib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);
        $data->contentformat = $payload->setting_int('contentformat', FORMAT_HTML);
        // The body is HTML from another site, so it is cleaned before it is
        // stored rather than trusted to be safe when rendered.
        $data->content = $payload->setting_html('content', $data->contentformat);
        $data->display = $payload->setting_int('display', RESOURCELIB_DISPLAY_OPEN);
        $data->printintro = $payload->setting_int('printintro', 0);
        $data->printlastmodified = $payload->setting_int('printlastmodified', 1);
        $data->popupwidth = $payload->setting_int('popupwidth', 620);
        $data->popupheight = $payload->setting_int('popupheight', 450);
        $data->legacyfiles = 0;
        $data->legacyfileslast = null;
        $data->revision = 1;

        // No form is passed, so page_add_instance() takes content and
        // contentformat straight from the data and skips its draft file
        // handling. Embedded files are not copied yet; the syncer warns when a
        // payload refers to any.
        $instanceid = \page_add_instance($data, null);

        if (!$instanceid) {
            // Do not leave a course module pointing at nothing.
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        // The module sets this itself, but a handler should not rely on another
        // module's internals to leave the course module consistent.
        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }
}
