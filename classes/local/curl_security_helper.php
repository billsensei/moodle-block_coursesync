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
 * cURL security helper enforcing this plugin's remote URL rules.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

/**
 * Applies this plugin's URL rules inside Moodle's cURL wrapper.
 *
 * Moodle's own helper only blocks what an administrator has listed in
 * $CFG->curlsecurityblockedhosts, which is empty by default, so this subclass
 * adds the plugin's own checks on top and pins the addresses that passed them
 * via CURLOPT_RESOLVE. Pinning matters because the URL is validated and then
 * requested as two separate steps; without it, a hostname could resolve to an
 * allowed address during validation and an internal one during the request.
 */
class curl_security_helper extends \core\files\curl_security_helper {
    /**
     * Addresses that passed validation, in CURLOPT_RESOLVE format.
     *
     * @var string[]
     */
    private array $resolveinfo = [];

    /**
     * Checks a URL against this plugin's rules and the site's blocklist.
     *
     * @param string $urlstring The URL to check.
     * @param int|null $notused Unused, kept for signature compatibility.
     * @return bool True if the URL must not be requested.
     */
    public function url_is_blocked($urlstring, $notused = null) {
        try {
            $inspected = url_validator::inspect($urlstring);
        } catch (invalid_remote_url_exception $e) {
            $this->resolveinfo = [];
            return true;
        }

        $this->resolveinfo = array_map(
            fn(string $ip): string => $inspected['host'] . ':' . $inspected['port'] . ':' . $ip,
            $inspected['ips']
        );

        return parent::url_is_blocked($urlstring, $notused);
    }

    /**
     * Returns the validated addresses so cURL does not resolve the host again.
     *
     * @return string[] Entries in hostname:port:ip_address format.
     */
    public function get_resolve_info(): array {
        return $this->resolveinfo;
    }
}
