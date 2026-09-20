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

namespace block_coursesync;

/**
 * Validation and normalisation of the remote site URL.
 *
 * As well as checking the shape of the address, this refuses to point the site
 * at itself or at anything else on the network it sits in. A destination site
 * makes server-side requests to whatever address is stored here, so an address
 * an attacker chooses is an attacker asking this server to make requests on
 * their behalf.
 *
 * Two layers do this work. The check here happens when an administrator saves
 * the address, and produces a message they can act on. Moodle's own
 * curl_security_helper runs again on every outgoing request through
 * \core\http_client, which is what actually protects against a hostname whose
 * answer changes between being saved and being used.
 *
 * A development override exists because test sites legitimately sit on private
 * networks - see ALLOW_PRIVATE_FLAG below.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_url {
    /**
     * The config.php flag that permits private addresses.
     *
     * Set `$CFG->block_coursesync_allowprivateurls = true;` in config.php to let
     * this site connect to an address on a private or loopback range.
     *
     * It is deliberately a config.php flag rather than an administration
     * setting. Turning off a protection against the server being used to reach
     * its own network is a decision that should need access to the server, not
     * a checkbox that comes with any compromised administrator account.
     *
     * @var string
     */
    const ALLOW_PRIVATE_FLAG = 'block_coursesync_allowprivateurls';

    /**
     * Address ranges a remote site may not live on, unless the override is set.
     *
     * Loopback and private ranges would let the address point back at this
     * server or at its neighbours. The link-local range covers the cloud
     * metadata endpoint at 169.254.169.254, which is the usual prize in an
     * attack of this shape.
     *
     * @var string[]
     */
    const BLOCKED_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '::1',
        'fc00::/7',
        'fe80::/10',
    ];

    /**
     * Addresses that are always refused, matched exactly.
     *
     * The all-zeros addresses need naming explicitly because
     * address_in_subnet('0.0.0.0', '0.0.0.0/8') is false in Moodle, even though
     * 0.1.2.3 in the same range is true.
     *
     * @var string[]
     */
    const BLOCKED_ADDRESSES = [
        '0.0.0.0',
        '::',
        '::1',
    ];

    /**
     * Host names that are never a remote site, whatever they resolve to.
     *
     * @var string[]
     */
    const BLOCKED_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
    ];

    /**
     * Check a URL entered by an administrator.
     *
     * @param string $url the raw value from the form
     * @return string|null a language string identifier describing the problem, or null if the URL is acceptable
     */
    public static function validate(string $url): ?string {
        $url = trim($url);

        if ($url === '') {
            return 'errorurlempty';
        }

        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['scheme'])) {
            return 'errorurlmalformed';
        }

        // The scheme is checked before the host, because javascript: and data:
        // addresses have no host at all and would otherwise be reported as
        // merely malformed rather than as the thing they are.
        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'errorurlbadscheme';
        }

        if (empty($parts['host'])) {
            return 'errorurlmalformed';
        }

        if ($scheme !== 'https') {
            return 'errorurlnothttps';
        }

        // Credentials in the URL would end up in logs and error messages.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'errorurlcredentials';
        }

        if (!self::has_valid_port($parts)) {
            return 'errorurlmalformed';
        }

        return self::check_address($parts['host']);
    }

    /**
     * Is this host somewhere a remote site is allowed to be?
     *
     * @param string $host the host part of the URL
     * @return string|null a language string identifier describing the problem, or null if the host is acceptable
     */
    protected static function check_address(string $host): ?string {
        if (self::private_addresses_allowed()) {
            return null;
        }

        $host = strtolower(trim($host, '[]'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'errorurlprivate';
        }

        foreach (self::resolve($host) as $address) {
            if (self::is_blocked_address($address)) {
                return 'errorurlprivate';
            }
        }

        // A name that does not resolve right now is accepted rather than
        // refused. Saving a setting should not fail because DNS happened to be
        // unavailable, and refusing here would buy nothing: a name that resolves
        // safely today can resolve to 127.0.0.1 tomorrow. That is why the same
        // check runs again in remote_client immediately before each request,
        // which is the point at which the answer actually matters.
        return null;
    }

    /**
     * Would this site refuse to make a request to this URL right now?
     *
     * Called immediately before an outgoing request rather than when the
     * address was saved, so a host whose answer has changed since - or which did
     * not resolve at all then - is still caught.
     *
     * @param string $url
     * @return string|null a language string identifier, or null if the request may proceed
     */
    public static function check_before_request(string $url): ?string {
        $parts = parse_url($url);

        if (!self::has_required_parts($parts)) {
            return 'errorurlmalformed';
        }

        return self::check_address($parts['host']);
    }

    /**
     * Every address a host resolves to.
     *
     * All of them are checked, not just the first: a name that answers with one
     * public address and one private one is still a way to reach the private one.
     *
     * @param string $host
     * @return string[]
     */
    protected static function resolve(string $host): array {
        if (\core\ip_utils::is_ip_address($host)) {
            return [$host];
        }

        $addresses = [];

        foreach (['A', 'AAAA'] as $type) {
            $records = @dns_get_record($host, $type === 'A' ? DNS_A : DNS_AAAA);

            if (!is_array($records)) {
                continue;
            }

            foreach ($records as $record) {
                $address = $record['ip'] ?? ($record['ipv6'] ?? null);

                if ($address !== null) {
                    $addresses[] = $address;
                }
            }
        }

        if ($addresses === []) {
            // Fall back to the resolver Moodle itself uses.
            $address = \core\ip_utils::get_ip_address($host);

            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * Does this address sit in a range a remote site may not live on?
     *
     * @param string $address an IPv4 or IPv6 address
     * @return bool
     */
    protected static function is_blocked_address(string $address): bool {
        $address = strtolower(trim($address, '[]'));

        if (in_array($address, self::BLOCKED_ADDRESSES, true)) {
            return true;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (address_in_subnet($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Has this site been told that private addresses are acceptable?
     *
     * @return bool
     */
    public static function private_addresses_allowed(): bool {
        global $CFG;

        $flag = self::ALLOW_PRIVATE_FLAG;

        return !empty($CFG->$flag);
    }

    /**
     * Does parse_url() give us the pieces a site address must have?
     *
     * @param array|false|null $parts the result of parse_url()
     * @return bool
     */
    protected static function has_required_parts($parts): bool {
        return is_array($parts) && !empty($parts['scheme']) && !empty($parts['host']);
    }

    /**
     * Is the port, if one was given, inside the valid range?
     *
     * @param array $parts the result of parse_url()
     * @return bool
     */
    protected static function has_valid_port(array $parts): bool {
        if (!isset($parts['port'])) {
            return true;
        }

        return $parts['port'] >= 1 && $parts['port'] <= 65535;
    }

    /**
     * Reduce a URL to the base the web service endpoint hangs off.
     *
     * Trailing slashes and anything the administrator pasted after the host and
     * path - a query string, a fragment, or the path of the page they happened
     * to be on - are discarded.
     *
     * @param string $url a URL that has already passed validate()
     * @return string
     */
    public static function normalise(string $url): string {
        $parts = parse_url(trim($url));

        // The scheme is kept rather than assumed. validate() has already
        // insisted on https for anything a user saved, so this only matters for
        // an address set up some other way - a test fixture, for instance - and
        // silently rewriting it would send a request somewhere it was not asked
        // to go.
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $normalised = $scheme . '://' . strtolower($parts['host']);

        if (!empty($parts['port'])) {
            $normalised .= ':' . (int) $parts['port'];
        }

        if (!empty($parts['path'])) {
            $normalised .= rtrim($parts['path'], '/');
        }

        return $normalised;
    }

    /**
     * The REST endpoint on the remote site.
     *
     * @param string $baseurl a normalised base URL
     * @return string
     */
    public static function webservice_endpoint(string $baseurl): string {
        return $baseurl . '/webservice/rest/server.php';
    }
}
