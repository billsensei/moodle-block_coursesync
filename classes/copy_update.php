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

namespace block_coursesync;

use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\userlist;

/**
 * Bringing an already-synced activity up to date with the source.
 *
 * No handler updates an activity in place - every one of them only knows how
 * to create. So an update is always a fresh copy, and what happens to the old
 * one depends on whether anybody has done anything in it:
 *
 * - **Nobody has**: the fresh copy takes the old one's place and the old one
 *   is deleted (into the recycle bin, where that is enabled). Whatever this
 *   course set up around the old copy - where it sits, whether it is shown,
 *   its access restrictions and completion settings, its grade category, and
 *   other activities' restrictions that point at it - is carried across, so
 *   the course looks the same apart from the content.
 * - **Somebody has**: nothing about the old copy is touched except that it
 *   stops being the tracked copy. The fresh one is added straight after it,
 *   named as a new edition, and carries the identity from then on. Deleting
 *   people's attempts, submissions or grades to make room for a newer version
 *   is never this plugin's decision to make.
 *
 * "Anybody has done anything" is asked of the activity's own privacy
 * provider, the one place every activity type already declares what it
 * holds about people - so this needs nothing per type, and a type that stores
 * nothing about anyone (a page, a URL) says so there. Completion progress
 * is counted too, because it is recorded against the course module and would
 * go with it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class copy_update {
    /**
     * Does anybody have anything recorded in this activity?
     *
     * @param int $cmid a course module in this site
     * @return bool
     */
    public static function has_people_data(int $cmid): bool {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $component = 'mod_' . $cm->modname;

        // Anything the activity itself declares it holds about someone:
        // attempts, submissions, posts, answers, grades, overrides.
        $userlist = new userlist($context, $component);
        \core_privacy\manager::component_class_callback(
            $component,
            core_userlist_provider::class,
            'get_users_in_context',
            [$userlist]
        );

        if (count($userlist) > 0) {
            return true;
        }

        // Progress recorded against the course module itself, which the
        // activity's own provider does not report.
        $completed = $DB->record_exists_select(
            'course_modules_completion',
            'coursemoduleid = ? AND completionstate > 0',
            [$cm->id]
        );

        if ($completed) {
            return true;
        }

        // A question bank's provider declares nothing, because its questions
        // are core_question's. What would be lost with it is a question added
        // here rather than synced, or one a quiz in this site now uses.
        if ($cm->modname === 'qbank') {
            return self::qbank_has_local_use($context);
        }

        return false;
    }

    /**
     * Has a question bank been added to locally, or are its questions in use?
     *
     * @param \context $context the question bank's module context
     * @return bool
     */
    protected static function qbank_has_local_use(\context $context): bool {
        global $DB;

        $local = $DB->record_exists_sql(
            "SELECT 1
               FROM {question_bank_entries} qbe
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qc.contextid = :contextid
                AND (qbe.idnumber IS NULL OR qbe.idnumber NOT LIKE :synced)",
            ['contextid' => $context->id, 'synced' => 'coursesync-%']
        );

        if ($local) {
            return true;
        }

        $fixed = $DB->record_exists_sql(
            "SELECT 1
               FROM {question_references} qr
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qc.contextid = :contextid",
            ['contextid' => $context->id]
        );

        return $fixed || $DB->record_exists('question_set_references', ['questionscontextid' => $context->id]);
    }

    /**
     * Give the fresh copy the place and set-up the old copy has in this course.
     *
     * None of this came from the source: the fresh copy was created with the
     * source's defaults, and these are the choices this course made since.
     *
     * @param \stdClass $old the old copy's course_modules record
     * @param \stdClass $new the fresh copy's course_modules record
     * @param bool $before true to sit in the old copy's place, false to sit straight after it
     * @return void
     */
    public static function take_local_setup(\stdClass $old, \stdClass $new, bool $before): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $section = $DB->get_record('course_sections', ['id' => $old->section], '*', MUST_EXIST);
        $beforemod = $before ? $old : self::next_in_section($section, (int) $old->id);

        // Moving needs the module as modinfo knows it (its type, current
        // section and visibility), not just its row.
        \rebuild_course_cache($old->course, true);
        \moveto_module(get_fast_modinfo($old->course)->get_cm($new->id), $section, $beforemod);

        $DB->update_record('course_modules', (object) [
            'id' => $new->id,
            'availability' => $old->availability,
            'completion' => $old->completion,
            'completionview' => $old->completionview,
            'completionexpected' => $old->completionexpected,
            'completiongradeitemnumber' => $old->completiongradeitemnumber,
            'completionpassgrade' => $old->completionpassgrade,
        ]);

        \set_coursemodule_visible($new->id, $old->visible, $old->visibleoncoursepage, false);

        self::take_grade_categories($old, $new);

        \rebuild_course_cache($old->course, true);
    }

    /**
     * Point everything in this course that referred to the old copy at the new one.
     *
     * Only for a replacement - a new edition sits alongside the old copy, which
     * keeps what refers to it.
     *
     * @param int $courseid
     * @param int $oldcmid
     * @param int $newcmid
     * @return void
     */
    public static function repoint_references(int $courseid, int $oldcmid, int $newcmid): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        \core_availability\info::update_dependency_id_across_course($courseid, 'course_modules', $oldcmid, $newcmid);

        $DB->set_field('course_completion_criteria', 'moduleinstance', $newcmid, [
            'course' => $courseid,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'moduleinstance' => $oldcmid,
        ]);
    }

    /**
     * Stamp a course module, and its main grade item, with an ID number.
     *
     * The two are kept the same, as Moodle's own edit form keeps them.
     *
     * @param int $cmid
     * @param string $idnumber an empty string clears it
     * @return void
     */
    public static function set_identity(int $cmid, string $idnumber): void {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);

        $DB->set_field('course_modules', 'idnumber', $idnumber, ['id' => $cm->id]);
        $DB->set_field('grade_items', 'idnumber', $idnumber === '' ? null : $idnumber, [
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber' => 0,
        ]);

        \rebuild_course_cache($cm->course, true);
    }

    /**
     * Name a fresh copy as a new edition, here and in the gradebook.
     *
     * @param int $cmid
     * @return string the new name
     */
    public static function name_as_new_edition(int $cmid): string {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $oldname = (string) $DB->get_field($cm->modname, 'name', ['id' => $cm->instance], MUST_EXIST);
        $newname = get_string('synceditionname', 'block_coursesync', $oldname);

        $DB->set_field($cm->modname, 'name', $newname, ['id' => $cm->instance]);

        // A grade item is named after its activity, sometimes with more after
        // it (a workshop's two are "... (submission)" and "... (assessment)").
        $items = $DB->get_records('grade_items', [
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
        ]);

        foreach ($items as $item) {
            if ($item->itemname !== null && str_starts_with($item->itemname, $oldname)) {
                $DB->set_field('grade_items', 'itemname', $newname . substr($item->itemname, strlen($oldname)), [
                    'id' => $item->id,
                ]);
            }
        }

        \rebuild_course_cache($cm->course, true);
        \core\event\course_module_updated::create_from_cm(get_fast_modinfo($cm->course)->get_cm($cm->id))->trigger();

        return $newname;
    }

    /**
     * The course module that follows another in its section, if any.
     *
     * @param \stdClass $section
     * @param int $cmid
     * @return \stdClass|null
     */
    protected static function next_in_section(\stdClass $section, int $cmid): ?\stdClass {
        global $DB;

        $sequence = array_map('intval', array_filter(explode(',', (string) $section->sequence)));
        $position = array_search($cmid, $sequence, true);

        if ($position === false || !isset($sequence[$position + 1])) {
            return null;
        }

        return $DB->get_record('course_modules', ['id' => $sequence[$position + 1]]) ?: null;
    }

    /**
     * Put each of the fresh copy's grade items in the category its
     * counterpart on the old copy was moved to.
     *
     * @param \stdClass $old
     * @param \stdClass $new
     * @return void
     */
    protected static function take_grade_categories(\stdClass $old, \stdClass $new): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');

        $modname = $DB->get_field('modules', 'name', ['id' => $old->module], MUST_EXIST);
        $fetch = static fn(\stdClass $cm) => \grade_item::fetch_all([
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $cm->instance,
        ]) ?: [];

        $categories = [];

        foreach ($fetch($old) as $item) {
            $categories[(int) $item->itemnumber] = (int) $item->categoryid;
        }

        foreach ($fetch($new) as $item) {
            $wanted = $categories[(int) $item->itemnumber] ?? 0;

            if ($wanted > 0 && $wanted !== (int) $item->categoryid) {
                $item->set_parent($wanted);
            }
        }
    }
}
