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
 * Exports a mod_resource instance's settings AND its underlying file(s) for
 * the source side - the only exporter that does file content.
 *
 * Files travel base64-encoded inside the JSON payload
 * (classes/external/get_activity_content.php's contentjson), the simplest
 * fit for a single web service call and this phase's scope. This is NOT
 * suitable for large files: base64 costs ~33% size overhead on top of
 * whatever PHP/webserver POST size and memory limits apply to the whole
 * web service request. A real "any file size" story (chunking, or a
 * separate download endpoint with a short-lived signed URL) is future
 * work, not attempted here.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_activity_exporter implements activity_exporter {
    /**
     * Builds the payload resource_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $DB;

        $resource = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);
        $displayoptions = @unserialize($resource->displayoptions ?? '', ['allowed_classes' => false]) ?: [];

        return [
            'name' => $resource->name,
            'intro' => (string) $resource->intro,
            'introformat' => (int) $resource->introformat,
            'display' => (int) $resource->display,
            'printintro' => empty($displayoptions['printintro']) ? 0 : 1,
            'files' => $this->export_files($cm),
        ];
    }

    /**
     * Exports every file in this resource's own content filearea.
     *
     * @param \cm_info $cm
     * @return array<int, array{filename: string, filepath: string, mimetype: ?string,
     *                          sortorder: int, contentbase64: string}>
     */
    protected function export_files(\cm_info $cm): array {
        $fs = get_file_storage();
        $context = \context_module::instance($cm->id);
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);

        $exported = [];
        foreach ($files as $file) {
            $exported[] = [
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'mimetype' => $file->get_mimetype(),
                'sortorder' => (int) $file->get_sortorder(),
                'contentbase64' => base64_encode($file->get_content()),
            ];
        }

        return $exported;
    }
}
