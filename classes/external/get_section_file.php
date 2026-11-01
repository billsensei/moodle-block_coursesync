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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Serves one chunk of one file used by a section's description.
 *
 * The only file area it can reach is the course's own "section" area, for an
 * ordinary section of the course asked about. The caller names a course and a
 * section; it never names a context or an item, so it cannot be steered at a
 * file anywhere else.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_section_file extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course id on this site.', VALUE_REQUIRED),
            'sectionid' => new external_value(PARAM_INT, 'The section id on this site.', VALUE_REQUIRED),
            'filepath' => new external_value(PARAM_PATH, 'Path within the section\'s files.', VALUE_REQUIRED),
            'filename' => new external_value(PARAM_FILE, 'File name.', VALUE_REQUIRED),
            'offset' => new external_value(PARAM_INT, 'Byte to start reading at.', VALUE_DEFAULT, 0),
            'length' => new external_value(
                PARAM_INT,
                'How many bytes to read.',
                VALUE_DEFAULT,
                get_activity_file::MAX_CHUNK
            ),
        ]);
    }

    /**
     * Read part of a file.
     *
     * @param int $courseid
     * @param int $sectionid
     * @param string $filepath
     * @param string $filename
     * @param int $offset
     * @param int $length
     * @return array
     */
    public static function execute(
        int $courseid,
        int $sectionid,
        string $filepath,
        string $filename,
        int $offset = 0,
        int $length = get_activity_file::MAX_CHUNK
    ): array {
        global $DB;

        [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'filepath' => $filepath,
            'filename' => $filename,
            'offset' => $offset,
            'length' => $length,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'filepath' => $filepath,
            'filename' => $filename,
            'offset' => $offset,
            'length' => $length,
        ]);

        if ($offset < 0 || $length < 1) {
            throw new \invalid_parameter_exception('offset must not be negative and length must be positive');
        }

        $length = min($length, get_activity_file::MAX_CHUNK);

        $course = $DB->get_record('course', ['id' => $courseid]);

        if (!$course) {
            throw new \moodle_exception('errorcoursenotfound', 'block_coursesync');
        }

        $context = \context_course::instance($course->id);
        require_capability('block/coursesync:sync', $context);
        self::validate_context($context);

        // The section has to belong to this course and be an ordinary one: a
        // delegated section's description is not this function's to serve.
        $section = $DB->get_record_select(
            'course_sections',
            'id = :id AND course = :course AND component IS NULL',
            ['id' => $sectionid, 'course' => $course->id]
        );

        if (!$section) {
            throw new \moodle_exception('errorfilenotfound', 'block_coursesync');
        }

        $file = get_file_storage()->get_file($context->id, 'course', 'section', $section->id, $filepath, $filename);

        if (!$file || $file->is_directory()) {
            throw new \moodle_exception('errorfilenotfound', 'block_coursesync');
        }

        $filesize = (int) $file->get_filesize();
        $content = $offset >= $filesize ? '' : get_activity_file::read_part($file, $offset, $length);
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
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return get_activity_file::execute_returns();
    }
}
