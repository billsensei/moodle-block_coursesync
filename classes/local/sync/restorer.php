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
 * Bringing one remote activity into the local course.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

use backup;
use block_coursesync\local\remote_client;
use block_coursesync\local\remote_exception;
use restore_controller;

/**
 * Fetches an activity's backup from the remote site and restores it here.
 *
 * This is the consumer half of a pull: ask the remote to package the activity,
 * download the .mbz, and restore it into the target course as an addition.
 */
class restorer {
    /**
     * Constructor.
     *
     * @param remote_client $client Client for the remote site.
     * @param \stdClass $course Local course to restore into.
     * @param int $userid User the restore runs as.
     */
    public function __construct(
        /** @var remote_client Client for the remote site. */
        private readonly remote_client $client,
        /** @var \stdClass Local course to restore into. */
        private readonly \stdClass $course,
        /** @var int User the restore runs as. */
        private readonly int $userid,
    ) {
    }

    /**
     * Pulls one activity and returns the course module it became.
     *
     * @param plan_item $item The activity to pull.
     * @return int The new local course module id.
     * @throws remote_exception If the remote side fails.
     * @throws \moodle_exception If the restore fails.
     */
    public function pull(plan_item $item): int {
        $details = $this->client->backup_activity($item->remotecmid);

        $localfile = make_request_directory() . '/' . 'coursesync.mbz';
        $this->client->download_backup((string) $details['downloadpath'], $localfile);

        // The temp directory is named before anything is written into it, so a
        // failure part way through unpacking still leaves something to clean up.
        $tempdirname = restore_controller::get_tempdir_name($this->course->id, $this->userid);

        try {
            $this->extract($localfile, $tempdirname);
            $newcmid = $this->restore($tempdirname);
        } finally {
            $this->discard_tempdir($tempdirname);
        }

        (new section_placement($this->course))->place($newcmid, $item->sectionnum);

        return $newcmid;
    }

    /**
     * Unpacks a downloaded backup into a directory the restore can read.
     *
     * @param string $localfile Path of the downloaded .mbz.
     * @param string $tempdirname Backup temp directory to unpack into.
     * @throws \moodle_exception If the archive cannot be unpacked.
     */
    private function extract(string $localfile, string $tempdirname): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $backuptempdir = make_backup_temp_directory('', false);

        $packer = get_file_packer('application/vnd.moodle.backup');
        $result = $packer->extract_to_pathname($localfile, $backuptempdir . '/' . $tempdirname . '/');

        if ($result === false) {
            throw new \moodle_exception('error:extractfailed', 'block_coursesync');
        }
    }

    /**
     * Restores an unpacked backup into the target course.
     *
     * @param string $tempdirname Backup temp directory holding the unpacked backup.
     * @return int The new local course module id.
     * @throws \moodle_exception If the restore cannot run or produces no activity.
     */
    private function restore(string $tempdirname): int {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $controller = new restore_controller(
            $tempdirname,
            $this->course->id,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $this->userid,
            backup::TARGET_CURRENT_ADDING
        );

        try {
            if (!$controller->execute_precheck()) {
                $results = $controller->get_precheck_results();
                if (!empty($results['errors'])) {
                    throw new \moodle_exception(
                        'error:restoreprecheck',
                        'block_coursesync',
                        '',
                        implode('; ', array_map('strval', $results['errors']))
                    );
                }
                // Warnings alone are not a reason to refuse; the restore UI carries on too.
            }

            $controller->execute_plan();

            $newcmid = 0;
            foreach ($controller->get_plan()->get_tasks() as $task) {
                if ($task instanceof \restore_activity_task) {
                    $newcmid = (int) $task->get_moduleid();
                    if ($newcmid) {
                        break;
                    }
                }
            }
        } finally {
            $controller->destroy();
        }

        if (!$newcmid) {
            throw new \moodle_exception('error:restorenoactivity', 'block_coursesync');
        }

        return $newcmid;
    }

    /**
     * Removes the unpacked backup, unless the site is keeping them for debugging.
     *
     * @param string $tempdirname Backup temp directory name.
     */
    private function discard_tempdir(string $tempdirname): void {
        global $CFG;

        if (!empty($CFG->keeptempdirectoriesonbackup)) {
            return;
        }

        fulldelete(make_backup_temp_directory('', false) . '/' . $tempdirname);
    }
}
