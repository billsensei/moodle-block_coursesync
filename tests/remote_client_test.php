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
use core\http_client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the client that calls the source site.
 *
 * The transport is mocked here; the real cross-site call is covered by manual
 * testing between two live sites, which is the only way to exercise TLS
 * verification and Moodle's outgoing request rules honestly.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(remote_client::class)]
final class remote_client_test extends advanced_testcase {
    /**
     * Build a client whose next response is the given one.
     *
     * @param Response $response
     * @return http_client
     */
    protected function mock_client(Response $response): http_client {
        return new http_client(['mock' => new MockHandler([$response])]);
    }

    /**
     * A well-formed answer becomes a success result.
     */
    public function test_successful_ping(): void {
        $this->resetAfterTest();

        $body = json_encode([
            'status' => true,
            'sitename' => 'Source Site',
            'release' => '5.1.7+ (Build: 20260916)',
            'pluginversion' => 2026092001,
        ]);

        $result = remote_client::ping(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            $this->mock_client(new Response(200, ['Content-Type' => 'application/json'], $body))
        );

        $this->assertTrue($result->success);
        $this->assertNull($result->errorkey);
        $this->assertSame('Source Site', $result->sitename);
        $this->assertSame('5.1.7+ (Build: 20260916)', $result->release);
        $this->assertSame(2026092001, $result->pluginversion);
        $this->assertStringContainsString('Source Site', $result->get_message());
    }

    /**
     * A 404 means the address points at something other than a Moodle site.
     */
    public function test_404_is_reported_as_wrong_address(): void {
        $this->resetAfterTest();

        $result = remote_client::ping(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            $this->mock_client(new Response(404, [], 'Not Found'))
        );

        $this->assertFalse($result->success);
        $this->assertSame('errornotmoodle', $result->errorkey);
    }

    /**
     * An HTML page where JSON was expected is also the wrong address.
     */
    public function test_html_response_is_reported_as_wrong_address(): void {
        $this->resetAfterTest();

        $result = remote_client::ping(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            $this->mock_client(new Response(200, ['Content-Type' => 'text/html'], '<html><body>Hello</body></html>'))
        );

        $this->assertFalse($result->success);
        $this->assertSame('errornotmoodle', $result->errorkey);
    }

    /**
     * A Moodle exception in the body is turned into our own message.
     */
    public function test_remote_exception_is_mapped(): void {
        $this->resetAfterTest();

        $body = json_encode([
            'exception' => 'core\\exception\\moodle_exception',
            'errorcode' => 'invalidtoken',
            'message' => 'Invalid token - token not found',
        ]);

        $result = remote_client::ping(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            $this->mock_client(new Response(200, [], $body))
        );

        $this->assertFalse($result->success);
        $this->assertSame('errorbadtoken', $result->errorkey);
        // The remote site's raw message must not reach the user.
        $this->assertStringNotContainsString('Invalid token - token not found', $result->get_message());
    }

    /**
     * A 200 with an unexpected shape is not treated as success.
     */
    public function test_unexpected_shape_is_a_failure(): void {
        $this->resetAfterTest();

        $result = remote_client::ping(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            $this->mock_client(new Response(200, [], json_encode(['something' => 'else'])))
        );

        $this->assertFalse($result->success);
        $this->assertSame('errorbadresponse', $result->errorkey);
    }

    /**
     * A server error is reported as unreachable rather than leaking the code.
     */
    public function test_server_error(): void {
        $this->resetAfterTest();

        $result = remote_client::ping(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            $this->mock_client(new Response(500, [], 'Internal Server Error'))
        );

        $this->assertFalse($result->success);
        $this->assertSame('errorunreachable', $result->errorkey);
        $this->assertDebuggingCalled();
    }

    /**
     * A resolved course comes back with everything the mapping needs.
     */
    public function test_resolve_course(): void {
        $this->resetAfterTest();

        $body = json_encode([
            'id' => 42,
            'shortname' => 'REMOTE1',
            'fullname' => 'Remote Source Course',
            'visible' => true,
            'activitycount' => 5,
        ]);

        $result = remote_client::resolve_course(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            'REMOTE1',
            $this->mock_client(new Response(200, [], $body))
        );

        $this->assertTrue($result->success);
        $this->assertSame(42, $result->id);
        $this->assertSame('REMOTE1', $result->shortname);
        $this->assertSame('Remote Source Course', $result->fullname);
        $this->assertSame(5, $result->activitycount);
        $this->assertStringContainsString('Remote Source Course', $result->get_message());
    }

    /**
     * The source site's own "no such course" error becomes a message about
     * checking the value, not a raw error code.
     */
    public function test_resolve_course_not_found(): void {
        $this->resetAfterTest();

        $body = json_encode([
            'exception' => 'moodle_exception',
            'errorcode' => 'errorcoursenotfound',
            'message' => 'No course found',
        ]);

        $result = remote_client::resolve_course(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            'NOPE',
            $this->mock_client(new Response(200, [], $body))
        );

        $this->assertFalse($result->success);
        $this->assertSame('errorcoursenotfound', $result->errorkey);
        $this->assertNotEmpty($result->get_message());
    }

    /**
     * A course response missing its fields is not accepted.
     */
    public function test_resolve_course_bad_shape(): void {
        $this->resetAfterTest();

        $result = remote_client::resolve_course(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            'REMOTE1',
            $this->mock_client(new Response(200, [], json_encode(['id' => 42])))
        );

        $this->assertFalse($result->success);
        $this->assertSame('errorbadresponse', $result->errorkey);
    }

    /**
     * A list of changed activities is parsed into activity objects.
     */
    public function test_get_modified_activities(): void {
        $this->resetAfterTest();

        $body = json_encode([
            [
                'cmid' => 11,
                'modname' => 'assign',
                'name' => 'Essay one',
                'idnumber' => 'ESSAY1',
                'timemodified' => 1750000000,
            ],
            [
                'cmid' => 12,
                'modname' => 'forum',
                'name' => 'Discussion',
                'idnumber' => '',
                'timemodified' => 1750000500,
            ],
        ]);

        $result = remote_client::get_modified_activities(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            42,
            1749000000,
            $this->mock_client(new Response(200, [], $body))
        );

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->count());
        $this->assertSame(11, $result->activities[0]->cmid);
        $this->assertSame('Essay one', $result->activities[0]->name);
        $this->assertSame('ESSAY1', $result->activities[0]->idnumber);
        $this->assertSame(1750000000, $result->activities[0]->timemodified);
        $this->assertSame('', $result->activities[1]->idnumber);
        $this->assertStringContainsString('2', $result->get_message(1749000000));
    }

    /**
     * An empty list is a success, not a failure.
     */
    public function test_get_modified_activities_empty(): void {
        $this->resetAfterTest();

        $result = remote_client::get_modified_activities(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            42,
            0,
            $this->mock_client(new Response(200, [], json_encode([])))
        );

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->count());
        $this->assertNotEmpty($result->get_message(null));
    }

    /**
     * A malformed row invalidates the whole response rather than being skipped,
     * so a partial list is never mistaken for the truth.
     */
    public function test_get_modified_activities_bad_row(): void {
        $this->resetAfterTest();

        $body = json_encode([
            ['cmid' => 11, 'modname' => 'assign', 'name' => 'Fine', 'idnumber' => '', 'timemodified' => 1],
            ['cmid' => 12],
        ]);

        $result = remote_client::get_modified_activities(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            42,
            0,
            $this->mock_client(new Response(200, [], $body))
        );

        $this->assertFalse($result->success);
        $this->assertSame('errorbadresponse', $result->errorkey);
    }

    /**
     * An activity type this site does not have still displays sensibly.
     */
    public function test_activity_type_name_falls_back_to_modname(): void {
        $this->resetAfterTest();

        $known = new activity(1, 'assign', 'Essay', '', 100);
        $unknown = new activity(2, 'notarealmodule', 'Mystery', '', 100);

        $this->assertSame(get_string('pluginname', 'mod_assign'), $known->get_type_name());
        $this->assertSame('notarealmodule', $unknown->get_type_name());
    }

    /**
     * A full activity payload is parsed into the object the handlers take.
     */
    public function test_get_activity(): void {
        $this->resetAfterTest();

        $body = json_encode([
            'cmid' => 11,
            'modname' => 'page',
            'name' => 'Week 1 Notes',
            'idnumber' => '',
            'sectionnum' => 2,
            'visible' => true,
            'intro' => '<p>Intro.</p>',
            'introformat' => FORMAT_HTML,
            'timemodified' => 1750000000,
            'settings' => [
                ['name' => 'content', 'value' => '<p>Body.</p>'],
                ['name' => 'contentformat', 'value' => (string) FORMAT_HTML],
            ],
        ]);

        $result = remote_client::get_activity(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            11,
            $this->mock_client(new Response(200, [], $body))
        );

        $this->assertTrue($result->success);
        $this->assertSame(11, $result->payload->cmid);
        $this->assertSame('page', $result->payload->modname);
        $this->assertSame('Week 1 Notes', $result->payload->name);
        $this->assertSame(2, $result->payload->sectionnum);
        $this->assertTrue($result->payload->visible);
        $this->assertSame('<p>Body.</p>', $result->payload->setting('content'));
        // FORMAT_HTML is the string '1' in Moodle, so compare as an integer.
        $this->assertSame((int) FORMAT_HTML, $result->payload->setting_int('contentformat'));
        // A setting the source did not send falls back rather than exploding.
        $this->assertSame(620, $result->payload->setting_int('popupwidth', 620));
    }

    /**
     * An activity the source can no longer find is reported distinctly.
     */
    public function test_get_activity_not_found(): void {
        $this->resetAfterTest();

        $body = json_encode([
            'exception' => 'moodle_exception',
            'errorcode' => 'erroractivitynotfound',
            'message' => 'gone',
        ]);

        $result = remote_client::get_activity(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            11,
            $this->mock_client(new Response(200, [], $body))
        );

        $this->assertFalse($result->success);
        $this->assertSame('erroractivitynotfound', $result->errorkey);
        $this->assertNotEmpty($result->get_message());
    }

    /**
     * A payload missing its identifying fields is not accepted.
     */
    public function test_get_activity_bad_shape(): void {
        $this->resetAfterTest();

        $result = remote_client::get_activity(
            'https://source.example.edu',
            'abcdef0123456789abcdef0123456789',
            11,
            $this->mock_client(new Response(200, [], json_encode(['cmid' => 11])))
        );

        $this->assertFalse($result->success);
        $this->assertSame('errorbadresponse', $result->errorkey);
    }

    /**
     * Remote error codes and the message each should produce.
     *
     * @return array
     */
    public static function errorcode_provider(): array {
        return [
            ['invalidtoken', 'errorbadtoken'],
            ['expiredtoken', 'errorbadtoken'],
            ['sitemaintenance', 'errormaintenance'],
            ['accessexception', 'errorserviceunavailable'],
            ['enablewsdescription', 'errorserviceunavailable'],
            ['nopermissions', 'errornopermission'],
            ['requirecapability', 'errornopermission'],
            ['invalidrecord', 'errorpluginmissing'],
            ['unknownfunction', 'errorpluginmissing'],
        ];
    }

    /**
     * Each remote error code maps to a message that exists.
     *
     * @param string $errorcode
     * @param string $expected
     */
    #[DataProvider('errorcode_provider')]
    public function test_error_mapping(string $errorcode, string $expected): void {
        $this->resetAfterTest();

        $this->assertSame($expected, remote_client::classify_moodle_error($errorcode));
        $this->assertNotEmpty(get_string($expected, 'block_coursesync'));
    }

    /**
     * An error code we have never seen still produces a usable sentence, and
     * leaves a breadcrumb for whoever has to add the mapping.
     */
    public function test_unknown_errorcode_is_reported_generically(): void {
        $this->resetAfterTest();

        $this->assertSame('errorremoterefused', remote_client::classify_moodle_error('somethingnobodyanticipated'));
        $this->assertDebuggingCalled();
        $this->assertNotEmpty(get_string('errorremoterefused', 'block_coursesync'));
    }

    /**
     * Transport failures are separated into certificate, blocked and unreachable.
     */
    public function test_transport_error_mapping(): void {
        $this->resetAfterTest();

        $this->assertSame(
            'errorcertificate',
            remote_client::classify_transport_error(new \Exception('SSL certificate problem: self signed certificate'))
        );
        $this->assertSame(
            'errorblocked',
            remote_client::classify_transport_error(new \Exception('The URL is blocked.'))
        );
        $this->assertSame(
            'errorunreachable',
            remote_client::classify_transport_error(new \Exception('Could not resolve host: nowhere.example'))
        );
    }
}
