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
 * Exports a mod_page instance's settings/content for the source side.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_activity_exporter implements activity_exporter {
    /**
     * Builds the payload page_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $DB;

        $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
        $displayoptions = @unserialize($page->displayoptions ?? '') ?: [];

        return [
            'name' => $page->name,
            'intro' => (string) $page->intro,
            'introformat' => (int) $page->introformat,
            'content' => (string) $page->content,
            'contentformat' => (int) $page->contentformat,
            'display' => (int) $page->display,
            'printintro' => empty($displayoptions['printintro']) ? 0 : 1,
            'printlastmodified' => empty($displayoptions['printlastmodified']) ? 0 : 1,
        ];
    }
}
