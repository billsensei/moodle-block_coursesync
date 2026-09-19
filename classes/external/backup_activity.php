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
 * Packages one activity so a remote site can download and restore it.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\external;

use block_coursesync\local\backup_packager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Backs up a single course module and says where to collect the .mbz.
 *
 * The file is not returned inline: it is parked in this plugin's file area in
 * the activity's own context, and the caller fetches it from
 * webservice/pluginfile.php with the same token. Anything not collected is
 * removed by the cleanup task.
 */
class backup_activity extends external_api {
    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id to back up'),
        ]);
    }

    /**
     * Backs up the activity.
     *
     * @param int $cmid Course module id to back up.
     * @return array Where to download the resulting backup from.
     */
    public static function execute(int $cmid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid']);
        $context = \context_module::instance($cm->id);
        $coursecontext = \context_course::instance($course->id);

        // Validated at course level on purpose. Validating the module context would
        // route through require_login($course, ..., $cm), which refuses anyone who
        // cannot view the activity as a participant; a service account scoped to
        // this integration holds no mod_*:view capabilities by design. What it must
        // hold is the capability to back the activity up, checked next.
        self::validate_context($coursecontext);
        require_capability('moodle/backup:backupactivity', $context);
        require_capability('moodle/backup:downloadfile', $context);

        if (!$cm->visible && !has_capability('moodle/course:viewhiddenactivities', $coursecontext)) {
            throw new \moodle_exception('error:activitynotvisible', 'block_coursesync');
        }

        if (!plugin_supports('mod', $cm->modname, FEATURE_BACKUP_MOODLE2)) {
            throw new \moodle_exception('error:backupnotsupported', 'block_coursesync', '', $cm->modname);
        }

        $file = backup_packager::package((int) $cm->id, (int) $USER->id);

        $downloadpath = '/webservice/pluginfile.php/' . $context->id . '/block_coursesync/'
            . backup_packager::FILEAREA . '/0/' . rawurlencode($file->get_filename());

        return [
            'cmid' => (int) $cm->id,
            'contextid' => (int) $context->id,
            'filearea' => backup_packager::FILEAREA,
            'itemid' => 0,
            'filename' => $file->get_filename(),
            'filesize' => (int) $file->get_filesize(),
            'downloadpath' => $downloadpath,
            'expires' => time() + backup_packager::EXPIRY_SECONDS,
        ];
    }

    /**
     * Describes the return value for execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module id that was backed up'),
            'contextid' => new external_value(PARAM_INT, 'Context the backup file lives in'),
            'filearea' => new external_value(PARAM_AREA, 'File area holding the backup'),
            'itemid' => new external_value(PARAM_INT, 'Item id of the backup file'),
            'filename' => new external_value(PARAM_FILE, 'Name of the backup file'),
            'filesize' => new external_value(PARAM_INT, 'Size of the backup file in bytes'),
            'downloadpath' => new external_value(PARAM_RAW, 'Path to request from this site, with a token, to download the file'),
            'expires' => new external_value(PARAM_INT, 'Time after which the file may be cleaned up'),
        ]);
    }
}
