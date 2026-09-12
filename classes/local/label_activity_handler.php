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
 * Recreates a mod_label instance from label_activity_exporter's payload.
 *
 * Same shape as page_activity_handler, with one difference worth flagging
 * for whoever adds the next type: unlike page_add_instance()/url_add_instance()/
 * resource_add_instance(), label_add_instance() does NOT set
 * course_modules.instance itself - that's done explicitly below. Always
 * check the specific `<modname>_add_instance()` function's own body before
 * assuming either way.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class label_activity_handler implements activity_handler {
    /**
     * Creates the label course module and instance.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from label_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/label/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'label'], MUST_EXIST);

        $newcm = new \stdClass();
        $newcm->course = $courseid;
        $newcm->module = $moduleid;
        $newcm->instance = 0;
        $newcm->section = 0;
        $newcm->idnumber = $idnumber;
        $newcm->visible = 1;
        $newcm->visibleold = 1;
        $newcm->visibleoncoursepage = 1;
        $newcm->groupmode = 0;
        $newcm->groupingid = 0;
        $newcm->completion = 0;
        $newcm->showdescription = 0;

        $cmid = add_course_module($newcm);

        $labeldata = new \stdClass();
        $labeldata->course = $courseid;
        $labeldata->coursemodule = $cmid;
        $labeldata->intro = sanitizer::html($data['intro'] ?? '');
        $labeldata->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        // Core's get_label_name() (called by label_add_instance()) reads
        // ->name directly with no isset() guard - always present (blank) on
        // a real form submission, so it's always set here too.
        $labeldata->name = '';

        $instanceid = label_add_instance($labeldata);
        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'label');
        rebuild_course_cache($courseid, true);

        return $cmid;
    }
}
