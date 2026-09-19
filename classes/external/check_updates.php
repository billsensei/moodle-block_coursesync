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
 * AJAX action listing what a sync would bring in.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\external;

use block_coursesync\local\block_helper;
use block_coursesync\local\sync\available;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Backs the block's "Check now" button.
 *
 * It asks the remote site what it holds and plans against it exactly as a run
 * would, but transfers nothing, so a teacher can see what pressing Sync now
 * would bring in before pressing it.
 *
 * This is an AJAX-only function: it is never added to an external service, so
 * it cannot be called with a token.
 */
class check_updates extends external_api {
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
     * Checks the remote course for anything this course could pull in.
     *
     * @param int $blockid Block instance id.
     * @return array Template context for block_coursesync/available_list.
     */
    public static function execute(int $blockid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['blockid' => $blockid]);

        $block = block_helper::get_instance($params['blockid']);
        self::validate_context($block->context);
        require_sesskey();

        // A check reaches out to another site on this server's behalf, so it
        // takes the same capability as starting a run rather than the weaker
        // one that merely reads this block's history.
        require_capability('block/coursesync:trigger', $block->context);

        // The list this returns carries a form, and that form needs somewhere
        // to send the user back to. The block only ever appears on a course
        // page, so that is where a sync started from it returns to.
        $returnurl = new \moodle_url('/course/view.php', [
            'id' => $block->context->get_course_context()->instanceid,
        ]);

        try {
            return available::refresh($params['blockid'], (int) $USER->id, $returnurl);
        } catch (\moodle_exception $e) {
            // The remote site being unreachable is an ordinary outcome here, not
            // a broken request: report it in the block and keep whatever the
            // last successful check found.
            $context = available::context($params['blockid'], $returnurl);
            $context['error'] = $e->getMessage();

            return $context;
        }
    }

    /**
     * Describes the return value for execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'checked' => new external_value(PARAM_BOOL, 'Whether a check has been made and kept'),
            'lastchecked' => new external_value(PARAM_TEXT, 'When that check was made, formatted for this user'),
            'hasitems' => new external_value(PARAM_BOOL, 'Whether anything is waiting to be synced'),
            'count' => new external_value(PARAM_INT, 'How many activities a sync would bring in'),
            'items' => new external_multiple_structure(new external_single_structure([
                'remotecmid' => new external_value(PARAM_INT, 'Course module id on the remote site'),
                'name' => new external_value(PARAM_TEXT, 'Activity name on the remote site'),
                'modname' => new external_value(PARAM_TEXT, 'Translated name of the activity type'),
                'isnew' => new external_value(PARAM_BOOL, 'Whether this course has no copy of it yet'),
                'actionlabel' => new external_value(PARAM_TEXT, 'Translated label for what would happen to it'),
            ])),
            'error' => new external_value(PARAM_TEXT, 'Why the check could not be made, or empty'),
            'blockid' => new external_value(PARAM_INT, 'Block instance the list belongs to'),
            'syncurl' => new external_value(PARAM_URL, 'Where the selection is posted to'),
            'returnurl' => new external_value(PARAM_LOCALURL, 'Where a sync started from the list returns to'),
            'sesskey' => new external_value(PARAM_ALPHANUM, 'Session key for the selection form'),
        ]);
    }
}
