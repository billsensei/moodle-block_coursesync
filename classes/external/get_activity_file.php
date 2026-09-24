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

use block_coursesync\local\handler\handler_registry;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Serves one chunk of one file belonging to a synced activity.
 *
 * Files are read a piece at a time rather than returned whole, so a large
 * attachment never has to sit in memory at either end inside a single response.
 *
 * This exists instead of pointing the destination at webservice/pluginfile.php,
 * which would need file downloading switched on for the whole service. That
 * endpoint will serve anything the token's user can reach; this one will only
 * serve a file that lives in a file area the activity's own handler has
 * declared, which is a far narrower promise.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_activity_file extends external_api {
    /** @var int Largest chunk a caller may ask for, in bytes. */
    public const MAX_CHUNK = 524288;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id the file belongs to.', VALUE_REQUIRED),
            'filearea' => new external_value(PARAM_AREA, 'File area within the activity.', VALUE_REQUIRED),
            'itemid' => new external_value(PARAM_INT, 'Item id within the file area.', VALUE_REQUIRED),
            'filepath' => new external_value(PARAM_PATH, 'Path within the file area.', VALUE_REQUIRED),
            'filename' => new external_value(PARAM_FILE, 'File name.', VALUE_REQUIRED),
            'offset' => new external_value(PARAM_INT, 'Byte to start reading at.', VALUE_DEFAULT, 0),
            'length' => new external_value(PARAM_INT, 'How many bytes to read.', VALUE_DEFAULT, self::MAX_CHUNK),
            'component' => new external_value(
                PARAM_COMPONENT,
                'Component the file area belongs to, when it is not the activity itself.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Read part of a file.
     *
     * @param int $cmid
     * @param string $filearea
     * @param int $itemid
     * @param string $filepath
     * @param string $filename
     * @param int $offset
     * @param int $length
     * @param string $component empty for the activity's own component
     * @return array
     */
    public static function execute(
        int $cmid,
        string $filearea,
        int $itemid,
        string $filepath,
        string $filename,
        int $offset = 0,
        int $length = self::MAX_CHUNK,
        string $component = ''
    ): array {
        global $DB;

        [
            'cmid' => $cmid,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $filename,
            'offset' => $offset,
            'length' => $length,
            'component' => $component,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $filename,
            'offset' => $offset,
            'length' => $length,
            'component' => $component,
        ]);

        if ($offset < 0 || $length < 1) {
            throw new \invalid_parameter_exception('offset must not be negative and length must be positive');
        }

        $length = min($length, self::MAX_CHUNK);

        $cm = $DB->get_record('course_modules', ['id' => $cmid]);

        if (!$cm || $cm->deletioninprogress) {
            throw new \moodle_exception('erroractivitynotfound', 'block_coursesync');
        }

        // The course is what is validated, matching block_coursesync_get_activity;
        // see the note there on why it is not the activity. What keeps this from
        // being a way to read any file of any activity is the check further down
        // that the area asked for is one the handler declared.
        $coursecontext = \context_course::instance($cm->course);
        require_capability('block/coursesync:sync', $coursecontext);
        self::validate_context($coursecontext);

        $context = \context_module::instance($cm->id);

        $cminfo = get_fast_modinfo($cm->course)->get_cm($cm->id);
        $handler = handler_registry::get($cminfo->modname);

        if ($handler === null) {
            throw new \moodle_exception('errorunsupportedtype', 'block_coursesync', '', $cminfo->modname);
        }

        // Only an area the handler has declared may be read. Without this the
        // function would be a way to read any file in any activity.
        if ($component === '') {
            $component = 'mod_' . $cminfo->modname;
        }

        if (!$handler->declares_file_area($component, $filearea, $itemid)) {
            throw new \moodle_exception('errorfilenotallowed', 'block_coursesync');
        }

        $fs = get_file_storage();
        $file = $fs->get_file($context->id, $component, $filearea, $itemid, $filepath, $filename);

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
     * get_content() would read the whole file for every piece asked for: a
     * 200 MB package fetched in 512 KB pieces would be read from disk 400
     * times over, 200 MB of memory each time. A stream positioned at the
     * offset reads only the piece.
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
            // A file system whose streams cannot seek. Rare, and still correct.
            return substr($file->get_content(), $offset, $length);
        }

        return $content;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'filesize' => new external_value(PARAM_INT, 'Total size of the file in bytes.'),
            'offset' => new external_value(PARAM_INT, 'Byte this chunk starts at.'),
            'returned' => new external_value(PARAM_INT, 'How many bytes this chunk holds.'),
            'eof' => new external_value(PARAM_BOOL, 'Whether this chunk reaches the end of the file.'),
            'contenthash' => new external_value(PARAM_ALPHANUM, 'SHA1 of the whole file, for checking what arrives.'),
            'content' => new external_value(PARAM_RAW, 'The chunk, base64 encoded.'),
        ]);
    }
}
