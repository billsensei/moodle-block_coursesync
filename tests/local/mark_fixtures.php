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
 * Real marks for tests, given the way a teacher gives them.
 *
 * The grade goes through mod_assign's own update_grade(), and the comment
 * into the comments feedback plugin's table and file area, so what the tests
 * hold is what a real site holds.
 *
 * Uses submission_fixtures for the assignment itself.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait mark_fixtures {
    /**
     * An assignment that can be marked: graded out of 100 with the written
     * comments feedback switched on.
     *
     * @param \stdClass $course
     * @param array $record extra generator fields
     * @param array $options generator options, such as an idnumber
     * @return \stdClass the module record
     */
    protected function markable(\stdClass $course, array $record = [], array $options = []): \stdClass {
        return $this->assignment($course, array_merge(['assignfeedback_comments_enabled' => 1], $record), $options);
    }

    /**
     * A teacher marks a student.
     *
     * @param \stdClass $module
     * @param \stdClass $student
     * @param float|null $grade null for a comment only
     * @param string|null $comment
     * @param string[] $files file name => content, embedded in the comment
     * @return \stdClass the assign_grades row
     */
    protected function give_mark(
        \stdClass $module,
        \stdClass $student,
        ?float $grade,
        ?string $comment = null,
        array $files = []
    ): \stdClass {
        global $DB;

        $assign = $this->assign_object($module);
        $row = $assign->get_user_grade((int) $student->id, true);
        $context = \context_module::instance($module->cmid);
        $fs = get_file_storage();

        $fs->delete_area_files($context->id, marks::COMPONENT, marks::AREA, $row->id);
        $DB->delete_records('assignfeedback_comments', ['grade' => $row->id]);

        if ($comment !== null) {
            $DB->insert_record('assignfeedback_comments', (object) [
                'assignment' => $module->id,
                'grade' => $row->id,
                'commenttext' => $comment,
                'commentformat' => FORMAT_HTML,
            ]);

            foreach ($files as $name => $content) {
                $fs->create_file_from_string([
                    'contextid' => $context->id,
                    'component' => marks::COMPONENT,
                    'filearea' => marks::AREA,
                    'itemid' => $row->id,
                    'filepath' => '/',
                    'filename' => $name,
                ], $content);
            }
        }

        $row->grade = $grade ?? -1;
        $row->grader = get_admin()->id;
        $assign->update_grade($row);

        return $DB->get_record('assign_grades', ['id' => $row->id], '*', MUST_EXIST);
    }
}
