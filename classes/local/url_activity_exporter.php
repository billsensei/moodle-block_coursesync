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
 * Exports a mod_url instance's settings for the source side.
 *
 * Does not export url's "parameters" feature (extra GET params appended to
 * the URL) - a rarely-used option, and url_add_instance() always rebuilds
 * it from parameter_N/variable_N form fields we don't have. A known, minor
 * fidelity gap, not a functional problem: the externalurl itself (where
 * any query string normally already lives) transfers exactly as-is.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_activity_exporter implements activity_exporter {
    /**
     * Builds the payload url_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $DB;

        $url = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);
        $displayoptions = @unserialize($url->displayoptions ?? '', ['allowed_classes' => false]) ?: [];

        return [
            'name' => $url->name,
            'intro' => (string) $url->intro,
            'introformat' => (int) $url->introformat,
            'externalurl' => (string) $url->externalurl,
            'display' => (int) $url->display,
            'printintro' => empty($displayoptions['printintro']) ? 0 : 1,
            'popupwidth' => (int) ($displayoptions['popupwidth'] ?? 620),
            'popupheight' => (int) ($displayoptions['popupheight'] ?? 450),
        ];
    }
}
