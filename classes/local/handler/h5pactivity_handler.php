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
 * Handles mod_h5pactivity, including the uploaded H5P package.
 *
 * An H5P activity is a settings row and one file: the .h5p package holding the
 * interactive content. The package carries its own libraries, so the copy works
 * on the destination without anything being installed first - core_h5p unpacks
 * and deploys it the first time somebody opens it, exactly as it would for a
 * package uploaded by hand.
 *
 * What does not come across is what anybody did with it. Attempts and their
 * results are the work of the people in that course, and they stay there.
 *
 * Two settings name something this site has to recognise: how attempts are
 * turned into a grade, and when a student may review their answers. Both are
 * checked against mod_h5pactivity's own lists. Grading may also name a scale,
 * which is matched by name like a forum's.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'h5pactivity';
    }

    /**
     * The package is the activity, and there is exactly one of it.
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'package', 'itemid' => 0],
        ];
    }

    /**
     * SOURCE SIDE. How the activity is set up.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the h5pactivity table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        $settings['scalename'] = self::scale_name((int) ($instance->grade ?? 0));

        return $settings;
    }

    /**
     * DESTINATION SIDE. Build the activity in a local course.
     *
     * The package is written afterwards by the syncer, for the reason every
     * activity with files is: a file area is addressed by the module's context
     * id, and that context does not exist until the course module does.
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

        require_once($CFG->dirroot . '/mod/h5pactivity/lib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        $data->grademethod = self::clean_grade_method($payload->setting_int('grademethod', 1));
        $data->reviewmode = self::clean_review_mode($payload->setting_int('reviewmode', 1));
        $data->grade = self::resolve_scale(
            $payload->setting_int('grade', 0),
            $payload->setting('scalename')
        );

        // Read as a draft area id to move the package from. There is no form and
        // no draft area here, so it is told there is nothing to move and the
        // package is written straight into the real area afterwards.
        $data->packagefile = 0;

        $instanceid = \h5pactivity_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Refuse an activity whose package did not come with it.
     *
     * An H5P activity is its package. Without it there is nothing to open, and
     * a copy that exists but cannot be opened would be marked as synced and
     * never looked at again.
     *
     * @param activity_payload $payload what the source site sent
     * @return string|null a message key, or null if the payload is usable
     */
    public function check_payload(activity_payload $payload): ?string {
        $problem = parent::check_payload($payload);

        if ($problem !== null) {
            return $problem;
        }

        // Its own package, specifically: a description image is a file too.
        return $payload->has_file_in('package', 0) ? null : 'errornopackage';
    }

    /**
     * Say that the copy arrived without anybody's attempts.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys to show against this activity
     */
    public function notes(activity_payload $payload): array {
        return ['synch5pnoattempts'];
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
     * Keep the grading method to one mod_h5pactivity offers.
     *
     * @param int $method what the source site sent
     * @return int
     */
    protected static function clean_grade_method(int $method): int {
        $known = array_keys(\mod_h5pactivity\local\manager::get_grading_methods());

        return in_array($method, $known, true)
            ? $method
            : \mod_h5pactivity\local\manager::GRADEHIGHESTATTEMPT;
    }

    /**
     * Keep the review mode to one mod_h5pactivity offers.
     *
     * @param int $mode what the source site sent
     * @return int
     */
    protected static function clean_review_mode(int $mode): int {
        $known = array_keys(\mod_h5pactivity\local\manager::get_review_modes());

        return in_array($mode, $known, true)
            ? $mode
            : \mod_h5pactivity\local\manager::REVIEWCOMPLETION;
    }

    /**
     * The settings that are carried, and what to assume without them.
     *
     * The grading method and review mode are in here so they travel, and are
     * then checked against this site's lists on the way in.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'grade' => 0,
            'displayoptions' => 0,
            'enabletracking' => 1,
            'grademethod' => 1,
            'reviewmode' => 1,
        ];
    }
}
