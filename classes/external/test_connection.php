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
 * AJAX action that checks a remote site URL and token from the configuration form.
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
 * Confirms that a remote site answers to the given token, and says which site it is.
 *
 * This is an AJAX-only function: it is never added to an external service, so
 * it cannot be called with a token, only by a signed-in user with the
 * capabilities to configure this block.
 */
class test_connection extends external_api {
    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'blockid' => new external_value(PARAM_INT, 'Block instance id being configured'),
            'remoteurl' => new external_value(PARAM_RAW_TRIMMED, 'Remote site URL as entered in the form'),
            'token' => new external_value(
                PARAM_RAW_TRIMMED,
                'Token as entered in the form, empty to use the stored one',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Tests the connection to the remote site.
     *
     * @param int $blockid Block instance id being configured.
     * @param string $remoteurl Remote site URL as entered in the form.
     * @param string $token Token as entered in the form, empty to use the stored one.
     * @return array Result with keys 'status', 'complete' and 'message'.
     */
    public static function execute(int $blockid, string $remoteurl, string $token = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'blockid' => $blockid,
            'remoteurl' => $remoteurl,
            'token' => $token,
        ]);

        $block = block_helper::get_instance($params['blockid']);
        self::validate_context($block->context);
        require_sesskey();
        block_helper::require_manage_capability($block->context);

        $usetoken = block_helper::effective_token($block->config ?? null, $params['token'], $params['remoteurl']);

        try {
            $client = new remote_client($params['remoteurl'], $usetoken);
            $site = connection::describe_site($client);
        } catch (\moodle_exception $e) {
            return [
                'status' => false,
                'complete' => false,
                'message' => $e->getMessage(),
            ];
        }

        $placeholders = (object) [
            'sitename' => $site['sitename'],
            'release' => $site['release'],
            'missing' => implode(', ', $site['missingfunctions']),
        ];

        $complete = empty($site['missingfunctions']);
        $message = $complete
            ? get_string('connectionok', 'block_coursesync', $placeholders)
            : get_string('connectionokmissing', 'block_coursesync', $placeholders);

        return [
            'status' => true,
            'complete' => $complete,
            'message' => $message,
        ];
    }

    /**
     * Describes the return value for execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Whether the remote site answered successfully'),
            'complete' => new external_value(PARAM_BOOL, 'Whether the remote service exposes every required function'),
            'message' => new external_value(PARAM_TEXT, 'Translated message to show to the user'),
        ]);
    }
}
