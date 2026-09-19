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
 * Packaging a single activity into a downloadable backup file.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

use backup;
use backup_controller;

/**
 * Runs a single-activity backup and parks the .mbz where the caller can fetch it.
 *
 * This is the provider half of a pull: it is the same backup the "duplicate
 * activity" feature uses, but written out as a real file so another site can
 * download and restore it.
 */
class backup_packager {
    /** @var string File area holding backups waiting to be collected. */
    public const FILEAREA = 'activitybackup';

    /** @var int How long a packaged backup stays available before cleanup removes it. */
    public const EXPIRY_SECONDS = 6 * HOURSECS;

    /**
     * Backs up one course module and stores the result for collection.
     *
     * @param int $cmid Course module id to back up.
     * @param int $userid User the backup runs as.
     * @return \stored_file The packaged .mbz, in this plugin's own file area.
     * @throws \moodle_exception If the backup produces no usable file.
     */
    public static function package(int $cmid, int $userid): \stored_file {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $controller = new backup_controller(
            backup::TYPE_1ACTIVITY,
            $cmid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $userid
        );

        try {
            $controller->execute_plan();
            $results = $controller->get_results();
        } finally {
            $controller->destroy();
        }

        if (empty($results['backup_destination'])) {
            throw new \moodle_exception('error:backupfailed', 'block_coursesync');
        }

        /** @var \stored_file $backupfile */
        $backupfile = $results['backup_destination'];
        if (!$backupfile->get_contenthash()) {
            $backupfile->delete();
            throw new \moodle_exception('error:backupfailed', 'block_coursesync');
        }

        $fs = get_file_storage();
        $stored = $fs->create_file_from_storedfile([
            'contextid' => \context_module::instance($cmid)->id,
            'component' => 'block_coursesync',
            'filearea' => self::FILEAREA,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => self::filename($cmid),
            'userid' => $userid,
        ], $backupfile);

        // The backup subsystem's own copy is no longer needed now it has been
        // moved into a file area this plugin controls and cleans up.
        $backupfile->delete();

        return $stored;
    }

    /**
     * Deletes packaged backups that were never collected.
     *
     * @param int|null $olderthan Unix timestamp; defaults to the expiry window.
     * @return int Number of files removed.
     */
    public static function cleanup(?int $olderthan = null): int {
        global $DB;

        $olderthan = $olderthan ?? (time() - self::EXPIRY_SECONDS);

        $files = $DB->get_records_select(
            'files',
            "component = :component AND filearea = :filearea AND filename <> '.' AND timecreated < :olderthan",
            [
                'component' => 'block_coursesync',
                'filearea' => self::FILEAREA,
                'olderthan' => $olderthan,
            ]
        );

        $fs = get_file_storage();
        $removed = 0;
        foreach ($files as $filerecord) {
            $file = $fs->get_file_instance($filerecord);
            $file->delete();
            $removed++;
        }

        return $removed;
    }

    /**
     * Builds a filename that is unique per packaging run.
     *
     * @param int $cmid Course module id being backed up.
     * @return string
     */
    private static function filename(int $cmid): string {
        return 'coursesync-cm' . $cmid . '-' . uniqid() . '.mbz';
    }
}
