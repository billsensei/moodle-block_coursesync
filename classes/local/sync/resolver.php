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

/**
 * Settling a conflict one way or the other.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

use block_coursesync\local\activity_signature;
use block_coursesync\local\block_helper;

/**
 * Applies a person's decision about a conflicted activity.
 *
 * Every decision is recorded in the audit log, including the one that changes
 * nothing, so the history shows who looked at a conflict and what they chose.
 */
class resolver {
    /** @var string Keep the local copy and stop reporting the divergence. */
    public const ACTION_KEEP_LOCAL = 'keeplocal';

    /** @var string Take the remote copy, replacing the local one. */
    public const ACTION_PULL_REMOTE = 'pullremote';

    /** @var string Leave it flagged and decide later. */
    public const ACTION_DEFER = 'defer';

    /**
     * The actions a person may choose.
     *
     * @return string[]
     */
    public static function actions(): array {
        return [self::ACTION_KEEP_LOCAL, self::ACTION_PULL_REMOTE, self::ACTION_DEFER];
    }

    /**
     * Carries out a decision.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid The conflicted remote course module.
     * @param string $action One of the ACTION_* constants.
     * @param int $userid The person deciding.
     * @return string A translated message describing what happened.
     * @throws \moodle_exception If the action is unknown or the conflict has gone.
     */
    public static function resolve(int $blockinstanceid, int $remotecmid, string $action, int $userid): string {
        if (!in_array($action, self::actions(), true)) {
            throw new \moodle_exception('error:unknownaction', 'block_coursesync');
        }

        // Checked here as well as on the page, so that no future caller can
        // resolve a conflict without the capability to change course content.
        $block = block_helper::get_instance($blockinstanceid);
        require_capability('block/coursesync:trigger', $block->context, $userid);

        $message = match ($action) {
            self::ACTION_PULL_REMOTE => self::pull_remote($blockinstanceid, $remotecmid, $userid),
            self::ACTION_KEEP_LOCAL => self::keep_local($blockinstanceid, $remotecmid, $userid),
            default => self::defer($blockinstanceid, $remotecmid, $userid),
        };

        // A settled conflict changes what the next run would do, so any list of
        // what is waiting to be synced is now out of date.
        available::invalidate($blockinstanceid);

        return $message;
    }

    /**
     * Takes the remote copy, which is the transfer the sync run held back.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid The conflicted remote course module.
     * @param int $userid The person deciding.
     * @return string A translated message describing what happened.
     */
    private static function pull_remote(int $blockinstanceid, int $remotecmid, int $userid): string {
        // Choosing the remote version means replacing whatever stands in its
        // way, including an activity this block did not create: that is the
        // whole point of the decision, and the page asks for it to be
        // confirmed before getting here.
        $result = engine::pull_one($blockinstanceid, $remotecmid, $userid, true);

        if ($result->errors) {
            return get_string('resolve:failed', 'block_coursesync');
        }

        return get_string('resolve:pulledremote', 'block_coursesync');
    }

    /**
     * Keeps the local copy and moves the baseline forward so it stops being flagged.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid The conflicted remote course module.
     * @param int $userid The person deciding.
     * @return string A translated message describing what happened.
     * @throws \moodle_exception If the conflict or its local copy has gone.
     */
    private static function keep_local(int $blockinstanceid, int $remotecmid, int $userid): string {
        $entry = self::require_conflict($blockinstanceid, $remotecmid);

        $localcmid = (int) ($entry->localcmid ?? 0);
        $localsignature = $localcmid ? activity_signature::for_local_cmid($localcmid) : null;
        if (!$localcmid || !$localsignature) {
            throw new \moodle_exception('error:localgone', 'block_coursesync');
        }

        // The remote signal being accepted has to be read now, so the next run
        // compares against the version this decision was actually made about.
        $activity = self::current_remote_activity($blockinstanceid, $remotecmid, $userid);

        $item = new plan_item(
            $remotecmid,
            (string) ($activity['modname'] ?? ''),
            (string) ($activity['name'] ?? ''),
            (int) ($activity['sectionnum'] ?? 0),
            (string) ($activity['signal'] ?? ''),
            (string) ($activity['signalmethod'] ?? ''),
            plan_item::ACTION_UNCHANGED,
            $localcmid
        );

        pull_ledger::record_local_kept($blockinstanceid, $item, $localcmid, $localsignature);

        self::log($blockinstanceid, $userid, audit_log::OUTCOME_KEPT_LOCAL, $item, $localcmid);

        return get_string('resolve:keptlocal', 'block_coursesync');
    }

    /**
     * Leaves the conflict in place, but records that someone looked at it.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid The conflicted remote course module.
     * @param int $userid The person deciding.
     * @return string A translated message describing what happened.
     * @throws \moodle_exception If the conflict has gone.
     */
    private static function defer(int $blockinstanceid, int $remotecmid, int $userid): string {
        $entry = self::require_conflict($blockinstanceid, $remotecmid);

        $item = new plan_item(
            $remotecmid,
            '',
            '',
            0,
            (string) ($entry->remotesignal ?? ''),
            (string) ($entry->remotesignalmethod ?? ''),
            plan_item::ACTION_CONFLICT,
            $entry->localcmid ? (int) $entry->localcmid : null,
            (string) ($entry->conflictreason ?? '')
        );

        self::log($blockinstanceid, $userid, audit_log::OUTCOME_DEFERRED, $item, null);

        return get_string('resolve:deferred', 'block_coursesync');
    }

    /**
     * Whether taking the remote version here would delete an activity this block did not create.
     *
     * Read from what the run recorded rather than by asking the remote site,
     * because this is called while drawing a page. It is the question of
     * whether to warn and confirm, not the decision itself: the activity in
     * the way is looked for again when the replacement actually happens.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid The conflicted remote course module.
     * @return bool
     */
    public static function replaces_foreign_activity(int $blockinstanceid, int $remotecmid): bool {
        $ledger = pull_ledger::for_block($blockinstanceid);
        $entry = $ledger[$remotecmid] ?? null;

        return $entry
            && $entry->status === pull_ledger::STATUS_CONFLICT
            && (string) $entry->conflictreason === plan_item::CONFLICT_NAME_COLLISION;
    }

    /**
     * Loads a conflicted ledger row, refusing anything that is no longer in conflict.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid The remote course module.
     * @return \stdClass
     * @throws \moodle_exception If there is no such conflict.
     */
    private static function require_conflict(int $blockinstanceid, int $remotecmid): \stdClass {
        $ledger = pull_ledger::for_block($blockinstanceid);
        $entry = $ledger[$remotecmid] ?? null;

        if (!$entry || $entry->status !== pull_ledger::STATUS_CONFLICT) {
            throw new \moodle_exception('error:conflictgone', 'block_coursesync');
        }

        return $entry;
    }

    /**
     * Reads an activity's current state from the remote site.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid The remote course module.
     * @param int $userid The person deciding, whose permissions apply.
     * @return array The activity as block_coursesync_list_activities describes it.
     * @throws \moodle_exception If the activity is no longer there.
     */
    private static function current_remote_activity(int $blockinstanceid, int $remotecmid, int $userid): array {
        [$block, , $client] = engine::prepare_context($blockinstanceid, $userid);

        foreach ($client->list_activities((int) $block->config->remotecourseid) as $activity) {
            if ((int) $activity['cmid'] === $remotecmid) {
                return $activity;
            }
        }

        throw new \moodle_exception('error:remotegone', 'block_coursesync');
    }

    /**
     * Writes a resolution to the audit trail as a run of its own.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $userid The person deciding.
     * @param string $outcome One of the audit_log OUTCOME_* constants.
     * @param plan_item $item The activity concerned.
     * @param int|null $localcmid The local copy, where one is known.
     */
    private static function log(
        int $blockinstanceid,
        int $userid,
        string $outcome,
        plan_item $item,
        ?int $localcmid
    ): void {
        $log = new audit_log(uniqid('csx', false), $blockinstanceid, $userid);
        $log->record($outcome, $item, $localcmid);
    }
}
