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
 * Encryption of remote web service tokens at rest.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

use core\encryption;

/**
 * Wraps core's encryption API so tokens are never written to block config in clear.
 *
 * Block instance configuration is stored base64-serialised in
 * block_instances.configdata, which is readable by anyone with database or
 * backup access, so the token is encrypted with the site key before it goes in.
 */
class token_store {
    /**
     * Encrypts a token for storage.
     *
     * @param string $token Plain token, or empty string.
     * @return string Ciphertext, or empty string if there was no token.
     */
    public static function encrypt(string $token): string {
        if ($token === '') {
            return '';
        }
        return encryption::encrypt($token);
    }

    /**
     * Decrypts a stored token.
     *
     * @param string $ciphertext Value previously returned by encrypt().
     * @return string The token, or empty string if it could not be decrypted.
     */
    public static function decrypt(string $ciphertext): string {
        if ($ciphertext === '') {
            return '';
        }

        try {
            return encryption::decrypt($ciphertext);
        } catch (\Throwable $e) {
            debugging('block_coursesync could not decrypt a stored token: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }
}
