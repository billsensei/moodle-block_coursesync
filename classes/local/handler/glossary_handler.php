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
 * Handles mod_glossary, settings only.
 *
 * A glossary's entries are written by the people in the course and are attached
 * to them: each entry carries its author, its approval state and its own
 * attachments. So the glossary comes across set up and empty, as a forum and a
 * wiki do, and the entries stay where they were written.
 *
 * Two of its settings name something that has to exist on this site. The display
 * format is a plugin under mod/glossary/formats, and glossary_add_instance()
 * throws rather than falling back if it is given one this site does not have.
 * Grading may name a scale, which is matched by name like a forum's.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class glossary_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'glossary';
    }

    /**
     * SOURCE SIDE. How the glossary is set up.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the glossary table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        $settings['displayformat'] = (string) ($instance->displayformat ?? 'dictionary');
        $settings['approvaldisplayformat'] = (string) ($instance->approvaldisplayformat ?? 'default');
        $settings['scalename'] = self::scale_name((int) ($instance->scale ?? 0));

        return $settings;
    }

    /**
     * DESTINATION SIDE. Build the glossary in a local course.
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

        require_once($CFG->dirroot . '/mod/glossary/lib.php');

        $sectionnum = $this->target_section($course, $payload);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        $data->displayformat = self::clean_display_format($payload->setting('displayformat'));
        $data->approvaldisplayformat = self::clean_approval_format(
            $payload->setting('approvaldisplayformat')
        );

        $data->scale = self::resolve_scale(
            $payload->setting_int('scale', 0),
            $payload->setting('scalename')
        );
        // Without this, glossary_add_instance() clears the rating window.
        $data->ratingtime = ($data->assessed && ($data->assesstimestart || $data->assesstimefinish)) ? 1 : 0;

        // A glossary shared across the whole site is a site-level decision, not
        // something a course should acquire by being copied into.
        $data->globalglossary = 0;

        // Only one glossary in a course can be the main one, and the course may
        // already have it. Being copied into does not take that over.
        if (
            !empty($data->mainglossary)
                && $DB->record_exists('glossary', ['course' => $course->id, 'mainglossary' => 1])
        ) {
            $data->mainglossary = 0;
        }

        $instanceid = \glossary_add_instance($data);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Say that the copy arrived without its entries.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys to show against this activity
     */
    public function notes(activity_payload $payload): array {
        return ['syncglossarynoentries'];
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
     * Keep the display format to one this site actually has installed.
     *
     * glossary_add_instance() throws on an unknown format rather than falling
     * back, so this is checked before it is reached.
     *
     * @param string $format what the source site sent
     * @return string
     */
    protected static function clean_display_format(string $format): string {
        return self::format_is_installed($format) ? $format : 'dictionary';
    }

    /**
     * Keep the format entries are approved in to one this site has.
     *
     * This one has an extra valid answer: the word 'default', meaning whatever
     * the display format is.
     *
     * @param string $format what the source site sent
     * @return string
     */
    protected static function clean_approval_format(string $format): string {
        if ($format === 'default') {
            return 'default';
        }

        return self::format_is_installed($format) ? $format : 'default';
    }

    /**
     * Is that display format installed on this site?
     *
     * @param string $format
     * @return bool
     */
    protected static function format_is_installed(string $format): bool {
        return in_array($format, \get_list_of_plugins('mod/glossary/formats', 'TEMPLATE'), true);
    }

    /**
     * The glossary settings that are carried, and what to assume without them.
     *
     * These are all whole numbers. The two display formats and the grading
     * scale are words, and are handled on their own.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'allowduplicatedentries' => 0,
            'mainglossary' => 0,
            'showspecial' => 1,
            'showalphabet' => 1,
            'showall' => 1,
            'allowcomments' => 0,
            'allowprintview' => 1,
            'usedynalink' => 1,
            'defaultapproval' => 1,
            'entbypage' => 10,
            'editalways' => 0,
            'rsstype' => 0,
            'rssarticles' => 0,
            'assessed' => 0,
            'assesstimestart' => 0,
            'assesstimefinish' => 0,
            'scale' => 0,
            'completionentries' => 0,
        ];
    }
}
