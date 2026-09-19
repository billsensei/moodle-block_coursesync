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
 * Validation of user-supplied remote Moodle site URLs.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

use core\ip_utils;

/**
 * Validates remote site URLs before the server makes outbound requests to them.
 *
 * The remote site URL is entered by a teacher or manager and the server then
 * makes requests to it, which makes it a server-side request forgery surface.
 * Every URL is therefore required to be https, free of embedded credentials,
 * and to resolve only to addresses outside the private, loopback, link-local
 * and reserved ranges.
 */
class url_validator {
    /**
     * Ranges blocked in addition to those PHP's private/reserved filters cover.
     *
     * @var string[]
     */
    private const EXTRA_BLOCKED_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
    ];

    /** @var callable|null Test-only stand-in for DNS resolution. */
    private static $resolver = null;

    /**
     * Substitutes host resolution for the duration of a unit test.
     *
     * Rebinding, where a name resolves to a public address when it is checked
     * and a private one when it is requested, cannot be provoked with real DNS
     * inside a test, so tests drive resolution directly.
     *
     * @param callable|null $resolver Takes a host and returns a list of addresses, or null to restore DNS.
     * @throws \coding_exception If called outside PHPUnit.
     */
    public static function set_resolver_for_testing(?callable $resolver): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Host resolution can only be substituted from a unit test.');
        }

        self::$resolver = $resolver;
    }

    /**
     * Whether this site has opted out of the https and address-range requirements.
     *
     * Set $CFG->block_coursesync_allowinsecureremotes in config.php to allow
     * plain http and private addresses. This exists so the plugin can be tested
     * against throwaway sites on a local network, and must not be set in
     * production: it removes the protection against pointing the block at
     * internal services.
     *
     * @return bool
     */
    public static function insecure_remotes_allowed(): bool {
        global $CFG;
        return !empty($CFG->block_coursesync_allowinsecureremotes);
    }

    /**
     * Validates a remote site URL and returns it in normalised form.
     *
     * @param string $url Raw URL as entered by the user.
     * @return string The normalised base URL, with no trailing slash.
     * @throws invalid_remote_url_exception If the URL is not acceptable.
     */
    public static function validate(string $url): string {
        return self::inspect($url, true)['url'];
    }

    /**
     * Validates a URL and returns its resolved components.
     *
     * @param string $url Raw URL to check.
     * @param bool $requiresitebase Whether to also apply the rules that only make sense for the
     *                              site address someone types in: no query string and no fragment.
     *                              Request URLs built from it, such as a pluginfile download, carry
     *                              a token in the query and must not be held to that.
     * @return array With keys 'url', 'host', 'port' and 'ips'.
     * @throws invalid_remote_url_exception If the URL is not acceptable.
     */
    public static function inspect(string $url, bool $requiresitebase = false): array {
        $url = trim($url);
        if ($url === '') {
            throw new invalid_remote_url_exception('error:urlempty');
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new invalid_remote_url_exception('error:urlmalformed');
        }

        $scheme = strtolower($parts['scheme']);
        $allowedschemes = self::insecure_remotes_allowed() ? ['https', 'http'] : ['https'];
        if (!in_array($scheme, $allowedschemes, true)) {
            throw new invalid_remote_url_exception('error:urlnothttps');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new invalid_remote_url_exception('error:urlhascredentials');
        }

        if ($requiresitebase && (!empty($parts['query']) || !empty($parts['fragment']))) {
            throw new invalid_remote_url_exception('error:urlhasquery');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new invalid_remote_url_exception('error:urlmalformed');
        }

        $ips = self::resolve($host);
        foreach ($ips as $ip) {
            if (self::is_blocked_address($ip)) {
                throw new invalid_remote_url_exception('error:urlprivateaddress');
            }
        }

        $hostinurl = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $normalised = $scheme . '://' . $hostinurl;
        if (isset($parts['port'])) {
            $normalised .= ':' . $port;
        }
        $normalised .= rtrim($parts['path'] ?? '', '/');

        return [
            'url' => $normalised,
            'host' => $host,
            'port' => $port,
            'ips' => $ips,
        ];
    }

    /**
     * Resolves a host to the list of addresses a request to it would reach.
     *
     * Only A records are looked up, matching the behaviour of Moodle's own
     * curl security helper.
     *
     * @param string $host Host component of the URL, without brackets.
     * @return string[] One or more IP addresses.
     * @throws invalid_remote_url_exception If the host is invalid or does not resolve.
     */
    private static function resolve(string $host): array {
        if (ip_utils::is_ip_address($host)) {
            return [$host];
        }

        if (!ip_utils::is_domain_name($host)) {
            throw new invalid_remote_url_exception('error:urlmalformed');
        }

        $ips = self::$resolver !== null ? (self::$resolver)($host) : gethostbynamel($host);
        if (empty($ips)) {
            throw new invalid_remote_url_exception('error:urlunresolvable');
        }

        return $ips;
    }

    /**
     * Whether an address is one this plugin refuses to send requests to.
     *
     * @param string $ip IPv4 or IPv6 address.
     * @return bool
     */
    public static function is_blocked_address(string $ip): bool {
        if (self::insecure_remotes_allowed()) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        foreach (self::EXTRA_BLOCKED_RANGES as $range) {
            if (address_in_subnet($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
