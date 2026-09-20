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
 * Handles mod_url.
 *
 * Like a page, a URL keeps its display preferences in a serialized blob, and
 * additionally a serialized map of variable substitutions. Both are unpacked on
 * the way out and rebuilt on the way in, so neither site has to understand the
 * other's storage format.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'url';
    }

    /**
     * SOURCE SIDE. The address, how it is shown, and any parameters.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the url table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $displayoptions = $this->unpack($instance->displayoptions);
        $parameters = $this->unpack($instance->parameters);

        return [
            'externalurl' => (string) $instance->externalurl,
            'display' => (string) $instance->display,
            'printintro' => (string) ($displayoptions['printintro'] ?? 0),
            'popupwidth' => (string) ($displayoptions['popupwidth'] ?? 620),
            'popupheight' => (string) ($displayoptions['popupheight'] ?? 450),
            // JSON rather than PHP serialisation, so the destination never has
            // to unserialize something another site wrote.
            'parameters' => json_encode($parameters),
        ];
    }

    /**
     * DESTINATION SIDE. Build the URL in a local course.
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

        require_once($CFG->dirroot . '/mod/url/lib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);
        // Rendered as a link by mod_url, so anything that is not a real web
        // address is refused rather than stored. A javascript: address here
        // would be script running on this site, chosen by the other one.
        $data->externalurl = $payload->setting_url('externalurl');

        if ($data->externalurl === '') {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorbadremoteurl', 'block_coursesync');
        }
        $data->display = $payload->setting_int('display', RESOURCELIB_DISPLAY_AUTO);
        $data->printintro = $payload->setting_int('printintro', 0);
        $data->popupwidth = $payload->setting_int('popupwidth', 620);
        $data->popupheight = $payload->setting_int('popupheight', 450);

        // Moodle rebuilds the parameters map from form fields named
        // parameter_N and variable_N, so the flat map is spread back out into
        // the shape it expects.
        $parameters = json_decode($payload->setting('parameters', '{}'), true);
        $index = 0;

        if (is_array($parameters)) {
            foreach ($parameters as $parameter => $variable) {
                $parametername = 'parameter_' . $index;
                $variablename = 'variable_' . $index;
                $data->$parametername = $parameter;
                $data->$variablename = $variable;
                $index++;
            }
        }

        $instanceid = \url_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Turn one of mod_url's serialized blobs into an array.
     *
     * @param string|null $serialized
     * @return array
     */
    protected function unpack(?string $serialized): array {
        if (empty($serialized)) {
            return [];
        }

        $unpacked = @unserialize($serialized, ['allowed_classes' => false]);

        return is_array($unpacked) ? $unpacked : [];
    }
}
