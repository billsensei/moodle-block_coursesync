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
 * Reading and writing the record of what has been pulled.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

/**
 * The live ledger of remote activities and the local copies they produced.
 *
 * One row per (block instance, remote course module). Rows are written only
 * after a pull has actually succeeded, so a failed run leaves the ledger
 * describing the last known-good state.
 */
class pull_ledger {
    /** @var string The last pull succeeded and neither side has diverged. */
    public const STATUS_SYNCED = 'synced';

    /** @var string Needs a person to resolve before this activity moves again. */
    public const STATUS_CONFLICT = 'conflict';

    /** @var string Cannot be pulled, for example a module with no backup support. */
    public const STATUS_SKIPPED = 'skipped';

    /** @var string Database table backing the ledger. */
    private const TABLE = 'block_coursesync_pulls';

    /**
     * Loads a block instance's ledger, keyed by remote course module id.
     *
     * @param int $blockinstanceid Block instance id.
     * @return \stdClass[] Keyed by remotecmid.
     */
    public static function for_block(int $blockinstanceid): array {
        global $DB;

        $rows = $DB->get_records(self::TABLE, ['blockinstanceid' => $blockinstanceid]);

        $bycmid = [];
        foreach ($rows as $row) {
            $bycmid[(int) $row->remotecmid] = $row;
        }

        return $bycmid;
    }

    /**
     * Records a successful pull.
     *
     * @param int $blockinstanceid Block instance id.
     * @param plan_item $item The activity that was pulled.
     * @param int $localcmid The course module it was restored into.
     * @param array $localsignature Signature of that new local copy, as returned by activity_signature.
     */
    public static function record_success(int $blockinstanceid, plan_item $item, int $localcmid, array $localsignature): void {
        $row = self::base_row($blockinstanceid, $item);
        $row->localcmid = $localcmid;
        $row->localsignal = (string) ($localsignature['signal'] ?? '');
        $row->localsignalmethod = (string) ($localsignature['method'] ?? '');
        $row->status = self::STATUS_SYNCED;
        $row->conflictreason = null;
        $row->timepulled = time();

        self::write($row);
    }

    /**
     * Records that an activity is in conflict and was left alone.
     *
     * @param int $blockinstanceid Block instance id.
     * @param plan_item $item The activity in conflict.
     */
    public static function record_conflict(int $blockinstanceid, plan_item $item): void {
        $existing = self::find($blockinstanceid, $item->remotecmid);

        $row = self::base_row($blockinstanceid, $item);
        $row->status = self::STATUS_CONFLICT;
        $row->conflictreason = $item->reason;

        // A conflict must not overwrite what the last good pull recorded, or the
        // next run would lose its reference point and treat the divergence as settled.
        if ($existing) {
            $row->localcmid = $existing->localcmid;
            $row->localsignal = $existing->localsignal;
            $row->localsignalmethod = $existing->localsignalmethod;
            $row->remotesignal = $existing->remotesignal;
            $row->remotesignalmethod = $existing->remotesignalmethod;
            $row->timepulled = $existing->timepulled;
        } else {
            // A name collision points at an activity this block did not create, so that
            // course module must not be recorded as a copy: the next run has to see this
            // as still unpulled and check the collision again.
            $row->localcmid = null;
            $row->remotesignal = '';
            $row->remotesignalmethod = '';
        }

        self::write($row);
    }

    /**
     * Accepts the current state of both sides as the new baseline.
     *
     * Used when a person resolves a conflict by keeping the local copy: nothing
     * is transferred, but both signals are brought up to date so the next run
     * stops reporting the same divergence. The last pull time is deliberately
     * left alone, because nothing was pulled.
     *
     * @param int $blockinstanceid Block instance id.
     * @param plan_item $item The activity, carrying the current remote signal.
     * @param int $localcmid The local copy being kept.
     * @param array $localsignature Current signature of that local copy.
     */
    public static function record_local_kept(
        int $blockinstanceid,
        plan_item $item,
        int $localcmid,
        array $localsignature
    ): void {
        $row = self::base_row($blockinstanceid, $item);
        $row->localcmid = $localcmid;
        $row->localsignal = (string) ($localsignature['signal'] ?? '');
        $row->localsignalmethod = (string) ($localsignature['method'] ?? '');
        $row->status = self::STATUS_SYNCED;
        $row->conflictreason = null;

        self::write($row);
    }

    /**
     * Records that an activity cannot be pulled at all.
     *
     * @param int $blockinstanceid Block instance id.
     * @param plan_item $item The activity being skipped.
     */
    public static function record_skipped(int $blockinstanceid, plan_item $item): void {
        $row = self::base_row($blockinstanceid, $item);
        $row->status = self::STATUS_SKIPPED;
        $row->conflictreason = $item->reason;
        $row->remotesignal = '';
        $row->remotesignalmethod = '';

        self::write($row);
    }

    /**
     * Removes a block instance's ledger, for when the block itself goes away.
     *
     * @param int $blockinstanceid Block instance id.
     */
    public static function delete_for_block(int $blockinstanceid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['blockinstanceid' => $blockinstanceid]);
    }

    /**
     * Fetches one ledger row.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid Remote course module id.
     * @return \stdClass|false
     */
    private static function find(int $blockinstanceid, int $remotecmid) {
        global $DB;

        return $DB->get_record(self::TABLE, [
            'blockinstanceid' => $blockinstanceid,
            'remotecmid' => $remotecmid,
        ]);
    }

    /**
     * Builds the fields every ledger row shares.
     *
     * @param int $blockinstanceid Block instance id.
     * @param plan_item $item The activity concerned.
     * @return \stdClass
     */
    private static function base_row(int $blockinstanceid, plan_item $item): \stdClass {
        return (object) [
            'blockinstanceid' => $blockinstanceid,
            'remotecmid' => $item->remotecmid,
            'remotesignal' => $item->remotesignal,
            'remotesignalmethod' => $item->remotesignalmethod,
            'timemodified' => time(),
        ];
    }

    /**
     * Inserts or updates a ledger row.
     *
     * @param \stdClass $row Row to persist, without an id.
     */
    private static function write(\stdClass $row): void {
        global $DB;

        $existing = self::find((int) $row->blockinstanceid, (int) $row->remotecmid);
        if ($existing) {
            $row->id = $existing->id;
            $row->timepulled = $row->timepulled ?? $existing->timepulled;
            $DB->update_record(self::TABLE, $row);
            return;
        }

        $row->timepulled = $row->timepulled ?? 0;
        $DB->insert_record(self::TABLE, $row);
    }
}
