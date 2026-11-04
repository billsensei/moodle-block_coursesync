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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * An assignment that a marks pull writes a grade through, and that never adds an attempt.
 *
 * mod_assign's update_grade() does more than save the grade: when the
 * assignment is set to reopen until the student passes, a pass mark is set and
 * the grade just written fails, it adds another attempt. On the source that
 * was a teacher's decision, and the attempt (or its absence) is described by
 * the submission pull. A pull that only brings a mark across must not make one
 * of its own, so this does nothing where core would reopen.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mark_assign extends \assign {
    /**
     * Never reopen: the attempts a student has are the ones described.
     *
     * @param int $userid
     * @param \stdClass $submission
     * @param bool $addattempt
     * @return bool always false, nothing was reopened
     */
    protected function reopen_submission_if_required($userid, $submission, $addattempt) {
        return false;
    }
}
