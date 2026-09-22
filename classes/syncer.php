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

use block_coursesync\local\file_sync;
use core\http_client;
use block_coursesync\local\handler\handler_registry;

/**
 * Runs a sync: find what changed, fetch it, rebuild it locally.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syncer {
    /**
     * The ID number stamped on activities this plugin created.
     *
     * A generated marker rather than the source's own ID number, because a
     * source activity usually has none, and one that does could collide with
     * something already in the destination course. The remote course module id
     * is stable for the life of the activity, and only one Course Sync block is
     * allowed per course, so this is unique within the course it is used in.
     *
     * @param int $remotecmid course module id on the source site
     * @return string
     */
    public static function build_idnumber(int $remotecmid): string {
        return 'coursesync-' . $remotecmid;
    }

    /**
     * Has this remote activity already been pulled into this course?
     *
     * A module a teacher just deleted through the course editor is not
     * actually gone yet - the standard "Delete" action is asynchronous by
     * default (course_delete_module($cmid, true)): it flags the row
     * deletioninprogress = 1 and queues an adhoc task to do the real work,
     * which can sit unprocessed for a long time depending on the site's cron
     * schedule. The teacher already sees it gone from the course, so a row
     * only waiting on that task is treated the same way here - as gone -
     * rather than making "delete it, then sync it back" depend on when cron
     * next runs.
     *
     * @param int $courseid the destination course
     * @param int $remotecmid course module id on the source site
     * @return int the local course module id, or 0 if it is not here
     */
    public static function find_existing(int $courseid, int $remotecmid): int {
        global $DB;

        return (int) $DB->get_field('course_modules', 'id', [
            'course' => $courseid,
            'idnumber' => self::build_idnumber($remotecmid),
            'deletioninprogress' => 0,
        ], IGNORE_MISSING);
    }

    /**
     * What a sync would offer to copy, without copying any of it.
     *
     * This is the same change detection a run starts with, sorted into what is
     * new here, what is already here, and what this plugin cannot handle. It
     * writes nothing, so a teacher can look at it as often as they like.
     *
     * Deciding "already here" is the same question a run asks - does anything in
     * this course carry that activity's identity - so the list and the run agree
     * about what is on offer.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param bool $full look at every activity rather than only what has changed
     * @param http_client|null $client injected only by tests
     * @return sync_candidates
     */
    public static function list_candidates(
        int $blockinstanceid,
        int $courseid,
        bool $full = false,
        ?http_client $client = null
    ): sync_candidates {
        $record = connection::get($blockinstanceid);

        if (!connection::is_mapped($record)) {
            return sync_candidates::failure('errornotmapped');
        }

        $token = connection::get_token($blockinstanceid);

        if ($token === null) {
            return sync_candidates::failure('errortokenunreadable');
        }

        $since = $full ? 0 : (connection::get_last_sync($blockinstanceid) ?? 0);

        $found = remote_client::get_modified_activities(
            $record->remoteurl,
            $token,
            (int) $record->remotecourseid,
            $since,
            $client
        );

        if (!$found->success) {
            return sync_candidates::failure($found->errorkey);
        }

        $candidates = new sync_candidates();
        $candidates->since = $since;

        foreach ($found->activities as $activity) {
            $existing = self::find_existing($courseid, $activity->cmid);

            if ($existing > 0) {
                // Whether this plugin put it there decides which it is. One is
                // ordinary housekeeping; the other is something a person should
                // look at, and was worth flagging before this list existed.
                if (history::was_pulled_here($blockinstanceid, $activity->cmid, $existing)) {
                    $candidates->present[] = $activity;
                } else {
                    $candidates->collisions[] = $activity;
                }

                continue;
            }

            if (!handler_registry::supports($activity->modname)) {
                $candidates->unsupported[] = $activity;

                continue;
            }

            $candidates->new[] = $activity;
        }

        return $candidates;
    }

    /**
     * Run a sync for one block instance.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param bool $full look at every activity rather than only what has changed
     * @param http_client|null $client injected only by tests
     * @param int[]|null $only remote course module ids the teacher chose; null means everything
     * @return sync_result
     */
    public static function run(
        int $blockinstanceid,
        int $courseid,
        bool $full = false,
        ?http_client $client = null,
        ?array $only = null
    ): sync_result {
        global $USER;

        $timestarted = time();
        $record = connection::get($blockinstanceid);

        if (!connection::is_mapped($record)) {
            return self::finish(
                $blockinstanceid,
                $courseid,
                (int) $USER->id,
                $timestarted,
                sync_result::failure('errornotmapped')
            );
        }

        $token = connection::get_token($blockinstanceid);

        if ($token === null) {
            return self::finish(
                $blockinstanceid,
                $courseid,
                (int) $USER->id,
                $timestarted,
                sync_result::failure('errortokenunreadable')
            );
        }

        $course = get_course($courseid);
        $lastsync = connection::get_last_sync($blockinstanceid);
        // A full re-check asks about everything, which is how an activity whose
        // conflict has been resolved by hand gets offered again: the last synced
        // marker has already moved past it.
        $since = $full ? 0 : ($lastsync ?? 0);

        // Taken before asking the source anything. Anything modified while this
        // run is in flight will have a later timestamp and so will be picked up
        // next time, rather than being skipped.
        $runstarted = time();

        $found = remote_client::get_modified_activities(
            $record->remoteurl,
            $token,
            (int) $record->remotecourseid,
            $since,
            $client
        );

        if (!$found->success) {
            $failure = sync_result::failure($found->errorkey);
            $failure->since = $since;

            return self::finish($blockinstanceid, $courseid, (int) $USER->id, $timestarted, $failure);
        }

        $result = new sync_result();
        $result->since = $since;

        // A chosen set is held to what was actually offered. An id that was not
        // on the list was not offered, so it is not acted on however it arrived
        // in the request.
        $chosen = $only === null ? null : array_map('intval', $only);

        foreach ($found->activities as $activity) {
            // Asked before the chosen set, and deliberately. Something this
            // plugin cannot handle was never on offer, so saying the teacher
            // chose to leave it out would be untrue - and worse, it would hold
            // the last synced marker back forever waiting for a choice that can
            // never be made.
            if (!handler_registry::supports($activity->modname)) {
                $result->add_skipped(
                    $activity->name,
                    $activity->modname,
                    $activity->cmid,
                    'syncskippedtype'
                );

                continue;
            }

            if ($chosen !== null && !in_array((int) $activity->cmid, $chosen, true)) {
                // Same reasoning again: something already in this course is not
                // on the list either, so it was not a choice the teacher made.
                // Calling it one would hold the marker back for good - it can
                // never be ticked, because it is never offered.
                $alreadyhere = self::find_existing($course->id, $activity->cmid) > 0;

                $result->add_skipped(
                    $activity->name,
                    $activity->modname,
                    $activity->cmid,
                    $alreadyhere ? 'syncskippedpresent' : 'syncskippeddeselected'
                );

                continue;
            }

            self::handle_one($course, $blockinstanceid, $record, $token, $activity, $result, $client);
        }

        // The marker moves only when the run is trustworthy. Moving it after a
        // partial failure would hide the activities that did not make it, and
        // moving it past something the teacher deliberately left out would mean
        // never being offered it again.
        if ($result->is_clean() && !$result->has_deselected()) {
            connection::set_last_sync($blockinstanceid, $runstarted);
            $result->lastsyncupdated = true;
        }

        return self::finish($blockinstanceid, $courseid, (int) $USER->id, $timestarted, $result);
    }

    /**
     * Write the run to the history and hand the result back.
     *
     * Every run is recorded, including ones that did nothing and ones that could
     * not start, so the history answers "was this tried?" as well as "what did
     * it do?".
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param int $userid
     * @param int $timestarted
     * @param sync_result $result
     * @return sync_result
     */
    protected static function finish(
        int $blockinstanceid,
        int $courseid,
        int $userid,
        int $timestarted,
        sync_result $result
    ): sync_result {
        $result->runid = history::record($blockinstanceid, $courseid, $userid, $timestarted, $result);

        return $result;
    }

    /**
     * Deal with one activity the source reported.
     *
     * @param \stdClass $course the destination course
     * @param int $blockinstanceid
     * @param \stdClass $record the connection record
     * @param string $token
     * @param activity $activity what change detection reported
     * @param sync_result $result collects the outcome
     * @param http_client|null $client injected only by tests
     * @return void
     */
    protected static function handle_one(
        \stdClass $course,
        int $blockinstanceid,
        \stdClass $record,
        string $token,
        activity $activity,
        sync_result $result,
        ?http_client $client = null
    ): void {
        if (!handler_registry::supports($activity->modname)) {
            $result->add_skipped($activity->name, $activity->modname, $activity->cmid, 'syncskippedtype');

            return;
        }

        $existing = self::find_existing($course->id, $activity->cmid);

        if ($existing > 0) {
            // Something is already carrying this activity's identity in this
            // course. It is never overwritten and never duplicated: the run
            // records what it found and leaves the decision to a person.
            $result->add_conflict(
                $activity->name,
                $activity->modname,
                $activity->cmid,
                $existing,
                history::was_pulled_here($blockinstanceid, $activity->cmid, $existing)
                    ? 'conflictchangedupstream'
                    : 'conflictlocalactivity'
            );

            return;
        }

        $fetched = remote_client::get_activity($record->remoteurl, $token, $activity->cmid, $client);

        if (!$fetched->success) {
            $result->add_failed($activity->name, $activity->modname, $activity->cmid, $fetched->errorkey);

            return;
        }

        $payload = $fetched->payload;
        $handler = handler_registry::get($payload->modname);

        if ($handler === null) {
            $result->add_failed($activity->name, $activity->modname, $activity->cmid, 'errorunsupportedtype');

            return;
        }

        $problem = $handler->check_payload($payload);

        if ($problem !== null) {
            $result->add_failed($activity->name, $activity->modname, $activity->cmid, $problem);

            return;
        }

        $cm = null;

        try {
            $cm = $handler->create_from_remote_data(
                $course,
                $payload,
                self::build_idnumber($activity->cmid)
            );

            // Files can only be written once the activity exists, because a file
            // area is addressed by the module's context id.
            if ($payload->has_files()) {
                file_sync::copy_files($cm, $handler, $payload, $record->remoteurl, $token, $client);
            }

            // Anything an activity can only finish once its files are here.
            $handler->post_files($cm, $payload);
        } catch (\Throwable $e) {
            debugging('block_coursesync: could not create ' . $payload->modname . ' from remote cmid '
                . $activity->cmid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);

            // An activity whose files did not arrive is worse than no activity
            // at all, because the next run would see it as already synced and
            // never fix it. Take it back out.
            if ($cm !== null) {
                self::remove_partial($cm, $payload->modname);
            }

            $reason = $e instanceof \moodle_exception && get_string_manager()
                ->string_exists($e->errorcode, 'block_coursesync')
                ? $e->errorcode
                : 'errorcreatefailed';

            $result->add_failed($activity->name, $activity->modname, $activity->cmid, $reason);

            return;
        }

        $notes = [];

        if ($payload->references_files()) {
            $notes[] = 'syncfilewarning';
        }

        if ($handler->lost_scale($payload)) {
            $notes[] = 'syncscaledropped';
        }

        // Whatever this type in particular could not bring across.
        foreach ($handler->notes($payload) as $note) {
            $notes[] = $note;
        }

        $result->add_created(
            $payload->name,
            $payload->modname,
            $activity->cmid,
            (int) $cm->id,
            $notes
        );
    }

    /**
     * Undo a half-finished activity.
     *
     * @param \stdClass $cm the course module that was created
     * @param string $modname
     * @return void
     */
    protected static function remove_partial(\stdClass $cm, string $modname): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        try {
            \course_delete_module($cm->id, false);
        } catch (\Throwable $e) {
            debugging(
                'block_coursesync: could not clean up a failed ' . $modname . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }
}
