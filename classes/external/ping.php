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

namespace block_coursesync\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * block_coursesync_ping external function.
 *
 * The minimum a "source" site needs to prove it is reachable, the caller's
 * token is valid for the Course Sync service, and that token's user has
 * the block/coursesync:sync capability. Returns basic site identification
 * only - Phase 2 exchanges no course or activity data.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ping extends external_api {
    /**
     * Describes the parameters (none).
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Confirms reachability and returns basic site info.
     *
     * @return array{sitename: string, moodlerelease: string, moodleversion: string}
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);

        // There is no course/block instance context to check this against
        // on the source side - a ping isn't about any one course - so this
        // is checked at the system context. The token's user needs
        // block/coursesync:sync granted at system level (for example via a
        // dedicated role assigned to the sync account); see REMOTE_SETUP.md.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('block/coursesync:sync', $context);

        global $SITE, $CFG;

        return [
            'sitename' => $SITE->fullname,
            'moodlerelease' => $CFG->release,
            'moodleversion' => $CFG->version,
        ];
    }

    /**
     * Describes the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sitename' => new external_value(PARAM_TEXT, 'Full name of the site.'),
            'moodlerelease' => new external_value(PARAM_TEXT, 'Human-readable Moodle release, e.g. "5.2.2+".'),
            'moodleversion' => new external_value(PARAM_TEXT, 'Moodle core version number.'),
        ]);
    }
}
