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
 * Recreates a mod_h5pactivity instance AND its .h5p package file from
 * h5pactivity_activity_exporter's payload.
 *
 * Unlike resource_activity_handler, the package file can't be written
 * straight into its final filearea before add_instance() runs:
 * h5pactivity_set_mainfile() (called from inside h5pactivity_add_instance())
 * expects $data->packagefile to be a *draft area* itemid and moves it into
 * the final area itself via file_save_draft_area_files() - see
 * mod/h5pactivity/lib.php. So the order here is: create the course_module
 * -> stash the file in a fresh draft area owned by the current user (the
 * teacher running "Sync now" - see sync.php's require_login()) -> point
 * $data->packagefile at that draft itemid -> THEN call
 * h5pactivity_add_instance(), which does the final move.
 *
 * $data->packagefile is deliberately left as int 0 (falsy) when there's no
 * package to store: h5pactivity_set_mainfile() only acts when
 * !empty($data->packagefile), same "leave the trigger falsy to skip it"
 * pattern resource_activity_handler uses for its own $data->files.
 *
 * Same instance-setting quirk as page/resource: h5pactivity_add_instance()
 * sets course_modules.instance itself.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity_activity_handler implements activity_handler {
    /**
     * Creates the h5pactivity course module, instance, and package file.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from h5pactivity_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/h5pactivity/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'h5pactivity'], MUST_EXIST);

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

        $h5pdata = new \stdClass();
        $h5pdata->course = $courseid;
        $h5pdata->coursemodule = $cmid;
        $h5pdata->name = sanitizer::text($data['name'] ?? '');
        $h5pdata->intro = sanitizer::html($data['intro'] ?? '');
        $h5pdata->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $h5pdata->grade = sanitizer::integer($data['grade'] ?? 100, 100);
        $h5pdata->displayoptions = sanitizer::integer($data['displayoptions'] ?? 0);
        $h5pdata->enabletracking = sanitizer::integer($data['enabletracking'] ?? 1, 1);
        $h5pdata->grademethod = sanitizer::integer($data['grademethod'] ?? 1, 1);
        $h5pdata->reviewmode = sanitizer::integer($data['reviewmode'] ?? 1, 1);
        $h5pdata->packagefile = $this->store_package_in_draft_area($data['package'] ?? null);

        // This call sets course_modules.instance for $cmid itself, via h5pactivity_add_instance().
        h5pactivity_add_instance($h5pdata, null);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'h5pactivity');
        rebuild_course_cache($courseid, true);

        return $cmid;
    }

    /**
     * Writes the exported .h5p file into a fresh draft area belonging to
     * the current user, ready for h5pactivity_set_mainfile() to move into
     * the new instance's own filearea.
     *
     * filename goes through sanitizer::filename() (PARAM_FILE rules) before
     * ever reaching the file API, same as resource_activity_handler.
     *
     * @param array{filename: string, contentbase64: string}|null $package From
     *     h5pactivity_activity_exporter::export_package(), or null if the
     *     source activity has no package uploaded yet.
     * @return int A draft itemid with the file in it, or 0 (falsy - see
     *     class docblock) if there's nothing to store.
     */
    protected function store_package_in_draft_area(?array $package): int {
        if ($package === null) {
            return 0;
        }

        $content = base64_decode($package['contentbase64'] ?? '', true);
        if ($content === false) {
            // Malformed payload - skip it rather than fail the whole
            // activity, same as resource_activity_handler does per-file.
            return 0;
        }

        global $USER;

        $usercontext = \context_user::instance($USER->id);
        $draftitemid = file_get_unused_draft_itemid();

        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => sanitizer::filename($package['filename'] ?? 'content.h5p'),
        ], $content);

        return $draftitemid;
    }
}
