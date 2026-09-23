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
 * Handles mod_data, including its fields and display templates.
 *
 * A database activity is its fields and the templates that lay them out, so
 * both come across. The entries people added do not: those are the course's own
 * work, and they are also what the approval and rating settings are about.
 *
 * Two things need care.
 *
 * The sort order names a field by id, and ids are local to the site they came
 * from, so the fields are created first and the sort order fixed afterwards.
 * The templates refer to fields by name rather than id - [[Surname]] and the
 * like - which is why they need no translating.
 *
 * The other is that two of the templates are not content at all. csstemplate is
 * served as a stylesheet and jstemplate is served as JavaScript, both verbatim,
 * and mod_data loads them into every page of the activity. There is no cleaning
 * that makes a block of script safe: it is script. Taking them from another site
 * would hand that site the ability to run code here, which is precisely what
 * this plugin is written not to allow, so they are refused and the copy says so.
 * The teacher can paste them across by hand if they wrote them and trust them.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_handler extends activity_handler {
    /**
     * The templates that are carried. They are laid out as HTML and are cleaned
     * as HTML, like any other block of markup from the other site.
     */
    protected const TEMPLATES = [
        'singletemplate',
        'listtemplate',
        'listtemplateheader',
        'listtemplatefooter',
        'addtemplate',
        'rsstemplate',
        'rsstitletemplate',
        'asearchtemplate',
    ];

    /**
     * The templates that are not carried, because they are code rather than
     * content. See the note on this class.
     */
    protected const REFUSED_TEMPLATES = [
        'csstemplate',
        'jstemplate',
    ];

    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'data';
    }

    /**
     * SOURCE SIDE. How the database is set up.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the data table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        foreach (self::TEMPLATES as $template) {
            $settings[$template] = (string) ($instance->$template ?? '');
        }

        // Said plainly so the destination can tell the teacher, rather than
        // having them wonder why the activity looks different.
        $hascode = false;

        foreach (self::REFUSED_TEMPLATES as $template) {
            if (trim((string) ($instance->$template ?? '')) !== '') {
                $hascode = true;
            }
        }

        $settings['hascodetemplates'] = $hascode ? '1' : '0';
        $settings['config'] = (string) ($instance->config ?? '');
        $settings['scalename'] = self::scale_name((int) ($instance->scale ?? 0));

        return $settings;
    }

    /**
     * SOURCE SIDE. The fields an entry is made of.
     *
     * The field's own id travels because the sort order refers to it; it is a
     * handle for matching them up again, not something this site stores.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the data table
     * @return array[]
     */
    public function export_children(\cm_info $cm, \stdClass $instance): array {
        global $DB;

        $children = [];
        $order = 0;

        foreach ($DB->get_records('data_fields', ['dataid' => $instance->id], 'id ASC') as $field) {
            $fields = [
                'remoteid' => (int) $field->id,
                'type' => (string) $field->type,
                'name' => (string) $field->name,
                'description' => (string) $field->description,
                'required' => (int) $field->required,
            ];

            for ($i = 1; $i <= 10; $i++) {
                $fields['param' . $i] = (string) ($field->{'param' . $i} ?? '');
            }

            $children[] = [
                'type' => 'field',
                'sortorder' => $order++,
                'fields' => $fields,
            ];
        }

        return $children;
    }

    /**
     * DESTINATION SIDE. Build the database, and its fields, in a local course.
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

        require_once($CFG->dirroot . '/mod/data/lib.php');

        $sectionnum = $this->target_section($course, $payload);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        foreach (self::TEMPLATES as $template) {
            $data->$template = $payload->setting_html($template, FORMAT_HTML);
        }

        foreach (self::REFUSED_TEMPLATES as $template) {
            $data->$template = '';
        }

        $data->config = self::clean_config($payload->setting('config'));
        $data->scale = self::resolve_scale(
            $payload->setting_int('scale', 0),
            $payload->setting('scalename')
        );
        $data->ratingtime = ($data->assessed && ($data->assesstimestart || $data->assesstimefinish)) ? 1 : 0;
        // Fixed once the fields exist and their local ids are known.
        $data->defaultsort = 0;

        $instanceid = \data_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        // A database missing some of its fields would quietly accept entries
        // shaped differently from the original, and the next run would pass
        // over it as already synced.
        try {
            $this->create_fields((int) $instanceid, $payload);
        } catch (\Throwable $e) {
            $DB->delete_records('data_fields', ['dataid' => $instanceid]);
            $DB->delete_records('data', ['id' => $instanceid]);
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Recreate the fields, then point the sort order at the right one.
     *
     * @param int $dataid the database on this site
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function create_fields(int $dataid, activity_payload $payload): void {
        global $DB;

        foreach ($payload->children('field') as $field) {
            $type = activity_payload::child_field($field, 'type');

            // A field of a type this site does not have could hold nothing and
            // display as nothing, so it is left out rather than stored.
            if (!self::field_type_is_installed($type)) {
                continue;
            }

            $record = (object) [
                'dataid' => $dataid,
                'type' => $type,
                'name' => clean_param(activity_payload::child_field($field, 'name'), PARAM_TEXT),
                'description' => activity_payload::child_html($field, 'description', FORMAT_HTML),
                'required' => activity_payload::child_int($field, 'required', 0) ? 1 : 0,
            ];

            // What each of these means is the field type's business: a menu
            // keeps its options here, one per line, and a number field keeps
            // its limits. They are stripped of markup rather than run through
            // the HTML cleaner, which would turn a bare ">" in an option into
            // an entity and leave the field reading oddly.
            for ($i = 1; $i <= 10; $i++) {
                $record->{'param' . $i} = clean_param(
                    activity_payload::child_field($field, 'param' . $i),
                    PARAM_NOTAGS
                );
            }

            $localid = (int) $DB->insert_record('data_fields', $record);

            $this->remember_id('field', activity_payload::child_int($field, 'remoteid', 0), $localid);
        }

        $sortfield = $payload->setting_int('defaultsort', 0);

        if ($sortfield > 0) {
            $DB->set_field('data', 'defaultsort', $this->mapped_id('field', $sortfield), ['id' => $dataid]);
        }
    }

    /**
     * Say what did not come with the copy.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys to show against this activity
     */
    public function notes(activity_payload $payload): array {
        $notes = ['syncdatanoentries'];

        if ($payload->setting_int('hascodetemplates', 0)) {
            $notes[] = 'syncdatacodetemplates';
        }

        return $notes;
    }

    /**
     * Was a grading scale dropped because this site does not have it?
     *
     * @param activity_payload $payload what the source site sent
     * @return bool
     */
    public function lost_scale(activity_payload $payload): bool {
        return self::scale_was_dropped($payload, [['scale', 'scalename']]);
    }

    /**
     * Is that kind of field installed on this site?
     *
     * @param string $type the field type, for example 'text'
     * @return bool
     */
    protected static function field_type_is_installed(string $type): bool {
        if ($type === '' || $type !== clean_param($type, PARAM_PLUGIN)) {
            return false;
        }

        return array_key_exists($type, \core_component::get_plugin_list('datafield'));
    }

    /**
     * Keep the template configuration to something that is actually data.
     *
     * mod_data stores this as JSON. Anything that is not JSON is not something
     * this site should hold, so it is dropped rather than stored unread.
     *
     * @param string $config what the source site sent
     * @return string
     */
    protected static function clean_config(string $config): string {
        if (trim($config) === '') {
            return '';
        }

        $decoded = json_decode($config, true);

        return is_array($decoded) ? json_encode($decoded) : '';
    }

    /**
     * The database settings that are carried, and what to assume without them.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'comments' => 0,
            'timeavailablefrom' => 0,
            'timeavailableto' => 0,
            'timeviewfrom' => 0,
            'timeviewto' => 0,
            'requiredentries' => 0,
            'requiredentriestoview' => 0,
            'maxentries' => 0,
            'rssarticles' => 0,
            'approval' => 0,
            'manageapproved' => 1,
            'scale' => 0,
            'assessed' => 0,
            'assesstimestart' => 0,
            'assesstimefinish' => 0,
            'defaultsort' => 0,
            'defaultsortdir' => 0,
            'editany' => 0,
            'notification' => 0,
            'completionentries' => 0,
        ];
    }
}
