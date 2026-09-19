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
 * Shared lookups for block instances and their stored connection settings.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

/**
 * Helpers used by both the configuration form and its AJAX actions.
 */
class block_helper {
    /**
     * Loads a block instance, with its configuration, by instance id.
     *
     * @param int $blockid Block instance id.
     * @return \block_base The loaded block instance.
     * @throws \dml_missing_record_exception If no such coursesync block exists.
     */
    public static function get_instance(int $blockid): \block_base {
        global $CFG, $DB;
        require_once($CFG->libdir . '/blocklib.php');

        $record = $DB->get_record('block_instances', ['id' => $blockid, 'blockname' => 'coursesync'], '*', MUST_EXIST);

        return \block_instance('coursesync', $record);
    }

    /**
     * Requires the capabilities needed to configure or test a connection.
     *
     * @param \context $context The block instance context.
     */
    public static function require_manage_capability(\context $context): void {
        require_capability('block/coursesync:addinstance', $context);
        require_capability('block/coursesync:trigger', $context);
    }

    /**
     * Returns the decrypted token stored against a block instance.
     *
     * @param \stdClass|null $config Block instance configuration.
     * @return string The token, or empty string if none is stored.
     */
    public static function stored_token(?\stdClass $config): string {
        return token_store::decrypt((string) ($config->tokenciphertext ?? ''));
    }

    /**
     * Returns the token to use for a check: the one just typed, else the stored one.
     *
     * The configuration form leaves the token field blank when a token is
     * already stored, so that the stored value is never written into the page.
     * Falling back to it is only safe while the site it belongs to has not
     * changed: otherwise anyone who can edit the block could point the URL at a
     * host of their own and have this server hand them a token they never saw.
     *
     * @param \stdClass|null $config Block instance configuration.
     * @param string $submitted Token entered in the form, possibly empty.
     * @param string $remoteurl Remote site URL the token is about to be sent to.
     * @return string The token to authenticate with, or empty if there is none to use.
     */
    public static function effective_token(?\stdClass $config, string $submitted, string $remoteurl): string {
        $submitted = trim($submitted);
        if ($submitted !== '') {
            return $submitted;
        }

        return self::is_stored_remote($config, $remoteurl) ? self::stored_token($config) : '';
    }

    /**
     * Whether a URL addresses the same site the stored token was issued by.
     *
     * @param \stdClass|null $config Block instance configuration.
     * @param string $remoteurl URL to compare against the stored one.
     * @return bool
     */
    public static function is_stored_remote(?\stdClass $config, string $remoteurl): bool {
        $stored = trim((string) ($config->remoteurl ?? ''));
        if ($stored === '') {
            return false;
        }

        return self::normalise_url($remoteurl) === self::normalise_url($stored);
    }

    /**
     * Normalises a URL for comparison, falling back to the raw string.
     *
     * @param string $url URL to normalise.
     * @return string
     */
    private static function normalise_url(string $url): string {
        try {
            return url_validator::validate($url);
        } catch (invalid_remote_url_exception $e) {
            // A URL that will not validate never matches a stored one that did.
            return trim($url);
        }
    }
}
