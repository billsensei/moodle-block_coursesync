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
 * Keeps this plugin's own records in step with what happens to the people and
 * courses they describe.
 *
 * A pull remembers what it wrote - which grades, which quiz attempts - so that
 * a later pull neither duplicates them nor overwrites a change made here. When
 * a course is reset, or a user is deleted, core removes the grades and
 * attempts but knows nothing of those memories. Left alone they are wrong in
 * two ways: they sit in the database about people who no longer exist, and,
 * after a reset, they make a pull treat attempts that are simply gone as
 * "deleted here since, somebody's decision", so they never come back.
 *
 * A block has no reset callbacks of its own (core calls those only for
 * activity modules), so this listens to the events core triggers instead.
 *
 * What is kept: the history of sync runs. It says what was copied into a
 * course and when, and the history page already copes with a person who no
 * longer exists.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A course has been reset: forget what was pulled into whatever it removed.
     *
     * The reset options travel with the event, so only what was really reset is
     * forgotten. A teacher who deleted a pulled attempt by hand, without a
     * reset, is still respected by the next pull.
     *
     * @param \core\event\course_reset_ended $event
     * @return void
     */
    public static function course_reset_ended(\core\event\course_reset_ended $event): void {
        global $DB;

        $options = $event->other['reset_options'] ?? [];
        $courseid = (int) $event->courseid;

        // Quiz attempts are removed by the quiz's own reset.
        if (!empty($options['reset_quiz_attempts'])) {
            $DB->delete_records('block_coursesync_attempt', ['courseid' => $courseid]);
        }

        // Removing the items removes their grades with them, so either option
        // leaves nothing for a remembered grade to refer to.
        if (!empty($options['reset_gradebook_items']) || !empty($options['reset_gradebook_grades'])) {
            $DB->delete_records('block_coursesync_grade', ['courseid' => $courseid]);
        }
    }

    /**
     * A user has been deleted: forget what was pulled for them.
     *
     * @param \core\event\user_deleted $event
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;

        $userid = (int) $event->objectid;

        $DB->delete_records('block_coursesync_grade', ['userid' => $userid]);
        $DB->delete_records('block_coursesync_attempt', ['userid' => $userid]);
    }
}
