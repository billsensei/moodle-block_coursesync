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
 * What both ends of an assignment submission pull agree on.
 *
 * The source reads a student's submission and describes it; the destination
 * writes it back. Both need the same idea of which submission counts, which
 * file areas are involved and what makes one submission different from
 * another, so those live here once.
 *
 * Only mod_assign's file and online text submissions are handled. Only the
 * latest attempt in status "submitted" travels: never a draft, never a team
 * submission.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submissions {
    /** @var string Files a student uploaded. */
    public const AREA_FILES = 'submission_files';

    /** @var string Files embedded in an online text submission. */
    public const AREA_TEXTFILES = 'submissions_onlinetext';

    /** @var string[] File area => the submission plugin component that owns it. */
    public const AREAS = [
        self::AREA_FILES => 'assignsubmission_file',
        self::AREA_TEXTFILES => 'assignsubmission_onlinetext',
    ];

    /** @var string[] The submission plugins carried. */
    public const PLUGINS = ['file', 'onlinetext'];

    /** @var string The status a carried submission has. */
    public const STATUS_SUBMITTED = 'submitted';

    /**
     * The submission plugins that are switched on for an assignment.
     *
     * @param int $assignid
     * @return string[] a subset of PLUGINS
     */
    public static function enabled_plugins(int $assignid): array {
        global $DB;

        $enabled = [];

        foreach (self::PLUGINS as $plugin) {
            $value = $DB->get_field('assign_plugin_config', 'value', [
                'assignment' => $assignid,
                'plugin' => $plugin,
                'subtype' => 'assignsubmission',
                'name' => 'enabled',
            ]);

            if ($value) {
                $enabled[] = $plugin;
            }
        }

        return $enabled;
    }

    /**
     * A student's latest submitted attempt.
     *
     * A newer attempt that is still a draft, or has been reopened and not yet
     * handed in, does not hide an earlier one that was submitted: what counts
     * is the latest the student actually handed in.
     *
     * @param int $assignid
     * @param int $userid
     * @return \stdClass|null the assign_submission row
     */
    public static function latest_submitted(int $assignid, int $userid): ?\stdClass {
        global $DB;

        $rows = $DB->get_records(
            'assign_submission',
            ['assignment' => $assignid, 'userid' => $userid, 'groupid' => 0, 'status' => self::STATUS_SUBMITTED],
            'attemptnumber DESC',
            '*',
            0,
            1
        );

        return $rows ? reset($rows) : null;
    }

    /**
     * Everything a submission holds that is carried: its text and its files.
     *
     * @param \context_module $context the assignment's context
     * @param \stdClass $submission the assign_submission row
     * @param string[] $plugins which plugins to read, from enabled_plugins()
     * @return array [onlinetext (string|null), onlineformat, files]. A file is
     *      area, filepath, filename, filesize, contenthash, timemodified.
     */
    public static function content(\context_module $context, \stdClass $submission, array $plugins): array {
        global $DB;

        $text = null;
        $format = FORMAT_HTML;
        $files = [];

        if (in_array('onlinetext', $plugins, true)) {
            $row = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);

            if ($row && (string) $row->onlinetext !== '') {
                $text = (string) $row->onlinetext;
                $format = (int) $row->onlineformat;
                $files = array_merge($files, self::files_in($context, self::AREA_TEXTFILES, (int) $submission->id));
            }
        }

        if (in_array('file', $plugins, true)) {
            $files = array_merge($files, self::files_in($context, self::AREA_FILES, (int) $submission->id));
        }

        return [$text, $format, $files];
    }

    /**
     * The files in one of a submission's areas, in a fixed order.
     *
     * @param \context_module $context
     * @param string $area one of the AREAS keys
     * @param int $submissionid
     * @return array[]
     */
    public static function files_in(\context_module $context, string $area, int $submissionid): array {
        $stored = get_file_storage()->get_area_files(
            $context->id,
            self::AREAS[$area],
            $area,
            $submissionid,
            'filepath, filename',
            false
        );

        $files = [];

        foreach ($stored as $file) {
            $files[] = [
                'area' => $area,
                'filepath' => $file->get_filepath(),
                'filename' => $file->get_filename(),
                'filesize' => (int) $file->get_filesize(),
                'contenthash' => $file->get_contenthash(),
                'timemodified' => (int) $file->get_timemodified(),
            ];
        }

        return $files;
    }

    /**
     * A short stand-in for a submission's content: the same content always
     * gives the same value, and any change to the text or to a file gives a
     * different one. It lets the destination tell that a submission has
     * changed without fetching it, and that a copy here has been edited.
     *
     * @param string|null $text online text, null if there is none
     * @param int $format the text's format
     * @param array[] $files as returned by content()
     * @return string SHA-1
     */
    public static function fingerprint(?string $text, int $format, array $files): string {
        $parts = [];

        foreach ($files as $file) {
            $parts[] = [
                (string) $file['area'],
                (string) $file['filepath'],
                (string) $file['filename'],
                (string) $file['contenthash'],
            ];
        }

        sort($parts);

        return sha1(json_encode([$text === null ? null : (string) $text, $text === null ? 0 : $format, $parts]));
    }

    /**
     * The checks every source-side submission function starts with, in order:
     * the site's switch, then the two permissions.
     *
     * Checked before anything else so a site that does not share submissions
     * gives every caller the same answer and nothing about its courses.
     *
     * @param int $courseid
     * @return \stdClass the course
     * @throws \moodle_exception
     */
    public static function require_export(int $courseid): \stdClass {
        global $DB;

        if (!get_config('block_coursesync', 'allowsubmissionexport')) {
            throw new \moodle_exception('errorsubmissionexportdisabled', 'block_coursesync');
        }

        $course = $DB->get_record('course', ['id' => $courseid]);

        if (!$course) {
            throw new \moodle_exception('errorcoursenotfound', 'block_coursesync');
        }

        // Our own permissions first, so a misconfigured sync account is told
        // which one it lacks rather than about enrolment.
        $context = \context_course::instance($course->id);
        require_capability('block/coursesync:sync', $context);
        require_capability('block/coursesync:exportsubmissions', $context);

        return $course;
    }
}
