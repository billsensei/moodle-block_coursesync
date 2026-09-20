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
 * Handles mod_forum, activity settings only.
 *
 * Discussions and posts are not synced. Note that creating a forum of type
 * "single" makes an opening post out of the description - that is how Moodle
 * builds that forum type, not this plugin copying content.
 *
 * One setting does not port cleanly. forum.scale is a site-local value: a
 * positive number is a maximum point score and means the same everywhere, but a
 * negative number is minus the id of a row in this site's scale table, and that
 * id means something different on another site. The source therefore sends the
 * scale's name alongside it, and the destination matches by name, falling back
 * to an ungraded forum and saying so rather than attaching the wrong scale.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_handler extends activity_handler {
    /**
     * Settings carried across as-is. All are plain numbers or short strings
     * whose meaning does not depend on the site they came from.
     *
     * @var string[]
     */
    private const PLAIN_SETTINGS = [
        'type',
        'duedate',
        'cutoffdate',
        'assessed',
        'assesstimestart',
        'assesstimefinish',
        'maxbytes',
        'maxattachments',
        'forcesubscribe',
        'trackingtype',
        'rsstype',
        'rssarticles',
        'warnafter',
        'blockafter',
        'blockperiod',
        'completiondiscussions',
        'completionreplies',
        'completionposts',
        'displaywordcount',
        'lockdiscussionafter',
    ];

    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'forum';
    }

    /**
     * SOURCE SIDE. The forum's own settings, plus enough context to make sense
     * of the two grading fields on another site.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the forum table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::PLAIN_SETTINGS as $name) {
            $settings[$name] = (string) ($instance->$name ?? 0);
        }

        $settings['scale'] = (string) ($instance->scale ?? 0);
        $settings['gradeforum'] = (string) ($instance->grade_forum ?? 0);
        $settings['scalename'] = self::scale_name((int) ($instance->scale ?? 0));
        $settings['gradeforumscalename'] = self::scale_name((int) ($instance->grade_forum ?? 0));

        return $settings;
    }

    /**
     * DESTINATION SIDE. Build the forum in a local course.
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

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::PLAIN_SETTINGS as $name) {
            $data->$name = $payload->setting_int($name, 0);
        }

        // The forum type is the one plain setting that is a word, not a number.
        // Only the types this site knows about are accepted; anything else
        // becomes a general forum rather than an unknown value in the database.
        $data->type = self::clean_type($payload->setting('type', 'general'));

        $data->scale = self::resolve_scale(
            $payload->setting_int('scale', 0),
            $payload->setting('scalename')
        );
        $data->grade_forum = self::resolve_scale(
            $payload->setting_int('gradeforum', 0),
            $payload->setting('gradeforumscalename')
        );
        $data->grade_forum_notify = 0;

        $instanceid = \forum_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Keep the forum type to one this site actually implements.
     *
     * @param string $type what the source site sent
     * @return string
     */
    protected static function clean_type(string $type): string {
        $known = array_keys(\forum_get_forum_types_all());

        return in_array($type, $known, true) ? $type : 'general';
    }

    /**
     * Did a grading setting have to be dropped because its scale is not here?
     *
     * @param activity_payload $payload what the source site sent
     * @return bool
     */
    public function lost_scale(activity_payload $payload): bool {
        return self::scale_was_dropped($payload, [
            ['scale', 'scalename'],
            ['gradeforum', 'gradeforumscalename'],
        ]);
    }
}
