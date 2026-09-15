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

/**
 * Runs one "Sync now": lists what changed on the mapped remote course since
 * a given time (the same block_coursesync_get_modified_activities Phase 3
 * added), pulls full content for whichever activity types this plugin
 * currently supports (activity_handler_registry; Page, URL, Label, Resource,
 * and Forum as of Phase 5, Assignment and H5P as of Phase 9, Quiz as of
 * Phase 10, and Glossary added after), and
 * creates them in the destination course, each
 * in the destination section matching the number it came from on the source
 * (see pull_and_create()) - never in an existing activity's place, and never
 * as a duplicate of one already pulled (see process_activities()), nor of
 * one that's simply already sitting in the destination course under the
 * same name and activity type - the two checks process_activities() runs,
 * in order, are:
 *
 * 1. idnumber match (the coursesync- tag this class itself creates, or a
 *    teacher-assigned idnumber that happens to collide) - flagged as a
 *    conflict, visible in the sync summary and history, since something
 *    specific occupies the exact identity the pulled activity would take.
 * 2. name + activity type match against anything already in the
 *    destination course - silently excluded, as if it had never been
 *    listed: this is the common case of a destination course that already
 *    has equivalent content (from a backup/restore, a shared template, or
 *    a teacher's own work) that just never went through this idnumber
 *    scheme, so there's nothing to show a teacher that they don't already
 *    know about.
 *
 * Doesn't touch block config, lastsync, or sync history itself -
 * block_coursesync::sync_now() decides what to persist from the result this
 * returns (lastsync via save_lastsync(), the run itself via
 * sync_history::record()). That separation is what lets this class be
 * exercised directly, against a stub remote_client, without a real block
 * instance or database writes beyond the destination course itself.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_runner {
    /** @var remote_client */
    protected remote_client $client;

    /** @var int Destination course id. */
    protected int $destinationcourseid;

    /** @var string Course ID or shortname on the remote (source) site. */
    protected string $remotecourse;

    /**
     * Creates a runner for one destination course, mapped to one remote course.
     *
     * @param remote_client $client
     * @param int $destinationcourseid
     * @param string $remotecourse
     */
    public function __construct(remote_client $client, int $destinationcourseid, string $remotecourse) {
        $this->client = $client;
        $this->destinationcourseid = $destinationcourseid;
        $this->remotecourse = $remotecourse;
    }

    /**
     * Runs the sync.
     *
     * newlastsync is only meaningful when success is true. It's capped just
     * before the earliest activity this run couldn't handle (either an
     * unsupported type, or one whose pull/create failed) - see
     * compute_new_lastsync() - so nothing is ever silently skipped forever:
     * an item left behind this way still has a timemodified after
     * newlastsync, so it reappears on the next sync's detection pass. A
     * flagged conflict, in contrast, is NOT a blocker: once recorded, it's
     * up to the teacher to resolve it manually (rename/remove the existing
     * activity so a later sync can create the pulled one) - Sync now itself
     * doesn't keep re-flagging the same conflict run after run.
     *
     * @param int $since Unix timestamp; only activities modified after this are considered.
     * @return array{
     *     success: bool, errorcode: ?string, technical: ?string,
     *     since: int, newlastsync: ?int,
     *     created: array, conflicts: array, unsupported: array, failed: array
     * }
     */
    public function run(int $since): array {
        $synctime = time();

        $listresult = $this->client->get_modified_activities($this->remotecourse, $since);
        if (!$listresult['success']) {
            return $this->result(false, $listresult['errorcode'], $listresult['technical'], $since, null, [], [], [], []);
        }

        $activities = $listresult['data']['activities'] ?? [];
        [$created, $conflicts, $unsupported, $failed] = $this->process_activities($activities);

        if (!empty($created)) {
            rebuild_course_cache($this->destinationcourseid, true);
        }

        $newlastsync = $this->compute_new_lastsync($since, $synctime, $unsupported, $failed);

        return $this->result(true, null, null, $since, $newlastsync, $created, $conflicts, $unsupported, $failed);
    }

    /**
     * Pulls and creates each activity this instance's registered handlers
     * support, skipping anything that's already effectively in the
     * destination course. Two different things count as "already there":
     *
     * - An existing activity under the idnumber this one would get - flagged
     *   as a conflict and left alone (never overwritten or duplicated),
     *   whether that existing activity came from an earlier sync or a
     *   teacher's own work.
     * - An existing activity of the same type with the same name, however it
     *   got there - excluded silently (not created, not flagged, not counted
     *   anywhere in the result), since it isn't news to the teacher and
     *   nothing collided that needs their attention.
     *
     * The idnumber check runs first: something already tagged from an
     * earlier sync is reported as a conflict, not silently dropped, even
     * though it would also match on name.
     *
     * @param array $activities From block_coursesync_get_modified_activities.
     * @return array{0: array, 1: array, 2: array, 3: array} [created, conflicts, unsupported, failed]
     */
    protected function process_activities(array $activities): array {
        global $DB;

        $created = [];
        $conflicts = [];
        $unsupported = [];
        $failed = [];

        // Only fetched if actually needed, and only once per run - used to
        // describe what an activity conflicts with, and to check for
        // existing activities of the same name and type, not to look
        // anything up about the activities being pulled.
        $destinationmodinfo = null;
        $existingnames = null;

        foreach ($activities as $activity) {
            if (!activity_handler_registry::is_supported($activity['modname'])) {
                $unsupported[] = $activity;
                continue;
            }

            $idnumber = self::make_idnumber((int) $activity['cmid']);
            $existingcmid = $DB->get_field('course_modules', 'id', [
                'course' => $this->destinationcourseid,
                'idnumber' => $idnumber,
            ]);

            if ($existingcmid) {
                $destinationmodinfo ??= get_fast_modinfo($this->destinationcourseid);
                $conflicts[] = $activity + [
                    'conflictcmid' => (int) $existingcmid,
                    'conflictname' => $this->describe_existing_cm($destinationmodinfo, (int) $existingcmid),
                ];
                continue;
            }

            $existingnames ??= $this->index_existing_names(
                $destinationmodinfo ??= get_fast_modinfo($this->destinationcourseid)
            );
            if (isset($existingnames[$activity['modname']][self::normalise_name($activity['name'])])) {
                continue;
            }

            $outcome = $this->pull_and_create($activity, $idnumber);
            if ($outcome === null) {
                $created[] = $activity;
            } else {
                $failed[] = $activity + ['error' => $outcome];
            }
        }

        return [$created, $conflicts, $unsupported, $failed];
    }

    /**
     * Builds a modname => {normalised name => true} lookup of every
     * non-deleting course module already in the destination course, so
     * process_activities() can recognise "an activity of this type with this
     * name is already here" without a query per candidate activity.
     *
     * @param \course_modinfo $modinfo
     * @return array<string, array<string, true>>
     */
    protected function index_existing_names(\course_modinfo $modinfo): array {
        $index = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            $index[$cm->modname][self::normalise_name($cm->get_formatted_name())] = true;
        }
        return $index;
    }

    /**
     * Normalises a name for the same-name-and-type match in
     * index_existing_names()/process_activities() - case and surrounding
     * whitespace shouldn't stop "Syllabus" and " syllabus " from being
     * recognised as the same activity.
     *
     * @param string $name
     * @return string
     */
    protected static function normalise_name(string $name): string {
        return \core_text::strtolower(trim($name));
    }

    /**
     * Best-effort display name for the local activity a conflict matched
     * against - never lets a lookup problem (e.g. modinfo somehow out of
     * sync with course_modules) stop the conflict itself being recorded.
     *
     * @param \course_modinfo $modinfo
     * @param int $cmid
     * @return string
     */
    protected function describe_existing_cm(\course_modinfo $modinfo, int $cmid): string {
        try {
            return $modinfo->get_cm($cmid)->get_formatted_name();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Pulls one activity's content and creates it locally, in the destination
     * section matching the number it's in on the source course - creating
     * that section on the destination first if it doesn't exist yet
     * (course_add_cm_to_section(), called by every activity_handler, does
     * this itself via course_create_sections_if_missing()). Section numbers
     * are relative (course_sections.section), so this only lines up cleanly
     * when both courses share the same section structure - there's no
     * name-based matching, and a source activity sitting in section 12 will
     * happily create eleven empty sections on a five-section destination
     * course to make room for it.
     *
     * @param array $activity One entry from block_coursesync_get_modified_activities.
     * @param string $idnumber
     * @return string|null Null on success, an error message on failure.
     */
    protected function pull_and_create(array $activity, string $idnumber): ?string {
        $contentresult = $this->client->get_activity_content((int) $activity['cmid']);
        if (!$contentresult['success']) {
            return $contentresult['technical'] ?? $contentresult['errorcode'];
        }

        try {
            $payload = json_decode($contentresult['data']['contentjson'] ?? '', true);
            $handler = activity_handler_registry::get_handler($activity['modname']);
            // The ?? 0 guards against a source running an older plugin version
            // whose get_modified_activities response predates the 'section'
            // field - falls back to the pre-existing "always section 0"
            // behaviour rather than a missing-key error.
            $sectionnum = (int) ($activity['section'] ?? 0);
            $handler->create_from_remote_data($this->destinationcourseid, $sectionnum, $payload ?? [], $idnumber);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Works out how far lastsync can safely advance.
     *
     * @param int $since
     * @param int $synctime When this run started - the new lastsync if nothing was left behind.
     * @param array $unsupported
     * @param array $failed
     * @return int
     */
    protected function compute_new_lastsync(int $since, int $synctime, array $unsupported, array $failed): int {
        $blockers = array_map(
            fn(array $a): int => (int) $a['timemodified'],
            array_merge($unsupported, $failed)
        );

        if (empty($blockers)) {
            return $synctime;
        }

        // Never move backwards, even if $since was already past this for some reason.
        return max($since, min($blockers) - 1);
    }

    /**
     * Deterministic idnumber for a synced copy of one remote course module -
     * used both to create it and, on later syncs, to recognise it's already here.
     *
     * @param int $remotecmid
     * @return string
     */
    public static function make_idnumber(int $remotecmid): string {
        return 'coursesync-' . $remotecmid;
    }

    /**
     * Small factory to keep run()'s two return points identical in shape.
     *
     * @param bool $success
     * @param string|null $errorcode
     * @param string|null $technical
     * @param int $since
     * @param int|null $newlastsync
     * @param array $created
     * @param array $conflicts
     * @param array $unsupported
     * @param array $failed
     * @return array
     */
    protected function result(
        bool $success,
        ?string $errorcode,
        ?string $technical,
        int $since,
        ?int $newlastsync,
        array $created,
        array $conflicts,
        array $unsupported,
        array $failed
    ): array {
        return [
            'success' => $success,
            'errorcode' => $errorcode,
            'technical' => $technical,
            'since' => $since,
            'newlastsync' => $newlastsync,
            'created' => $created,
            'conflicts' => $conflicts,
            'unsupported' => $unsupported,
            'failed' => $failed,
        ];
    }
}
