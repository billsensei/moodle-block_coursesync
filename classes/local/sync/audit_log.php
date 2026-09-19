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
 * The append-only record of what each sync run did.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

/**
 * Writes one row per activity processed, grouped by run.
 *
 * Nothing here is ever updated or deleted during normal operation; this is the
 * history a teacher sees, so it has to survive later runs changing their minds.
 */
class audit_log {
    /** @var string Pulled for the first time. */
    public const OUTCOME_NEW = 'new';

    /** @var string Local copy replaced with a newer remote version. */
    public const OUTCOME_UPDATED = 'updated';

    /** @var string Nothing to do. */
    public const OUTCOME_UNCHANGED = 'unchanged';

    /** @var string Left alone for a person to resolve. */
    public const OUTCOME_CONFLICT = 'conflict';

    /** @var string Could not be pulled at all. */
    public const OUTCOME_SKIPPED = 'skipped';

    /** @var string Something went wrong with this one activity. */
    public const OUTCOME_ERROR = 'error';

    /** @var string A conflict was settled by keeping the local copy. */
    public const OUTCOME_KEPT_LOCAL = 'keptlocal';

    /** @var string A conflict was looked at and left for later. */
    public const OUTCOME_DEFERRED = 'deferred';

    /** @var string Database table backing the log. */
    private const TABLE = 'block_coursesync_log';

    /**
     * Constructor.
     *
     * @param string $runid Identifier grouping every row this run writes.
     * @param int $blockinstanceid Block instance being synced.
     * @param int $userid User who triggered the run.
     */
    public function __construct(
        /** @var string Identifier grouping every row this run writes. */
        private readonly string $runid,
        /** @var int Block instance being synced. */
        private readonly int $blockinstanceid,
        /** @var int User who triggered the run. */
        private readonly int $userid,
    ) {
    }

    /**
     * Records what happened to one activity.
     *
     * @param string $outcome One of the OUTCOME_* constants.
     * @param plan_item $item The activity concerned.
     * @param int|null $localcmid The local course module, where one exists.
     * @param string|null $message Detail for conflicts and errors.
     */
    public function record(string $outcome, plan_item $item, ?int $localcmid = null, ?string $message = null): void {
        global $DB;

        $DB->insert_record(self::TABLE, (object) [
            'runid' => $this->runid,
            'blockinstanceid' => $this->blockinstanceid,
            'userid' => $this->userid,
            'remotecmid' => $item->remotecmid,
            'localcmid' => $localcmid ?? $item->localcmid,
            'modname' => $item->modname,
            'name' => \core_text::substr($item->name, 0, 255),
            'outcome' => $outcome,
            'message' => $message,
            'timecreated' => time(),
        ]);
    }

    /**
     * Records a problem that stopped the run before any activity was considered.
     *
     * @param string $message What went wrong.
     */
    public function record_run_failure(string $message): void {
        global $DB;

        $DB->insert_record(self::TABLE, (object) [
            'runid' => $this->runid,
            'blockinstanceid' => $this->blockinstanceid,
            'userid' => $this->userid,
            'remotecmid' => 0,
            'localcmid' => null,
            'modname' => null,
            'name' => null,
            'outcome' => self::OUTCOME_ERROR,
            'message' => $message,
            'timecreated' => time(),
        ]);
    }

    /**
     * Removes a block instance's history, for when the block itself goes away.
     *
     * @param int $blockinstanceid Block instance id.
     */
    public static function delete_for_block(int $blockinstanceid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['blockinstanceid' => $blockinstanceid]);
    }
}
