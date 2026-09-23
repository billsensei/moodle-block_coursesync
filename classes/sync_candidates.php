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
 * What a sync would offer to copy, worked out without copying anything.
 *
 * The sync page asks this before it does anything, so a teacher sees what is
 * actually on the table and can leave parts of it out. Three groups come back,
 * because a teacher wants different things from each:
 *
 * - **new**: in the other course and not in this one. These are what is offered,
 *   each one selectable.
 * - **changed**: this plugin copied it here already, and the source has
 *   changed it since. Offered, but not pre-selected: syncing it replaces the
 *   copy, or adds a new edition beside it when people already have something
 *   in the copy - see copy_update. Which of the two is known up front, so the
 *   page can say.
 * - **present**: this plugin copied it here already, and it has not changed
 *   since. Not offered, because copying it twice is exactly what this plugin
 *   refuses to do. Counted so the list being shorter than the other course is
 *   explained rather than mysterious.
 * - **collisions**: something in this course carries that activity's identity,
 *   but this plugin did not put it there. Also not offered, but named rather
 *   than counted: it is the one case a person needs to look at, and hiding it
 *   among the ordinary already-here ones would lose what phase 6 added.
 * - **unsupported**: in the other course, not here, and of a type this plugin
 *   has no handler for. Never offered - there is nothing a teacher can do
 *   about it from the sync page, so it is left off that page entirely rather
 *   than named there. Still counted here, for anything that wants to know.
 *
 * Working this out costs one request to the other site - the same change
 * detection a sync starts with - and reads the local course. It creates nothing.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_candidates {
    /** @var bool Whether the other site could be asked at all. */
    public bool $success = true;

    /** @var string|null Why not, when it could not. */
    public ?string $errorkey = null;

    /** @var activity[] In the other course, not in this one, and syncable. */
    public array $new = [];

    /** @var activity[] Copied here by an earlier run, and changed on the source since. */
    public array $changed = [];

    /** @var int[] Remote cmids of changed activities whose local copy people have data in. */
    public array $neweditions = [];

    /** @var activity[] Copied here by an earlier run, and unchanged since. */
    public array $present = [];

    /** @var activity[] Something here carries the identity, but this plugin did not put it there. */
    public array $collisions = [];

    /** @var activity[] In the other course, not here, and of a type nothing handles. */
    public array $unsupported = [];

    /** @var int The timestamp change detection asked about. */
    public int $since = 0;

    /**
     * A list that could not be built.
     *
     * @param string $errorkey a language string key in this plugin
     * @return self
     */
    public static function failure(string $errorkey): self {
        $candidates = new self();
        $candidates->success = false;
        $candidates->errorkey = $errorkey;

        return $candidates;
    }

    /**
     * Is there anything a teacher could choose to copy?
     *
     * @return bool
     */
    public function has_any(): bool {
        return $this->new !== [] || $this->changed !== [];
    }

    /**
     * Would syncing this changed activity add a new edition rather than replace the copy?
     *
     * @param int $remotecmid
     * @return bool
     */
    public function is_new_edition(int $remotecmid): bool {
        return in_array($remotecmid, $this->neweditions, true);
    }

    /**
     * The remote course module ids of everything on offer.
     *
     * This is what a selection is checked against: an id that is not in here was
     * not offered, so it is not synced however it arrived.
     *
     * @return int[]
     */
    public function offered_cmids(): array {
        return array_map(
            static fn(activity $activity): int => $activity->cmid,
            array_merge($this->new, $this->changed)
        );
    }

    /**
     * How many activities were left out because they are already here.
     *
     * @return int
     */
    public function present_count(): int {
        return count($this->present);
    }

    /**
     * Is there anything a person ought to look at before syncing?
     *
     * @return bool
     */
    public function needs_review(): bool {
        return $this->collisions !== [];
    }
}
