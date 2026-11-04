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
 * @copyright  2026 Bill White
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
     * The features a source says it offers are kept; a source that predates
     * them offers none, and nothing odd is let through.
     */
    public function test_features_in_a_ping(): void {
        $this->resetAfterTest();

        $ping = function (array $extra): ping_result {
            $body = json_encode(['status' => true, 'sitename' => 'S', 'release' => '5.1', 'pluginversion' => 1] + $extra);

            return remote_client::ping(
                'https://source.example.edu',
                'abcdef0123456789abcdef0123456789',
                $this->mock_client(new Response(200, ['Content-Type' => 'application/json'], $body))
            );
        };

        $old = $ping([]);
        $this->assertSame([], $old->features);
        $this->assertFalse($old->offers('attempts1'));

        $new = $ping(['features' => ['attempts1']]);
        $this->assertSame(['attempts1'], $new->features);
        $this->assertTrue($new->offers('attempts1'));
        $this->assertFalse($new->offers('attempts2'));

        // Not a list, or not plain words: ignored rather than trusted.
        $this->assertSame([], $ping(['features' => 'attempts1'])->features);
        $this->assertSame(
            ['attempts1'],
            $ping(['features' => ['attempts1', 'attempts1', '<b>x</b>', '', 5, ['a'], 'a b']])->features
        );
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

    /**
     * A redirect is reported rather than followed, so the token is never
     * sent on to an address nobody checked.
     */
    public function test_a_redirect_is_not_followed(): void {
        $this->resetAfterTest();

        $seen = null;
        $client = new http_client(['mock' => new MockHandler([
            function ($request, array $options) use (&$seen) {
                $seen = $options;

                return new Response(307, ['Location' => 'http://elsewhere.example.org/'], '');
            },
        ])]);

        $result = remote_client::ping('https://source.example.edu', 'abcdef0123456789abcdef0123456789', $client);

        $this->assertFalse($result->success);
        $this->assertSame('errorredirected', $result->errorkey);
        $this->assertFalse($seen['allow_redirects']);
    }

    /**
     * A long list of activities is asked for in batches the source accepts,
     * and the answers are put back together - for grades and for attempts.
     */
    public function test_many_activities_are_asked_for_in_batches(): void {
        $this->resetAfterTest();

        $calls = [];
        $client = new http_client(['mock' => function (\Psr\Http\Message\RequestInterface $request) use (&$calls) {
            parse_str((string) $request->getBody(), $params);
            $calls[] = $params;
            $cmids = array_map('intval', $params['cmids']);

            $body = $params['wsfunction'] === 'block_coursesync_get_grades'
                ? ['items' => array_map(static fn($cmid) => [
                    'cmid' => $cmid, 'itemnumber' => 0, 'gradetype' => 1, 'grademin' => 0, 'grademax' => 10,
                    'scale' => '', 'hidden' => 0, 'grades' => [],
                ], $cmids)]
                : ['formatversion' => 1, 'quizzes' => array_map(static fn($cmid) => [
                    'cmid' => $cmid, 'sumgrades' => 1, 'grade' => 10, 'slots' => [], 'attempts' => [],
                ], $cmids)];

            return \GuzzleHttp\Promise\Create::promiseFor(new Response(200, [], json_encode($body)));
        }]);

        $wanted = range(1, \block_coursesync\external\get_grades::MAX_CMIDS + 1);

        $grades = remote_client::get_grades('https://source.example.edu', str_repeat('a', 32), 42, $wanted, $client, ['sam']);
        $attempts = remote_client::get_quiz_attempts(
            'https://source.example.edu',
            str_repeat('a', 32),
            42,
            $wanted,
            $client,
            ['sam']
        );

        $this->assertTrue($grades->success);
        $this->assertSame($wanted, array_column($grades->items, 'cmid'));
        $this->assertTrue($attempts->success);
        $this->assertSame($wanted, array_column($attempts->quizzes, 'cmid'));
        $this->assertSame([500, 1, 500, 1], array_map(static fn($call) => count($call['cmids']), $calls));
    }

    /**
     * A source whose answers can be counted: it describes each assignment asked
     * about with one row per student asked about, and notes every call.
     *
     * @param array $calls filled with the parameters of every call
     * @param bool $everyone also answer with students that were not asked for
     * @return http_client
     */
    protected function counting_source(array &$calls, bool $everyone = false): http_client {
        return new http_client(['mock' => function (\Psr\Http\Message\RequestInterface $request) use (&$calls, $everyone) {
            parse_str((string) $request->getBody(), $params);
            $calls[] = $params;
            $names = isset($params['usernames']) ? explode("\n", $params['usernames']) : ['everyone'];

            if ($everyone) {
                $names = array_merge(['sam'], $names);
            }

            $items = [];

            foreach ($params['cmids'] as $cmid) {
                $rows = array_map(
                    static fn($name) => $params['wsfunction'] === 'block_coursesync_get_submissions'
                        ? ['username' => $name, 'fingerprint' => 'abc', 'files' => [], 'onlinetext' => 'text',
                            'attempts' => [['attemptnumber' => 0, 'fingerprint' => 'abc', 'files' => [], 'onlinetext' => 'text']]]
                        : ['username' => $name, 'fingerprint' => 'abc', 'files' => [], 'grade' => 5,
                            'attempts' => [['attemptnumber' => 0, 'fingerprint' => 'abc', 'files' => [], 'grade' => 5]]],
                    $names
                );

                $items[] = $params['wsfunction'] === 'block_coursesync_get_submissions'
                    ? ['cmid' => (int) $cmid, 'reason' => '', 'plugins' => ['onlinetext'], 'submissions' => $rows]
                    : ['cmid' => (int) $cmid, 'reason' => '', 'grademax' => 10, 'marks' => $rows];
            }

            return \GuzzleHttp\Promise\Create::promiseFor(new Response(200, [], json_encode(['items' => $items])));
        }]);
    }

    /**
     * A big class is asked about in groups of students, so no call has more
     * to answer than it can in good time, and each assignment comes back
     * whole: every student, once, in the order asked.
     */
    public function test_many_students_are_asked_for_in_batches(): void {
        $this->resetAfterTest();

        $students = array_map(static fn($n) => 'student' . $n, range(1, 450));
        $url = 'https://source.example.edu';
        $token = str_repeat('a', 32);

        foreach (['submissions' => 'get_submissions', 'marks' => 'get_marks'] as $field => $function) {
            $calls = [];
            $answer = remote_client::$function($url, $token, 42, [7, 8], $this->counting_source($calls), $students);

            $this->assertTrue($answer->success, $function);

            // Two assignments of 450 students is 900 pairs: groups of 200.
            $this->assertSame([200, 200, 50], array_map(static fn($c) => count(explode("\n", $c['usernames'])), $calls));
            $this->assertCount(3, $calls);
            $this->assertSame([7, 8], array_column($answer->items, 'cmid'));

            foreach ($answer->items as $item) {
                $this->assertSame($students, array_column($item->$field, 'username'), $function);
            }
        }
    }

    /**
     * Asking for every attempt uses smaller groups of students, and says so to
     * the source - which a request for the latest alone never does.
     */
    public function test_every_attempt_uses_smaller_groups_and_says_so(): void {
        $this->resetAfterTest();

        $students = array_map(static fn($n) => 'student' . $n, range(1, 250));
        $url = 'https://source.example.edu';
        $token = str_repeat('a', 32);

        foreach (['submissions' => 'get_submissions', 'marks' => 'get_marks'] as $field => $function) {
            $calls = [];
            $latest = remote_client::$function($url, $token, 42, [7], $this->counting_source($calls), $students);
            $this->assertTrue($latest->success, $function);
            $this->assertSame([250], array_map(static fn($c) => count(explode("\n", $c['usernames'])), $calls), $function);
            $this->assertArrayNotHasKey('allattempts', $calls[0], $function);

            $calls = [];
            $every = remote_client::$function($url, $token, 42, [7], $this->counting_source($calls), $students, true);
            $this->assertTrue($every->success, $function);
            $this->assertSame(
                [remote_client::ATTEMPT_PAIRS_PER_CALL, remote_client::ATTEMPT_PAIRS_PER_CALL, 50],
                array_map(static fn($c) => count(explode("\n", $c['usernames'])), $calls),
                $function
            );
            $this->assertSame(['1', '1', '1'], array_column($calls, 'allattempts'), $function);
            $this->assertSame($students, array_column($every->items[0]->$field, 'username'), $function);
            $this->assertCount(1, $every->items[0]->$field[0]->attempts, $function);
        }
    }

    /**
     * Many assignments cut the groups of students smaller, still within the
     * most one call is asked for.
     */
    public function test_many_assignments_make_smaller_groups_of_students(): void {
        $this->resetAfterTest();

        $cmids = range(1, \block_coursesync\external\get_submissions::MAX_CMIDS);
        $students = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'];
        $calls = [];

        $answer = remote_client::get_submissions(
            'https://source.example.edu',
            str_repeat('a', 32),
            42,
            $cmids,
            $this->counting_source($calls),
            $students
        );

        $this->assertTrue($answer->success);
        // 100 assignments: 4 students a call, so 3 calls.
        $this->assertSame([4, 4, 1], array_map(static fn($c) => count(explode("\n", $c['usernames'])), $calls));
        $this->assertSame(
            [100, 100, 100],
            array_map(static fn($c) => count($c['cmids']), $calls)
        );
        $this->assertCount(100, $answer->items);
        $this->assertSame($students, array_column($answer->items[99]->submissions, 'username'));

        foreach ($calls as $call) {
            $pairs = count($call['cmids']) * count(explode("\n", $call['usernames']));
            $this->assertLessThanOrEqual(remote_client::PAIRS_PER_CALL, $pairs);
        }
    }

    /**
     * With no list of students there is nothing to count: one call, and no list.
     */
    public function test_with_no_list_of_students_there_is_one_call(): void {
        $this->resetAfterTest();

        $calls = [];
        $source = $this->counting_source($calls);
        $answer = remote_client::get_marks('https://source.example.edu', str_repeat('a', 32), 42, [7], $source);

        $this->assertTrue($answer->success);
        $this->assertCount(1, $calls);
        $this->assertArrayNotHasKey('usernames', $calls[0]);
    }

    /**
     * A student the source answers for again, whatever it was asked, is held
     * once: nothing is added twice to what the pull then writes.
     */
    public function test_a_student_answered_twice_is_kept_once(): void {
        $this->resetAfterTest();

        $students = array_map(static fn($n) => 'student' . $n, range(1, 450));
        $calls = [];

        $answer = remote_client::get_submissions(
            'https://source.example.edu',
            str_repeat('a', 32),
            42,
            [7, 8],
            $this->counting_source($calls, true),
            $students
        );

        $this->assertTrue($answer->success);
        $this->assertCount(2, $answer->items);

        foreach ($answer->items as $item) {
            $this->assertSame(array_merge(['sam'], $students), array_column($item->submissions, 'username'));
        }
    }
}
