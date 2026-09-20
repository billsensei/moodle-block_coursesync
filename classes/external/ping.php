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
 * Confirms that a destination site can reach this site and is authorised to talk to it.
 *
 * This is the only external function in phase 2. It deliberately exposes nothing
 * about courses or activities: just enough for the caller to confirm the token
 * works and that both ends are running compatible versions of the plugin.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ping extends external_api {
    /**
     * Describes the parameters. The ping takes none.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Confirm reachability and authorisation.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG, $SITE;

        // The token's user must hold the sync capability at system level on this,
        // the source site. validate_context() also enforces that web services are
        // enabled and the user is allowed to use them in this context.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('block/coursesync:sync', $context);

        $plugin = \core_plugin_manager::instance()->get_plugin_info('block_coursesync');

        return [
            'status' => true,
            'sitename' => format_string($SITE->fullname, true, ['context' => $context]),
            'release' => $CFG->release,
            'pluginversion' => (int) ($plugin->versiondb ?? 0),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Always true; the call reached a working source site.'),
            'sitename' => new external_value(PARAM_TEXT, 'Full name of the source site.'),
            'release' => new external_value(PARAM_TEXT, 'Moodle release of the source site, e.g. "5.1.7+".'),
            'pluginversion' => new external_value(PARAM_INT, 'block_coursesync version installed on the source site.'),
        ]);
    }
}
