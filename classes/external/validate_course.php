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
 * AJAX action that resolves the remote course named in the configuration form.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\external;

use block_coursesync\local\block_helper;
use block_coursesync\local\connection;
use block_coursesync\local\remote_client;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Confirms the remote course ID or shortname resolves to exactly one course.
 *
 * This is an AJAX-only function: it is never added to an external service, so
 * it cannot be called with a token, only by a signed-in user with the
 * capabilities to configure this block.
 */
class validate_course extends external_api {
    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'blockid' => new external_value(PARAM_INT, 'Block instance id being configured'),
            'remoteurl' => new external_value(PARAM_RAW_TRIMMED, 'Remote site URL as entered in the form'),
            'remotecourse' => new external_value(PARAM_RAW_TRIMMED, 'Remote course id or shortname as entered in the form'),
            'token' => new external_value(
                PARAM_RAW_TRIMMED,
                'Token as entered in the form, empty to use the stored one',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Resolves the remote course.
     *
     * @param int $blockid Block instance id being configured.
     * @param string $remoteurl Remote site URL as entered in the form.
     * @param string $remotecourse Remote course id or shortname as entered in the form.
     * @param string $token Token as entered in the form, empty to use the stored one.
     * @return array Result with keys 'status', 'message', 'courseid' and 'fullname'.
     */
    public static function execute(int $blockid, string $remoteurl, string $remotecourse, string $token = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'blockid' => $blockid,
            'remoteurl' => $remoteurl,
            'remotecourse' => $remotecourse,
            'token' => $token,
        ]);

        $block = block_helper::get_instance($params['blockid']);
        self::validate_context($block->context);
        require_sesskey();
        block_helper::require_manage_capability($block->context);

        $usetoken = block_helper::effective_token($block->config ?? null, $params['token'], $params['remoteurl']);

        try {
            $client = new remote_client($params['remoteurl'], $usetoken);
            $course = connection::resolve_course($client, $params['remotecourse']);
        } catch (\moodle_exception $e) {
            return [
                'status' => false,
                'message' => $e->getMessage(),
                'courseid' => 0,
                'fullname' => '',
            ];
        }

        return [
            'status' => true,
            'message' => get_string('courseok', 'block_coursesync', (object) $course),
            'courseid' => $course['id'],
            'fullname' => $course['fullname'],
        ];
    }

    /**
     * Describes the return value for execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Whether exactly one matching course was found'),
            'message' => new external_value(PARAM_TEXT, 'Translated message to show to the user'),
            'courseid' => new external_value(PARAM_INT, 'Id of the matched course on the remote site'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name of the matched course'),
        ]);
    }
}
