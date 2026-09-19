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
 * Library functions for block_coursesync.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serves packaged activity backups to a site that is pulling from this one.
 *
 * Reached through webservice/pluginfile.php, where the caller has already been
 * authenticated by its web service token, so the job here is authorisation:
 * the token's user must be allowed to back up and download this very activity.
 *
 * @param stdClass $course Course the file belongs to.
 * @param stdClass|null $birecordorcm Block instance record; always null here, as the file lives in a module context.
 * @param context $context Context of the file.
 * @param string $filearea File area being requested.
 * @param array $args Remaining URL arguments: itemid, then path, then filename.
 * @param bool $forcedownload Whether to force a download.
 * @param array $options Options for send_stored_file().
 */
function block_coursesync_pluginfile($course, $birecordorcm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($filearea !== \block_coursesync\local\backup_packager::FILEAREA) {
        send_file_not_found();
    }

    if ($context->contextlevel != CONTEXT_MODULE) {
        send_file_not_found();
    }

    // These are the same two capabilities the backup itself required, checked
    // again here because a file area is reachable independently of the
    // function that filled it.
    require_capability('moodle/backup:backupactivity', $context);
    require_capability('moodle/backup:downloadfile', $context);

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $file = get_file_storage()->get_file($context->id, 'block_coursesync', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, true, $options);
}
