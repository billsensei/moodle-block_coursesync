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
use core_external\util as external_util;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Lists activities in a course that changed after a given time.
 *
 * Metadata only: enough for the destination to show what is available and,
 * later, to decide what to pull. No activity content is returned.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_modified_activities extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id on this site.', VALUE_REQUIRED),
            'since' => new external_value(
                PARAM_INT,
                'Unix time to compare against. 0 returns every activity in the course.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * List the activities modified since the given time.
     *
     * @param int $courseid
     * @param int $since
     * @return array
     */
    public static function execute(int $courseid, int $since = 0): array {
        global $DB;

        [
            'courseid' => $courseid,
            'since' => $since,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'since' => $since,
        ]);

        if ($since < 0) {
            throw new \invalid_parameter_exception('since must not be negative');
        }

        $course = $DB->get_record('course', ['id' => $courseid]);

        if (!$course) {
            throw new \moodle_exception('errorcoursenotfound', 'block_coursesync');
        }

        // Our own permission first, so a misconfigured sync account gets a
        // message about the sync permission rather than about enrolment.
        $context = \context_course::instance($course->id);
        require_capability('block/coursesync:sync', $context);

        // The require_login() inside validate_context() is why the sync account
        // also needs moodle/course:view: it is not enrolled in the source course.
        self::validate_context($context);

        $activities = [];
        $modinfo = get_fast_modinfo($course->id);

        foreach (self::get_activity_times($course->id) as $cmid => $timemodified) {
            if ($timemodified <= $since) {
                continue;
            }

            $cm = $modinfo->get_cm($cmid);

            $activities[] = [
                'cmid' => (int) $cm->id,
                'modname' => $cm->modname,
                'name' => external_util::format_string($cm->name, $context, true),
                'idnumber' => (string) $cm->idnumber,
                'timemodified' => (int) $timemodified,
            ];
        }

        // Oldest first, so the destination can work through them in the order
        // they changed.
        usort($activities, static function (array $a, array $b): int {
            return $a['timemodified'] <=> $b['timemodified'] ?: $a['cmid'] <=> $b['cmid'];
        });

        return $activities;
    }

    /**
     * Modification time for every activity in a course, keyed by course module id.
     *
     * course_modules has no timemodified of its own - only "added" - so the time
     * has to come from each activity's own table. Those are read one module type
     * at a time rather than one activity at a time. A module whose table has no
     * timemodified column (some third-party ones do not) falls back to when the
     * activity was added to the course.
     *
     * @param int $courseid
     * @return array cmid => unix time
     */
    protected static function get_activity_times(int $courseid): array {
        global $DB;

        $modinfo = get_fast_modinfo($courseid);
        $dbman = $DB->get_manager();
        $times = [];
        $bymodname = [];

        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }

            $bymodname[$cm->modname][$cm->instance] = $cm->id;
            // Fallback, replaced below when the module table can do better.
            $times[$cm->id] = (int) $cm->added;
        }

        foreach ($bymodname as $modname => $instances) {
            $table = new \xmldb_table($modname);
            $field = new \xmldb_field('timemodified');

            if (!$dbman->table_exists($table) || !$dbman->field_exists($table, $field)) {
                continue;
            }

            [$insql, $params] = $DB->get_in_or_equal(array_keys($instances), SQL_PARAMS_NAMED);
            $records = $DB->get_records_select($modname, "id {$insql}", $params, '', 'id, timemodified');

            foreach ($records as $record) {
                if (empty($record->timemodified)) {
                    continue;
                }

                $times[$instances[$record->id]] = (int) $record->timemodified;
            }
        }

        return self::with_question_times($times, $bymodname);
    }

    /**
     * A question bank or quiz counts as changed when one of its questions was.
     *
     * Editing a question writes a new question version, and touches neither the
     * bank's nor the quiz's own row, so its modified time alone would never say
     * so. For a quiz that is the questions it uses: the ones in its fixed slots,
     * and everything in the categories its random slots draw from.
     *
     * @param array $times cmid => unix time
     * @param array $bymodname modname => [instance id => cmid]
     * @return array cmid => unix time
     */
    protected static function with_question_times(array $times, array $bymodname): array {
        global $DB, $CFG;

        require_once($CFG->libdir . '/questionlib.php');

        $ready = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

        // A question bank: every ready question in a category of its own context.
        if (!empty($bymodname['qbank'])) {
            [$insql, $params] = $DB->get_in_or_equal(array_values($bymodname['qbank']), SQL_PARAMS_NAMED);
            $params['ready'] = $ready;
            $params['level'] = CONTEXT_MODULE;

            $rows = $DB->get_records_sql(
                "SELECT ctx.instanceid AS cmid, MAX(q.timemodified) AS changed
                   FROM {context} ctx
                   JOIN {question_categories} qc ON qc.contextid = ctx.id
                   JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
                   JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id AND qv.status = :ready
                   JOIN {question} q ON q.id = qv.questionid
                  WHERE ctx.contextlevel = :level AND ctx.instanceid {$insql}
               GROUP BY ctx.instanceid",
                $params
            );

            foreach ($rows as $row) {
                $times[(int) $row->cmid] = max($times[(int) $row->cmid], (int) $row->changed);
            }
        }

        foreach ($bymodname['quiz'] ?? [] as $quizid => $cmid) {
            $changed = self::quiz_question_time((int) $quizid, (int) $cmid, $ready);
            $times[$cmid] = max($times[$cmid], $changed);
        }

        return $times;
    }

    /**
     * When the newest question a quiz uses last changed.
     *
     * @param int $quizid
     * @param int $cmid
     * @param string $ready the status of a question that is ready to use
     * @return int 0 when the quiz uses none
     */
    protected static function quiz_question_time(int $quizid, int $cmid, string $ready): int {
        global $DB;

        $modcontextid = \context_module::instance($cmid)->id;
        $newest = 0;

        $fixed = $DB->get_field_sql(
            "SELECT MAX(q.timemodified)
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz'
                    AND qr.questionarea = 'slot' AND qr.usingcontextid = :ctx
               JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid AND qv.status = :ready
               JOIN {question} q ON q.id = qv.questionid
              WHERE qs.quizid = :quizid",
            ['ctx' => $modcontextid, 'ready' => $ready, 'quizid' => $quizid]
        );
        $newest = max($newest, (int) $fixed);

        $categoryids = [];

        $setrefs = $DB->get_records_sql(
            "SELECT sr.id, sr.filtercondition
               FROM {quiz_slots} qs
               JOIN {question_set_references} sr ON sr.itemid = qs.id AND sr.component = 'mod_quiz'
                    AND sr.questionarea = 'slot'
              WHERE qs.quizid = :quizid",
            ['quizid' => $quizid]
        );

        foreach ($setrefs as $setref) {
            $filter = json_decode((string) $setref->filtercondition, true) ?: [];
            $categoryid = (int) ($filter['filter']['category']['values'][0] ?? 0);

            if ($categoryid === 0) {
                continue;
            }

            $categoryids[$categoryid] = true;

            if (!empty($filter['filter']['category']['filteroptions']['includesubcategories'])) {
                foreach (\question_categorylist($categoryid) as $descendantid) {
                    $categoryids[(int) $descendantid] = true;
                }
            }
        }

        if ($categoryids !== []) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($categoryids), SQL_PARAMS_NAMED);
            $params['ready'] = $ready;

            $random = $DB->get_field_sql(
                "SELECT MAX(q.timemodified)
                   FROM {question_bank_entries} qbe
                   JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id AND qv.status = :ready
                   JOIN {question} q ON q.id = qv.questionid
                  WHERE qbe.questioncategoryid {$insql}",
                $params
            );
            $newest = max($newest, (int) $random);
        }

        return $newest;
    }

    /**
     * Describes the return value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id on the source site.'),
                'modname' => new external_value(PARAM_PLUGIN, 'Activity type, for example "assign" or "forum".'),
                'name' => new external_value(PARAM_TEXT, 'Activity name.'),
                'idnumber' => new external_value(PARAM_RAW, 'Activity ID number, empty if it has none.'),
                'timemodified' => new external_value(PARAM_INT, 'When the activity was last modified.'),
            ]),
            'Activities modified after the given time, oldest first.'
        );
    }
}
