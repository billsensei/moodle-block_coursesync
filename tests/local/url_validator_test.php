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
 * Tests for the remote site URL validator.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for \block_coursesync\local\url_validator.
 *
 * Only IP literals are used, so that no test depends on DNS.
 */
#[CoversClass(url_validator::class)]
final class url_validator_test extends \advanced_testcase {
    /**
     * URLs that must be refused, with the error they must be refused with.
     *
     * @return array[]
     */
    public static function rejected_url_provider(): array {
        return [
            'empty' => ['', 'error:urlempty'],
            'whitespace only' => ['   ', 'error:urlempty'],
            'no scheme' => ['moodle.example.edu', 'error:urlmalformed'],
            'plain http' => ['http://93.184.216.34/', 'error:urlnothttps'],
            'ftp' => ['ftp://93.184.216.34/', 'error:urlnothttps'],
            'embedded credentials' => ['https://user:pass@93.184.216.34/', 'error:urlhascredentials'],
            'query string' => ['https://93.184.216.34/?id=2', 'error:urlhasquery'],
            'fragment' => ['https://93.184.216.34/#section', 'error:urlhasquery'],
            'loopback' => ['https://127.0.0.1/', 'error:urlprivateaddress'],
            'private class a' => ['https://10.1.2.3/', 'error:urlprivateaddress'],
            'private class b' => ['https://172.16.4.5/', 'error:urlprivateaddress'],
            'private class c' => ['https://192.168.1.1/', 'error:urlprivateaddress'],
            'link local' => ['https://169.254.169.254/', 'error:urlprivateaddress'],
            'carrier grade nat' => ['https://100.64.0.1/', 'error:urlprivateaddress'],
            'ipv6 loopback' => ['https://[::1]/', 'error:urlprivateaddress'],
            'ipv6 unique local' => ['https://[fd00::1]/', 'error:urlprivateaddress'],
        ];
    }

    /**
     * Unacceptable URLs are refused with a specific, translatable reason.
     *
     * @param string $url The URL to validate.
     * @param string $expectederrorcode The language string key expected.
     */
    #[DataProvider('rejected_url_provider')]
    public function test_rejected_urls(string $url, string $expectederrorcode): void {
        $this->resetAfterTest();

        try {
            url_validator::validate($url);
            $this->fail('Expected ' . $expectederrorcode . ' for ' . $url);
        } catch (invalid_remote_url_exception $e) {
            $this->assertSame($expectederrorcode, $e->errorcode);
        }
    }

    /**
     * URLs that must be accepted, with the normalised form expected back.
     *
     * @return array[]
     */
    public static function accepted_url_provider(): array {
        return [
            'bare host' => ['https://93.184.216.34', 'https://93.184.216.34'],
            'trailing slash dropped' => ['https://93.184.216.34/', 'https://93.184.216.34'],
            'subdirectory kept' => ['https://93.184.216.34/moodle/', 'https://93.184.216.34/moodle'],
            'surrounding space trimmed' => ['  https://93.184.216.34/  ', 'https://93.184.216.34'],
            'explicit port kept' => ['https://93.184.216.34:8443/', 'https://93.184.216.34:8443'],
            'ipv6 keeps brackets' => ['https://[2606:2800:220:1::1]/', 'https://[2606:2800:220:1::1]'],
        ];
    }

    /**
     * Acceptable URLs come back normalised.
     *
     * @param string $url The URL to validate.
     * @param string $expected The normalised URL expected.
     */
    #[DataProvider('accepted_url_provider')]
    public function test_accepted_urls(string $url, string $expected): void {
        $this->resetAfterTest();

        $this->assertSame($expected, url_validator::validate($url));
    }

    /**
     * The scheme and address rules can be lifted for local testing.
     */
    public function test_insecure_remotes_can_be_allowed(): void {
        global $CFG;

        $this->resetAfterTest();

        $CFG->block_coursesync_allowinsecureremotes = true;

        $this->assertSame('http://127.0.0.1:8000', url_validator::validate('http://127.0.0.1:8000/'));
        $this->assertFalse(url_validator::is_blocked_address('10.0.0.1'));
    }

    /**
     * A query string is refused in a site address but allowed in a request URL.
     *
     * Downloads carry their token in the query, so the stricter rule must apply
     * only to the address a person types into the configuration form.
     */
    public function test_query_strings_are_only_refused_in_site_addresses(): void {
        $this->resetAfterTest();

        $withquery = 'https://93.184.216.34/webservice/pluginfile.php/13/block_coursesync/a/0/b.mbz?token=abc';

        $inspected = url_validator::inspect($withquery);
        $this->assertSame('93.184.216.34', $inspected['host']);

        $this->expectException(invalid_remote_url_exception::class);
        url_validator::inspect($withquery, true);
    }

    /**
     * Resets any substituted resolver so one test cannot leak into the next.
     */
    protected function tearDown(): void {
        url_validator::set_resolver_for_testing(null);
        parent::tearDown();
    }

    /**
     * A name that resolves to a private address is refused, not just a literal one.
     *
     * This is the case that matters for rebinding: the address is only visible
     * once the name is resolved, and resolution happens again on every request
     * rather than once when the URL was first saved.
     */
    public function test_hostname_resolving_to_a_private_address_is_refused(): void {
        $this->resetAfterTest();

        url_validator::set_resolver_for_testing(fn(string $host): array => ['10.1.2.3']);

        try {
            url_validator::validate('https://looks-public.example.edu');
            $this->fail('Expected a host resolving to a private address to be refused');
        } catch (invalid_remote_url_exception $e) {
            $this->assertSame('error:urlprivateaddress', $e->errorcode);
        }
    }

    /**
     * One private address among several is enough to refuse the whole name.
     */
    public function test_any_private_address_in_the_answer_refuses_the_host(): void {
        $this->resetAfterTest();

        url_validator::set_resolver_for_testing(fn(string $host): array => ['93.184.216.34', '127.0.0.1']);

        $this->expectException(invalid_remote_url_exception::class);
        url_validator::validate('https://mixed.example.edu');
    }

    /**
     * A name resolving only to public addresses is accepted.
     */
    public function test_hostname_resolving_publicly_is_accepted(): void {
        $this->resetAfterTest();

        url_validator::set_resolver_for_testing(fn(string $host): array => ['93.184.216.34']);

        $inspected = url_validator::inspect('https://moodle.example.edu/moodle', true);

        $this->assertSame('https://moodle.example.edu/moodle', $inspected['url']);
        $this->assertSame(['93.184.216.34'], $inspected['ips']);
        $this->assertSame(443, $inspected['port']);
    }

    /**
     * A name that resolves to nothing is refused rather than attempted.
     */
    public function test_unresolvable_hostname_is_refused(): void {
        $this->resetAfterTest();

        url_validator::set_resolver_for_testing(fn(string $host): array => []);

        try {
            url_validator::validate('https://nowhere.example.edu');
            $this->fail('Expected an unresolvable host to be refused');
        } catch (invalid_remote_url_exception $e) {
            $this->assertSame('error:urlunresolvable', $e->errorcode);
        }
    }

    /**
     * The addresses that passed are handed back so the request can be pinned to them.
     */
    public function test_validated_addresses_are_returned_for_pinning(): void {
        $this->resetAfterTest();

        url_validator::set_resolver_for_testing(fn(string $host): array => ['93.184.216.34', '93.184.216.35']);

        $inspected = url_validator::inspect('https://moodle.example.edu');

        $this->assertSame(['93.184.216.34', '93.184.216.35'], $inspected['ips']);
        $this->assertSame('moodle.example.edu', $inspected['host']);
    }

    /**
     * Addresses outside the private and reserved ranges are allowed.
     */
    public function test_public_addresses_are_not_blocked(): void {
        $this->resetAfterTest();

        $this->assertFalse(url_validator::is_blocked_address('93.184.216.34'));
        $this->assertFalse(url_validator::is_blocked_address('2606:2800:220:1::1'));
        $this->assertTrue(url_validator::is_blocked_address('192.168.0.1'));
        $this->assertTrue(url_validator::is_blocked_address('::1'));
    }
}
