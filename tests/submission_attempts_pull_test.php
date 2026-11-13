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
use block_coursesync\local\submission_fixtures;
use block_coursesync\local\submission_writer;
use block_coursesync\local\submissions;
use core\http_client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local/source_on_this_site.php');
require_once(__DIR__ . '/local/submission_fixtures.php');

/**
 * Tests for pulling every attempt of students' assignment submissions.
 *
 * The "other site" is this site, as in submission_pull_test: a source course
 * holds the attempts, a destination course holds the copy of the assignment,
 * and every call between them runs the real source functions.
 *
 * What matters most is the history here: each attempt lands as the same
 * attempt, there is always exactly one latest, nothing a person did here is
 * overwritten, and the two ways of pulling cannot damage each other's work.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(submission_pull::class)]
#[CoversClass(submission_writer::class)]
#[CoversClass(submissions_result::class)]
final class submission_attempts_pull_test extends advanced_testcase {
    use local\source_on_this_site;
    use submission_fixtures;

    /** @var \stdClass The destination course. */
    protected \stdClass $course;

    /** @var int The Course Sync block in the destination. */
    protected int $instanceid;

    /** @var \stdClass A student in both courses, username "sam". */
    protected \stdClass $sam;

    /** @var \stdClass The assignment on the source. */
    protected \stdClass $there;

    /** @var \stdClass The copy of it here. */
    protected \stdClass $here;

    /** @var callable[] Changes to what the source says, by function name. */
    protected array $tamper = [];

    /** @var array[] Parameters of every call the source received. */
    protected array $calls = [];

    /**
     * A source course and a destination course with an assignment that allows
     * as many attempts as there are, a block mapped between them, a student in
     * both, and every switch on.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $source = $generator->create_course();
        $this->course = $generator->create_course();
        $this->sam = $generator->create_and_enrol($source, 'student', ['username' => 'sam']);
        $generator->enrol_user($this->sam->id, $this->course->id, 'student');

        $attempts = ['attemptreopenmethod' => 'manual', 'maxattempts' => -1];
        $this->there = $this->assignment($source, ['name' => 'Essay'] + $attempts);
        $this->here = $this->assignment(
            $this->course,
            ['name' => 'Essay'] + $attempts,
            ['idnumber' => 'coursesync-' . $this->there->cmid]
        );

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($this->course->id));
        $page->set_course($this->course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $this->course->format);
        $page->set_url('/course/view.php', ['id' => $this->course->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');

        $this->instanceid = (int) $DB->get_field('block_instances', 'id', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($this->course->id)->id,
        ], MUST_EXIST);

        connection::set_url($this->instanceid, $this->course->id, 'https://source.example.edu');
        connection::set_token($this->instanceid, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            $this->instanceid,
            'REMOTE1',
            course_result::success((int) $source->id, 'REMOTE1', 'Remote Source Course', true, 1)
        );

        foreach (['allowsubmissionpull', 'allowsubmissionexport', 'allowattemptspull', 'allowattemptsexport'] as $switch) {
            set_config($switch, 1, 'block_coursesync');
        }
    }

    /**
     * The source: this site's own functions, which a test can make lie.
     *
     * @return http_client
     */
    protected function source(): http_client {
        return new http_client(['mock' => function (RequestInterface $request) {
            parse_str((string) $request->getBody(), $params);
            $this->calls[] = $params;
            $function = (string) ($params['wsfunction'] ?? '');
            $puller = $GLOBALS['USER'];
            $this->setAdminUser();

            try {
                $body = $this->answer($function, $params);

                if (isset($this->tamper[$function])) {
                    $body = ($this->tamper[$function])($body, $params);
                }
            } catch (\moodle_exception $e) {
                $body = ['exception' => get_class($e), 'errorcode' => $e->errorcode, 'message' => $e->getMessage()];
            } finally {
                $this->setUser($puller);
            }

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
        }]);
    }

    /**
     * Preview a pull of every attempt.
     *
     * @return grade_pull_result
     */
    protected function preview_all(): grade_pull_result {
        return submission_pull::preview($this->instanceid, $this->course->id, $this->source(), null, true);
    }

    /**
     * Pull every attempt for real.
     *
     * @return grade_pull_result
     */
    protected function pull_all(): grade_pull_result {
        return submission_pull::run($this->instanceid, $this->course->id, $this->source(), null, true);
    }

    /**
     * Pull the latest attempt alone, as before.
     *
     * @return grade_pull_result
     */
    protected function pull_latest(): grade_pull_result {
        return submission_pull::run($this->instanceid, $this->course->id, $this->source());
    }

    /**
     * What a pull did, attempt by attempt: outcome (and reason, if any) by attempt number.
     *
     * @param grade_pull_result $result
     * @return string[]
     */
    protected function outcomes(grade_pull_result $result): array {
        $this->assertTrue($result->success, (string) $result->errorkey);
        $found = [];

        foreach ($result->entries as $entry) {
            $found[$entry->attempt] = $entry->outcome . ($entry->reason ? ':' . $entry->reason : '');
        }

        return $found;
    }

    /**
     * Hand work in on the source.
     *
     * @param string $text
     * @param string[] $files
     * @return void
     */
    protected function hand_in(string $text, array $files = []): void {
        $this->submit($this->sam, $this->there, $text, $files);
    }

    /**
     * Hand work in to the copy here, as the student would have.
     *
     * @param string $text
     * @return void
     */
    protected function hand_in_here(string $text): void {
        $this->submit($this->sam, $this->here, $text);
    }

    /**
     * Three goes on the source, each with a file of its own.
     *
     * @return void
     */
    protected function three_goes(): void {
        $this->hand_in('first', ['a.txt' => 'one']);
        $this->reopen($this->there, $this->sam);
        $this->hand_in('second', ['b.txt' => 'two']);
        $this->reopen($this->there, $this->sam);
        $this->hand_in('third', ['c.txt' => 'three']);
    }

    /**
     * The student's attempts here: attempt number => [text, files, status, latest].
     *
     * @return array[]
     */
    protected function attempts_here(): array {
        global $DB;

        $found = [];
        $rows = $DB->get_records(
            'assign_submission',
            ['assignment' => $this->here->id, 'userid' => $this->sam->id],
            'attemptnumber ASC'
        );

        foreach ($rows as $row) {
            $text = $DB->get_field('assignsubmission_onlinetext', 'onlinetext', ['submission' => $row->id]);
            $files = [];

            foreach (
                get_file_storage()->get_area_files(
                    \context_module::instance($this->here->cmid)->id,
                    'assignsubmission_file',
                    'submission_files',
                    $row->id,
                    'filename',
                    false
                ) as $file
            ) {
                $files[$file->get_filename()] = $file->get_content();
            }

            $found[(int) $row->attemptnumber] = [
                'text' => $text === false ? null : $text,
                'files' => $files,
                'status' => $row->status,
                'latest' => (int) $row->latest,
            ];
        }

        return $found;
    }

    /**
     * The attempts here that are marked latest.
     *
     * @return int[] attempt numbers
     */
    protected function latest_here(): array {
        return array_keys(array_filter($this->attempts_here(), static fn(array $a) => $a['latest'] === 1));
    }

    /**
     * The records of what was pulled, by attempt number.
     *
     * @return \stdClass[]
     */
    protected function ledger(): array {
        global $DB;

        $found = [];

        foreach ($DB->get_records('block_coursesync_submission', ['assignid' => $this->here->id], 'attemptnumber ASC') as $row) {
            $found[(int) $row->attemptnumber] = $row;
        }

        return $found;
    }

    /**
     * Every attempt comes across as the same attempt, with its own text and
     * files, a record each, and exactly one is the latest.
     */
    public function test_every_attempt_comes_across_as_the_same_attempt(): void {
        global $DB;

        $this->three_goes();

        // A preview writes nothing.
        $this->assertSame(
            [0 => 'add', 1 => 'add', 2 => 'add'],
            $this->outcomes($this->preview_all())
        );
        $this->assertSame(0, $DB->count_records('assign_submission', ['assignment' => $this->here->id]));
        $this->assertSame([], $this->ledger());

        $this->assertSame([0 => 'add', 1 => 'add', 2 => 'add'], $this->outcomes($this->pull_all()));

        $here = $this->attempts_here();
        $this->assertSame([0, 1, 2], array_keys($here));
        $this->assertSame(['first', 'second', 'third'], array_column($here, 'text'));
        $this->assertSame(['a.txt' => 'one'], $here[0]['files']);
        $this->assertSame(['b.txt' => 'two'], $here[1]['files']);
        $this->assertSame(['c.txt' => 'three'], $here[2]['files']);
        $this->assertSame(['submitted', 'submitted', 'submitted'], array_column($here, 'status'));
        $this->assertSame([2], $this->latest_here());

        $ledger = $this->ledger();
        $this->assertSame([0, 1, 2], array_keys($ledger));

        foreach ($ledger as $number => $record) {
            $this->assertSame($number, (int) $record->remoteattempt);
            $this->assertSame((int) $DB->get_field('assign_submission', 'id', [
                'assignment' => $this->here->id, 'userid' => $this->sam->id, 'attemptnumber' => $number,
            ]), (int) $record->submissionid);
        }
    }

    /**
     * Each file is asked for by the attempt it is in.
     */
    public function test_files_are_fetched_from_the_named_attempt(): void {
        $this->three_goes();
        $this->pull_all();

        $fetched = [];

        foreach ($this->calls as $call) {
            if ($call['wsfunction'] === 'block_coursesync_get_submission_file') {
                $fetched[$call['filename']] = (int) $call['attemptnumber'];
            }
        }

        $this->assertSame(['a.txt' => 0, 'b.txt' => 1, 'c.txt' => 2], $fetched);
    }

    /**
     * A second pull finds everything the same.
     */
    public function test_pulling_again_changes_nothing(): void {
        global $DB;

        $this->three_goes();
        $this->pull_all();
        $before = $this->attempts_here();
        $rows = $DB->count_records('assign_submission', ['assignment' => $this->here->id]);
        $ledger = $DB->get_records('block_coursesync_submission');

        $this->assertSame([0 => 'same', 1 => 'same', 2 => 'same'], $this->outcomes($this->pull_all()));

        $this->assertSame($rows, $DB->count_records('assign_submission', ['assignment' => $this->here->id]));
        $this->assertSame($before, $this->attempts_here());
        $this->assertEquals($ledger, $DB->get_records('block_coursesync_submission'));
    }

    /**
     * A change to one attempt on the source updates that attempt here and no other.
     */
    public function test_a_change_to_one_attempt_updates_only_it(): void {
        global $DB;

        $this->three_goes();
        $this->pull_all();
        $second = $DB->get_field('assign_submission', 'id', [
            'assignment' => $this->there->id, 'userid' => $this->sam->id, 'attemptnumber' => 1,
        ]);
        $DB->set_field('assignsubmission_onlinetext', 'onlinetext', 'second, corrected', ['submission' => $second]);

        $this->assertSame([0 => 'same', 1 => 'update', 2 => 'same'], $this->outcomes($this->pull_all()));

        $this->assertSame(['first', 'second, corrected', 'third'], array_column($this->attempts_here(), 'text'));
        $this->assertSame([2], $this->latest_here());
    }

    /**
     * An attempt made on the source later is added, and becomes the latest here.
     */
    public function test_a_later_attempt_is_added_and_becomes_the_latest(): void {
        $this->hand_in('first');
        $this->reopen($this->there, $this->sam);
        $this->hand_in('second');
        $this->pull_all();
        $this->assertSame([1], $this->latest_here());

        $this->reopen($this->there, $this->sam);
        $this->hand_in('third');

        $this->assertSame([0 => 'same', 1 => 'same', 2 => 'add'], $this->outcomes($this->pull_all()));
        $this->assertSame(['first', 'second', 'third'], array_column($this->attempts_here(), 'text'));
        $this->assertSame([2], $this->latest_here());
    }

    /**
     * An attempt that someone changed here is kept - and only that one.
     */
    public function test_an_attempt_changed_here_is_kept(): void {
        global $DB;

        $this->three_goes();
        $this->pull_all();
        $here = $DB->get_field('assign_submission', 'id', [
            'assignment' => $this->here->id, 'userid' => $this->sam->id, 'attemptnumber' => 0,
        ]);
        $DB->set_field('assignsubmission_onlinetext', 'onlinetext', 'edited here', ['submission' => $here]);

        $first = $DB->get_field('assign_submission', 'id', [
            'assignment' => $this->there->id, 'userid' => $this->sam->id, 'attemptnumber' => 0,
        ]);
        $DB->set_field('assignsubmission_onlinetext', 'onlinetext', 'edited there', ['submission' => $first]);

        $this->assertSame(
            [0 => 'conflict:subreasonchanged', 1 => 'same', 2 => 'same'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame('edited here', $this->attempts_here()[0]['text']);
        $this->assertSame([2], $this->latest_here());
    }

    /**
     * Work here that did not come from the other site is kept, and the
     * attempts above it wait, so the two histories are not mixed.
     */
    public function test_work_here_of_its_own_marks_where_the_histories_part(): void {
        $this->three_goes();
        $this->hand_in_here('my own first go');

        $this->assertSame(
            [0 => 'conflict:subreasonexists', 1 => 'skipped:subreasonreopenedhere', 2 => 'skipped:subreasonreopenedhere'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame(['my own first go'], array_column($this->attempts_here(), 'text'));
        $this->assertSame([0], $this->latest_here());
        $this->assertSame([], $this->ledger());
    }

    /**
     * The attempts below such work still come across.
     */
    public function test_attempts_below_work_of_its_own_still_come_across(): void {
        $this->hand_in('first');
        $this->pull_all();

        // The teacher here gives another go and the student takes it.
        $this->reopen($this->here, $this->sam);
        $this->hand_in_here('my own second go');

        $this->reopen($this->there, $this->sam);
        $this->hand_in('second');
        $this->reopen($this->there, $this->sam);
        $this->hand_in('third');

        $this->assertSame(
            [0 => 'same', 1 => 'conflict:subreasonexists', 2 => 'skipped:subreasonreopenedhere'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame(['first', 'my own second go'], array_column($this->attempts_here(), 'text'));
        $this->assertSame([1], $this->latest_here());
    }

    /**
     * Work here that is exactly the other site's attempt is the same work,
     * and does not stop the attempts above it.
     */
    public function test_identical_work_here_does_not_stop_later_attempts(): void {
        $this->hand_in('first');
        $this->reopen($this->there, $this->sam);
        $this->hand_in('second');
        $this->hand_in_here('first');
        $this->reopen($this->here, $this->sam);
        $this->hand_in_here('second');

        $this->reopen($this->there, $this->sam);
        $this->hand_in('third');

        $this->assertSame([0 => 'same', 1 => 'same', 2 => 'add'], $this->outcomes($this->pull_all()));
        $this->assertSame(['first', 'second', 'third'], array_column($this->attempts_here(), 'text'));
        $this->assertSame([2], $this->latest_here());
    }

    /**
     * Rows here that were only opened hold no work: the attempts use them.
     */
    public function test_attempts_use_rows_that_were_only_opened(): void {
        global $DB;

        $this->hand_in('first');
        $this->reopen($this->there, $this->sam);
        $this->hand_in('second');

        $base = ['assignment' => $this->here->id, 'userid' => $this->sam->id, 'groupid' => 0,
            'timecreated' => time(), 'timemodified' => time(), 'timestarted' => time()];
        $DB->insert_record('assign_submission', (object) ($base + ['status' => 'new', 'attemptnumber' => 0, 'latest' => 0]));
        $DB->insert_record('assign_submission', (object) ($base + ['status' => 'reopened', 'attemptnumber' => 1, 'latest' => 1]));

        $this->assertSame([0 => 'add', 1 => 'add'], $this->outcomes($this->pull_all()));

        $this->assertSame(2, $DB->count_records('assign_submission', ['assignment' => $this->here->id]));
        $this->assertSame(['first', 'second'], array_column($this->attempts_here(), 'text'));
        $this->assertSame(['submitted', 'submitted'], array_column($this->attempts_here(), 'status'));
        $this->assertSame([1], $this->latest_here());
    }

    /**
     * A pull of the latest alone leaves a student whose attempts were pulled
     * exactly as they are: it would write the newest over attempt 0.
     */
    public function test_a_latest_only_pull_leaves_pulled_attempts_alone(): void {
        global $DB;

        $this->three_goes();
        $this->pull_all();
        $before = $this->attempts_here();
        $ledger = $DB->get_records('block_coursesync_submission');

        $third = $DB->get_field('assign_submission', 'id', [
            'assignment' => $this->there->id, 'userid' => $this->sam->id, 'attemptnumber' => 2,
        ]);
        $DB->set_field('assignsubmission_onlinetext', 'onlinetext', 'third, changed', ['submission' => $third]);

        $this->assertSame([2 => 'skipped:subreasonattemptsdone'], $this->outcomes($this->pull_latest()));

        $this->assertSame($before, $this->attempts_here());
        $this->assertEquals($ledger, $DB->get_records('block_coursesync_submission'));
    }

    /**
     * A student with only the first attempt pulled is still updated by a
     * pull of the latest: nothing there to damage.
     */
    public function test_a_latest_only_pull_still_updates_a_single_attempt(): void {
        global $DB;

        $this->hand_in('first');
        $this->pull_all();
        $first = $DB->get_field('assign_submission', 'id', [
            'assignment' => $this->there->id, 'userid' => $this->sam->id, 'attemptnumber' => 0,
        ]);
        $DB->set_field('assignsubmission_onlinetext', 'onlinetext', 'first, changed', ['submission' => $first]);

        $this->assertSame([0 => 'update'], $this->outcomes($this->pull_latest()));
        $this->assertSame(['first, changed'], array_column($this->attempts_here(), 'text'));
    }

    /**
     * Pulling every attempt mends a copy a pull of the latest made: the
     * newest was written as attempt 0, and goes back to being attempt 0.
     */
    public function test_pulling_every_attempt_mends_a_latest_only_copy(): void {
        $this->three_goes();
        $this->pull_latest();

        $this->assertSame(['third'], array_column($this->attempts_here(), 'text'));
        $this->assertSame(2, (int) $this->ledger()[0]->remoteattempt);

        $this->assertSame([0 => 'update', 1 => 'add', 2 => 'add'], $this->outcomes($this->pull_all()));

        $this->assertSame(['first', 'second', 'third'], array_column($this->attempts_here(), 'text'));
        $this->assertSame(['a.txt' => 'one'], $this->attempts_here()[0]['files']);
        $this->assertSame([2], $this->latest_here());
        $this->assertSame([0, 1, 2], array_keys($this->ledger()));
        $this->assertSame(0, (int) $this->ledger()[0]->remoteattempt);
    }

    /**
     * An attempt that does not arrive intact holds back the later ones, so the
     * history here has no hole; the next pull carries on.
     */
    public function test_a_failed_attempt_holds_back_the_later_ones(): void {
        $this->three_goes();
        $this->tamper['block_coursesync_get_submissions'] = static function (array $body): array {
            $body['items'][0]['submissions'][0]['attempts'][1]['files'][0]['contenthash'] = sha1('not the file');

            return $body;
        };

        $this->assertSame(
            [0 => 'add', 1 => 'skipped:subreasoncorrupt', 2 => 'skipped:subreasonearlierskipped'],
            $this->outcomes($this->pull_all())
        );
        $this->assertSame(['first'], array_column($this->attempts_here(), 'text'));
        $this->assertSame([0], $this->latest_here());
        $this->assertSame([0], array_keys($this->ledger()));

        $this->tamper = [];

        $this->assertSame([0 => 'same', 1 => 'add', 2 => 'add'], $this->outcomes($this->pull_all()));
        $this->assertSame(['first', 'second', 'third'], array_column($this->attempts_here(), 'text'));
        $this->assertSame([2], $this->latest_here());
    }

    /**
     * An attempt whose content this assignment cannot take holds back the later ones too.
     */
    public function test_an_attempt_this_assignment_cannot_take_holds_back_the_later_ones(): void {
        $this->three_goes();
        $this->tamper['block_coursesync_get_submissions'] = static function (array $body): array {
            $body['items'][0]['submissions'][0]['attempts'][1]['files'][0]['filesize'] = 999999999999;

            return $body;
        };
        set_config('maxbytes', 1048576);

        $this->assertSame(
            [0 => 'add', 1 => 'skipped:subreasonfilesize', 2 => 'skipped:subreasonearlierskipped'],
            $this->outcomes($this->pull_all())
        );
    }

    /**
     * Asking for every attempt is refused unless this site allows it, before
     * the other site is asked anything.
     */
    public function test_pulling_every_attempt_needs_this_sites_switch(): void {
        $this->hand_in('first');
        set_config('allowattemptspull', 0, 'block_coursesync');

        $result = $this->pull_all();

        $this->assertFalse($result->success);
        $this->assertSame('errorattemptspulloff', $result->errorkey);
        $this->assertSame([], $this->calls);
        $this->assertNull($this->attempts_here()[0] ?? null);

        // The latest alone is not affected.
        $this->assertSame([0 => 'add'], $this->outcomes($this->pull_latest()));
    }

    /**
     * A source that does not offer every attempt is not asked for them, and
     * nothing is pulled in their place.
     */
    public function test_a_source_that_does_not_offer_every_attempt_is_not_asked(): void {
        $this->hand_in('first');
        set_config('allowattemptsexport', 0, 'block_coursesync');

        $result = $this->pull_all();

        $this->assertFalse($result->success);
        $this->assertSame('errorattemptssourceunable', $result->errorkey);
        $this->assertSame(['block_coursesync_ping'], array_column($this->calls, 'wsfunction'));
        $this->assertSame([], $this->attempts_here());

        // And one from before the feature existed, which says nothing about it.
        $this->calls = [];
        set_config('allowattemptsexport', 1, 'block_coursesync');
        $this->tamper['block_coursesync_ping'] = static function (array $body): array {
            unset($body['features']);

            return $body;
        };

        $this->assertSame('errorattemptssourceunable', $this->pull_all()->errorkey);
        $this->assertNotContains('block_coursesync_get_submissions', array_column($this->calls, 'wsfunction'));
    }

    /**
     * A ping that fails stops the pull with its own error.
     */
    public function test_a_failed_ping_stops_the_pull(): void {
        $this->hand_in('first');
        $this->tamper['block_coursesync_ping'] = static function (): array {
            return ['exception' => 'moodle_exception', 'errorcode' => 'invalidtoken', 'message' => 'no'];
        };

        $result = $this->pull_all();

        $this->assertFalse($result->success);
        $this->assertNotContains('block_coursesync_get_submissions', array_column($this->calls, 'wsfunction'));
        $this->assertSame([], $this->attempts_here());
    }

    /**
     * A pull of the latest never mentions attempts to the source: one from
     * before they existed would refuse a parameter it does not know.
     */
    public function test_a_latest_only_pull_sends_nothing_about_attempts(): void {
        $this->hand_in('first', ['a.txt' => 'one']);
        $this->pull_latest();

        $this->assertNotEmpty($this->calls);
        $this->assertContains('block_coursesync_get_submission_file', array_column($this->calls, 'wsfunction'));

        foreach ($this->calls as $call) {
            $this->assertArrayNotHasKey('allattempts', $call);
            $this->assertArrayNotHasKey('attemptnumber', $call);
        }

        $this->assertNotContains('block_coursesync_ping', array_column($this->calls, 'wsfunction'));
    }

    /**
     * An assignment that allows fewer attempts than the other site has says so
     * in the preview and in the pull, writes every attempt all the same, and
     * says nothing where the limit is enough or there is none.
     */
    public function test_too_few_attempts_allowed_here_is_a_warning_not_a_stop(): void {
        global $DB;

        $this->three_goes();
        $DB->set_field('assign', 'maxattempts', 2, ['id' => $this->here->id]);

        foreach ([$this->preview_all(), $this->pull_all()] as $result) {
            $this->assertCount(1, $result->warnings);
            $this->assertSame('submissionswarnmaxattempts', $result->warnings[0]->key);
            $this->assertSame('Essay', $result->warnings[0]->a->activity);
            $this->assertSame(3, $result->warnings[0]->a->attempts);
            $this->assertSame(2, $result->warnings[0]->a->allowed);
            $this->assertTrue($result->everyattempt);
        }

        $this->assertSame(['first', 'second', 'third'], array_column($this->attempts_here(), 'text'));

        // Enough, or no limit: nothing to say.
        foreach ([3, 5, -1] as $allowed) {
            $DB->set_field('assign', 'maxattempts', $allowed, ['id' => $this->here->id]);
            $this->assertSame([], $this->pull_all()->warnings, (string) $allowed);
        }

        // And a pull of the latest alone is not about attempts.
        $DB->set_field('assign', 'maxattempts', 1, ['id' => $this->here->id]);
        $latest = $this->pull_latest();
        $this->assertSame([], $latest->warnings);
        $this->assertFalse($latest->everyattempt);
    }

    /**
     * The ticks the page would send for a preview of every attempt: the keys of
     * the attempts that would be written, less the numbers left unticked.
     *
     * @param int[] $unticked attempt numbers left out
     * @return bool[] key => true
     */
    protected function ticks_except(array $unticked): array {
        $ticks = [];

        foreach ($this->preview_all()->entries as $entry) {
            if (in_array($entry->outcome, ['add', 'update'], true) && !in_array((int) $entry->attempt, $unticked, true)) {
                $ticks[grade_pull_result::key($entry)] = true;
            }
        }

        return $ticks;
    }

    /**
     * An attempt left unticked is not brought, and the student's later ones
     * wait for it, so their history has no hole. Nothing is recorded for the
     * ones left out, so the next preview offers them again.
     */
    public function test_an_unticked_attempt_holds_back_the_later_ones(): void {
        $this->three_goes();
        $ticks = $this->ticks_except([1]);
        $this->assertCount(2, $ticks);

        $result = submission_pull::run($this->instanceid, $this->course->id, $this->source(), null, true, $ticks);

        $this->assertSame(
            [0 => 'add', 1 => 'skipped:subreasonnotchosen', 2 => 'skipped:subreasonearlierskipped'],
            $this->outcomes($result)
        );
        $this->assertSame(['first'], array_column($this->attempts_here(), 'text'));
        $this->assertSame([0], array_keys($this->ledger()));

        // Both are offered again.
        $this->assertSame([0 => 'same', 1 => 'add', 2 => 'add'], $this->outcomes($this->preview_all()));
    }

    /**
     * An attempt that is not offered because it is already the same is not a
     * hole: the later attempts that were ticked still come across.
     */
    public function test_an_attempt_that_is_already_here_does_not_hold_back_the_later_ones(): void {
        $this->hand_in('first', ['a.txt' => 'one']);
        $this->pull_all();
        $this->reopen($this->there, $this->sam);
        $this->hand_in('second', ['b.txt' => 'two']);
        $this->reopen($this->there, $this->sam);
        $this->hand_in('third', ['c.txt' => 'three']);

        $ticks = $this->ticks_except([]);
        $this->assertCount(2, $ticks);

        $result = submission_pull::run($this->instanceid, $this->course->id, $this->source(), null, true, $ticks);

        $this->assertSame([0 => 'same', 1 => 'add', 2 => 'add'], $this->outcomes($result));
        $this->assertSame(['first', 'second', 'third'], array_column($this->attempts_here(), 'text'));
    }

    /**
     * One attempt removed here is offered again on its own, comes back as the
     * same attempt without disturbing the others, and the latest stays the
     * latest.
     */
    public function test_a_removed_attempt_comes_back_as_the_same_attempt(): void {
        global $DB;

        $this->three_goes();
        $this->pull_all();
        $row = $DB->get_record('assign_submission', [
            'assignment' => $this->here->id, 'userid' => $this->sam->id, 'attemptnumber' => 1,
        ], '*', MUST_EXIST);
        $DB->delete_records('assignsubmission_onlinetext', ['submission' => $row->id]);
        $DB->delete_records('assignsubmission_file', ['submission' => $row->id]);
        $DB->delete_records('assign_submission', ['id' => $row->id]);
        $this->assertSame(['first', 'third'], array_column($this->attempts_here(), 'text'));

        $preview = $this->preview_all();
        $this->assertSame([0 => 'same', 1 => 'add:subreasonreturned', 2 => 'same'], $this->outcomes($preview));

        $this->assertSame([0 => 'same', 1 => 'add:subreasonreturned', 2 => 'same'], $this->outcomes($this->pull_all()));

        $here = $this->attempts_here();
        $this->assertSame([0, 1, 2], array_keys($here));
        $this->assertSame(['first', 'second', 'third'], array_column($here, 'text'));
        $this->assertSame(['b.txt' => 'two'], $here[1]['files']);
        $this->assertSame([2], $this->latest_here());
        $this->assertSame([0, 1, 2], array_keys($this->ledger()));
        $this->assertSame([0 => 'same', 1 => 'same', 2 => 'same'], $this->outcomes($this->preview_all()));
    }
}
