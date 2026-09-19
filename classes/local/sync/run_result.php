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
 * What a sync run ended up doing, in numbers.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

/**
 * Tally of a single run, for the caller and for Phase 4's display.
 */
class run_result {
    /** @var int Activities pulled for the first time. */
    public int $new = 0;

    /** @var int Local copies replaced with a newer remote version. */
    public int $updated = 0;

    /** @var int Activities that needed nothing doing. */
    public int $unchanged = 0;

    /** @var int Activities left alone for a person to resolve. */
    public int $conflicts = 0;

    /** @var int Activities that could not be pulled at all. */
    public int $skipped = 0;

    /** @var int Activities that failed with an error. */
    public int $errors = 0;

    /**
     * Constructor.
     *
     * @param string $runid Identifier shared by every log row of this run.
     */
    public function __construct(
        /** @var string Identifier shared by every log row of this run. */
        public readonly string $runid,
    ) {
    }

    /**
     * Whether anything was actually brought over.
     *
     * @return bool
     */
    public function pulled_anything(): bool {
        return ($this->new + $this->updated) > 0;
    }

    /**
     * The tally as a plain array.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'runid' => $this->runid,
            'new' => $this->new,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'conflicts' => $this->conflicts,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
