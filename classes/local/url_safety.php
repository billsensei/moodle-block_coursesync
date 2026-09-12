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

namespace block_coursesync\local;

/**
 * Decides whether a remote site URL is safe to save without the
 * development/testing override - rejects anything whose host resolves to a
 * loopback, link-local, or private-range address, on top of edit_form.php's
 * own scheme check (HTTPS required, same override relaxes it to HTTP too).
 *
 * This is a form-time check, independent of $CFG->curlsecurityblockedhosts
 * (which \core\curl already enforces on every actual request, and which
 * this plugin does NOT rely on alone - the destination site admin could
 * have relaxed it for unrelated reasons, as this project's own test servers
 * needed done for local testing). Rejecting an obviously dangerous address
 * here, at the point a teacher enters it, is a second, independent layer.
 *
 * KNOWN LIMITATION: like any hostname-based check done once at save time,
 * this can't catch a hostname that resolves safely now but is repointed at
 * a private address later (DNS rebinding). \core\curl's own protection
 * (see above) is enforced on every request, so it's still the backstop for
 * that case - this class narrows the window, it doesn't close it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_safety {
    /**
     * Whether $url's host is a loopback, link-local, or private-range
     * address - or simply couldn't be resolved to any address at all,
     * which is treated the same way: not confirmed safe, so not allowed
     * through without the override.
     *
     * @param string $url
     * @return bool
     */
    public static function is_private_or_loopback(string $url): bool {
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return true;
        }

        $addresses = self::resolve($host);
        if (empty($addresses)) {
            return true;
        }

        foreach ($addresses as $address) {
            $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves a host to every IPv4/IPv6 address it has.
     *
     * @param string $host
     * @return array<int, string>
     */
    protected static function resolve(string $host): array {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = array_merge($addresses, $ipv4);
        }

        $ipv6records = @dns_get_record($host, DNS_AAAA);
        if (is_array($ipv6records)) {
            foreach ($ipv6records as $record) {
                if (!empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return $addresses;
    }
}
