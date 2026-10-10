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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Describes the assignment submissions of the students in a course.
 *
 * Like get_grades this hands out people's data, so it is off until an
 * administrator ticks "Let other sites read assignment submissions from this
 * site" (block_coursesync | allowsubmissionexport), and it has its own
 * permission, block/coursesync:exportsubmissions, on top of the sync
 * permission.
 *
 * It describes; it does not carry files. Each file's name, size and SHA-1 are
 * listed, and the bytes come from block_coursesync_get_submission_file.
 *
 * Students are identified by username, only students the gradebook itself
 * would list are reported, and only the latest attempt each has handed in.
 * Team assignments are left out with a reason, never described.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_submissions extends external_api {
    /** @var int Most activities one request may name; the destination sends longer lists in batches. */
    public const MAX_CMIDS = 100;

    /** @var string The assignment is a team assignment; nothing is reported. */
    public const REASON_TEAM = 'team';

    /** @var string The activity is not an assignment. */
    public const REASON_NOTASSIGN = 'notassign';

    /** @var string Neither file nor online text submissions are switched on. */
    public const REASON_NOPLUGINS = 'noplugins';

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id on this site.', VALUE_REQUIRED),
            'cmids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course module id on this site.'),
                'Assignments whose submissions are wanted.',
                VALUE_REQUIRED
            ),
            'usernames' => new external_value(
                PARAM_RAW,
                'Only these students, one username per line. Empty for every student.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Describe the submissions.
     *
     * @param int $courseid
     * @param int[] $cmids
     * @param string $usernames one per line; empty for every student
     * @return array
     */
    public static function execute(int $courseid, array $cmids, string $usernames = ''): array {
        global $DB;

        [
            'courseid' => $courseid,
            'cmids' => $cmids,
            'usernames' => $usernames,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmids' => $cmids,
            'usernames' => $usernames,
        ]);

        if (count($cmids) > self::MAX_CMIDS) {
            throw new \invalid_parameter_exception('At most ' . self::MAX_CMIDS . ' activities per request.');
        }

        $course = submissions::require_export($courseid);
        $context = \context_course::instance($course->id);
        self::validate_context($context);

        $modinfo = get_fast_modinfo($course->id);
        $students = null;
        $items = [];

        // An activity that is not in this course, or no longer exists, is left
        // out rather than refused: the destination asks with every copy it
        // holds, and one deleted here must not stop the rest being answered.
        foreach (array_unique($cmids) as $cmid) {
            $cm = $modinfo->get_cms()[$cmid] ?? null;

            if (!$cm || $cm->deletioninprogress) {
                continue;
            }

            if ($cm->modname !== 'assign') {
                $items[] = self::item($cm, self::REASON_NOTASSIGN, [], []);
                continue;
            }

            $assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, teamsubmission', MUST_EXIST);
            $plugins = submissions::enabled_plugins((int) $assign->id);

            if ($assign->teamsubmission) {
                $items[] = self::item($cm, self::REASON_TEAM, $plugins, []);
                continue;
            }

            if (!$plugins) {
                $items[] = self::item($cm, self::REASON_NOPLUGINS, $plugins, []);
                continue;
            }

            // Worked out once, and only if there is an assignment to answer
            // for: recalculating a gradebook is not free.
            $students ??= gradebook::only(gradebook::gradable_users($course->id, true), $usernames);
            $items[] = self::item($cm, '', $plugins, self::describe($cm, (int) $assign->id, $plugins, $students));
        }

        return ['items' => $items];
    }

    /**
     * One assignment's entry.
     *
     * @param \cm_info $cm
     * @param string $reason empty when the assignment can be described
     * @param string[] $plugins
     * @param array[] $submissions
     * @return array
     */
    protected static function item(\cm_info $cm, string $reason, array $plugins, array $submissions): array {
        return [
            'cmid' => (int) $cm->id,
            'reason' => $reason,
            'plugins' => array_values($plugins),
            'submissions' => $submissions,
        ];
    }

    /**
     * What each of the named students has handed in.
     *
     * @param \cm_info $cm
     * @param int $assignid
     * @param string[] $plugins
     * @param \stdClass[] $students user id => user
     * @return array[]
     */
    protected static function describe(\cm_info $cm, int $assignid, array $plugins, array $students): array {
        $context = \context_module::instance($cm->id);
        $described = [];

        foreach ($students as $student) {
            $submission = submissions::latest_submitted($assignid, (int) $student->id);

            if (!$submission) {
                continue;
            }

            [$text, $format, $files] = submissions::content($context, $submission, $plugins);

            // Handed in with nothing in it, or with only plugins that are
            // not carried: nothing to bring.
            if ($text === null && !$files) {
                continue;
            }

            $described[] = [
                'username' => $student->username,
                'attemptnumber' => (int) $submission->attemptnumber,
                'timecreated' => (int) $submission->timecreated,
                'timemodified' => (int) $submission->timemodified,
                'onlinetext' => $text,
                'onlineformat' => $format,
                'files' => $files,
                'fingerprint' => submissions::fingerprint($text, $format, $files),
            ];
        }

        return $described;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id on the source site.'),
                    'reason' => new external_value(
                        PARAM_ALPHA,
                        'Empty if described; otherwise team, notassign or noplugins, and no submissions follow.'
                    ),
                    'plugins' => new external_multiple_structure(
                        new external_value(PARAM_ALPHANUMEXT, 'A submission plugin switched on there.'),
                        'The carried submission plugins (file, onlinetext) switched on for the assignment.'
                    ),
                    'submissions' => new external_multiple_structure(
                        new external_single_structure([
                            'username' => new external_value(PARAM_RAW, 'The student\'s username.'),
                            'attemptnumber' => new external_value(PARAM_INT, 'Which attempt this is, from 0.'),
                            'timecreated' => new external_value(PARAM_INT, 'When the submission was first made.'),
                            'timemodified' => new external_value(PARAM_INT, 'When it last changed.'),
                            'onlinetext' => new external_value(
                                PARAM_RAW,
                                'Online text, unformatted; null if there is none.',
                                VALUE_REQUIRED,
                                null,
                                NULL_ALLOWED
                            ),
                            'onlineformat' => new external_value(PARAM_INT, 'Format of the online text.'),
                            'files' => new external_multiple_structure(
                                new external_single_structure([
                                    'area' => new external_value(PARAM_ALPHANUMEXT, 'submission_files or submissions_onlinetext.'),
                                    'filepath' => new external_value(PARAM_PATH, 'Path within the area.'),
                                    'filename' => new external_value(PARAM_FILE, 'File name.'),
                                    'filesize' => new external_value(PARAM_INT, 'Size in bytes.'),
                                    'contenthash' => new external_value(PARAM_ALPHANUM, 'SHA-1 of the content.'),
                                    'timemodified' => new external_value(PARAM_INT, 'When the file last changed.'),
                                ]),
                                'The files, in a fixed order.'
                            ),
                            'fingerprint' => new external_value(PARAM_ALPHANUM, 'SHA-1 standing for the whole content.'),
                        ]),
                        'The students\' latest submitted attempts. A student with none is left out.'
                    ),
                ]),
                'The requested assignments that exist in this course.'
            ),
        ]);
    }
}
