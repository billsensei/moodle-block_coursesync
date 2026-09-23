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
 * Handles mod_scorm: a SCORM or AICC package and how it is presented.
 *
 * Only the package travels, not what Moodle made of it. On the source the
 * package (the uploaded zip, in the 'package' area) has been unpacked into
 * the 'content' area and parsed into the scorm_scoes tables; none of that is
 * copied. Here the package is written into the new activity, and then
 * scorm_parse() - the same call scorm_add_instance() makes for an upload -
 * unpacks and parses it on this site. That has to wait for post_files(),
 * because the package cannot be written until the activity's context exists.
 *
 * Then the copy is checked the way it would be opened: a package that parsed
 * as 'ERROR', or that gave no SCO to launch, is not a working activity, and
 * is refused rather than reported as copied. A package arriving intact is a
 * transfer test; this is the functional one.
 *
 * Only an uploaded package can be copied. A SCORM kept as a link to a file
 * elsewhere ("external" or "AICC URL") has no package to send, so it is
 * refused with a message saying so. One kept in sync with a URL
 * ("localsync") does have its latest package here, so it is copied as an
 * uploaded one - and says that it will no longer update from that URL.
 *
 * What does not come across is anybody's attempts or tracking data.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'scorm';
    }

    /**
     * The package, and only the package: its unpacked content is rebuilt here.
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'package', 'itemid' => 0],
        ];
    }

    /**
     * SOURCE SIDE. How the package is presented and graded.
     *
     * The popup window's options are stored as one string of name=value
     * pairs; they are sent one by one, so scorm_add_instance() can rebuild
     * the string the way this site's version stores it.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the scorm table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        $settings['scormtype'] = (string) ($instance->scormtype ?? 'local');
        $settings['maxgrade'] = (string) (float) ($instance->maxgrade ?? 100);

        foreach (explode(',', (string) ($instance->options ?? '')) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '0');

            if ($name !== '') {
                $settings['popup_' . $name] = (string) (int) $value;
            }
        }

        return $settings;
    }

    /**
     * DESTINATION SIDE. Build the activity; post_files() unpacks the package.
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

        require_once($CFG->dirroot . '/mod/scorm/lib.php');
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        $data->maxgrade = max(0.0, (float) $payload->setting('maxgrade', '100'));
        $data->grademethod = self::clean_choice($data->grademethod, \scorm_get_grade_method_array(), GRADESCOES);
        $data->whatgrade = self::clean_choice($data->whatgrade, \scorm_get_what_grade_array(), HIGHESTATTEMPT);
        $data->skipview = self::clean_choice($data->skipview, \scorm_get_skip_view_array(), SCORM_SKIPVIEW_NEVER);
        $data->hidetoc = self::clean_choice($data->hidetoc, \scorm_get_hidetoc_array(), SCORM_TOC_SIDE);
        $data->nav = self::clean_choice($data->nav, \scorm_get_navigation_display_array(), SCORM_NAV_UNDER_CONTENT);
        $data->displayattemptstatus = self::clean_choice(
            $data->displayattemptstatus,
            \scorm_get_attemptstatus_array(),
            SCORM_DISPLAY_ATTEMPTSTATUS_ALL
        );
        $data->forcenewattempt = self::clean_choice($data->forcenewattempt, \scorm_get_forceattempt_array(), 0);
        $data->popup = $data->popup ? 1 : 0;
        $data->maxattempt = max(0, $data->maxattempt);

        // Named one by one, as the form would; scorm_option2text() joins them.
        foreach (array_keys(\scorm_get_popup_options_array()) as $option) {
            $data->$option = $payload->setting_int('popup_' . $option, 0);
        }

        // An uploaded package, whatever the source kept it as; see the class
        // comment. No draft area to move it from: it arrives afterwards.
        $data->scormtype = SCORM_TYPE_LOCAL;
        $data->packagefile = '';
        $data->updatefreq = 0;

        $instanceid = \scorm_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * DESTINATION SIDE. Unpack and parse the package that has just arrived,
     * and refuse a copy that would not open.
     *
     * An exception here is caught by the syncer, which takes the half-made
     * activity back out and reports the failure.
     *
     * @param \stdClass $cm the course module
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    public function post_files(\stdClass $cm, activity_payload $payload): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $context = \context_module::instance($cm->id);
        $files = get_file_storage()->get_area_files($context->id, 'mod_scorm', 'package', 0, 'id', false);
        $package = reset($files);

        if (!$package) {
            throw new \moodle_exception('errornopackage', 'block_coursesync');
        }

        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);
        $scorm->reference = $package->get_filename();
        $DB->set_field('scorm', 'reference', $scorm->reference, ['id' => $scorm->id]);

        $scorm->cmid = $cm->id;
        \scorm_parse($scorm, true);

        // What opening it needs: a package that parsed, and a SCO to launch.
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);

        if ($scorm->version === 'ERROR' || !$DB->record_exists('scorm_scoes', ['id' => $scorm->launch, 'scorm' => $scorm->id])) {
            throw new \moodle_exception('errorpackagenotdeployed', 'block_coursesync');
        }
    }

    /**
     * Refuse a SCORM that has no package to send.
     *
     * @param activity_payload $payload what the source site sent
     * @return string|null a message key, or null if the payload is usable
     */
    public function check_payload(activity_payload $payload): ?string {
        $problem = parent::check_payload($payload);

        if ($problem !== null) {
            return $problem;
        }

        if (!in_array($payload->setting('scormtype', 'local'), ['local', 'localsync'], true)) {
            return 'errorscormnotuploaded';
        }

        return $payload->has_file_in('package', 0) ? null : 'errornopackage';
    }

    /**
     * What a teacher should know about the copy.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys
     */
    public function notes(activity_payload $payload): array {
        $notes = ['syncscormnoattempts'];

        if ($payload->setting('scormtype') === 'localsync') {
            $notes[] = 'syncscormnolongersynced';
        }

        return $notes;
    }

    /**
     * Keep a setting to one this site's SCORM module offers.
     *
     * @param int $value what the source site sent
     * @param array $allowed this site's options, keyed by value
     * @param int $default what to use otherwise
     * @return int
     */
    protected static function clean_choice(int $value, array $allowed, int $default): int {
        return array_key_exists($value, $allowed) ? $value : $default;
    }

    /**
     * The settings that are carried, and what to assume without them.
     *
     * These are all whole numbers. The ones that name a choice from a list
     * are checked against this site's lists in create_from_remote_data().
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'grademethod' => 1,
            'whatgrade' => 0,
            'maxattempt' => 0,
            'forcecompleted' => 0,
            'forcenewattempt' => 0,
            'lastattemptlock' => 0,
            'masteryoverride' => 1,
            'displayattemptstatus' => 1,
            'displaycoursestructure' => 0,
            'skipview' => 0,
            'hidebrowse' => 0,
            'hidetoc' => 0,
            'nav' => 1,
            'navpositionleft' => -100,
            'navpositiontop' => -100,
            'auto' => 0,
            'popup' => 0,
            'width' => 100,
            'height' => 500,
            'timeopen' => 0,
            'timeclose' => 0,
            'completionstatusrequired' => 0,
            'completionscorerequired' => 0,
            'completionstatusallscos' => 0,
            'autocommit' => 0,
        ];
    }
}
