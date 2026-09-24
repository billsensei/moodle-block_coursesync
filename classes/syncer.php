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
use block_coursesync\local\handler\activity_handler;
use block_coursesync\local\handler\handler_registry;

/**
 * Runs a sync: find what changed, fetch it, rebuild it locally.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syncer {
    /** @var int Longest a run may hold its lock, in seconds, if it dies without releasing it. */
    public const LOCK_LIFETIME = 3600;

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
     * Has the source changed an activity since this plugin last pulled it here?
     *
     * @param int $blockinstanceid
     * @param activity $activity as change detection reported it
     * @param int $localcmid the copy already in this course
     * @return bool false as well when this plugin did not put that copy here
     */
    public static function is_changed(int $blockinstanceid, activity $activity, int $localcmid): bool {
        $pulledat = history::pulled_at($blockinstanceid, $activity->cmid, $localcmid);

        return $pulledat !== null && self::changed_since($activity, $pulledat);
    }

    /**
     * Was an activity modified after the run that pulled it started?
     *
     * Both times are compared as they are for the last synced marker: the
     * source's clock for the change, this site's for the run.
     *
     * @param activity $activity
     * @param int $pulledat
     * @return bool
     */
    protected static function changed_since(activity $activity, int $pulledat): bool {
        return (int) $activity->timemodified > $pulledat;
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
                $pulledat = history::pulled_at($blockinstanceid, $activity->cmid, $existing);

                if ($pulledat === null) {
                    $candidates->collisions[] = $activity;
                } else if (self::changed_since($activity, $pulledat) && handler_registry::supports($activity->modname)) {
                    $candidates->changed[] = $activity;

                    if (handler_registry::get($activity->modname)->updates_in_place()) {
                        $candidates->inplace[] = (int) $activity->cmid;
                    } else if (copy_update::has_people_data($existing)) {
                        $candidates->neweditions[] = (int) $activity->cmid;
                    }
                } else {
                    $candidates->present[] = $activity;

                    // Asked now so the page can say what ticking it would do.
                    // A type updated where it stands has no copy to keep.
                    $handler = handler_registry::get($activity->modname);

                    if (
                        $handler !== null && !$handler->updates_in_place()
                            && copy_update::has_people_data($existing)
                    ) {
                        $candidates->presentwithdata[] = (int) $activity->cmid;
                    }
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

        // One run per block at a time. Two at once - a double click on the
        // button, or two teachers - would each find an activity not yet here
        // and each create it, leaving two copies with the same identity. The
        // second is refused rather than made to wait: once the first has
        // finished, what the second was asked to copy is already here, and
        // running it then would replace the copies just made.
        $lock = \core\lock\lock_config::get_lock_factory('block_coursesync')
            ->get_lock('run-' . $blockinstanceid, 0, self::LOCK_LIFETIME);

        if (!$lock) {
            return self::finish(
                $blockinstanceid,
                $courseid,
                (int) $USER->id,
                time(),
                sync_result::failure('errorsyncinprogress')
            );
        }

        try {
            return self::run_locked($blockinstanceid, $courseid, $full, $client, $only);
        } finally {
            $lock->release();
        }
    }

    /**
     * The run itself, once run() holds the lock for this block.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param bool $full
     * @param http_client|null $client
     * @param int[]|null $only
     * @return sync_result
     */
    protected static function run_locked(
        int $blockinstanceid,
        int $courseid,
        bool $full,
        ?http_client $client,
        ?array $only
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

        // Subsections first, so that anything inside one, copied in this
        // same run, finds this course's copy of it already there. Otherwise
        // the order is the source's: oldest change first.
        $activities = $found->activities;
        usort(
            $activities,
            static fn(activity $a, activity $b): int => ($b->modname === 'subsection') <=> ($a->modname === 'subsection')
        );

        foreach ($activities as $activity) {
            // One activity going wrong in a way nobody foresaw is reported
            // against that activity, not allowed to end the run: the rest
            // still get their turn, and the run is still written to the
            // history, with the marker held because of the failure.
            try {
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
                    // A changed one was on the list, though, so leaving it unticked
                    // is a choice like any other, and it is offered again.
                    $existing = self::find_existing($course->id, $activity->cmid);
                    $alreadyhere = $existing > 0 && !self::is_changed($blockinstanceid, $activity, $existing);

                    $result->add_skipped(
                        $activity->name,
                        $activity->modname,
                        $activity->cmid,
                        $alreadyhere ? 'syncskippedpresent' : 'syncskippeddeselected'
                    );

                    continue;
                }

                // Updating or copying again is only ever something a teacher
                // ticked. A run that was not given a choice keeps to what it always
                // did with a copy that is already here: flag it and leave it alone.
                $existing = self::find_existing($course->id, $activity->cmid);

                if ($chosen !== null && $existing > 0) {
                    if (self::is_changed($blockinstanceid, $activity, $existing)) {
                        self::handle_update($course, $record, $token, $activity, $existing, $result, $client);
                    } else if (history::was_pulled_here($blockinstanceid, $activity->cmid, $existing)) {
                        self::handle_update($course, $record, $token, $activity, $existing, $result, $client, true);
                    } else {
                        self::copy_beside($course, $record, $token, $activity, $existing, $result, $client);
                    }

                    continue;
                }

                self::handle_one($course, $blockinstanceid, $record, $token, $activity, $result, $client);
            } catch (\Throwable $e) {
                debugging('block_coursesync: unexpected failure on remote cmid ' . $activity->cmid . ': '
                    . $e->getMessage(), DEBUG_DEVELOPER);

                $result->add_failed($activity->name, $activity->modname, $activity->cmid, 'errorcreatefailed');
            }
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

        $created = self::create_copy($course, $record, $token, $activity, $result, $client, self::build_idnumber($activity->cmid));

        if ($created === null) {
            return;
        }

        [$cm, $payload, $handler] = $created;

        $result->add_created(
            $payload->name,
            $payload->modname,
            $activity->cmid,
            (int) $cm->id,
            self::notes_for($handler, $payload, $course)
        );
    }

    /**
     * Bring a copy this plugin made up to date with a changed source activity.
     *
     * A fresh copy is made first, without the identity, so that nothing about
     * the old one changes until the new one is known to be complete. Only then
     * is the old one either replaced or set aside - see copy_update for which,
     * and why - and the identity moved to the fresh copy.
     *
     * @param \stdClass $course
     * @param \stdClass $record the connection
     * @param string $token
     * @param activity $activity
     * @param int $existingcmid the copy already here
     * @param sync_result $result
     * @param http_client|null $client
     * @param bool $recopy true when the source has not changed and the teacher asked for a fresh copy anyway
     * @return void
     */
    protected static function handle_update(
        \stdClass $course,
        \stdClass $record,
        string $token,
        activity $activity,
        int $existingcmid,
        sync_result $result,
        ?http_client $client = null,
        bool $recopy = false
    ): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $handler = handler_registry::get($activity->modname);

        if ($handler !== null && $handler->updates_in_place()) {
            self::update_in_place($handler, $record, $token, $activity, $existingcmid, $result, $client);

            return;
        }

        $created = self::create_copy($course, $record, $token, $activity, $result, $client, '');

        if ($created === null) {
            return;
        }

        [$cm, $payload, $handler] = $created;

        $old = $DB->get_record('course_modules', ['id' => $existingcmid], '*', MUST_EXIST);
        $idnumber = self::build_idnumber($activity->cmid);
        $newedition = copy_update::has_people_data($existingcmid);
        $name = $payload->name;

        try {
            copy_update::take_local_setup($old, $cm, !$newedition);

            if ($newedition) {
                // The old copy stays exactly as it is, people's work and all;
                // it only stops being the one this plugin tracks.
                copy_update::set_identity($existingcmid, '');
                $name = $recopy
                    ? copy_update::name_as_copy((int) $cm->id)
                    : copy_update::name_as_new_edition((int) $cm->id);
                copy_update::set_identity((int) $cm->id, $idnumber);
            } else {
                copy_update::repoint_references((int) $course->id, $existingcmid, (int) $cm->id);
                copy_update::set_identity((int) $cm->id, $idnumber);

                // Last, so that nothing after it can fail and leave neither copy.
                \course_delete_module($existingcmid);
            }
        } catch (\Throwable $e) {
            debugging('block_coursesync: could not update ' . $payload->modname . ' from remote cmid '
                . $activity->cmid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);

            // Back to how it was: the old copy, still carrying the identity.
            self::remove_partial($cm, $payload->modname);

            if ($DB->record_exists('course_modules', ['id' => $existingcmid, 'deletioninprogress' => 0])) {
                copy_update::set_identity($existingcmid, $idnumber);
            }

            $result->add_failed($activity->name, $activity->modname, $activity->cmid, 'errorupdatefailed');

            return;
        }

        $notes = self::notes_for($handler, $payload, $course);

        if ($recopy && $newedition) {
            // Nothing was updated: an unchanged activity was copied again,
            // beside the one people have work in.
            $result->add_created($name, $payload->modname, $activity->cmid, (int) $cm->id, $notes, 'syncrecopiedcopy');

            return;
        }

        $result->add_updated(
            $name,
            $payload->modname,
            $activity->cmid,
            (int) $cm->id,
            match (true) {
                $recopy => 'syncrecopiedreplaced',
                $newedition => 'syncupdatednewedition',
                default => 'syncupdatedreplaced',
            },
            $notes
        );
    }

    /**
     * Copy an activity again beside something here that carries its identity
     * but that this plugin did not put here.
     *
     * What is here is not this plugin's, so nothing about it changes - not
     * even its ID number. The fresh copy therefore carries no identity of its
     * own: it is not tracked for later updates, and what is here is still
     * flagged for review until a person deals with it.
     *
     * @param \stdClass $course
     * @param \stdClass $record the connection
     * @param string $token
     * @param activity $activity
     * @param int $existingcmid what is here
     * @param sync_result $result
     * @param http_client|null $client
     * @return void
     */
    protected static function copy_beside(
        \stdClass $course,
        \stdClass $record,
        string $token,
        activity $activity,
        int $existingcmid,
        sync_result $result,
        ?http_client $client = null
    ): void {
        global $DB;

        $created = self::create_copy($course, $record, $token, $activity, $result, $client, '');

        if ($created === null) {
            return;
        }

        [$cm, $payload, $handler] = $created;

        try {
            copy_update::take_local_setup(
                $DB->get_record('course_modules', ['id' => $existingcmid], '*', MUST_EXIST),
                $cm,
                false
            );
            $name = copy_update::name_as_copy((int) $cm->id);
        } catch (\Throwable $e) {
            debugging('block_coursesync: could not copy ' . $payload->modname . ' from remote cmid '
                . $activity->cmid . ' beside an activity already here: ' . $e->getMessage(), DEBUG_DEVELOPER);

            self::remove_partial($cm, $payload->modname);
            $result->add_failed($activity->name, $activity->modname, $activity->cmid, 'errorcreatefailed');

            return;
        }

        $result->add_created(
            $name,
            $payload->modname,
            $activity->cmid,
            (int) $cm->id,
            self::notes_for($handler, $payload, $course),
            'syncrecopiedbeside'
        );
    }

    /**
     * Bring a copy up to date where it stands, for a type that is never
     * replaced (activity_handler::updates_in_place()).
     *
     * @param activity_handler $handler
     * @param \stdClass $record the connection
     * @param string $token
     * @param activity $activity
     * @param int $existingcmid the copy here
     * @param sync_result $result
     * @param http_client|null $client
     * @return void
     */
    protected static function update_in_place(
        activity_handler $handler,
        \stdClass $record,
        string $token,
        activity $activity,
        int $existingcmid,
        sync_result $result,
        ?http_client $client = null
    ): void {
        global $DB;

        $fetched = remote_client::get_activity($record->remoteurl, $token, $activity->cmid, $client);

        if (!$fetched->success) {
            $result->add_failed($activity->name, $activity->modname, $activity->cmid, $fetched->errorkey);

            return;
        }

        $payload = $fetched->payload;
        $problem = $handler->check_permission(get_course((int) $DB->get_field('course_modules', 'course', ['id' => $existingcmid])))
            ?? $handler->check_payload($payload);

        if ($problem !== null) {
            $result->add_failed($activity->name, $activity->modname, $activity->cmid, $problem);

            return;
        }

        try {
            $handler->update_in_place($DB->get_record('course_modules', ['id' => $existingcmid], '*', MUST_EXIST), $payload);
        } catch (\Throwable $e) {
            debugging('block_coursesync: could not update ' . $payload->modname . ' from remote cmid '
                . $activity->cmid . ' in place: ' . $e->getMessage(), DEBUG_DEVELOPER);

            $result->add_failed($activity->name, $activity->modname, $activity->cmid, 'errorupdatefailed');

            return;
        }

        $result->add_updated($payload->name, $payload->modname, $activity->cmid, $existingcmid, 'syncupdatedinplace');
    }

    /**
     * Fetch one activity from the source and create it here.
     *
     * Anything that goes wrong is recorded against the activity, and a
     * half-made activity is taken back out.
     *
     * @param \stdClass $course
     * @param \stdClass $record the connection
     * @param string $token
     * @param activity $activity
     * @param sync_result $result
     * @param http_client|null $client
     * @param string $idnumber what to stamp on the new course module
     * @return array|null [course_modules record, activity_payload, activity_handler], or null if it failed
     */
    protected static function create_copy(
        \stdClass $course,
        \stdClass $record,
        string $token,
        activity $activity,
        sync_result $result,
        ?http_client $client,
        string $idnumber
    ): ?array {
        $fetched = remote_client::get_activity($record->remoteurl, $token, $activity->cmid, $client);

        if (!$fetched->success) {
            $result->add_failed($activity->name, $activity->modname, $activity->cmid, $fetched->errorkey);

            return null;
        }

        $payload = $fetched->payload;
        $handler = handler_registry::get($payload->modname);

        if ($handler === null) {
            $result->add_failed($activity->name, $activity->modname, $activity->cmid, 'errorunsupportedtype');

            return null;
        }

        $problem = $handler->check_permission($course)
            ?? $handler->check_payload($payload)
            ?? $handler->check_destination($course, $payload);

        if ($problem !== null) {
            $result->add_failed(
                $activity->name,
                $activity->modname,
                $activity->cmid,
                $problem,
                $handler->failure_notes($payload, $problem)
            );

            return null;
        }

        $cm = null;

        try {
            $cm = $handler->create_from_remote_data($course, $payload, $idnumber);

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

            $result->add_failed(
                $activity->name,
                $activity->modname,
                $activity->cmid,
                $reason,
                $handler->failure_notes($payload, $reason)
            );

            return null;
        }

        return [$cm, $payload, $handler];
    }

    /**
     * What a teacher should be told about one copied activity.
     *
     * @param activity_handler $handler
     * @param activity_payload $payload
     * @param \stdClass $course where it was copied to
     * @return array
     */
    protected static function notes_for(activity_handler $handler, activity_payload $payload, \stdClass $course): array {
        $notes = [];

        // It sits in a subsection on the other site that is not in this
        // course, so it went in the section that subsection is in.
        if ($payload->subsectioncmid > 0 && activity_handler::local_subsection_section($course, $payload) === null) {
            $notes[] = 'syncsubsectionmissing';
        }

        if ($payload->references_files()) {
            // Named, so a teacher knows which pictures to put back by hand.
            // (Runs from before files embedded in text were copied recorded
            // the plain syncfilewarning, which their history still shows.)
            $notes[] = ['syncfilesmissing', implode(', ', $payload->missing_files())];
        }

        if ($handler->lost_scale($payload)) {
            $notes[] = 'syncscaledropped';
        }

        // Whatever this type in particular could not bring across.
        foreach ($handler->notes($payload) as $note) {
            $notes[] = $note;
        }

        return $notes;
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
