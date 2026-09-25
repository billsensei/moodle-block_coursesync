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

/**
 * Returns the gradebook grades of the students in a course, for chosen activities.
 *
 * This is the one function that hands out people's data, so it is off until
 * an administrator ticks "Let other sites read grades from this site"
 * (block_coursesync | allowgradeexport), and it has its own permission,
 * block/coursesync:exportgrades, on top of the sync permission. An account set
 * up only to copy activities cannot read grades until an administrator grants
 * that as well.
 *
 * Students are identified by username - the key the destination matches them
 * on. Only students the gradebook itself would list are reported: people in a
 * graded role with an active enrolment. Only final gradebook grades travel,
 * never attempts, submissions or responses.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_grades extends external_api {
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
                'Activities whose grades are wanted.',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Collect the grades.
     *
     * @param int $courseid
     * @param int[] $cmids
     * @return array
     */
    public static function execute(int $courseid, array $cmids): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');

        [
            'courseid' => $courseid,
            'cmids' => $cmids,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmids' => $cmids,
        ]);

        // Off until an administrator here turns it on. Checked before
        // anything else, so a site that does not share grades gives every
        // caller the same answer and nothing about its courses.
        if (!get_config('block_coursesync', 'allowgradeexport')) {
            throw new \moodle_exception('errorgradeexportdisabled', 'block_coursesync');
        }

        $course = $DB->get_record('course', ['id' => $courseid]);

        if (!$course) {
            throw new \moodle_exception('errorcoursenotfound', 'block_coursesync');
        }

        // Our own permissions first, so a misconfigured sync account is told
        // which one it lacks rather than about enrolment.
        $context = \context_course::instance($course->id);
        require_capability('block/coursesync:sync', $context);
        require_capability('block/coursesync:exportgrades', $context);
        self::validate_context($context);

        $modinfo = get_fast_modinfo($course->id);
        $cms = [];

        // An activity that is not in this course, or no longer exists, is left
        // out rather than refused: the destination asks with every copy it
        // holds, and one deleted here must not stop the rest being answered.
        foreach (array_unique($cmids) as $cmid) {
            $cm = $modinfo->get_cms()[$cmid] ?? null;

            if ($cm && !$cm->deletioninprogress) {
                $cms[] = $cm;
            }
        }

        if (!$cms) {
            return ['items' => []];
        }

        // A course whose gradebook is waiting to be recalculated holds stale
        // final grades, and the gradable-users list refuses to run at all
        // until it is done. Recalculate now, as core's own user-grades web
        // service does - the grader report would do the same on opening.
        if (grade_needs_regrade_final_grades($course->id)) {
            grade_regrade_final_grades($course->id);
        }

        $students = get_gradable_users($course->id, null, true);
        $items = [];

        foreach ($cms as $cm) {
            $gradeitems = \grade_item::fetch_all([
                'courseid' => $course->id,
                'itemtype' => 'mod',
                'itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance,
            ]) ?: [];

            // Order by itemnumber, so an activity with several grade items
            // (workshop, forum) always reports them the same way round.
            usort($gradeitems, static fn($a, $b) => $a->itemnumber <=> $b->itemnumber);

            foreach ($gradeitems as $gradeitem) {
                if ((int) $gradeitem->gradetype === GRADE_TYPE_NONE) {
                    continue;
                }

                $items[] = self::export_item($cm, $gradeitem, $students);
            }
        }

        return ['items' => $items];
    }

    /**
     * One grade item and every student's grade in it.
     *
     * @param \cm_info $cm
     * @param \grade_item $gradeitem
     * @param \stdClass[] $students gradable users keyed by id
     * @return array
     */
    protected static function export_item(\cm_info $cm, \grade_item $gradeitem, array $students): array {
        global $DB;

        $scale = '';

        if ((int) $gradeitem->gradetype === GRADE_TYPE_SCALE && $gradeitem->scaleid) {
            $scale = (string) $DB->get_field('scale', 'scale', ['id' => $gradeitem->scaleid]);
        }

        $grades = [];

        if ($students) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED);
            $params['itemid'] = $gradeitem->id;
            $records = $DB->get_records_select(
                'grade_grades',
                "itemid = :itemid AND userid {$insql}",
                $params,
                'userid',
                'id, userid, finalgrade, feedback, feedbackformat, hidden, timemodified'
            );

            foreach ($records as $record) {
                // Nothing to carry: no grade and no feedback. A grade row
                // without either exists whenever an activity was opened but
                // never graded.
                if ($record->finalgrade === null && ($record->feedback === null || $record->feedback === '')) {
                    continue;
                }

                $grades[] = [
                    'username' => $students[$record->userid]->username,
                    'grade' => $record->finalgrade === null ? null : (float) $record->finalgrade,
                    'feedback' => (string) $record->feedback,
                    'feedbackformat' => (int) $record->feedbackformat,
                    'hidden' => (int) $record->hidden,
                    'timemodified' => (int) $record->timemodified,
                ];
            }
        }

        return [
            'cmid' => (int) $cm->id,
            'itemnumber' => (int) $gradeitem->itemnumber,
            'gradetype' => (int) $gradeitem->gradetype,
            'grademin' => (float) $gradeitem->grademin,
            'grademax' => (float) $gradeitem->grademax,
            'scale' => $scale,
            'hidden' => (int) $gradeitem->hidden,
            'grades' => $grades,
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
                    'itemnumber' => new external_value(
                        PARAM_INT,
                        'Which of the activity\'s grade items this is; 0 for most activities.'
                    ),
                    'gradetype' => new external_value(PARAM_INT, 'GRADE_TYPE_VALUE, GRADE_TYPE_SCALE or GRADE_TYPE_TEXT.'),
                    'grademin' => new external_value(PARAM_FLOAT, 'Lowest possible grade.'),
                    'grademax' => new external_value(PARAM_FLOAT, 'Highest possible grade.'),
                    'scale' => new external_value(
                        PARAM_RAW,
                        'Comma-separated scale items for a scale grade item, otherwise empty.'
                    ),
                    'hidden' => new external_value(
                        PARAM_INT,
                        'Grade item hidden flag: 0 shown, 1 hidden, or a time it is hidden until.'
                    ),
                    'grades' => new external_multiple_structure(
                        new external_single_structure([
                            'username' => new external_value(PARAM_RAW, 'The student\'s username.'),
                            'grade' => new external_value(
                                PARAM_FLOAT,
                                'Final gradebook grade, null if there is only feedback.',
                                VALUE_REQUIRED,
                                null,
                                NULL_ALLOWED
                            ),
                            'feedback' => new external_value(PARAM_RAW, 'Feedback text, unformatted.'),
                            'feedbackformat' => new external_value(PARAM_INT, 'Format of the feedback text.'),
                            'hidden' => new external_value(
                                PARAM_INT,
                                'Grade hidden flag: 0 shown, 1 hidden, or a time it is hidden until.'
                            ),
                            'timemodified' => new external_value(PARAM_INT, 'When this grade last changed.'),
                        ]),
                        'Grades of active students in a graded role. A student with nothing recorded is left out.'
                    ),
                ]),
                'Grade items of the requested activities that exist in this course.'
            ),
        ]);
    }
}
