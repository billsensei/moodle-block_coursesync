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

use block_coursesync\file_chunk;
use block_coursesync\remote_client;
use core\http_client;

/**
 * Writes one student's submission into mod_assign's tables and file storage.
 *
 * This is the part of a submission pull that changes anything, kept apart from
 * the part that decides what to do (submission_pull). It is written straight
 * into the assignment's tables, as mod_assign's own restore does, and not
 * through the student-facing save path: that would enforce deadlines, cut-off
 * dates and locks, which are about when a student may hand work in and not
 * about work that was handed in somewhere else, and it would send
 * notifications and fire events for something the student did not just do.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_writer {
    /**
     * Fetch every file of a submission from the source into temporary files.
     *
     * @param \stdClass $record the block's connection
     * @param string $token
     * @param int $remotecmid
     * @param \stdClass $submission
     * @param http_client|null $client
     * @return string[] temporary path for each file, in the same order as the submission's files
     * @throws \moodle_exception if any file cannot be fetched or fails its check
     */
    public static function fetch_files(
        \stdClass $record,
        string $token,
        int $remotecmid,
        \stdClass $submission,
        ?http_client $client
    ): array {
        $paths = [];

        try {
            foreach ($submission->files as $file) {
                $paths[] = file_sync::download(
                    static fn(int $offset): file_chunk => remote_client::get_submission_file(
                        $record->remoteurl,
                        $token,
                        $remotecmid,
                        $submission->username,
                        $file['area'],
                        $file['filepath'],
                        $file['filename'],
                        $offset,
                        file_sync::CHUNK,
                        $client
                    ),
                    $file['contenthash'],
                    (int) $file['filesize']
                );
            }
        } catch (\moodle_exception $e) {
            foreach ($paths as $path) {
                @unlink($path);
            }

            throw $e;
        }

        return $paths;
    }

    /**
     * Write one submission, and the record of having written it.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param \stdClass $submission what the source described
     * @param \stdClass $student
     * @param int $blockinstanceid
     * @param int $remotecmid
     * @param \stdClass|false $ledger the record of an earlier pull, if any
     * @param \stdClass|null $local the submission here being updated, if any
     * @param string[] $paths the fetched files, in the submission's order
     * @return void
     */
    public static function write(
        \stdClass $course,
        \cm_info $cm,
        \stdClass $submission,
        \stdClass $student,
        int $blockinstanceid,
        int $remotecmid,
        $ledger,
        ?\stdClass $local,
        array $paths
    ): void {
        global $DB;

        $assignid = (int) $cm->instance;
        $context = \context_module::instance($cm->id);
        $transaction = $DB->start_delegated_transaction();
        $created = null;

        try {
            $row = self::save_row($submission, $student, $local, $assignid);

            // Nothing here held work before, so on failure there is nothing to keep.
            $created = $local ? null : (int) $row->id;

            self::save_files($context, $row, $submission, $student, $paths);
            self::write_plugin_rows($assignid, $row->id, $submission);
            self::save_record($context, $row, $submission, $student, $course, $assignid, $blockinstanceid, $remotecmid, $ledger);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The rows roll back; files do not, so a submission that was new
            // here must not leave files behind under an id that may be reused.
            if ($created !== null) {
                foreach (submissions::AREAS as $area => $component) {
                    get_file_storage()->delete_area_files($context->id, $component, $area, $created);
                }
            }

            $transaction->rollback($e);
        }

        // Completion by handing in work is worked out from the submission.
        $completion = new \completion_info($course);

        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $student->id);
        }
    }

    /**
     * Save the assign_submission row: a new one, or the one being updated.
     *
     * @param \stdClass $submission what the source described
     * @param \stdClass $student
     * @param \stdClass|null $local the submission here being updated, if any
     * @param int $assignid
     * @return \stdClass the saved row
     */
    protected static function save_row(\stdClass $submission, \stdClass $student, ?\stdClass $local, int $assignid): \stdClass {
        global $DB;

        $row = $local ?: self::empty_submission($assignid, (int) $student->id);
        $row->timecreated = $submission->timecreated ?: time();
        $row->timemodified = $submission->timemodified ?: time();
        $row->timestarted = $row->timecreated;
        $row->status = submissions::STATUS_SUBMITTED;
        $row->latest = 1;

        if (empty($row->id)) {
            $row->attemptnumber = 0;
            $row->groupid = 0;
            $row->id = $DB->insert_record('assign_submission', $row);
        } else {
            $DB->update_record('assign_submission', $row);
        }

        return $row;
    }

    /**
     * Put the submission's files in file storage, replacing what an earlier
     * pull put there and nothing else: classify() only lets an untouched
     * earlier pull's work reach this.
     *
     * @param \context_module $context
     * @param \stdClass $row the saved assign_submission row
     * @param \stdClass $submission what the source described
     * @param \stdClass $student
     * @param string[] $paths the fetched files, in the submission's order
     * @return void
     */
    protected static function save_files(
        \context_module $context,
        \stdClass $row,
        \stdClass $submission,
        \stdClass $student,
        array $paths
    ): void {
        $fs = get_file_storage();

        foreach (submissions::AREAS as $area => $component) {
            $fs->delete_area_files($context->id, $component, $area, $row->id);
        }

        foreach ($submission->files as $index => $file) {
            $fs->create_file_from_pathname((object) [
                'contextid' => $context->id,
                'component' => submissions::AREAS[$file['area']],
                'filearea' => $file['area'],
                'itemid' => $row->id,
                'filepath' => $file['filepath'],
                'filename' => $file['filename'],
                'userid' => $student->id,
                'timecreated' => $row->timecreated,
                'timemodified' => $file['timemodified'] ?: $row->timemodified,
            ], $paths[$index]);
        }
    }

    /**
     * Record that the submission was brought across: what the source held and
     * what is here now, so a later pull can tell either changing.
     *
     * @param \context_module $context
     * @param \stdClass $row the saved assign_submission row
     * @param \stdClass $submission what the source described
     * @param \stdClass $student
     * @param \stdClass $course
     * @param int $assignid
     * @param int $blockinstanceid
     * @param int $remotecmid
     * @param \stdClass|false $ledger the record of an earlier pull, if any
     * @return void
     */
    protected static function save_record(
        \context_module $context,
        \stdClass $row,
        \stdClass $submission,
        \stdClass $student,
        \stdClass $course,
        int $assignid,
        int $blockinstanceid,
        int $remotecmid,
        $ledger
    ): void {
        global $DB;

        [$text, $format, $files] = submissions::content($context, $row, submissions::PLUGINS);
        $record = (object) [
            'blockinstanceid' => $blockinstanceid,
            'courseid' => $course->id,
            'userid' => $student->id,
            'assignid' => $assignid,
            'submissionid' => $row->id,
            'remotecmid' => $remotecmid,
            'remoteattempt' => $submission->attemptnumber,
            'fingerprint' => $submission->fingerprint,
            'localfingerprint' => submissions::fingerprint($text, $format, $files),
            'remotetime' => $submission->timemodified,
            'timeimported' => time(),
        ];

        if ($ledger) {
            $record->id = $ledger->id;
            $DB->update_record('block_coursesync_submission', $record);
        } else {
            $DB->insert_record('block_coursesync_submission', $record);
        }
    }

    /**
     * A new assign_submission row, not yet saved.
     *
     * @param int $assignid
     * @param int $userid
     * @return \stdClass
     */
    protected static function empty_submission(int $assignid, int $userid): \stdClass {
        global $DB;

        // A student who only opened the assignment has a "new" (or "reopened")
        // row that holds no work: use it rather than leave two latest rows.
        $rows = $DB->get_records('assign_submission', [
            'assignment' => $assignid,
            'userid' => $userid,
            'groupid' => 0,
            'latest' => 1,
        ]);
        $existing = $rows ? reset($rows) : null;

        if ($existing && in_array($existing->status, ['new', 'reopened'], true) && (int) $existing->attemptnumber === 0) {
            return $existing;
        }

        return (object) ['assignment' => $assignid, 'userid' => $userid];
    }

    /**
     * The submission plugins' own rows for a submission.
     *
     * @param int $assignid
     * @param int $submissionid
     * @param \stdClass $submission what the source described
     * @return void
     */
    protected static function write_plugin_rows(int $assignid, int $submissionid, \stdClass $submission): void {
        global $DB;

        $by = ['submission' => $submissionid];
        $DB->delete_records('assignsubmission_onlinetext', $by);
        $DB->delete_records('assignsubmission_file', $by);

        if ($submission->onlinetext !== null) {
            $DB->insert_record('assignsubmission_onlinetext', (object) ($by + [
                'assignment' => $assignid,
                'onlinetext' => $submission->onlinetext,
                'onlineformat' => $submission->onlineformat,
            ]));
        }

        $numfiles = count(array_filter(
            $submission->files,
            static fn(array $file) => $file['area'] === submissions::AREA_FILES
        ));

        if ($numfiles) {
            $DB->insert_record('assignsubmission_file', (object) ($by + [
                'assignment' => $assignid,
                'numfiles' => $numfiles,
            ]));
        }
    }
}
