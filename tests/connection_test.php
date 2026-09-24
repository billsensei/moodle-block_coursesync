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

/**
 * Tests for connection storage, including encryption of the token at rest.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(connection::class)]
final class connection_test extends advanced_testcase {
    /** @var int A stand-in block instance id. */
    public const INSTANCE = 4242;

    /**
     * A URL is stored normalised, and the record starts out untested.
     */
    public function test_set_url_creates_record(): void {
        $this->resetAfterTest();

        $record = connection::set_url(self::INSTANCE, 7, 'https://source.example.edu/');

        $this->assertSame('https://source.example.edu', $record->remoteurl);
        $this->assertSame(7, (int) $record->courseid);
        $this->assertSame(connection::STAGE_TOKEN, $record->setupstage);
        $this->assertSame(connection::STATUS_NEW, $record->status);
        $this->assertNull($record->token);
        $this->assertFalse(connection::is_configured($record));
    }

    /**
     * The token never reaches the database in plain text, but round-trips.
     */
    public function test_token_is_encrypted_at_rest(): void {
        global $DB;

        $this->resetAfterTest();

        $token = 'abcdef0123456789abcdef0123456789';
        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, $token);

        $stored = $DB->get_field('block_coursesync_connection', 'token', ['blockinstanceid' => self::INSTANCE]);

        $this->assertNotEmpty($stored);
        $this->assertNotSame($token, $stored);
        $this->assertStringNotContainsString($token, $stored);
        $this->assertSame($token, connection::get_token(self::INSTANCE));
    }

    /**
     * The hint is short enough to be useless on its own.
     */
    public function test_token_hint_is_only_the_tail(): void {
        $this->resetAfterTest();

        $token = 'abcdef0123456789abcdef0123456789';
        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, $token);

        $record = connection::get(self::INSTANCE);

        $this->assertSame('6789', $record->tokenhint);
        $this->assertSame(4, \core_text::strlen($record->tokenhint));
    }

    /**
     * Pointing the block at a different site throws the old token away.
     */
    public function test_changing_url_discards_the_token(): void {
        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');
        connection::record_success(self::INSTANCE, 'Source', '5.1');

        $this->assertNotNull(connection::get_token(self::INSTANCE));

        $record = connection::set_url(self::INSTANCE, 7, 'https://elsewhere.example.edu');

        $this->assertNull($record->token);
        $this->assertNull($record->tokenhint);
        $this->assertNull(connection::get_token(self::INSTANCE));
        $this->assertSame(connection::STATUS_NEW, $record->status);
        $this->assertSame(0, (int) $record->lastcheck);
    }

    /**
     * Re-saving the same URL leaves a working connection alone.
     */
    public function test_saving_the_same_url_keeps_the_token(): void {
        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');

        // Same site, typed with a trailing slash this time.
        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu/');

        $this->assertSame('abcdef0123456789abcdef0123456789', connection::get_token(self::INSTANCE));
    }

    /**
     * An unreadable stored token is reported as absent rather than throwing.
     */
    public function test_unreadable_token_returns_null(): void {
        global $DB;

        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');

        // Simulate a database restored without the site's encryption key.
        $DB->set_field(
            'block_coursesync_connection',
            'token',
            'not-actually-ciphertext',
            ['blockinstanceid' => self::INSTANCE]
        );

        $this->assertNull(connection::get_token(self::INSTANCE));
    }

    /**
     * A token cannot be stored before we know where it belongs.
     */
    public function test_token_requires_a_url_first(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');
    }

    /**
     * Success and failure are both recorded against the connection.
     */
    public function test_recording_results(): void {
        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');

        connection::record_success(self::INSTANCE, 'Source Site', '5.1.7+');
        $record = connection::get(self::INSTANCE);

        $this->assertSame(connection::STATUS_OK, $record->status);
        $this->assertSame('Source Site', $record->remotesitename);
        $this->assertSame('5.1.7+', $record->remoterelease);
        $this->assertNull($record->lasterror);
        $this->assertGreaterThan(0, $record->lastcheck);
        $this->assertTrue(connection::is_configured($record));

        connection::record_failure(self::INSTANCE, 'errorbadtoken');
        $record = connection::get(self::INSTANCE);

        $this->assertSame(connection::STATUS_ERROR, $record->status);
        $this->assertSame('errorbadtoken', $record->lasterror);
        $this->assertNotEmpty(get_string($record->lasterror, 'block_coursesync'));
    }

    /**
     * A course mapping is stored alongside the connection.
     */
    public function test_set_remote_course(): void {
        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');

        $resolved = course_result::success(42, 'REMOTE1', 'Remote Source Course', true, 3);
        connection::set_remote_course(self::INSTANCE, 'REMOTE1', $resolved);

        $record = connection::get(self::INSTANCE);

        $this->assertSame('REMOTE1', $record->remotecourseref);
        $this->assertSame(42, (int) $record->remotecourseid);
        $this->assertSame('REMOTE1', $record->remotecourseshortname);
        $this->assertSame('Remote Source Course', $record->remotecoursename);
        $this->assertSame(connection::STAGE_MAPPED, $record->setupstage);
        $this->assertTrue(connection::is_mapped($record));
    }

    /**
     * A connection with no course mapped is not ready to be asked what changed.
     */
    public function test_is_mapped_requires_a_course(): void {
        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');

        $record = connection::get(self::INSTANCE);

        $this->assertTrue(connection::is_configured($record));
        $this->assertFalse(connection::is_mapped($record));
    }

    /**
     * Changing the site drops the course mapping as well as the token: a course
     * id or shortname on one site means nothing on another.
     */
    public function test_changing_url_discards_the_course_mapping(): void {
        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            self::INSTANCE,
            'REMOTE1',
            course_result::success(42, 'REMOTE1', 'Remote Source Course', true, 3)
        );

        $record = connection::set_url(self::INSTANCE, 7, 'https://elsewhere.example.edu');

        $this->assertNull($record->remotecourseid);
        $this->assertNull($record->remotecourseref);
        $this->assertNull($record->remotecoursename);
        $this->assertFalse(connection::is_mapped($record));
    }

    /**
     * The mapping can be cleared without losing the site connection.
     */
    public function test_clear_remote_course(): void {
        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            self::INSTANCE,
            'REMOTE1',
            course_result::success(42, 'REMOTE1', 'Remote Source Course', true, 3)
        );

        connection::clear_remote_course(self::INSTANCE);

        $record = connection::get(self::INSTANCE);

        $this->assertNull($record->remotecourseid);
        $this->assertFalse(connection::is_mapped($record));
        // The site connection survives.
        $this->assertTrue(connection::is_configured($record));
        $this->assertSame('abcdef0123456789abcdef0123456789', connection::get_token(self::INSTANCE));
        $this->assertSame(connection::STAGE_TESTED, $record->setupstage);
    }

    /**
     * Re-testing a mapped connection does not walk it back a stage.
     */
    public function test_retest_keeps_the_mapped_stage(): void {
        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            self::INSTANCE,
            'REMOTE1',
            course_result::success(42, 'REMOTE1', 'Remote Source Course', true, 3)
        );

        connection::record_success(self::INSTANCE, 'Source', '5.1');

        $this->assertSame(connection::STAGE_MAPPED, connection::get(self::INSTANCE)->setupstage);
    }

    /**
     * A new connection has never been synced, and phase 3 leaves it that way.
     */
    public function test_last_sync_starts_as_never(): void {
        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            self::INSTANCE,
            'REMOTE1',
            course_result::success(42, 'REMOTE1', 'Remote Source Course', true, 3)
        );

        $this->assertNull(connection::get_last_sync(self::INSTANCE));
        $this->assertNull(connection::get(self::INSTANCE)->lastsync);
    }

    /**
     * A stored value is read back, so phase 4 has somewhere to write to.
     */
    public function test_last_sync_is_read_when_set(): void {
        global $DB;

        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        $DB->set_field(
            'block_coursesync_connection',
            'lastsync',
            1750000000,
            ['blockinstanceid' => self::INSTANCE]
        );

        $this->assertSame(1750000000, connection::get_last_sync(self::INSTANCE));
    }

    /**
     * A course cannot be mapped before there is a site to map it on.
     */
    public function test_mapping_requires_a_connection(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        connection::set_remote_course(
            self::INSTANCE,
            'REMOTE1',
            course_result::success(42, 'REMOTE1', 'Remote Source Course', true, 3)
        );
    }

    /**
     * Deleting the block instance takes the stored token with it.
     */
    public function test_delete(): void {
        $this->resetAfterTest();

        connection::set_url(self::INSTANCE, 7, 'https://source.example.edu');
        connection::set_token(self::INSTANCE, 'abcdef0123456789abcdef0123456789');

        connection::delete(self::INSTANCE);

        $this->assertNull(connection::get(self::INSTANCE));
        $this->assertNull(connection::get_token(self::INSTANCE));
    }
}
