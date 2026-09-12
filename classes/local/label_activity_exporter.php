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
 * Exports a mod_label instance's content for the source side.
 *
 * A label has no separate "name" a teacher sets - label_add_instance()
 * derives it from the intro text itself (get_label_name()) - so only intro
 * (the label's actual visible content) and introformat are exported.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class label_activity_exporter implements activity_exporter {
    /**
     * Builds the payload label_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $DB;

        $label = $DB->get_record('label', ['id' => $cm->instance], '*', MUST_EXIST);

        return [
            'intro' => (string) $label->intro,
            'introformat' => (int) $label->introformat,
        ];
    }
}
