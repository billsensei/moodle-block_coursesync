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

namespace block_coursesync\local;

/**
 * The students whose grades and attempts grade sync deals in.
 *
 * One place for this because it has a trap that has bitten three times: core's
 * get_gradable_users() is built on graded_users_iterator, whose init() throws
 * "gradesneedregrading" whenever the course's gradebook is waiting to be
 * recalculated - which a fresh course is, and any course is after a change to
 * a grade setting. Final grades are stale until then anyway. So the
 * recalculation runs first, as core's own user-grades web service and the
 * grader report do.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradebook {
    /**
     * Active students in a graded role, once the gradebook is up to date.
     *
     * Core's list is also limited to the current user's groups when the
     * course has separate groups and they may not see all groups - right for
     * a teacher here, whose view that is. The source's web services ask for
     * $allgroups instead: what authorises the sync account there is
     * block/coursesync:exportgrades on the whole course, and it is rarely in
     * any group, so the limited list would be empty.
     *
     * @param int $courseid
     * @param bool $allgroups every student, whatever the current user's groups
     * @return \stdClass[] user id => user
     */
    public static function gradable_users(int $courseid, bool $allgroups = false): array {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');

        if (grade_needs_regrade_final_grades($courseid)) {
            grade_regrade_final_grades($courseid);
        }

        return $allgroups ? self::all_gradable_users($courseid) : get_gradable_users($courseid, null, true);
    }

    /**
     * Only the students a destination asked for.
     *
     * The list is one username per line, in a single parameter rather than an
     * array: a web service request with more array entries than PHP's
     * max_input_vars (1000 by default) is silently cut short, which in a big
     * course would quietly leave students' grades behind. An empty list is
     * what a destination from before v1.19.1 sends, and means every student.
     *
     * @param \stdClass[] $users user id => user
     * @param string $usernames one per line, or empty
     * @return \stdClass[] the users named, user id => user
     */
    public static function only(array $users, string $usernames): array {
        if (trim($usernames) === '') {
            return $users;
        }

        $wanted = [];

        foreach (preg_split('/\R/', $usernames) as $line) {
            $username = clean_param(trim($line), PARAM_USERNAME);

            if ($username !== '') {
                $wanted[$username] = true;
            }
        }

        return array_filter($users, static fn(\stdClass $user) => isset($wanted[$user->username]));
    }

    /**
     * Core's gradable users query (graded_users_iterator::init()) without its
     * separate-groups join: active enrolment, a gradebook role in the course
     * or above, not deleted.
     *
     * @param int $courseid
     * @return \stdClass[] user id => user
     */
    protected static function all_gradable_users(int $courseid): array {
        global $CFG, $DB;

        $context = \context_course::instance($courseid);
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
        [$rolesql, $roleparams] = $DB->get_in_or_equal(explode(',', $CFG->gradebookroles), SQL_PARAMS_NAMED, 'grbr');
        [$enrolledsql, $enrolledparams] = get_enrolled_sql($context, '', 0, true);

        return $DB->get_records_sql(
            "SELECT u.*
               FROM {user} u
               JOIN ({$enrolledsql}) je ON je.id = u.id
               JOIN (
                        SELECT DISTINCT ra.userid
                          FROM {role_assignments} ra
                         WHERE ra.roleid {$rolesql}
                           AND ra.contextid {$ctxsql}
                    ) rainner ON rainner.userid = u.id
              WHERE u.deleted = 0
           ORDER BY u.id ASC",
            array_merge($enrolledparams, $roleparams, $ctxparams)
        );
    }
}
