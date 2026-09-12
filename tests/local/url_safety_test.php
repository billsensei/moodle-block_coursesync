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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests url_safety - Phase 7's SSRF hardening for the remote site URL field.
 *
 * Every case here uses a literal IP address, deliberately: is_private_or_loopback()
 * skips DNS resolution entirely for a literal IP (see resolve()), so these
 * run with no network access and can't be flaky in a CI environment that
 * has none.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(url_safety::class)]
final class url_safety_test extends \advanced_testcase {
    /**
     * Loopback addresses must be rejected.
     *
     * @param string $url
     */
    #[DataProvider('loopback_url_provider')]
    public function test_rejects_loopback(string $url): void {
        $this->assertTrue(url_safety::is_private_or_loopback($url));
    }

    /**
     * Loopback URLs to test against.
     *
     * @return array<string, array{0: string}>
     */
    public static function loopback_url_provider(): array {
        return [
            'IPv4 loopback' => ['https://127.0.0.1/'],
            'IPv4 loopback, non-default port' => ['https://127.0.0.1:8443/webservice/rest/server.php'],
            'IPv6 loopback' => ['https://[::1]/'],
        ];
    }

    /**
     * Private-range (RFC 1918) addresses must be rejected.
     *
     * @param string $url
     */
    #[DataProvider('private_range_url_provider')]
    public function test_rejects_private_range(string $url): void {
        $this->assertTrue(url_safety::is_private_or_loopback($url));
    }

    /**
     * Private-range URLs to test against.
     *
     * @return array<string, array{0: string}>
     */
    public static function private_range_url_provider(): array {
        return [
            '10.0.0.0/8' => ['https://10.1.2.3/'],
            '172.16.0.0/12' => ['https://172.16.5.5/'],
            '192.168.0.0/16 (this project\'s own test servers)' => ['https://192.168.56.15/'],
        ];
    }

    /**
     * Link-local addresses (including the cloud-metadata address that makes
     * this class of bug more than theoretical) must be rejected.
     *
     * @param string $url
     */
    #[DataProvider('link_local_url_provider')]
    public function test_rejects_link_local(string $url): void {
        $this->assertTrue(url_safety::is_private_or_loopback($url));
    }

    /**
     * Link-local URLs to test against.
     *
     * @return array<string, array{0: string}>
     */
    public static function link_local_url_provider(): array {
        return [
            'link-local' => ['https://169.254.1.1/'],
            'cloud metadata address' => ['https://169.254.169.254/latest/meta-data/'],
        ];
    }

    /**
     * A public address must NOT be rejected by this check.
     */
    public function test_allows_public_address(): void {
        $this->assertFalse(url_safety::is_private_or_loopback('https://93.184.216.34/'));
    }

    /**
     * A URL with no host at all (so nothing to even classify) fails closed.
     */
    public function test_rejects_url_with_no_host(): void {
        $this->assertTrue(url_safety::is_private_or_loopback('not-a-url'));
    }
}
