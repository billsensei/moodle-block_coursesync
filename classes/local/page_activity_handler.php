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
 * Recreates a mod_page instance from page_activity_exporter's payload.
 *
 * Deliberately uses the same low-level building blocks mod_page's own "Add
 * an activity" form ends up calling - add_course_module(),
 * page_add_instance(), course_add_cm_to_section(), rebuild_course_cache() -
 * rather than the generic course/modlib.php add_moduleinfo() helper. This
 * data isn't a form submission (there's no $USER capability context that
 * makes sense to check against remote data, and no mform to hand
 * page_add_instance()), so building the course_module and instance rows
 * directly gives full, explicit control over exactly what gets written.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_activity_handler implements activity_handler {
    /**
     * Creates the page course module and instance.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from page_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/page/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'page'], MUST_EXIST);

        // First, the course_modules row - page_add_instance() needs its id
        // (as $data->coursemodule) to exist before it can create a context
        // for it. instance is a placeholder until page_add_instance() below
        // reports back the real page.id.
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

        // Now the page row itself. page_add_instance() reads $data->coursemodule,
        // $data->display, $data->printintro and $data->printlastmodified
        // unconditionally (see mod/page/lib.php) - all four are always set below,
        // even though page_activity_exporter only sends what it found.
        // Every field is remote-sourced, so run through sanitizer before it
        // touches the database - see that class's docblock.
        $pagedata = new \stdClass();
        $pagedata->course = $courseid;
        $pagedata->coursemodule = $cmid;
        $pagedata->name = sanitizer::text($data['name'] ?? '');
        $pagedata->intro = sanitizer::html($data['intro'] ?? '');
        $pagedata->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $pagedata->content = sanitizer::html($data['content'] ?? '');
        $pagedata->contentformat = sanitizer::textformat($data['contentformat'] ?? FORMAT_HTML);
        $pagedata->display = sanitizer::integer($data['display'] ?? 0);
        $pagedata->printintro = sanitizer::integer($data['printintro'] ?? 1, 1);
        $pagedata->printlastmodified = sanitizer::integer($data['printlastmodified'] ?? 1, 1);

        // This call sets course_modules.instance for $cmid itself, via page_add_instance().
        page_add_instance($pagedata);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'page');
        rebuild_course_cache($courseid, true);

        return $cmid;
    }
}
