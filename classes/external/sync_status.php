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

/**
 * AJAX action reporting whether a sync is still running.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\external;

use block_coursesync\local\block_helper;
use block_coursesync\local\sync\status;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Lets the block find out when a queued run has finished.
 *
 * This is an AJAX-only function: it is never added to an external service, so
 * it cannot be called with a token.
 */
class sync_status extends external_api {
    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'blockid' => new external_value(PARAM_INT, 'Block instance id'),
        ]);
    }

    /**
     * Reports the block instance's current sync state.
     *
     * @param int $blockid Block instance id.
     * @return array Whether a run is in flight, and the current tallies.
     */
    public static function execute(int $blockid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['blockid' => $blockid]);

        $block = block_helper::get_instance($params['blockid']);
        self::validate_context($block->context);
        require_capability('block/coursesync:viewhistory', $block->context);

        $state = status::for_block($params['blockid'], $block->config ?? null);

        return [
            'running' => $state['running'],
            'synced' => $state['synced'],
            'conflicts' => $state['conflicts'],
            'lastrun' => $state['lastrun'],
        ];
    }

    /**
     * Describes the return value for execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'running' => new external_value(PARAM_BOOL, 'Whether a run is queued or in progress'),
            'synced' => new external_value(PARAM_INT, 'How many activities are in step with the remote course'),
            'conflicts' => new external_value(PARAM_INT, 'How many activities need review'),
            'lastrun' => new external_value(PARAM_INT, 'When the last run finished, or 0'),
        ]);
    }
}
