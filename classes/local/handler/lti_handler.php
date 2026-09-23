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
 * Handles mod_lti: an external tool activity.
 *
 * An external tool activity is a link to a tool that an administrator set up
 * on each site separately - its address, its keys, and what it is allowed to
 * be told about people (their names, their email addresses). The activity
 * says which tool; the tool says what it gets. So the copy is only ever
 * linked to a tool this site's administrator already set up, found by the
 * same address matching Moodle uses when an activity is launched
 * (lti_get_tool_by_url_match(), which also respects site, course and category
 * scope). Nothing about the source's tool is created here, and whatever the
 * activity asked to send is then held to what this site's tool allows
 * (lti_force_type_config_settings(), inside lti_add_instance()). No student's
 * details ever go to a service this site's administrator did not approve.
 *
 * Without a matching tool the activity is refused, naming the tool an
 * administrator would need to add. A refusal is a failure, so it is offered
 * again: once the tool is added, the next sync brings the activity across.
 *
 * Secrets never travel. An activity set up with its own key and secret rather
 * than a site tool cannot work without them, so it is refused too.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lti_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'lti';
    }

    /**
     * SOURCE SIDE. Where the activity points, how it launches, and which
     * tool it uses - described, since the tool's own id means nothing on
     * another site. Never the key or the secret.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the lti table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        global $DB;

        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        foreach (['toolurl', 'securetoolurl', 'instructorcustomparameters', 'icon', 'secureicon'] as $field) {
            $settings[$field] = (string) ($instance->$field ?? '');
        }

        $settings['scalename'] = self::scale_name((int) ($instance->grade ?? 0));

        // Whether it depends on a key and secret of its own, not what they are.
        $settings['hasownsecret'] = empty($instance->typeid)
            && ((string) ($instance->password ?? '') !== '' || (string) ($instance->resourcekey ?? '') !== '') ? '1' : '0';

        $type = empty($instance->typeid) ? false : $DB->get_record('lti_types', ['id' => $instance->typeid]);
        $settings['toolname'] = $type ? (string) $type->name : '';
        $settings['toolbaseurl'] = $type ? (string) $type->baseurl : '';

        return $settings;
    }

    /**
     * DESTINATION SIDE. Link the activity to the matching tool here.
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

        require_once($CFG->dirroot . '/mod/lti/lib.php');
        require_once($CFG->dirroot . '/mod/lti/locallib.php');

        // Asked already by check_destination(); asked again because nothing
        // else here may be created without it.
        $tool = self::matching_tool($payload, (int) $course->id);

        if ($tool === null) {
            throw new \moodle_exception('errorltinotool', 'block_coursesync');
        }

        $sectionnum = $this->target_section($course, $payload);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        $data->typeid = (int) $tool->id;
        $data->toolurl = self::clean_url($payload->setting('toolurl'));
        $data->securetoolurl = self::clean_url($payload->setting('securetoolurl'));
        $data->icon = self::clean_url($payload->setting('icon'));
        $data->secureicon = self::clean_url($payload->setting('secureicon'));
        $data->instructorcustomparameters = clean_param($payload->setting('instructorcustomparameters'), PARAM_TEXT);
        $data->resourcekey = '';
        $data->password = '';
        $data->debuglaunch = 0;
        $data->grade = self::resolve_scale($payload->setting_int('grade', 0), $payload->setting('scalename'));

        $instanceid = \lti_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * The tool this site's administrator set up for the activity's address.
     *
     * The activity's own address if it has one, else its tool's, matched as
     * Moodle matches at launch: configured tools only, for this site or this
     * course, by domain, the closest address winning.
     *
     * @param activity_payload $payload
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function matching_tool(activity_payload $payload, int $courseid): ?\stdClass {
        global $CFG;

        require_once($CFG->dirroot . '/mod/lti/locallib.php');

        $url = self::clean_url($payload->setting('toolurl')) ?: self::clean_url($payload->setting('toolbaseurl'));

        if ($url === '') {
            return null;
        }

        return \lti_get_tool_by_url_match($url, $courseid) ?: null;
    }

    /**
     * Refuse an activity that only works with a key and secret of its own.
     *
     * Whether this site has a matching tool depends on the course, so that is
     * check_destination(); this is what can be told from the activity alone.
     *
     * @param activity_payload $payload what the source site sent
     * @return string|null a message key, or null if the payload is usable
     */
    public function check_payload(activity_payload $payload): ?string {
        $problem = parent::check_payload($payload);

        if ($problem !== null) {
            return $problem;
        }

        return $payload->setting_int('hasownsecret', 0) ? 'errorltiownsecret' : null;
    }

    /**
     * Refuse an activity this course has no matching tool for.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload
     * @return string|null
     */
    public function check_destination(\stdClass $course, activity_payload $payload): ?string {
        return self::matching_tool($payload, (int) $course->id) === null ? 'errorltinotool' : null;
    }

    /**
     * Name the tool an administrator would have to add.
     *
     * @param activity_payload $payload what the source site sent
     * @param string $reason
     * @return array
     */
    public function failure_notes(activity_payload $payload, string $reason): array {
        if ($reason !== 'errorltinotool') {
            return [];
        }

        $url = self::clean_url($payload->setting('toolbaseurl')) ?: self::clean_url($payload->setting('toolurl'));
        $name = clean_param($payload->setting('toolname'), PARAM_TEXT);

        return [['syncltitoolneeded', $name === '' ? $url : $name . ' (' . $url . ')']];
    }

    /**
     * What a teacher should know about the copy.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys
     */
    public function notes(activity_payload $payload): array {
        return ['syncltiusestoolhere'];
    }

    /**
     * Was a grading scale dropped because this site does not have it?
     *
     * @param activity_payload $payload what the source site sent
     * @return bool
     */
    public function lost_scale(activity_payload $payload): bool {
        return self::scale_was_dropped($payload, [['grade', 'scalename']]);
    }

    /**
     * An address, as long as it is a web one.
     *
     * @param string $url
     * @return string
     */
    protected static function clean_url(string $url): string {
        $url = clean_param($url, PARAM_URL);

        return preg_match('~^https?://~i', $url) ? $url : '';
    }

    /**
     * The settings that are carried, and what to assume without them.
     *
     * What to send about people (name, email, roster) is carried as the
     * teacher chose it; lti_add_instance() then holds it to what this site's
     * tool allows.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'instructorchoicesendname' => 0,
            'instructorchoicesendemailaddr' => 0,
            'instructorchoiceallowroster' => 0,
            'instructorchoiceallowsetting' => 0,
            'instructorchoiceacceptgrades' => 0,
            'grade' => 0,
            'launchcontainer' => 1,
            'showtitlelaunch' => 0,
            'showdescriptionlaunch' => 0,
        ];
    }
}
