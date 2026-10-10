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

namespace block_coursesync\external;

use block_coursesync\local\gradebook;
use block_coursesync\local\marks;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Serves one chunk of one file embedded in a teacher's comment on a mark.
 *
 * This is neither get_activity_file nor get_submission_file. A comment file
 * belongs to one student's mark, so this one refuses unless the file is in a
 * comment on a mark that
 *
 *   - belongs to a student the gradebook lists (never a teacher, never a
 *     suspended enrolment),
 *   - is the mark get_marks would describe for that student (so one that is
 *     not yet released under a marking workflow cannot be read), and
 *   - is in an assignment that is not a team assignment and is graded in points.
 *
 * It sits behind the same switch and permissions as get_marks, so it cannot
 * read anything the description function would not have listed.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_mark_file extends external_api {
    /** @var int Largest chunk a caller may ask for, in bytes. */
    public const MAX_CHUNK = get_activity_file::MAX_CHUNK;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the assignment.', VALUE_REQUIRED),
            'username' => new external_value(PARAM_USERNAME, 'The student whose mark holds the file.', VALUE_REQUIRED),
            'filepath' => new external_value(PARAM_PATH, 'Path within the area.', VALUE_REQUIRED),
            'filename' => new external_value(PARAM_FILE, 'File name.', VALUE_REQUIRED),
            'offset' => new external_value(PARAM_INT, 'Byte to start reading at.', VALUE_DEFAULT, 0),
            'length' => new external_value(PARAM_INT, 'How many bytes to read.', VALUE_DEFAULT, self::MAX_CHUNK),
        ]);
    }

    /**
     * Read part of a file.
     *
     * @param int $cmid
     * @param string $username
     * @param string $filepath
     * @param string $filename
     * @param int $offset
     * @param int $length
     * @return array
     */
    public static function execute(
        int $cmid,
        string $username,
        string $filepath,
        string $filename,
        int $offset = 0,
        int $length = self::MAX_CHUNK
    ): array {
        global $DB;

        [
            'cmid' => $cmid,
            'username' => $username,
            'filepath' => $filepath,
            'filename' => $filename,
            'offset' => $offset,
            'length' => $length,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'username' => $username,
            'filepath' => $filepath,
            'filename' => $filename,
            'offset' => $offset,
            'length' => $length,
        ]);

        if ($offset < 0 || $length < 1) {
            throw new \invalid_parameter_exception('offset must not be negative and length must be positive');
        }

        $length = min($length, self::MAX_CHUNK);

        // Switch and permissions first, so a site that does not share marks
        // answers every caller alike. The course is found from the activity,
        // since that is all the destination names.
        $cm = $DB->get_record('course_modules', ['id' => $cmid]);

        if (!$cm || $cm->deletioninprogress) {
            throw new \moodle_exception('erroractivitynotfound', 'block_coursesync');
        }

        $course = marks::require_export((int) $cm->course);
        self::validate_context(\context_course::instance($course->id));

        $cminfo = get_fast_modinfo($course->id)->get_cm($cm->id);

        if ($cminfo->modname !== 'assign') {
            throw new \moodle_exception('errorfilenotallowed', 'block_coursesync');
        }

        $assign = $DB->get_record('assign', ['id' => $cminfo->instance], 'id, grade, teamsubmission, markingworkflow', MUST_EXIST);

        if (marks::assignment_problem($assign) !== null || !marks::comments_enabled((int) $assign->id)) {
            throw new \moodle_exception('errorfilenotallowed', 'block_coursesync');
        }

        // Only a student the gradebook lists, only the mark that would be described.
        $students = gradebook::only(gradebook::gradable_users($course->id, true), $username);
        $student = reset($students);
        $grade = $student && $student->username === $username ? marks::mark_for($assign, (int) $student->id) : null;

        if (!$grade) {
            throw new \moodle_exception('errorfilenotallowed', 'block_coursesync');
        }

        $file = get_file_storage()->get_file(
            \context_module::instance($cm->id)->id,
            marks::COMPONENT,
            marks::AREA,
            (int) $grade->id,
            $filepath,
            $filename
        );

        if (!$file || $file->is_directory()) {
            throw new \moodle_exception('errorfilenotfound', 'block_coursesync');
        }

        $filesize = (int) $file->get_filesize();
        $content = $offset >= $filesize ? '' : self::read_part($file, $offset, $length);
        $read = strlen($content);

        return [
            'filesize' => $filesize,
            'offset' => $offset,
            'returned' => $read,
            'eof' => ($offset + $read) >= $filesize,
            'contenthash' => $file->get_contenthash(),
            'content' => base64_encode($content),
        ];
    }

    /**
     * Read one piece of a stored file without loading the rest of it.
     *
     * @param \stored_file $file
     * @param int $offset
     * @param int $length
     * @return string
     */
    protected static function read_part(\stored_file $file, int $offset, int $length): string {
        $handle = $file->get_content_file_handle();

        if (!$handle) {
            throw new \moodle_exception('errorfilenotfound', 'block_coursesync');
        }

        try {
            $content = stream_get_contents($handle, $length, $offset);
        } finally {
            fclose($handle);
        }

        if ($content === false) {
            return substr($file->get_content(), $offset, $length);
        }

        return $content;
    }

    /**
     * Describes the return value. The same shape as get_activity_file's, so
     * the destination reads both the same way.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return get_activity_file::execute_returns();
    }
}
