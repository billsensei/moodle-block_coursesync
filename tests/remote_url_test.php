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
 * Tests for the remote site URL validator.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(remote_url::class)]
final class remote_url_test extends advanced_testcase {
    /**
     * URLs and the problem each one should report, or null when acceptable.
     *
     * @return array
     */
    public static function url_provider(): array {
        return [
            'plain https' => ['https://moodle.example.edu', null],
            'https with port' => ['https://moodle.example.edu:8443', null],
            'https with path' => ['https://example.edu/moodle', null],
            'trailing slash' => ['https://moodle.example.edu/', null],
            'surrounding space' => ['  https://moodle.example.edu  ', null],
            'empty' => ['', 'errorurlempty'],
            'only space' => ['   ', 'errorurlempty'],
            'http' => ['http://moodle.example.edu', 'errorurlnothttps'],
            'ftp' => ['ftp://moodle.example.edu', 'errorurlbadscheme'],
            'no scheme' => ['moodle.example.edu', 'errorurlmalformed'],
            'scheme only' => ['https://', 'errorurlmalformed'],
            'not a url' => ['this is not a url', 'errorurlmalformed'],
            'credentials' => ['https://admin:hunter2@moodle.example.edu', 'errorurlcredentials'],
            'username only' => ['https://admin@moodle.example.edu', 'errorurlcredentials'],
            'port out of range' => ['https://moodle.example.edu:99999', 'errorurlmalformed'],
        ];
    }

    /**
     * The validator accepts and rejects the right things.
     *
     * @param string $url
     * @param string|null $expected
     */
    #[DataProvider('url_provider')]
    public function test_validate(string $url, ?string $expected): void {
        $this->assertSame($expected, remote_url::validate($url));
    }

    /**
     * Every rejection identifier has a language string behind it.
     */
    public function test_every_error_has_a_string(): void {
        foreach (self::url_provider() as $case) {
            if ($case[1] === null) {
                continue;
            }

            $this->assertNotEmpty(get_string($case[1], 'block_coursesync'));
        }
    }

    /**
     * Normalisation strips the noise an administrator is likely to paste.
     */
    public function test_normalise(): void {
        $this->assertSame('https://moodle.example.edu', remote_url::normalise('https://moodle.example.edu/'));
        $this->assertSame('https://moodle.example.edu', remote_url::normalise('  https://MOODLE.example.edu  '));
        $this->assertSame('https://moodle.example.edu:8443', remote_url::normalise('https://moodle.example.edu:8443/'));
        $this->assertSame('https://example.edu/moodle', remote_url::normalise('https://example.edu/moodle/'));
        $this->assertSame('https://example.edu', remote_url::normalise('https://example.edu/?redirect=0'));
    }

    /**
     * Normalisation keeps the scheme it was given rather than assuming https,
     * so an address is never quietly redirected to a different protocol.
     */
    public function test_normalise_keeps_the_scheme(): void {
        $this->assertSame('https://example.edu', remote_url::normalise('https://example.edu/'));
        $this->assertSame('http://127.0.0.1:8001', remote_url::normalise('http://127.0.0.1:8001/'));
    }

    /**
     * The endpoint is built from the normalised base.
     */
    public function test_webservice_endpoint(): void {
        $this->assertSame(
            'https://moodle.example.edu/webservice/rest/server.php',
            remote_url::webservice_endpoint('https://moodle.example.edu')
        );
    }
}
