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
use mod_quiz\question\display_options;

/**
 * Handles mod_quiz: the quiz's settings, not its questions.
 *
 * A quiz is the one type here whose content does not belong to it. Its
 * questions live in the question bank, and a quiz holds references into that
 * bank rather than the questions themselves. Copying those references to
 * another site would point them at whatever happened to hold the same ids
 * there, so this handler brings the quiz across empty: the settings, the dates,
 * the review rules and the access rules, with no questions in it. The teacher
 * adds questions on this site.
 *
 * That is a real limit, not an oversight, and the sync says so on every quiz it
 * creates so nobody discovers it by opening an empty quiz. Question banks are
 * their own piece of work - they are shared between activities, they have
 * categories and versions of their own, and moving them safely is a larger job
 * than moving an activity.
 *
 * The other thing to know is that quiz_add_instance() does not take a quiz as
 * it is stored. It runs the data through quiz_process_options() first, which
 * expects what the quiz settings form submits. Two things follow from that: the
 * password arrives as "quizpassword", and the eight review columns are not read
 * at all - they are rebuilt from four checkboxes each. So the stored bitmasks
 * are taken apart into those checkboxes here; see review_checkboxes().
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_handler extends activity_handler {
    /**
     * The eight things a student may be shown when reviewing an attempt.
     *
     * Each is stored as one column holding a bitmask of when it is shown.
     */
    protected const REVIEW_FIELDS = [
        'attempt',
        'correctness',
        'maxmarks',
        'marks',
        'specificfeedback',
        'generalfeedback',
        'rightanswer',
        'overallfeedback',
    ];

    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'quiz';
    }

    /**
     * SOURCE SIDE. How the quiz is set up.
     *
     * The password is deliberately not exported. It is a shared secret for
     * sitting the quiz, the destination teacher can set their own, and the
     * point of not sending it is that it does not then exist in a request, a
     * log or a response on either site.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the quiz table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        foreach (self::REVIEW_FIELDS as $field) {
            $settings['review' . $field] = (string) ($instance->{'review' . $field} ?? 0);
        }

        // A quiz's maximum grade is not a whole number: it is stored to five
        // decimal places, so it travels as a number rather than through the
        // loop above.
        $settings['grade'] = (string) (float) ($instance->grade ?? 0);

        // The settings that are words rather than numbers. Each is checked
        // against what this site has when it is read back.
        $settings['overduehandling'] = (string) ($instance->overduehandling ?? 'autoabandon');
        $settings['navmethod'] = (string) ($instance->navmethod ?? 'free');
        $settings['preferredbehaviour'] = (string) ($instance->preferredbehaviour ?? 'deferredfeedback');

        return $settings;
    }

    /**
     * DESTINATION SIDE. Build the quiz in a local course.
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

        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        // Clamped as well as cast: a negative maximum grade is not a scale here
        // the way it is in an assignment, it is simply not a grade.
        $data->grade = max(0.0, (float) $payload->setting('grade', '0'));

        $data->overduehandling = self::clean_choice(
            $payload->setting('overduehandling'),
            ['autosubmit', 'graceperiod', 'autoabandon'],
            'autoabandon'
        );
        $data->navmethod = self::clean_choice($payload->setting('navmethod'), ['free', 'sequential'], 'free');
        $data->preferredbehaviour = self::clean_behaviour($payload->setting('preferredbehaviour'));

        // A quiz with no questions has nothing to add up.
        $data->sumgrades = 0;

        // Access rule settings that name something local, or are a secret of
        // the source site, do not travel; the teacher sets them here.
        $data->quizpassword = '';
        $data->subnet = '';
        $data->browsersecurity = '-';

        foreach (self::review_checkboxes($payload) as $field => $value) {
            $data->$field = $value;
        }

        $instanceid = \quiz_add_instance($data);

        // Instead of an id, quiz_add_instance() returns a message when it
        // refuses the settings, so anything that is not a number is a failure.
        if (!$instanceid || !is_numeric($instanceid)) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Take the stored review bitmasks apart into the checkboxes the quiz wants.
     *
     * Each review column holds a bitmask of when that thing is shown: during the
     * attempt, immediately after, later while the quiz is open, and after it
     * closes. quiz_process_options() ignores the columns and rebuilds them from
     * one checkbox per moment, named for instance "marksimmediately", so the
     * bitmask has to be taken apart again on the way in. Handing over the stored
     * columns instead would silently produce a quiz that reviews nothing.
     *
     * @param activity_payload $payload what the source site sent
     * @return array<string, int> form field name => 1 for the boxes that are ticked
     */
    protected static function review_checkboxes(activity_payload $payload): array {
        $moments = [
            'during' => display_options::DURING,
            'immediately' => display_options::IMMEDIATELY_AFTER,
            'open' => display_options::LATER_WHILE_OPEN,
            'closed' => display_options::AFTER_CLOSE,
        ];

        $checkboxes = [];

        foreach (self::REVIEW_FIELDS as $field) {
            $stored = $payload->setting_int('review' . $field, 0);

            foreach ($moments as $when => $bit) {
                if ($stored & $bit) {
                    $checkboxes[$field . $when] = 1;
                }
            }
        }

        return $checkboxes;
    }

    /**
     * Keep a question behaviour to one this site actually has installed.
     *
     * @param string $behaviour what the source site sent
     * @return string
     */
    protected static function clean_behaviour(string $behaviour): string {
        $installed = array_keys(\question_engine::get_behaviour_options(''));

        return in_array($behaviour, $installed, true) ? $behaviour : 'deferredfeedback';
    }

    /**
     * Keep a setting to one of the values this site recognises.
     *
     * @param string $value what the source site sent
     * @param string[] $allowed
     * @param string $default used when the value is not one of them
     * @return string
     */
    protected static function clean_choice(string $value, array $allowed, string $default): string {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Say on every quiz that it arrived without its questions.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys to show against this activity
     */
    public function notes(activity_payload $payload): array {
        return ['syncquiznoquestions'];
    }

    /**
     * The quiz settings that are carried, and what to assume without them.
     *
     * These are all whole numbers, which is why they can be carried in a loop.
     * The ones that are words - the overdue handling, the navigation method, the
     * question behaviour - are each checked against what this site has.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'timeopen' => 0,
            'timeclose' => 0,
            'timelimit' => 0,
            'graceperiod' => 0,
            'canredoquestions' => 0,
            'attempts' => 0,
            'attemptonlast' => 0,
            'grademethod' => 1,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'questionsperpage' => 0,
            'shuffleanswers' => 0,
            'delay1' => 0,
            'delay2' => 0,
            'showuserpicture' => 0,
            'showblocks' => 0,
            'completionattemptsexhausted' => 0,
            'completionminattempts' => 0,
            'allowofflineattempts' => 0,
        ];
    }
}
