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

/**
 * Lists the activities a remote site may pull from a course here.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\external;

use block_coursesync\local\activity_signature;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Describes every activity in a course, with a signal saying when it last changed.
 *
 * This is the provider half of a pull. It reports only what the token's user
 * may actually see: the course access check comes from validate_context(), and
 * each module is filtered on uservisible, so hidden or restricted activities
 * are not disclosed.
 */
class list_activities extends external_api {
    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Id of the course to list activities from'),
        ]);
    }

    /**
     * Lists the course's activities.
     *
     * @param int $courseid Id of the course to list activities from.
     * @return array The course id and its activities.
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);

        // Enforces that this token's user may access the course at all.
        self::validate_context($context);
        require_capability('moodle/backup:backupactivity', $context);

        // What may be listed is governed by the capability to back activities up,
        // not by whether the account could take part in them. A service account
        // scoped to this integration deliberately has no student or teacher role,
        // so it has no mod_*:view capabilities and $cm->uservisible would hide
        // everything from it.
        $canviewhidden = has_capability('moodle/course:viewhiddenactivities', $context);

        $activities = [];
        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->visible && !$canviewhidden) {
                continue;
            }

            if (!has_capability('moodle/backup:backupactivity', \context_module::instance($cm->id))) {
                continue;
            }

            $signature = activity_signature::for_instance($cm->modname, (int) $cm->instance);

            $activities[] = [
                'cmid' => (int) $cm->id,
                'modname' => $cm->modname,
                'name' => \core_external\util::format_string($cm->name, $context),
                'sectionnum' => self::ordinary_sectionnum($cm),
                'idnumber' => (string) $cm->idnumber,
                'signal' => $signature['signal'],
                'signalmethod' => $signature['method'],
                'backupsupported' => (bool) plugin_supports('mod', $cm->modname, FEATURE_BACKUP_MOODLE2),
            ];
        }

        return [
            'courseid' => (int) $course->id,
            'activities' => $activities,
        ];
    }

    /**
     * The number of the ordinary section an activity appears in.
     *
     * An activity inside a subsection belongs to a section delegated to that
     * subsection, and that section's number means nothing to a course which
     * has no such subsection. The number of the ordinary section the
     * subsection itself sits in is reported instead, so that a course pulling
     * the activity can still put it somewhere recognisable.
     *
     * @param \cm_info $cm The activity.
     * @return int A section number that an ordinary course can have.
     */
    private static function ordinary_sectionnum(\cm_info $cm): int {
        $section = $cm->get_section_info();

        // Subsections can sit inside subsections, so this walks up. The depth
        // limit is only a guard against a delegate that points back at itself.
        for ($depth = 0; $section && $section->is_delegated() && $depth < 10; $depth++) {
            $section = $section->get_component_instance()?->get_parent_section();
        }

        return $section ? (int) $section->sectionnum : 0;
    }

    /**
     * Describes the return value for execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Id of the course listed'),
            'activities' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id on this site'),
                    'modname' => new external_value(PARAM_PLUGIN, 'Activity module type'),
                    'name' => new external_value(PARAM_TEXT, 'Activity name'),
                    'sectionnum' => new external_value(
                        PARAM_INT,
                        'Number of the ordinary section the activity appears in; for one inside a subsection, '
                            . 'the section that subsection sits in'
                    ),
                    'idnumber' => new external_value(PARAM_RAW, 'Activity id number, empty if unset'),
                    'signal' => new external_value(PARAM_RAW, 'Value that changes when the activity changes'),
                    'signalmethod' => new external_value(PARAM_ALPHA, 'How the signal was derived: timemodified or hash'),
                    'backupsupported' => new external_value(PARAM_BOOL, 'Whether this module type can be backed up'),
                ])
            ),
        ]);
    }
}
