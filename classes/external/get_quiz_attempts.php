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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use block_coursesync\local\gradebook;
use mod_quiz\question\bank\qbank_helper;

/**
 * Returns students' finished quiz attempts, as marks, for chosen quizzes.
 *
 * The same kind of people's data as get_grades, behind the same gates: the
 * "Let other sites read grades from this site" switch and
 * block/coursesync:exportgrades, on top of the sync permission.
 *
 * What an attempt carries is deliberately its marks, not its responses: when
 * it was started and finished, and the mark for each slot. A student's
 * answers are stored against ids that mean nothing on another site, so they
 * are left for a later format version (see FORMAT_VERSION) rather than sent
 * half-translated. Alongside the attempts goes the quiz's outline - each
 * slot's question type and maximum mark - so the destination can tell
 * whether its copy of the quiz still lines up before building anything.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_quiz_attempts extends external_api {
    /** @var int Most activities one request may name; the destination sends longer lists in batches. */
    public const MAX_CMIDS = 500;

    /** @var int Version 1: marks only. A later version may add responses. */
    public const FORMAT_VERSION = 1;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id on this site.', VALUE_REQUIRED),
            'cmids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course module id of a quiz on this site.'),
                'Quizzes whose attempts are wanted.',
                VALUE_REQUIRED
            ),
            'usernames' => new external_value(
                PARAM_RAW,
                'Only these students, one username per line: the ones the destination can use. '
                    . 'Empty (as older destinations send) for every student.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Collect the attempts.
     *
     * @param int $courseid
     * @param int[] $cmids
     * @param string $usernames one per line; empty for every student
     * @return array
     */
    public static function execute(int $courseid, array $cmids, string $usernames = ''): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        [
            'courseid' => $courseid,
            'cmids' => $cmids,
            'usernames' => $usernames,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmids' => $cmids,
            'usernames' => $usernames,
        ]);

        // The same switch as grades, checked before anything else.
        if (!get_config('block_coursesync', 'allowgradeexport')) {
            throw new \moodle_exception('errorgradeexportdisabled', 'block_coursesync');
        }

        if (count($cmids) > self::MAX_CMIDS) {
            throw new \invalid_parameter_exception('At most ' . self::MAX_CMIDS . ' activities per request.');
        }

        $course = $DB->get_record('course', ['id' => $courseid]);

        if (!$course) {
            throw new \moodle_exception('errorcoursenotfound', 'block_coursesync');
        }

        $context = \context_course::instance($course->id);
        require_capability('block/coursesync:sync', $context);
        require_capability('block/coursesync:exportgrades', $context);
        self::validate_context($context);

        $modinfo = get_fast_modinfo($course->id);
        $quizzes = [];

        // As get_grades: anything that is not a quiz in this course is left
        // out, not refused.
        foreach (array_unique($cmids) as $cmid) {
            $cm = $modinfo->get_cms()[$cmid] ?? null;

            if ($cm && $cm->modname === 'quiz' && !$cm->deletioninprogress) {
                $quizzes[] = $cm;
            }
        }

        // Recalculated first if it needs it: see gradebook::gradable_users().
        // Every student, not only the sync account's groups: see gradebook.
        $students = $quizzes ? gradebook::gradable_users($course->id, true) : [];

        // Only the students the destination can use leave this site.
        $students = gradebook::only($students, $usernames);
        $out = [];

        foreach ($quizzes as $cm) {
            $out[] = self::export_quiz($cm, $students);
        }

        return ['formatversion' => self::FORMAT_VERSION, 'quizzes' => $out];
    }

    /**
     * One quiz: its outline, and every student's finished attempts at it.
     *
     * @param \cm_info $cm
     * @param \stdClass[] $students gradable users keyed by id
     * @return array
     */
    protected static function export_quiz(\cm_info $cm, array $students): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id, sumgrades, grade', MUST_EXIST);
        $slots = [];

        foreach (qbank_helper::get_question_structure($quiz->id, \context_module::instance($cm->id)) as $slot) {
            $slots[] = [
                'slot' => (int) $slot->slot,
                'page' => (int) $slot->page,
                'maxmark' => (float) $slot->maxmark,
                'qtype' => (string) $slot->qtype,
            ];
        }

        $attempts = [];

        if ($students) {
            // Finished attempts only, and never a teacher's preview. One still
            // in progress, or abandoned, is not a result; one submitted but
            // waiting for cron to grade it has no marks yet, and is picked up
            // by a later pull once it is finished.
            [$insql, $params] = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED);
            $params['quiz'] = $quiz->id;
            $params['finished'] = \mod_quiz\quiz_attempt::FINISHED;
            $records = $DB->get_records_select(
                'quiz_attempts',
                "quiz = :quiz AND preview = 0 AND state = :finished AND userid {$insql}",
                $params,
                'userid, attempt',
                'id, uniqueid, userid, attempt, timestart, timefinish, sumgrades'
            );

            $marks = self::slot_marks(array_column($records, 'uniqueid'));

            foreach ($records as $record) {
                $attempts[] = [
                    'id' => (int) $record->id,
                    'username' => $students[$record->userid]->username,
                    'attempt' => (int) $record->attempt,
                    'timestart' => (int) $record->timestart,
                    'timefinish' => (int) $record->timefinish,
                    'sumgrades' => $record->sumgrades === null ? null : (float) $record->sumgrades,
                    'marks' => array_values($marks[$record->uniqueid] ?? []),
                ];
            }
        }

        return [
            'cmid' => (int) $cm->id,
            'sumgrades' => (float) $quiz->sumgrades,
            'grade' => (float) $quiz->grade,
            'slots' => $slots,
            'attempts' => $attempts,
        ];
    }

    /**
     * The mark for every slot of every usage, from each question's latest
     * step - the same query the quiz's own reports use.
     *
     * @param int[] $usageids question_usages ids (quiz_attempts.uniqueid)
     * @return array[] usage id => slot => [slot, maxmark, mark, state]
     */
    protected static function slot_marks(array $usageids): array {
        if (!$usageids) {
            return [];
        }

        $dm = new \question_engine_data_mapper();
        $rows = $dm->load_questions_usages_latest_steps(
            new \qubaid_list($usageids),
            null,
            'qas.id, qa.questionusageid, qa.slot, qa.maxmark, qas.fraction, qas.state'
        );

        $marks = [];

        foreach ($rows as $row) {
            $marks[$row->questionusageid][(int) $row->slot] = [
                'slot' => (int) $row->slot,
                'maxmark' => (float) $row->maxmark,
                // Null both while a question still needs grading by hand and
                // when it was never answered; the state tells them apart.
                'mark' => $row->fraction === null ? null : (float) $row->fraction * (float) $row->maxmark,
                'state' => (string) $row->state,
            ];
        }

        foreach ($marks as &$byslot) {
            ksort($byslot);
        }

        return $marks;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'formatversion' => new external_value(PARAM_INT, 'Version of this format. 1 = marks only.'),
            'quizzes' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id on the source site.'),
                    'sumgrades' => new external_value(PARAM_FLOAT, 'Total of the quiz\'s slot marks.'),
                    'grade' => new external_value(PARAM_FLOAT, 'The grade the quiz is out of.'),
                    'slots' => new external_multiple_structure(
                        new external_single_structure([
                            'slot' => new external_value(PARAM_INT, 'Slot number.'),
                            'page' => new external_value(PARAM_INT, 'Page the slot is on.'),
                            'maxmark' => new external_value(PARAM_FLOAT, 'Maximum mark for the slot.'),
                            'qtype' => new external_value(PARAM_ALPHANUMEXT, 'Question type, or "random".'),
                        ]),
                        'The quiz\'s outline, in slot order.'
                    ),
                    'attempts' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Attempt id on the source site.'),
                            'username' => new external_value(PARAM_RAW, 'The student\'s username.'),
                            'attempt' => new external_value(PARAM_INT, 'Attempt number for that student.'),
                            'timestart' => new external_value(PARAM_INT, 'When the attempt was started.'),
                            'timefinish' => new external_value(PARAM_INT, 'When it was submitted.'),
                            'sumgrades' => new external_value(
                                PARAM_FLOAT,
                                'Total mark, null while a question still needs grading.',
                                VALUE_REQUIRED,
                                null,
                                NULL_ALLOWED
                            ),
                            'marks' => new external_multiple_structure(
                                new external_single_structure([
                                    'slot' => new external_value(PARAM_INT, 'Slot number.'),
                                    'maxmark' => new external_value(PARAM_FLOAT, 'Maximum mark in this attempt.'),
                                    'mark' => new external_value(
                                        PARAM_FLOAT,
                                        'Mark scored; null if it still needs grading or was never answered.',
                                        VALUE_REQUIRED,
                                        null,
                                        NULL_ALLOWED
                                    ),
                                    'state' => new external_value(
                                        PARAM_ALPHA,
                                        'The question\'s state, e.g. gradedright, needsgrading, gaveup.'
                                    ),
                                ]),
                                'The mark for each slot, in slot order.'
                            ),
                        ]),
                        'Finished attempts by active graded students, by student then attempt number.'
                    ),
                ]),
                'The requested quizzes that exist in this course.'
            ),
        ]);
    }
}
