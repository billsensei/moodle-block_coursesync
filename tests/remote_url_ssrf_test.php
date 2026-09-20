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

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests that the remote site address cannot be pointed at this server's own network.
 *
 * A destination site makes server-side requests to whatever address is stored,
 * so an address an attacker chooses is an attacker asking this server to make
 * requests on their behalf. These are the cases that must be refused.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(remote_url::class)]
final class remote_url_ssrf_test extends advanced_testcase {
    /**
     * Addresses that must be refused, and why.
     *
     * @return array
     */
    public static function blocked_provider(): array {
        return [
            'loopback v4' => ['https://127.0.0.1/moodle', 'errorurlprivate'],
            'loopback v4 elsewhere in range' => ['https://127.9.9.9/', 'errorurlprivate'],
            'loopback by name' => ['https://localhost/moodle', 'errorurlprivate'],
            'loopback v6' => ['https://[::1]/moodle', 'errorurlprivate'],
            'private 10/8' => ['https://10.1.2.3/', 'errorurlprivate'],
            'private 172.16/12' => ['https://172.16.5.5/', 'errorurlprivate'],
            'private 192.168/16' => ['https://192.168.1.50/', 'errorurlprivate'],
            'link-local' => ['https://169.254.10.10/', 'errorurlprivate'],
            'cloud metadata endpoint' => ['https://169.254.169.254/latest/meta-data/', 'errorurlprivate'],
            'unspecified address' => ['https://0.0.0.0/', 'errorurlprivate'],
            'carrier grade nat' => ['https://100.100.100.100/', 'errorurlprivate'],
            'unique local v6' => ['https://[fd00::1]/', 'errorurlprivate'],
            'link local v6' => ['https://[fe80::1]/', 'errorurlprivate'],
            'private with a port' => ['https://192.168.56.10:8443/', 'errorurlprivate'],
        ];
    }

    /**
     * Addresses on this server's own networks are refused.
     *
     * @param string $url
     * @param string $expected
     */
    #[DataProvider('blocked_provider')]
    public function test_private_addresses_are_refused(string $url, string $expected): void {
        $this->resetAfterTest();

        $this->assertSame($expected, remote_url::validate($url), "Should have refused {$url}");
        $this->assertNotEmpty(get_string($expected, 'block_coursesync'));
    }

    /**
     * Schemes other than http and https are refused before anything else.
     *
     * @return array
     */
    public static function scheme_provider(): array {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD4='],
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://example.edu:70/'],
            'ftp' => ['ftp://example.edu/'],
            'dict' => ['dict://example.edu:2628/'],
        ];
    }

    /**
     * A scheme that is not http or https is refused.
     *
     * @param string $url
     */
    #[DataProvider('scheme_provider')]
    public function test_other_schemes_are_refused(string $url): void {
        $this->resetAfterTest();

        $problem = remote_url::validate($url);

        $this->assertNotNull($problem, "Should have refused {$url}");
        $this->assertContains($problem, ['errorurlbadscheme', 'errorurlmalformed']);
    }

    /**
     * Plain http is still refused, separately from the address checks, because
     * a token must never travel unencrypted.
     */
    public function test_http_is_still_refused(): void {
        $this->resetAfterTest();

        $this->assertSame('errorurlnothttps', remote_url::validate('http://moodle.example.edu'));
    }

    /**
     * The development override lets a private address through, and only then.
     */
    public function test_override_allows_private_addresses(): void {
        global $CFG;

        $this->resetAfterTest();

        $private = 'https://192.168.56.10:8443';

        // Off by default, whatever the site is.
        $this->assertFalse(remote_url::private_addresses_allowed());
        $this->assertSame('errorurlprivate', remote_url::validate($private));

        $flag = remote_url::ALLOW_PRIVATE_FLAG;
        $CFG->$flag = true;

        $this->assertTrue(remote_url::private_addresses_allowed());
        $this->assertNull(remote_url::validate($private));
    }

    /**
     * The override relaxes the address rules and nothing else. It is a way to
     * reach a test server, not a way to switch the validator off.
     */
    public function test_override_does_not_relax_anything_else(): void {
        global $CFG;

        $this->resetAfterTest();

        $flag = remote_url::ALLOW_PRIVATE_FLAG;
        $CFG->$flag = true;

        $this->assertSame('errorurlnothttps', remote_url::validate('http://192.168.56.10:8443'));
        $this->assertSame('errorurlbadscheme', remote_url::validate('javascript:alert(1)'));
        $this->assertSame('errorurlcredentials', remote_url::validate('https://admin:pw@192.168.56.10/'));
        $this->assertSame('errorurlempty', remote_url::validate('   '));
    }

    /**
     * A public address is accepted, so the checks have not simply refused
     * everything.
     */
    public function test_public_addresses_are_still_accepted(): void {
        $this->resetAfterTest();

        // A documentation address from RFC 5737, which is public but not routed.
        $this->assertNull(remote_url::validate('https://203.0.113.10/moodle'));
    }

    /**
     * A host name that does not resolve is accepted when the address is saved.
     *
     * Refusing here would make saving a setting depend on DNS being available,
     * and would buy nothing: a name that resolves safely today can resolve to
     * 127.0.0.1 tomorrow. The check that matters runs again immediately before
     * each request - see test_request_time_check_catches_private_addresses().
     */
    public function test_unresolvable_host_is_accepted_when_saved(): void {
        $this->resetAfterTest();

        $this->assertNull(remote_url::validate('https://this-name-does-not-exist.invalid/'));
    }

    /**
     * The request-time check refuses a private address whatever was saved.
     *
     * This is the one that protects against a host whose answer changed after
     * it was saved, which no amount of checking at save time can catch.
     */
    public function test_request_time_check_catches_private_addresses(): void {
        global $CFG;

        $this->resetAfterTest();

        $endpoint = remote_url::webservice_endpoint('https://127.0.0.1:8443');

        $this->assertSame('errorurlprivate', remote_url::check_before_request($endpoint));

        // And honours the same development override.
        $flag = remote_url::ALLOW_PRIVATE_FLAG;
        $CFG->$flag = true;

        $this->assertNull(remote_url::check_before_request($endpoint));
    }

    /**
     * A request to a private address is refused by the client, not merely by the
     * form that saved it.
     */
    public function test_client_refuses_a_private_address(): void {
        $this->resetAfterTest();

        $result = remote_client::ping('https://169.254.169.254', 'abcdef0123456789abcdef0123456789');

        $this->assertFalse($result->success);
        $this->assertSame('errorurlprivate', $result->errorkey);
        $this->assertDebuggingCalled();
    }
}
