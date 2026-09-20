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

namespace block_coursesync\local\handler;

use block_coursesync\activity_payload;
/**
 * Handles mod_workshop: the activity and its assessment form.
 *
 * A workshop without its assessment form is not much use - the form is what
 * reviewers fill in, and a teacher wrote it - so it comes across. Submissions,
 * assessments, grades and allocations do not: those are the work of the people
 * in that course.
 *
 * The assessment form does not live in one table. Each grading strategy is a
 * subplugin with its own storage: accumulative and comments keep a row per
 * criterion, numerrors adds a grade mapping, and rubric keeps its levels in a
 * second table pointing back at the criterion by id. All four are carried,
 * rather than only the one in use, so that a teacher who switches strategy on
 * the copy finds the form they had rather than an empty one.
 *
 * Those rubric levels are the reason this handler has a second pass: a level
 * refers to its criterion by id, and that id is this site's, not the source's.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_handler extends activity_handler {
    /**
     * The per-criterion table each grading strategy stores its form in, and the
     * columns that belong to a criterion in it.
     */
    protected const STRATEGY_TABLES = [
        'accumulative' => [
            'table' => 'workshopform_accumulative',
            'text' => ['description'],
            'numbers' => ['sort', 'descriptionformat', 'grade', 'weight'],
        ],
        'comments' => [
            'table' => 'workshopform_comments',
            'text' => ['description'],
            'numbers' => ['sort', 'descriptionformat'],
        ],
        'numerrors' => [
            'table' => 'workshopform_numerrors',
            'text' => ['description', 'grade0', 'grade1'],
            'numbers' => ['sort', 'descriptionformat', 'descriptiontrust', 'weight'],
        ],
        'rubric' => [
            'table' => 'workshopform_rubric',
            'text' => ['description'],
            'numbers' => ['sort', 'descriptionformat'],
        ],
    ];

    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'workshop';
    }

    /**
     * The files that belong to the activity rather than to anybody's work.
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'instructauthors', 'itemid' => 0],
            ['filearea' => 'instructreviewers', 'itemid' => 0],
            ['filearea' => 'conclusion', 'itemid' => 0],
        ];
    }

    /**
     * SOURCE SIDE. How the workshop is set up.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the workshop table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        foreach (['instructauthors', 'instructreviewers', 'conclusion'] as $text) {
            $settings[$text] = (string) ($instance->$text ?? '');
        }

        $settings['strategy'] = (string) ($instance->strategy ?? 'accumulative');
        $settings['submissionfiletypes'] = (string) ($instance->submissionfiletypes ?? '');
        $settings['overallfeedbackfiletypes'] = (string) ($instance->overallfeedbackfiletypes ?? '');
        // Both are marks out of, stored to five decimal places.
        $settings['grade'] = (string) (float) ($instance->grade ?? 80);
        $settings['gradinggrade'] = (string) (float) ($instance->gradinggrade ?? 20);

        return $settings;
    }

    /**
     * SOURCE SIDE. The assessment form, for every strategy that has one.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the workshop table
     * @return array[]
     */
    public function export_children(\cm_info $cm, \stdClass $instance): array {
        global $DB;

        $children = [];
        $order = 0;

        foreach (self::STRATEGY_TABLES as $strategy => $spec) {
            $rows = $DB->get_records($spec['table'], ['workshopid' => $instance->id], 'sort ASC');

            foreach ($rows as $row) {
                $fields = ['remoteid' => (int) $row->id, 'strategy' => $strategy];

                foreach (array_merge($spec['text'], $spec['numbers']) as $column) {
                    $fields[$column] = (string) ($row->$column ?? '');
                }

                $children[] = [
                    'type' => 'dimension',
                    'sortorder' => $order++,
                    'fields' => $fields,
                ];
            }
        }

        // A rubric's levels belong to a criterion, named by its id.
        $levels = $DB->get_records_sql(
            'SELECT l.* FROM {workshopform_rubric_levels} l
               JOIN {workshopform_rubric} r ON r.id = l.dimensionid
              WHERE r.workshopid = ?
           ORDER BY l.id ASC',
            [$instance->id]
        );

        $order = 0;

        foreach ($levels as $level) {
            $children[] = [
                'type' => 'rubriclevel',
                'sortorder' => $order++,
                'fields' => [
                    'dimensionid' => (int) $level->dimensionid,
                    'grade' => (string) $level->grade,
                    'definition' => (string) $level->definition,
                    'definitionformat' => (int) $level->definitionformat,
                ],
            ];
        }

        // The two per-workshop settings the strategies keep of their own.
        $config = $DB->get_record('workshopform_rubric_config', ['workshopid' => $instance->id]);

        if ($config) {
            $children[] = [
                'type' => 'rubricconfig',
                'sortorder' => 0,
                'fields' => ['layout' => (string) $config->layout],
            ];
        }

        $map = $DB->get_records('workshopform_numerrors_map', ['workshopid' => $instance->id], 'nonegative ASC');
        $order = 0;

        foreach ($map as $row) {
            $children[] = [
                'type' => 'numerrorsmap',
                'sortorder' => $order++,
                'fields' => [
                    'nonegative' => (int) $row->nonegative,
                    'grade' => (string) $row->grade,
                ],
            ];
        }

        return $children;
    }

    /**
     * DESTINATION SIDE. Build the workshop, and its assessment form, locally.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload what the source site sent
     * @param string $idnumber the ID number that marks this as synced
     * @return \stdClass the new course_modules record
     */
    public function create_from_remote_data(
        \stdClass $course,
        activity_payload $payload,
        string $idnumber
    ): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/workshop/lib.php');
        require_once($CFG->dirroot . '/mod/workshop/locallib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        $data->grade = max(0.0, (float) $payload->setting('grade', '80'));
        $data->gradinggrade = max(0.0, (float) $payload->setting('gradinggrade', '20'));
        $data->strategy = self::clean_strategy($payload->setting('strategy'));
        $data->submissionfiletypes = clean_param($payload->setting('submissionfiletypes'), PARAM_TEXT);
        $data->overallfeedbackfiletypes = clean_param(
            $payload->setting('overallfeedbackfiletypes'),
            PARAM_TEXT
        );

        // Which gradebook category the two grade items go in. These are read
        // without being checked for, and a category is a local thing anyway, so
        // the copy takes this course's default rather than a number from
        // somewhere else.
        $data->gradecategory = 0;
        $data->gradinggradecategory = 0;

        // All three of these are read by workshop_add_instance() without being
        // checked for. A zero item id means there is no draft area to move
        // anything from, which is right: the text is already in hand and the
        // files are written straight into the real areas afterwards.
        foreach (
            [
            'instructauthors' => 'instructauthorseditor',
            'instructreviewers' => 'instructreviewerseditor',
            'conclusion' => 'conclusioneditor',
            ] as $field => $editor
        ) {
            $format = $payload->setting_int($field . 'format', FORMAT_HTML);
            $data->$field = $payload->setting_html($field, $format);
            $data->{$field . 'format'} = $format;
            $data->$editor = ['text' => $data->$field, 'format' => $format, 'itemid' => 0];
        }

        $instanceid = \workshop_add_instance($data);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        // A workshop with half an assessment form would be marked against
        // criteria the teacher did not write, and the next run would pass over
        // it as already synced.
        try {
            $this->create_assessment_form((int) $instanceid, $payload);
        } catch (\Throwable $e) {
            $this->remove_assessment_form((int) $instanceid);
            $DB->delete_records('workshop', ['id' => $instanceid]);
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Recreate the assessment form for every strategy the source had one for.
     *
     * @param int $workshopid the workshop on this site
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function create_assessment_form(int $workshopid, activity_payload $payload): void {
        global $DB;

        foreach ($payload->children('dimension') as $dimension) {
            $strategy = activity_payload::child_field($dimension, 'strategy');

            if (!isset(self::STRATEGY_TABLES[$strategy])) {
                continue;
            }

            if (!self::strategy_is_installed($strategy)) {
                continue;
            }

            $spec = self::STRATEGY_TABLES[$strategy];
            $record = (object) ['workshopid' => $workshopid];

            foreach ($spec['text'] as $column) {
                $record->$column = activity_payload::child_html($dimension, $column, FORMAT_HTML);
            }

            foreach ($spec['numbers'] as $column) {
                $record->$column = activity_payload::child_int($dimension, $column, 0);
            }

            $localid = (int) $DB->insert_record($spec['table'], $record);

            // Only a rubric's criteria are referred to again, but remembering
            // them all costs nothing and keeps the kinds from colliding.
            $this->remember_id(
                $strategy . 'dimension',
                activity_payload::child_int($dimension, 'remoteid', 0),
                $localid
            );
        }

        $this->create_strategy_extras($workshopid, $payload);
    }

    /**
     * The parts of the assessment form that hang off something else.
     *
     * A rubric's levels belong to a criterion and can only be written once the
     * criteria have ids here. The other two are settings the strategies keep
     * per workshop rather than per criterion.
     *
     * @param int $workshopid the workshop on this site
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function create_strategy_extras(int $workshopid, activity_payload $payload): void {
        global $DB;

        // The levels, now their criteria have ids here.
        foreach ($payload->children('rubriclevel') as $level) {
            $dimensionid = $this->mapped_id(
                'rubricdimension',
                activity_payload::child_int($level, 'dimensionid', 0)
            );

            // A level whose criterion did not arrive belongs to nothing.
            if ($dimensionid === 0) {
                continue;
            }

            $DB->insert_record('workshopform_rubric_levels', (object) [
                'dimensionid' => $dimensionid,
                'grade' => (float) activity_payload::child_field($level, 'grade', '0'),
                'definition' => activity_payload::child_html($level, 'definition', FORMAT_HTML),
                'definitionformat' => activity_payload::child_int($level, 'definitionformat', FORMAT_HTML),
            ]);
        }

        foreach ($payload->children('rubricconfig') as $config) {
            $DB->insert_record('workshopform_rubric_config', (object) [
                'workshopid' => $workshopid,
                'layout' => self::clean_layout(activity_payload::child_field($config, 'layout')),
            ]);
        }

        foreach ($payload->children('numerrorsmap') as $row) {
            $DB->insert_record('workshopform_numerrors_map', (object) [
                'workshopid' => $workshopid,
                'nonegative' => activity_payload::child_int($row, 'nonegative', 0),
                'grade' => (float) activity_payload::child_field($row, 'grade', '0'),
            ]);
        }
    }

    /**
     * Take back a half-written assessment form.
     *
     * @param int $workshopid the workshop on this site
     * @return void
     */
    protected function remove_assessment_form(int $workshopid): void {
        global $DB;

        $rubrics = $DB->get_fieldset_select('workshopform_rubric', 'id', 'workshopid = ?', [$workshopid]);

        if ($rubrics !== []) {
            $DB->delete_records_list('workshopform_rubric_levels', 'dimensionid', $rubrics);
        }

        foreach (self::STRATEGY_TABLES as $spec) {
            $DB->delete_records($spec['table'], ['workshopid' => $workshopid]);
        }

        $DB->delete_records('workshopform_rubric_config', ['workshopid' => $workshopid]);
        $DB->delete_records('workshopform_numerrors_map', ['workshopid' => $workshopid]);
    }

    /**
     * Say that the copy arrived without anybody's work in it.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys to show against this activity
     */
    public function notes(activity_payload $payload): array {
        return ['syncworkshopnosubmissions'];
    }

    /**
     * Keep the grading strategy to one this site actually has installed.
     *
     * @param string $strategy what the source site sent
     * @return string
     */
    protected static function clean_strategy(string $strategy): string {
        return self::strategy_is_installed($strategy) ? $strategy : 'accumulative';
    }

    /**
     * Is that grading strategy installed on this site?
     *
     * @param string $strategy
     * @return bool
     */
    protected static function strategy_is_installed(string $strategy): bool {
        if ($strategy === '' || $strategy !== clean_param($strategy, PARAM_PLUGIN)) {
            return false;
        }

        return array_key_exists($strategy, \core_component::get_plugin_list('workshopform'));
    }

    /**
     * Keep the rubric layout to one this site knows how to draw.
     *
     * @param string $layout what the source site sent
     * @return string
     */
    protected static function clean_layout(string $layout): string {
        return in_array($layout, ['list', 'grid'], true) ? $layout : 'list';
    }

    /**
     * The workshop settings that are carried, and what to assume without them.
     *
     * These are all whole numbers. The two marks out of, the grading strategy
     * and the three blocks of instructions are handled on their own.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'instructauthorsformat' => FORMAT_HTML,
            'instructreviewersformat' => FORMAT_HTML,
            'conclusionformat' => FORMAT_HTML,
            'useexamples' => 0,
            'usepeerassessment' => 1,
            'useselfassessment' => 0,
            'gradedecimals' => 0,
            'submissiontypetext' => 1,
            'submissiontypefile' => 1,
            'nattachments' => 1,
            'latesubmissions' => 0,
            'maxbytes' => 0,
            'examplesmode' => 0,
            'submissionstart' => 0,
            'submissionend' => 0,
            'assessmentstart' => 0,
            'assessmentend' => 0,
            'phaseswitchassessment' => 0,
            'overallfeedbackmode' => 1,
            'overallfeedbackfiles' => 0,
            'overallfeedbackmaxbytes' => 0,
        ];
    }
}
