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
 * Recreates a mod_resource instance AND its file(s) from
 * resource_activity_exporter's payload.
 *
 * The file(s) have to be written into the file API BEFORE
 * resource_add_instance() runs: that function calls resource_set_mainfile(),
 * which (when not handed a draft area - see below) just looks at whatever
 * is already stored at [context, mod_resource, content, itemid=0] and marks
 * the only file there as the "main" one (mod/resource/locallib.php). So the
 * order here is: create the course_module (for its context) -> write the
 * files -> THEN call resource_add_instance().
 *
 * $data->files is deliberately left falsy (0): that field is a *draft area*
 * itemid resource_set_mainfile() would otherwise try to copy from via
 * file_save_draft_area_files() - not relevant here since store_files()
 * already wrote directly into the final area.
 *
 * Same instance-setting quirk as page/url: resource_add_instance() sets
 * course_modules.instance itself.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_activity_handler implements activity_handler {
    /**
     * Creates the resource course module, instance, and file(s).
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from resource_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/resource/lib.php');
        require_once($CFG->dirroot . '/mod/resource/locallib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'resource'], MUST_EXIST);

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

        $this->store_files($cmid, $data['files'] ?? []);

        $resourcedata = new \stdClass();
        $resourcedata->course = $courseid;
        $resourcedata->coursemodule = $cmid;
        $resourcedata->name = sanitizer::text($data['name'] ?? '');
        $resourcedata->intro = sanitizer::html($data['intro'] ?? '');
        $resourcedata->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $resourcedata->display = sanitizer::integer($data['display'] ?? 0);
        $resourcedata->printintro = sanitizer::integer($data['printintro'] ?? 1, 1);
        $resourcedata->files = 0;

        // This call sets course_modules.instance for $cmid itself, via resource_add_instance().
        resource_add_instance($resourcedata, null);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'resource');
        rebuild_course_cache($courseid, true);

        return $cmid;
    }

    /**
     * Writes exported files directly into the new course module's own
     * mod_resource/content filearea.
     *
     * filename and filepath go through sanitizer (PARAM_FILE/PARAM_PATH
     * rules - no '..', no path separators smuggled into what should be a
     * bare filename, etc.) before ever reaching the file API. mimetype is
     * NOT taken from the remote payload at all: file_storage derives it
     * from the (sanitized) filename itself, which is safer than trusting a
     * remote-declared type that might not match the actual bytes.
     *
     * @param int $cmid
     * @param array $files From resource_activity_exporter::export_files().
     */
    protected function store_files(int $cmid, array $files): void {
        $fs = get_file_storage();
        $context = \context_module::instance($cmid);

        foreach ($files as $filedata) {
            $content = base64_decode($filedata['contentbase64'] ?? '', true);
            if ($content === false) {
                // Malformed payload for this one file - skip it rather than
                // fail the whole activity. This surfaces to the teacher as a
                // "successfully created" activity missing a file, since a
                // single bad file among several isn't a whole-activity
                // failure - see sync_history for what does get recorded.
                continue;
            }

            $filerecord = [
                'contextid' => $context->id,
                'component' => 'mod_resource',
                'filearea' => 'content',
                'itemid' => 0,
                'filepath' => sanitizer::filepath($filedata['filepath'] ?? '/'),
                'filename' => sanitizer::filename($filedata['filename'] ?? 'file'),
            ];

            $fs->create_file_from_string($filerecord, $content);
        }
    }
}
