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
 * Handles mod_choice, including the options people choose between.
 *
 * A choice is its question and its options, so both come across. What does not
 * is who picked what: those answers are the course's own, and they stay where
 * they were given, as a forum's posts do.
 *
 * The options travel as child records and are handed to choice_add_instance()
 * in the shape its settings form submits - two parallel arrays, the option text
 * and its limit, indexed together. That is one of several modules whose
 * add_instance() takes the form rather than the stored row.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class choice_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'choice';
    }

    /**
     * SOURCE SIDE. How the choice is set up.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the choice table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        return $settings;
    }

    /**
     * SOURCE SIDE. The options people choose between.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the choice table
     * @return array[]
     */
    public function export_children(\cm_info $cm, \stdClass $instance): array {
        global $DB;

        $children = [];
        $order = 0;

        foreach ($DB->get_records('choice_options', ['choiceid' => $instance->id], 'id ASC') as $option) {
            $children[] = [
                'type' => 'option',
                'sortorder' => $order++,
                'fields' => [
                    'text' => (string) $option->text,
                    'maxanswers' => (int) $option->maxanswers,
                ],
            ];
        }

        return $children;
    }

    /**
     * DESTINATION SIDE. Build the choice in a local course.
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

        require_once($CFG->dirroot . '/mod/choice/lib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        // The options are read by choice_add_instance() the way its form
        // submits them: the text in one array and the limit in another,
        // sharing an index.
        $data->option = [];
        $data->limit = [];

        foreach ($payload->children('option') as $index => $option) {
            $text = clean_param(activity_payload::child_field($option, 'text'), PARAM_TEXT);

            if (trim($text) === '') {
                continue;
            }

            $data->option[$index] = $text;
            $data->limit[$index] = max(0, activity_payload::child_int($option, 'maxanswers', 0));
        }

        $instanceid = \choice_add_instance($data);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Say that the copy arrived without anybody's answers.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys to show against this activity
     */
    public function notes(activity_payload $payload): array {
        return ['syncchoicenoanswers'];
    }

    /**
     * The choice settings that are carried, and what to assume without them.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'publish' => 0,
            'showresults' => 0,
            'display' => 0,
            'allowupdate' => 0,
            'allowmultiple' => 0,
            'showunanswered' => 0,
            'includeinactive' => 1,
            'limitanswers' => 0,
            'timeopen' => 0,
            'timeclose' => 0,
            'showpreview' => 0,
            'completionsubmit' => 0,
            'showavailable' => 0,
        ];
    }
}
