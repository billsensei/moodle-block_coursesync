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
 * Recreates a mod_url instance from url_activity_exporter's payload.
 *
 * Same shape as page_activity_handler (see that class for why this bypasses
 * add_moduleinfo()). Unlike page_add_instance()/resource_add_instance(),
 * url_add_instance() does NOT set course_modules.instance itself - that's
 * done explicitly below. (An earlier version of this class assumed it did,
 * by analogy with page/resource, without actually checking - it doesn't;
 * see activity_handler.php's docblock, which now says so explicitly.)
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_activity_handler implements activity_handler {
    /**
     * Creates the url course module and instance.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from url_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/url/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'url'], MUST_EXIST);

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

        // This function always rebuilds parameters/displayoptions itself
        // (see the class docblock re: the "parameters" fidelity gap) and
        // reads popupwidth/popupheight unconditionally, even when display
        // isn't "popup" - both are always set below regardless.
        $urldata = new \stdClass();
        $urldata->course = $courseid;
        $urldata->coursemodule = $cmid;
        $urldata->name = sanitizer::text($data['name'] ?? '');
        $urldata->intro = sanitizer::html($data['intro'] ?? '');
        $urldata->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $urldata->externalurl = sanitizer::url($data['externalurl'] ?? '');
        $urldata->display = sanitizer::integer($data['display'] ?? 0);
        $urldata->printintro = sanitizer::integer($data['printintro'] ?? 1, 1);
        $urldata->popupwidth = sanitizer::integer($data['popupwidth'] ?? 620, 620);
        $urldata->popupheight = sanitizer::integer($data['popupheight'] ?? 450, 450);

        $instanceid = url_add_instance($urldata, null);
        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'url');
        rebuild_course_cache($courseid, true);

        return $cmid;
    }
}
