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
 * Handles mod_wiki, settings only.
 *
 * What a wiki is for is what the people in the course write in it, and that is
 * their work, not the teacher's. So this brings across the empty wiki - its
 * name, its description, its mode, its first page title and its editing window
 * - and none of the pages or versions inside it, exactly as mod_forum brings a
 * forum without its discussions.
 *
 * A wiki in individual mode gives every student their own copy, which is made
 * for them when they first open it, so nothing needs carrying across for that
 * to keep working here.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wiki_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'wiki';
    }

    /**
     * SOURCE SIDE. How the wiki is set up.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the wiki table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        return [
            'firstpagetitle' => (string) ($instance->firstpagetitle ?? ''),
            'wikimode' => (string) ($instance->wikimode ?? 'collaborative'),
            'defaultformat' => (string) ($instance->defaultformat ?? 'html'),
            'forceformat' => (string) ($instance->forceformat ?? 1),
            'editbegin' => (string) ($instance->editbegin ?? 0),
            'editend' => (string) ($instance->editend ?? 0),
        ];
    }

    /**
     * DESTINATION SIDE. Build the wiki in a local course.
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

        require_once($CFG->dirroot . '/mod/wiki/lib.php');

        $sectionnum = $this->target_section($course, $payload);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);
        $data->firstpagetitle = self::clean_first_page_title($payload->setting('firstpagetitle'));
        $data->wikimode = self::clean_mode($payload->setting('wikimode'));
        $data->defaultformat = self::clean_format($payload->setting('defaultformat'));
        $data->forceformat = $payload->setting_int('forceformat', 1) ? 1 : 0;
        $data->editbegin = $payload->setting_int('editbegin', 0);
        $data->editend = $payload->setting_int('editend', 0);

        $instanceid = \wiki_add_instance($data);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Say on every wiki that it arrived without its pages.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys to show against this activity
     */
    public function notes(activity_payload $payload): array {
        return ['syncwikinopages'];
    }

    /**
     * Keep the wiki mode to one this site recognises.
     *
     * @param string $mode what the source site sent
     * @return string
     */
    protected static function clean_mode(string $mode): string {
        return in_array($mode, ['collaborative', 'individual'], true) ? $mode : 'collaborative';
    }

    /**
     * Keep the page format to one this site recognises.
     *
     * @param string $format what the source site sent
     * @return string
     */
    protected static function clean_format(string $format): string {
        return in_array($format, ['html', 'creole', 'nwiki'], true) ? $format : 'html';
    }

    /**
     * A wiki cannot start without a first page title.
     *
     * @param string $title what the source site sent
     * @return string
     */
    protected static function clean_first_page_title(string $title): string {
        $title = trim(clean_param($title, PARAM_TEXT));

        return $title === '' ? get_string('wikifirstpagedefault', 'block_coursesync') : $title;
    }
}
