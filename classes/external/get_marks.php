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
use block_coursesync\local\attempts;
use block_coursesync\local\marks;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Describes the marks and written feedback teachers gave on assignments.
 *
 * Like get_grades this hands out people's data, so it is off until an
 * administrator ticks "Let other sites read assignment marks and feedback from
 * this site" (block_coursesync | allowmarksexport), and it has its own
 * permission, block/coursesync:exportmarks, on top of the sync permission.
 *
 * It describes; it does not carry files. Each file embedded in a comment is
 * listed with its name, size and SHA-1, and the bytes come from
 * block_coursesync_get_mark_file.
 *
 * Students are identified by username and only students the gradebook itself
 * would list are reported. Who graded is never reported.
 *
 * By default each student has the one mark for their latest attempt handed in.
 * With allattempts every attempt's mark is described, oldest first, in
 * "attempts" - which needs a switch of its own
 * (block_coursesync | allowattemptsexport). The top level of each student's
 * entry stays what it always was, so a caller that does not read "attempts" is
 * not disturbed. Under a marking workflow only the latest attempt's mark is
 * known to be released, so earlier ones are held back and the assignment says
 * so in "attemptsreason".
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_marks extends external_api {
    /** @var int Most activities one request may name; the destination sends longer lists in batches. */
    public const MAX_CMIDS = 100;

    /** @var string The activity is not an assignment. */
    public const REASON_NOTASSIGN = 'notassign';

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
                'Assignments whose marks are wanted.',
                VALUE_REQUIRED
            ),
            'usernames' => new external_value(
                PARAM_RAW,
                'Only these students, one username per line. Empty for every student.',
                VALUE_DEFAULT,
                ''
            ),
            'allattempts' => new external_value(
                PARAM_BOOL,
                'Describe the mark of every attempt, not only the latest. Needs the source to share earlier attempts.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Describe the marks.
     *
     * @param int $courseid
     * @param int[] $cmids
     * @param string $usernames one per line; empty for every student
     * @param bool $allattempts describe the mark of every attempt
     * @return array
     */
    public static function execute(int $courseid, array $cmids, string $usernames = '', bool $allattempts = false): array {
        global $DB;

        [
            'courseid' => $courseid,
            'cmids' => $cmids,
            'usernames' => $usernames,
            'allattempts' => $allattempts,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmids' => $cmids,
            'usernames' => $usernames,
            'allattempts' => $allattempts,
        ]);

        if (count($cmids) > self::MAX_CMIDS) {
            throw new \invalid_parameter_exception('At most ' . self::MAX_CMIDS . ' activities per request.');
        }

        $course = marks::require_export($courseid);
        self::validate_context(\context_course::instance($course->id));

        if ($allattempts) {
            attempts::require_export();
        }

        $modinfo = get_fast_modinfo($course->id);
        $students = null;
        $items = [];

        // An activity that is not in this course, or no longer exists, is left
        // out rather than refused: one deleted here must not stop the rest
        // being answered.
        foreach (array_unique($cmids) as $cmid) {
            $cm = $modinfo->get_cms()[$cmid] ?? null;

            if (!$cm || $cm->deletioninprogress) {
                continue;
            }

            if ($cm->modname !== 'assign') {
                $items[] = self::item($cm, self::REASON_NOTASSIGN, 0.0, []);
                continue;
            }

            $assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, grade, teamsubmission, markingworkflow', MUST_EXIST);
            $reason = marks::assignment_problem($assign);

            if ($reason !== null) {
                $items[] = self::item($cm, $reason, max(0.0, (float) $assign->grade), []);
                continue;
            }

            $students ??= gradebook::only(gradebook::gradable_users($course->id, true), $usernames);
            $held = $allattempts && $assign->markingworkflow ? attempts::REASON_WORKFLOW : null;
            $items[] = self::item($cm, '', (float) $assign->grade, self::describe($cm, $assign, $students, $allattempts), $held);
        }

        return ['items' => $items];
    }

    /**
     * One assignment's entry.
     *
     * @param \cm_info $cm
     * @param string $reason empty when the marks can be described
     * @param float $grademax what the assignment is out of
     * @param array[] $marks
     * @param string|null $attemptsreason why earlier attempts' marks are missing, when every attempt was asked for
     * @return array
     */
    protected static function item(
        \cm_info $cm,
        string $reason,
        float $grademax,
        array $marks,
        ?string $attemptsreason = null
    ): array {
        $item = [
            'cmid' => (int) $cm->id,
            'reason' => $reason,
            'grademax' => $grademax,
            'marks' => $marks,
        ];

        if ($attemptsreason !== null) {
            $item['attemptsreason'] = $attemptsreason;
        }

        return $item;
    }

    /**
     * The mark each of the named students has.
     *
     * @param \cm_info $cm
     * @param \stdClass $assign
     * @param \stdClass[] $students user id => user
     * @param bool $allattempts the mark of every attempt, not only the latest
     * @return array[]
     */
    protected static function describe(\cm_info $cm, \stdClass $assign, array $students, bool $allattempts = false): array {
        $context = \context_module::instance($cm->id);
        $described = [];

        foreach ($students as $student) {
            if ($allattempts) {
                $grades = marks::marks_for($assign, (int) $student->id);
            } else {
                $grade = marks::mark_for($assign, (int) $student->id);
                $grades = $grade ? [$grade] : [];
            }

            $entries = [];

            foreach ($grades as $grade) {
                $entry = self::mark($context, $assign, $grade);

                if ($entry) {
                    $entries[] = $entry;
                }
            }

            if (!$entries) {
                continue;
            }

            // The top level is the latest mark carried, as it always was.
            $row = ['username' => $student->username] + end($entries);

            if ($allattempts) {
                $row['attempts'] = $entries;
                $row['historyfingerprint'] = attempts::history_fingerprint($entries);
            }

            $described[] = $row;
        }

        return $described;
    }

    /**
     * One mark, as it is described.
     *
     * @param \context_module $context
     * @param \stdClass $assign
     * @param \stdClass $grade the assign_grades row
     * @return array|null null for a placeholder row: nothing given, nothing said
     */
    protected static function mark(\context_module $context, \stdClass $assign, \stdClass $grade): ?array {
        $number = marks::is_marked($grade) ? (float) $grade->grade : null;
        [$text, $format, $files] = marks::comment($context, (int) $grade->id, (int) $assign->id);

        if ($number === null && $text === null) {
            return null;
        }

        return [
            'attemptnumber' => (int) $grade->attemptnumber,
            'timemodified' => (int) $grade->timemodified,
            'grade' => $number,
            'comment' => $text,
            'commentformat' => $format,
            'files' => $files,
            'fingerprint' => marks::fingerprint($number, $text, $format, $files),
        ];
    }

    /**
     * What describes one mark: the same fields at the top of a student's entry
     * (the latest) and in each of "attempts".
     *
     * @return external_value[]|external_multiple_structure[]
     */
    protected static function mark_fields(): array {
        return [
            'attemptnumber' => new external_value(PARAM_INT, 'Which attempt the mark is for, from 0.'),
            'timemodified' => new external_value(PARAM_INT, 'When the mark last changed.'),
            'grade' => new external_value(
                PARAM_FLOAT,
                'The mark; null if only a comment was given.',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'comment' => new external_value(
                PARAM_RAW,
                'The written comment, unformatted; null if there is none.',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'commentformat' => new external_value(PARAM_INT, 'Format of the comment.'),
            'files' => new external_multiple_structure(
                new external_single_structure([
                    'area' => new external_value(PARAM_ALPHANUMEXT, 'Always feedback.'),
                    'filepath' => new external_value(PARAM_PATH, 'Path within the area.'),
                    'filename' => new external_value(PARAM_FILE, 'File name.'),
                    'filesize' => new external_value(PARAM_INT, 'Size in bytes.'),
                    'contenthash' => new external_value(PARAM_ALPHANUM, 'SHA-1 of the content.'),
                    'timemodified' => new external_value(PARAM_INT, 'When the file last changed.'),
                ]),
                'Files embedded in the comment, in a fixed order.'
            ),
            'fingerprint' => new external_value(PARAM_ALPHANUM, 'SHA-1 standing for the whole mark.'),
        ];
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
                        'Empty if described; otherwise team, scale, nograde or notassign, and no marks follow.'
                    ),
                    'grademax' => new external_value(PARAM_FLOAT, 'What the assignment is out of; 0 if not graded in points.'),
                    'attemptsreason' => new external_value(
                        PARAM_ALPHA,
                        'Only when allattempts was asked for: workflowhistory if earlier attempts\' marks were held back.',
                        VALUE_OPTIONAL
                    ),
                    'marks' => new external_multiple_structure(
                        new external_single_structure(
                            ['username' => new external_value(PARAM_RAW, 'The student\'s username.')]
                            + self::mark_fields()
                            + [
                                'attempts' => new external_multiple_structure(
                                    new external_single_structure(self::mark_fields()),
                                    'The mark of every attempt, oldest first. Only when allattempts was asked for.',
                                    VALUE_OPTIONAL
                                ),
                                'historyfingerprint' => new external_value(
                                    PARAM_ALPHANUM,
                                    'SHA-1 standing for all the marks. Only when allattempts was asked for.',
                                    VALUE_OPTIONAL
                                ),
                            ]
                        ),
                        'The students\' marks. A student with none is left out.'
                    ),
                ]),
                'The requested assignments that exist in this course.'
            ),
        ]);
    }
}
