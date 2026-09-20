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
 * Handles mod_assign: the assignment's settings, not its submissions.
 *
 * What comes across is the assignment as a teacher set it up - the task, its
 * dates, how it is graded, how it is marked, and which submission and feedback
 * types are turned on. What does not come across is anything a student did:
 * submissions, grades, feedback, extensions and marking allocations all stay
 * where they were made, exactly as a forum's discussions do.
 *
 * An assignment is not one row. Which submission and feedback types are enabled,
 * and how each one is configured, lives in assign_plugin_config, so those rows
 * travel as child records and are written back into that table once the
 * assignment exists.
 *
 * Writing them directly is deliberate, and it is what mod_assign's own restore
 * does. The obvious alternative - hand mod_assign the settings form it expects
 * and let each subplugin save its own - cannot work, because a subplugin's form
 * field names and its stored setting names are not the same thing and nothing
 * relates them: assignsubmission_file's "assignsubmission_file_maxfiles" field
 * is stored as "maxfilesubmissions". Only a subplugin knows its own mapping, so
 * the rows are carried as they are stored.
 *
 * Grading needs care. A positive grade is a maximum score and means the same
 * anywhere; a negative one is minus the id of a scale, and that id is local to
 * the site it came from. So the scale's name travels too, and is matched by name
 * here - see activity_handler::resolve_scale().
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'assign';
    }

    /**
     * The two areas an assignment's own files live in.
     *
     * Both belong to the task rather than to anyone's submission: the files
     * attached to the description, and those attached to the extra activity
     * instructions.
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'introattachment', 'itemid' => 0],
            ['filearea' => 'activityattachment', 'itemid' => 0],
        ];
    }

    /**
     * SOURCE SIDE. How the assignment is set up.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the assign table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        // Which scale a negative grade meant on the source site.
        $settings['gradescalename'] = self::scale_name((int) ($instance->grade ?? 0));

        // The fields that are not whole numbers.
        $settings['attemptreopenmethod'] = (string) ($instance->attemptreopenmethod ?? 'untilpass');
        $settings['activity'] = (string) ($instance->activity ?? '');
        $settings['activityformat'] = (string) ($instance->activityformat ?? FORMAT_HTML);

        return $settings;
    }

    /**
     * SOURCE SIDE. The assignment's submission and feedback plugin settings.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the assign table
     * @return array[]
     */
    public function export_children(\cm_info $cm, \stdClass $instance): array {
        global $DB;

        $rows = $DB->get_records('assign_plugin_config', ['assignment' => $instance->id], 'id ASC');
        $children = [];
        $order = 0;

        foreach ($rows as $row) {
            $children[] = [
                'type' => 'pluginconfig',
                'sortorder' => $order++,
                'fields' => [
                    'subtype' => (string) $row->subtype,
                    'plugin' => (string) $row->plugin,
                    'name' => (string) $row->name,
                    'value' => (string) $row->value,
                ],
            ];
        }

        return $children;
    }

    /**
     * DESTINATION SIDE. Build the assignment in a local course.
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

        require_once($CFG->dirroot . '/mod/assign/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        $data->attemptreopenmethod = self::clean_reopen_method($payload->setting('attemptreopenmethod'));
        $data->grade = self::resolve_scale(
            $payload->setting_int('grade', 0),
            $payload->setting('gradescalename')
        );

        // A grouping is a local thing and its id means nothing here, so team
        // submission comes across without one rather than pointed at whatever
        // grouping happens to hold that id.
        $data->teamsubmissiongroupingid = 0;
        // Nobody has submitted here yet, so identities cannot have been revealed.
        $data->revealidentities = 0;
        $data->nosubmissions = 0;

        $activityformat = $payload->setting_int('activityformat', FORMAT_HTML);
        $activity = $payload->setting_html('activity', $activityformat);

        if ($activity !== '') {
            // No itemid, so mod_assign stores the text and does not go looking
            // for a draft file area that does not exist here.
            $data->activityeditor = ['text' => $activity, 'format' => $activityformat];
        }

        try {
            $instanceid = \assign_add_instance($data, null);
        } catch (\Throwable $e) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        $cm = $this->finish_creation($course, $cmid, $sectionnum);

        // After finish_creation, not before: working out whether the assignment
        // takes submissions goes through mod_assign, which reads the course
        // cache, and the new activity is only in that cache once the section
        // has been set and the cache rebuilt.
        //
        // An assignment left with this site's default submission and feedback
        // types would look finished while quietly accepting the wrong kind of
        // work, and the next run would pass over it as already synced. So a
        // failure here takes the whole assignment back out.
        try {
            $this->restore_plugin_config((int) $instanceid, $cmid, $payload);
        } catch (\Throwable $e) {
            require_once($CFG->dirroot . '/course/lib.php');
            \course_delete_module($cm->id, false);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        return $cm;
    }

    /**
     * Say on every assignment that it arrived without its submissions.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys to show against this activity
     */
    public function notes(activity_payload $payload): array {
        return ['syncassignnosubmissions'];
    }

    /**
     * Keep the reopen method to one this site recognises.
     *
     * @param string $method what the source site sent
     * @return string
     */
    protected static function clean_reopen_method(string $method): string {
        $known = [
            ASSIGN_ATTEMPT_REOPEN_METHOD_NONE,
            ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL,
            ASSIGN_ATTEMPT_REOPEN_METHOD_UNTILPASS,
        ];

        return in_array($method, $known, true) ? $method : ASSIGN_ATTEMPT_REOPEN_METHOD_NONE;
    }

    /**
     * Put the source's submission and feedback plugin settings on the copy.
     *
     * Creating the assignment left this site's defaults in assign_plugin_config,
     * so those are cleared first and the source's rows put in their place. Only
     * rows naming a subplugin this site actually has are kept: a setting for a
     * plugin that is not installed here would sit in the table unread, and would
     * come back to life confusingly if that plugin were ever installed.
     *
     * @param int $assignid the assignment on this site
     * @param int $cmid its course module
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function restore_plugin_config(int $assignid, int $cmid, activity_payload $payload): void {
        global $DB;

        $rows = [];

        foreach ($payload->children('pluginconfig') as $child) {
            $subtype = activity_payload::child_field($child, 'subtype');
            $plugin = activity_payload::child_field($child, 'plugin');
            $name = activity_payload::child_field($child, 'name');

            // An assignment has exactly these two kinds of subplugin.
            if (!in_array($subtype, ['assignsubmission', 'assignfeedback'], true)) {
                continue;
            }

            if ($plugin === '' || $plugin !== clean_param($plugin, PARAM_PLUGIN)) {
                continue;
            }

            if ($name === '' || $name !== clean_param($name, PARAM_ALPHANUMEXT)) {
                continue;
            }

            if (!self::subplugin_is_installed($subtype, $plugin)) {
                continue;
            }

            $rows[] = (object) [
                'assignment' => $assignid,
                'subtype' => $subtype,
                'plugin' => $plugin,
                'name' => $name,
                'value' => activity_payload::child_field($child, 'value'),
            ];
        }

        if ($rows === []) {
            return;
        }

        $DB->delete_records('assign_plugin_config', ['assignment' => $assignid]);
        $DB->insert_records('assign_plugin_config', $rows);

        // Whether the assignment takes submissions at all was worked out from
        // this site's defaults while it was being created, so it is worked out
        // again now the real settings are in place - by mod_assign, so the
        // answer is the one mod_assign would give.
        $assign = new \assign(\context_module::instance($cmid), null, null);

        $DB->set_field(
            'assign',
            'nosubmissions',
            $assign->is_any_submission_plugin_enabled() ? 0 : 1,
            ['id' => $assignid]
        );
    }

    /**
     * Is that submission or feedback plugin installed on this site?
     *
     * @param string $subtype assignsubmission or assignfeedback
     * @param string $plugin the plugin's name
     * @return bool
     */
    protected static function subplugin_is_installed(string $subtype, string $plugin): bool {
        return array_key_exists($plugin, \core_component::get_plugin_list($subtype));
    }

    /**
     * Was a grading scale dropped because this site does not have it?
     *
     * @param activity_payload $payload what the source site sent
     * @return bool
     */
    public function lost_scale(activity_payload $payload): bool {
        return self::scale_was_dropped($payload, [['grade', 'gradescalename']]);
    }

    /**
     * The assignment settings that are carried, and what to assume without them.
     *
     * Every one of these is a whole number, which is why they can be carried in
     * a loop. The ones that are not - the grade, which may name a scale; the
     * reopen method, which is a word; the extra instructions, which are HTML -
     * are each handled on their own.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'alwaysshowdescription' => 1,
            'submissiondrafts' => 0,
            'sendnotifications' => 0,
            'sendlatenotifications' => 0,
            'sendstudentnotifications' => 1,
            'duedate' => 0,
            'allowsubmissionsfromdate' => 0,
            'cutoffdate' => 0,
            'gradingduedate' => 0,
            'timelimit' => 0,
            'requiresubmissionstatement' => 0,
            'completionsubmit' => 0,
            'teamsubmission' => 0,
            'requireallteammemberssubmit' => 0,
            'blindmarking' => 0,
            'hidegrader' => 0,
            'maxattempts' => -1,
            'markingworkflow' => 0,
            'markingallocation' => 0,
            'markinganonymous' => 0,
            'preventsubmissionnotingroup' => 0,
            'submissionattachments' => 0,
            'gradepenalty' => 0,
            'grade' => 0,
        ];
    }
}
