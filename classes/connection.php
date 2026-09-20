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

use core\encryption;

/**
 * Reads and writes the connection record for a block instance.
 *
 * The remote token is encrypted at rest with core\encryption (libsodium), whose
 * key lives outside the database in the site's secret data directory. The plain
 * token is never written to the database, a log, or an exception message.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection {
    /** @var string No remote site recorded yet. */
    const STAGE_URL = 'url';

    /** @var string The remote URL is known, waiting for a token. */
    const STAGE_TOKEN = 'token';

    /** @var string A token is stored and has been tested at least once. */
    const STAGE_TESTED = 'tested';

    /** @var string A remote course has been mapped. */
    const STAGE_MAPPED = 'mapped';

    /** @var string Never tested. */
    const STATUS_NEW = 'new';

    /** @var string Last test succeeded. */
    const STATUS_OK = 'ok';

    /** @var string Last test failed. */
    const STATUS_ERROR = 'error';

    /**
     * Fetch the connection for a block instance.
     *
     * @param int $blockinstanceid
     * @return \stdClass|null
     */
    public static function get(int $blockinstanceid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('block_coursesync_connection', ['blockinstanceid' => $blockinstanceid]);

        return $record ?: null;
    }

    /**
     * Store the remote site URL, creating the connection record if needed.
     *
     * Changing the URL discards any stored token: a token issued by one site is
     * meaningless to another, and silently keeping it would be confusing.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param string $url a URL that has already passed remote_url::validate()
     * @return \stdClass the stored record
     */
    public static function set_url(int $blockinstanceid, int $courseid, string $url): \stdClass {
        global $DB;

        $url = remote_url::normalise($url);
        $now = time();
        $record = self::get($blockinstanceid);

        if ($record === null) {
            $record = (object) [
                'blockinstanceid' => $blockinstanceid,
                'courseid' => $courseid,
                'remoteurl' => $url,
                'token' => null,
                'tokenhint' => null,
                'remotesitename' => null,
                'remoterelease' => null,
                'remotecourseref' => null,
                'remotecourseid' => null,
                'remotecourseshortname' => null,
                'remotecoursename' => null,
                'setupstage' => self::STAGE_TOKEN,
                'lastsync' => null,
                'status' => self::STATUS_NEW,
                'lasterror' => null,
                'lastcheck' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record('block_coursesync_connection', $record);

            return $record;
        }

        if ($record->remoteurl !== $url) {
            $record->token = null;
            $record->tokenhint = null;
            $record->remotesitename = null;
            $record->remoterelease = null;
            // A course id or shortname on the old site means nothing on the new one.
            $record->remotecourseref = null;
            $record->remotecourseid = null;
            $record->remotecourseshortname = null;
            $record->remotecoursename = null;
            $record->status = self::STATUS_NEW;
            $record->lasterror = null;
            $record->lastcheck = 0;
            $record->setupstage = self::STAGE_TOKEN;
        }

        $record->remoteurl = $url;
        $record->courseid = $courseid;
        $record->timemodified = $now;
        $DB->update_record('block_coursesync_connection', $record);

        return $record;
    }

    /**
     * Store a token, encrypted.
     *
     * @param int $blockinstanceid
     * @param string $token the token as pasted by the administrator
     * @return void
     */
    public static function set_token(int $blockinstanceid, string $token): void {
        global $DB;

        $record = self::get($blockinstanceid);

        if ($record === null) {
            throw new \coding_exception('Cannot store a token before the remote site URL is known.');
        }

        $token = trim($token);

        $record->token = encryption::encrypt($token);
        // Enough to tell two tokens apart in the UI, not enough to be useful to anyone else.
        $record->tokenhint = \core_text::substr($token, -4);
        $record->setupstage = self::STAGE_TESTED;
        $record->status = self::STATUS_NEW;
        $record->lasterror = null;
        $record->lastcheck = 0;
        $record->timemodified = time();

        $DB->update_record('block_coursesync_connection', $record);
    }

    /**
     * Retrieve the decrypted token.
     *
     * @param int $blockinstanceid
     * @return string|null null when no token is stored, or when it cannot be decrypted
     */
    public static function get_token(int $blockinstanceid): ?string {
        $record = self::get($blockinstanceid);

        if ($record === null || $record->token === null || $record->token === '') {
            return null;
        }

        try {
            return encryption::decrypt($record->token);
        } catch (\Throwable $e) {
            // The key is missing or has changed - for instance the database was
            // restored onto a different site. The token is unrecoverable and the
            // administrator has to paste a new one.
            return null;
        }
    }

    /**
     * Does this connection have everything it needs to attempt a call?
     *
     * @param \stdClass|null $record
     * @return bool
     */
    public static function is_configured(?\stdClass $record): bool {
        return $record !== null && $record->remoteurl !== '' && !empty($record->token);
    }

    /**
     * Record a successful connection test.
     *
     * @param int $blockinstanceid
     * @param string $sitename site name reported by the remote site
     * @param string $release Moodle release reported by the remote site
     * @return void
     */
    public static function record_success(int $blockinstanceid, string $sitename, string $release): void {
        global $DB;

        $record = self::get($blockinstanceid);

        if ($record === null) {
            return;
        }

        $record->status = self::STATUS_OK;
        $record->lasterror = null;
        $record->lastcheck = time();
        $record->remotesitename = \core_text::substr($sitename, 0, 255);
        $record->remoterelease = \core_text::substr($release, 0, 50);
        // Do not walk a mapped connection back to an earlier stage just because
        // its connection was re-tested.
        if ($record->setupstage !== self::STAGE_MAPPED) {
            $record->setupstage = self::STAGE_TESTED;
        }
        $record->timemodified = time();

        $DB->update_record('block_coursesync_connection', $record);
    }

    /**
     * Record a failed connection test.
     *
     * @param int $blockinstanceid
     * @param string $errorkey a language string identifier from this plugin
     * @return void
     */
    public static function record_failure(int $blockinstanceid, string $errorkey): void {
        global $DB;

        $record = self::get($blockinstanceid);

        if ($record === null) {
            return;
        }

        $record->status = self::STATUS_ERROR;
        $record->lasterror = $errorkey;
        $record->lastcheck = time();
        $record->timemodified = time();

        $DB->update_record('block_coursesync_connection', $record);
    }

    /**
     * Record which remote course this block is mapped to.
     *
     * @param int $blockinstanceid
     * @param string $courseref what the administrator typed
     * @param course_result $course the resolved course, from the remote site
     * @return void
     */
    public static function set_remote_course(int $blockinstanceid, string $courseref, course_result $course): void {
        global $DB;

        $record = self::get($blockinstanceid);

        if ($record === null) {
            throw new \coding_exception('Cannot map a course before the remote site URL is known.');
        }

        $record->remotecourseref = \core_text::substr(trim($courseref), 0, 255);
        $record->remotecourseid = $course->id;
        $record->remotecourseshortname = \core_text::substr($course->shortname, 0, 255);
        $record->remotecoursename = \core_text::substr($course->fullname, 0, 255);
        $record->setupstage = self::STAGE_MAPPED;
        $record->timemodified = time();

        $DB->update_record('block_coursesync_connection', $record);
    }

    /**
     * Forget the course mapping, leaving the site connection alone.
     *
     * @param int $blockinstanceid
     * @return void
     */
    public static function clear_remote_course(int $blockinstanceid): void {
        global $DB;

        $record = self::get($blockinstanceid);

        if ($record === null) {
            return;
        }

        $record->remotecourseref = null;
        $record->remotecourseid = null;
        $record->remotecourseshortname = null;
        $record->remotecoursename = null;
        $record->setupstage = self::STAGE_TESTED;
        $record->timemodified = time();

        $DB->update_record('block_coursesync_connection', $record);
    }

    /**
     * Is this connection ready to be asked what has changed?
     *
     * @param \stdClass|null $record
     * @return bool
     */
    public static function is_mapped(?\stdClass $record): bool {
        return self::is_configured($record) && !empty($record->remotecourseid);
    }

    /**
     * When this block last pulled activities, or null if it never has.
     *
     * Phase 3 only reads this. Nothing writes it yet: marking activities as
     * synced before anything is actually pulled would hide them from the next
     * run. Writing starts in phase 4.
     *
     * @param int $blockinstanceid
     * @return int|null
     */
    public static function get_last_sync(int $blockinstanceid): ?int {
        $record = self::get($blockinstanceid);

        if ($record === null || $record->lastsync === null || (int) $record->lastsync === 0) {
            return null;
        }

        return (int) $record->lastsync;
    }

    /**
     * Record that a sync completed.
     *
     * Only a run that created everything it set out to create should call this.
     * Moving the marker after a partial failure would hide the activities that
     * did not make it from the next run.
     *
     * @param int $blockinstanceid
     * @param int $time the moment the run asked the source what had changed
     * @return void
     */
    public static function set_last_sync(int $blockinstanceid, int $time): void {
        global $DB;

        $record = self::get($blockinstanceid);

        if ($record === null) {
            return;
        }

        $record->lastsync = $time;
        $record->timemodified = time();

        $DB->update_record('block_coursesync_connection', $record);
    }

    /**
     * Remove the connection belonging to a block instance.
     *
     * @param int $blockinstanceid
     * @return void
     */
    public static function delete(int $blockinstanceid): void {
        global $DB;

        $DB->delete_records('block_coursesync_connection', ['blockinstanceid' => $blockinstanceid]);
    }
}
