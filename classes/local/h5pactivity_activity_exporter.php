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
 * Exports a mod_h5pactivity instance's settings AND its .h5p package file
 * for the source side - the second exporter (after resource) that carries
 * file content.
 *
 * Same base64-in-JSON approach and the same "not suitable for large files"
 * caveat as resource_activity_exporter - see that class's docblock. It
 * applies with extra force here: an H5P package is usually the entire
 * point of the activity (unlike a resource, which is often a small file),
 * so this exporter can produce a genuinely large payload. That tradeoff was
 * a deliberate choice for this plugin (see DEVELOPER_NOTES.md) rather than
 * an oversight - a "settings only" H5P sync would create an activity with
 * no content at all, which isn't a useful sync outcome.
 *
 * mod_h5pactivity stores its package as a single file (at most one, per
 * mod_form.php's own maxfiles => 1) in the 'package' filearea, itemid 0 -
 * so, unlike resource, there's at most one file to export.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity_activity_exporter implements activity_exporter {
    /**
     * Builds the payload h5pactivity_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $DB;

        $h5pactivity = $DB->get_record('h5pactivity', ['id' => $cm->instance], '*', MUST_EXIST);

        return [
            'name' => $h5pactivity->name,
            'intro' => (string) $h5pactivity->intro,
            'introformat' => (int) $h5pactivity->introformat,
            'grade' => (int) $h5pactivity->grade,
            'displayoptions' => (int) $h5pactivity->displayoptions,
            'enabletracking' => (int) $h5pactivity->enabletracking,
            'grademethod' => (int) $h5pactivity->grademethod,
            'reviewmode' => (int) $h5pactivity->reviewmode,
            'package' => $this->export_package($cm),
        ];
    }

    /**
     * Exports the activity's single .h5p package file, if it has one.
     *
     * @param \cm_info $cm
     * @return array{filename: string, contentbase64: string}|null Null if no package is uploaded yet.
     */
    protected function export_package(\cm_info $cm): ?array {
        $fs = get_file_storage();
        $context = \context_module::instance($cm->id);
        $files = $fs->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'sortorder', false);

        $file = reset($files);
        if (!$file) {
            return null;
        }

        return [
            'filename' => $file->get_filename(),
            'contentbase64' => base64_encode($file->get_content()),
        ];
    }
}
