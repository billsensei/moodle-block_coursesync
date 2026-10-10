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
use block_coursesync\local\submissions;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Serves one chunk of one file from a student's submitted assignment.
 *
 * This is not get_activity_file, and cannot be built on it: that function's
 * promise is "the file is in an area the activity's handler declared", which
 * is a promise about an activity. A submission file belongs to one student, so
 * this one refuses unless the file is in a submission that
 *
 *   - belongs to a student the gradebook lists (never a teacher, never a
 *     suspended enrolment),
 *   - is that student's latest submitted attempt, and
 *   - is in a file or online text area of an assignment that is not a team
 *     assignment and has that submission plugin switched on.
 *
 * It sits behind the same switch and permissions as get_submissions, so it
 * cannot read anything the description function would not have listed.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_submission_file extends external_api {
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
            'username' => new external_value(PARAM_USERNAME, 'The student whose submission holds the file.', VALUE_REQUIRED),
            'area' => new external_value(PARAM_ALPHANUMEXT, 'submission_files or submissions_onlinetext.', VALUE_REQUIRED),
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
     * @param string $area
     * @param string $filepath
     * @param string $filename
     * @param int $offset
     * @param int $length
     * @return array
     */
    public static function execute(
        int $cmid,
        string $username,
        string $area,
        string $filepath,
        string $filename,
        int $offset = 0,
        int $length = self::MAX_CHUNK
    ): array {
        global $DB;

        [
            'cmid' => $cmid,
            'username' => $username,
            'area' => $area,
            'filepath' => $filepath,
            'filename' => $filename,
            'offset' => $offset,
            'length' => $length,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'username' => $username,
            'area' => $area,
            'filepath' => $filepath,
            'filename' => $filename,
            'offset' => $offset,
            'length' => $length,
        ]);

        if ($offset < 0 || $length < 1) {
            throw new \invalid_parameter_exception('offset must not be negative and length must be positive');
        }

        $length = min($length, self::MAX_CHUNK);

        // Switch and permissions first, so a site that does not share
        // submissions answers every caller alike. The course is found from the
        // activity, since that is all the destination names.
        $cm = $DB->get_record('course_modules', ['id' => $cmid]);

        if (!$cm || $cm->deletioninprogress) {
            throw new \moodle_exception('erroractivitynotfound', 'block_coursesync');
        }

        $course = submissions::require_export((int) $cm->course);
        self::validate_context(\context_course::instance($course->id));

        $cminfo = get_fast_modinfo($course->id)->get_cm($cm->id);

        if ($cminfo->modname !== 'assign' || !isset(submissions::AREAS[$area])) {
            throw new \moodle_exception('errorfilenotallowed', 'block_coursesync');
        }

        $assign = $DB->get_record('assign', ['id' => $cminfo->instance], 'id, teamsubmission', MUST_EXIST);
        $plugin = submissions::AREAS[$area] === 'assignsubmission_file' ? 'file' : 'onlinetext';

        if ($assign->teamsubmission || !in_array($plugin, submissions::enabled_plugins((int) $assign->id), true)) {
            throw new \moodle_exception('errorfilenotallowed', 'block_coursesync');
        }

        // Only a student the gradebook lists, only their latest handed-in work.
        $students = gradebook::only(gradebook::gradable_users($course->id, true), $username);
        $student = reset($students);
        $submission = $student && $student->username === $username
            ? submissions::latest_submitted((int) $assign->id, (int) $student->id)
            : null;

        if (!$submission) {
            throw new \moodle_exception('errorfilenotallowed', 'block_coursesync');
        }

        $context = \context_module::instance($cm->id);
        $file = get_file_storage()->get_file(
            $context->id,
            submissions::AREAS[$area],
            $area,
            (int) $submission->id,
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
