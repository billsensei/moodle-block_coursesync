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
 * Chooses how the plugin talks to a remote site.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\http;

/**
 * Hands out the real transport, unless a test has arranged otherwise.
 *
 * Production always gets curl_transport. The two overrides exist so that no
 * test needs a second live Moodle: PHPUnit sets an instance directly, and
 * Behat leaves a script in plugin config, because the step that arranges it
 * runs in a different process from the page under test.
 */
class transport_factory {
    /** @var string Config setting Behat leaves its canned responses in. */
    public const BEHAT_SETTING = 'behatresponses';

    /** @var transport|null Override set by a unit test. */
    private static ?transport $override = null;

    /**
     * Returns the transport to use for this request.
     *
     * @return transport
     */
    public static function create(): transport {
        if (self::$override !== null) {
            return self::$override;
        }

        if (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING) {
            $script = get_config('block_coursesync', self::BEHAT_SETTING);
            if (!empty($script)) {
                return new fake_transport(json_decode($script, true) ?: []);
            }
        }

        return new curl_transport();
    }

    /**
     * Substitutes a transport for the duration of a unit test.
     *
     * @param transport|null $transport The transport to use, or null to restore the real one.
     * @throws \coding_exception If called outside PHPUnit.
     */
    public static function set_for_testing(?transport $transport): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('The transport can only be substituted from a unit test.');
        }

        self::$override = $transport;
    }
}
