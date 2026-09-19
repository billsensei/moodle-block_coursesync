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
 * Presenting sync outcomes to people.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\output;

use block_coursesync\local\sync\audit_log;

/**
 * Turns the log's stored outcome values into something readable.
 */
class outcome {
    /**
     * The human-readable name of an outcome.
     *
     * @param string $outcome A value stored in the log's outcome column.
     * @return string
     */
    public static function label(string $outcome): string {
        $key = 'outcome:' . $outcome;

        if (!get_string_manager()->string_exists($key, 'block_coursesync')) {
            return $outcome;
        }

        return get_string($key, 'block_coursesync');
    }

    /**
     * The bootstrap badge variant that suits an outcome.
     *
     * @param string $outcome A value stored in the log's outcome column.
     * @return string
     */
    public static function badge(string $outcome): string {
        return match ($outcome) {
            audit_log::OUTCOME_NEW, audit_log::OUTCOME_UPDATED => 'success',
            audit_log::OUTCOME_CONFLICT, audit_log::OUTCOME_DEFERRED => 'warning',
            audit_log::OUTCOME_ERROR => 'danger',
            audit_log::OUTCOME_KEPT_LOCAL => 'info',
            default => 'secondary',
        };
    }
}
