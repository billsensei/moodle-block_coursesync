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

/**
 * What one run of a sync did.
 *
 * Phase 4 keeps this in memory for the length of the request and shows it once.
 * Persistent sync history is phase 6; when it arrives this is the shape that
 * should be written down.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_result {
    /** @var bool Whether the run got far enough to be trusted. */
    public bool $success = true;

    /** @var string|null Language string identifier for a run that failed outright. */
    public ?string $errorkey = null;

    /** @var array[] One entry per activity the run considered. */
    public array $items = [];

    /** @var bool Whether lastsync was moved forward. */
    public bool $lastsyncupdated = false;

    /** @var int The time the run asked the source about. */
    public int $since = 0;

    /** @var int The history row this run was written to. */
    public int $runid = 0;

    /**
     * The run could not start or could not finish.
     *
     * @param string $errorkey a language string identifier in block_coursesync
     * @return self
     */
    public static function failure(string $errorkey): self {
        $result = new self();
        $result->success = false;
        $result->errorkey = $errorkey;

        return $result;
    }

    /**
     * Record an activity that was created.
     *
     * @param string $name
     * @param string $modname
     * @param int $remotecmid
     * @param int $localcmid
     * @param string[] $notes language string identifiers for anything the user should know
     * @return void
     */
    public function add_created(
        string $name,
        string $modname,
        int $remotecmid,
        int $localcmid,
        array $notes = []
    ): void {
        $this->items[] = [
            'outcome' => 'created',
            'name' => $name,
            'modname' => $modname,
            'remotecmid' => $remotecmid,
            'localcmid' => $localcmid,
            'notes' => $notes,
            'detail' => null,
        ];
    }

    /**
     * Record an activity that was left alone.
     *
     * @param string $name
     * @param string $modname
     * @param int $remotecmid
     * @param string $reasonkey a language string identifier explaining why
     * @return void
     */
    public function add_skipped(string $name, string $modname, int $remotecmid, string $reasonkey): void {
        $this->items[] = [
            'outcome' => 'skipped',
            'name' => $name,
            'modname' => $modname,
            'remotecmid' => $remotecmid,
            'localcmid' => 0,
            'notes' => [],
            'detail' => $reasonkey,
        ];
    }

    /**
     * Record an activity that was left alone because something is already there.
     *
     * A conflict is never resolved automatically. The existing activity is not
     * overwritten and a second copy is not made; the run records what it found
     * and leaves the decision to a person.
     *
     * @param string $name
     * @param string $modname
     * @param int $remotecmid
     * @param int $localcmid the activity already in the destination course
     * @param string $reasonkey a language string identifier explaining what was found
     * @return void
     */
    public function add_conflict(
        string $name,
        string $modname,
        int $remotecmid,
        int $localcmid,
        string $reasonkey
    ): void {
        $this->items[] = [
            'outcome' => 'conflict',
            'name' => $name,
            'modname' => $modname,
            'remotecmid' => $remotecmid,
            'localcmid' => $localcmid,
            'notes' => [],
            'detail' => $reasonkey,
        ];
    }

    /**
     * Record an activity that could not be created.    /**
     * Record an activity that could not be created.
     *
     * @param string $name
     * @param string $modname
     * @param int $remotecmid
     * @param string $reasonkey a language string identifier explaining why
     * @return void
     */
    public function add_failed(string $name, string $modname, int $remotecmid, string $reasonkey): void {
        $this->items[] = [
            'outcome' => 'failed',
            'name' => $name,
            'modname' => $modname,
            'remotecmid' => $remotecmid,
            'localcmid' => 0,
            'notes' => [],
            'detail' => $reasonkey,
        ];
    }

    /**
     * How many activities had a given outcome.
     *
     * @param string $outcome one of created, skipped, failed
     * @return int
     */
    public function count(string $outcome): int {
        return count(array_filter($this->items, static fn(array $item): bool => $item['outcome'] === $outcome));
    }

    /**
     * Did the teacher leave anything out of this run?
     *
     * This is what stops the last synced marker moving past an activity that
     * was deliberately not copied. Without it, choosing "not this one" would
     * mean never being offered it again, which is not what not-this-one means.
     *
     * @return bool
     */
    public function has_deselected(): bool {
        foreach ($this->items as $item) {
            if (($item['detail'] ?? null) === 'syncskippeddeselected') {
                return true;
            }
        }

        return false;
    }

    /**
     * Did everything the run attempted actually work?
     *
     * Conflicts do not count against this. A conflict is a decision the run
     * made and wrote down, not something that went wrong, and holding the last
     * synced marker for one would re-flag every previously pulled activity on
     * the next run.
     *
     * @return bool
     */
    public function is_clean(): bool {
        return $this->success && $this->count('failed') === 0;
    }

    /**
     * Is there anything here a person needs to look at?
     *
     * @return bool
     */
    public function needs_review(): bool {
        return $this->count('conflict') > 0 || $this->count('failed') > 0;
    }

    /**
     * A sentence summarising the run.
     *
     * @return string
     */
    public function get_message(): string {
        if (!$this->success) {
            return get_string($this->errorkey, 'block_coursesync');
        }

        if ($this->items === []) {
            return get_string('syncnothingtodo', 'block_coursesync');
        }

        return get_string('syncsummary', 'block_coursesync', (object) [
            'created' => $this->count('created'),
            'conflicts' => $this->count('conflict'),
            'skipped' => $this->count('skipped'),
            'failed' => $this->count('failed'),
        ]);
    }
}
