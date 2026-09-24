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

        // A grade in the gradebook, which a teacher can enter or override
        // there without the activity itself knowing.
        $graded = $DB->record_exists_sql(
            "SELECT 1
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.courseid = :courseid
                AND gi.itemtype = 'mod'
                AND gi.itemmodule = :modname
                AND gi.iteminstance = :instance
                AND (gg.finalgrade IS NOT NULL OR gg.rawgrade IS NOT NULL OR gg.overridden > 0)",
            ['courseid' => $cm->course, 'modname' => $cm->modname, 'instance' => $cm->instance]
        );

        if ($graded) {
            return true;
        }

        if (self::has_group_overrides($cm)) {
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

    /** @var array<string, string[]> Module => [overrides table, column naming the instance]. */
    public const OVERRIDE_TABLES = [
        'assign' => ['assign_overrides', 'assignid'],
        'lesson' => ['lesson_overrides', 'lessonid'],
        'quiz' => ['quiz_overrides', 'quiz'],
    ];

    /**
     * Has this course set different dates or limits for a group here?
     *
     * A user override is someone's data and the module's privacy provider
     * already reports it. A group override is not about any one person, so no
     * provider does - but it is still this course's work (an extension agreed
     * with a class), and deleting the old copy would delete it. Treated as
     * people's data so the old copy is kept rather than replaced.
     *
     * @param \stdClass $cm
     * @return bool
     */
    protected static function has_group_overrides(\stdClass $cm): bool {
        global $DB;

        if (!isset(self::OVERRIDE_TABLES[$cm->modname])) {
            return false;
        }

        [$table, $column] = self::OVERRIDE_TABLES[$cm->modname];

        return $DB->record_exists_select($table, "{$column} = ? AND groupid IS NOT NULL", [$cm->instance]);
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
     * The course_modules fields that are this course's choices rather than
     * the source's, carried from the old copy to the new one. Visibility is
     * carried separately (set_coursemodule_visible() keeps its related
     * fields in step).
     *
     * @var string[]
     */
    public const LOCAL_SETUP_FIELDS = [
        'availability',
        'completion',
        'completionview',
        'completionexpected',
        'completiongradeitemnumber',
        'completionpassgrade',
        'groupmode',
        'groupingid',
        'indent',
        'showdescription',
        'downloadcontent',
        'lang',
        'enableaitools',
        'enabledaiactions',
    ];

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

        $update = (object) ['id' => $new->id];

        foreach (self::LOCAL_SETUP_FIELDS as $field) {
            // Some are newer than others; a site without one has nothing to carry.
            if (property_exists($old, $field)) {
                $update->$field = $old->$field;
            }
        }

        $DB->update_record('course_modules', $update);

        \set_coursemodule_visible($new->id, $old->visible, $old->visibleoncoursepage, false);

        self::take_grade_categories($old, $new);
        self::take_permissions($old, $new);

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
        return self::rename($cmid, static fn(string $name): string => get_string('synceditionname', 'block_coursesync', $name));
    }

    /**
     * Name a fresh copy as a copy of what is already here, here and in the
     * gradebook.
     *
     * "Name (copy)" the first time, then "Name (copy 2)" and so on, so that
     * copying the same activity again never leaves two with the same name.
     *
     * @param int $cmid
     * @return string the new name
     */
    public static function name_as_copy(int $cmid): string {
        return self::rename($cmid, static function (string $name) use ($cmid): string {
            $taken = [];
            $courseid = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST)->course;

            foreach (get_fast_modinfo($courseid)->get_cms() as $other) {
                if ((int) $other->id !== $cmid) {
                    $taken[$other->name] = true;
                }
            }

            $candidate = get_string('synccopyname', 'block_coursesync', $name);

            for ($n = 2; isset($taken[$candidate]); $n++) {
                $candidate = get_string('synccopynamenumbered', 'block_coursesync', (object) [
                    'name' => $name,
                    'number' => $n,
                ]);
            }

            return $candidate;
        });
    }

    /**
     * Rename an activity, and its grade items with it.
     *
     * @param int $cmid
     * @param callable $newname given the current name, returns the new one
     * @return string the new name
     */
    protected static function rename(int $cmid, callable $newname): string {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $oldname = (string) $DB->get_field($cm->modname, 'name', ['id' => $cm->instance], MUST_EXIST);
        $newname = $newname($oldname);

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
     * Give the new copy the permission overrides and local role assignments
     * the old copy had in this course.
     *
     * "Students may not post here", or a student made a moderator of one
     * forum: set on the activity's own context, so they would go with it.
     * Only roles assigned by hand (no component) are carried; ones an
     * enrolment plugin or another component manages are theirs to keep.
     *
     * @param \stdClass $old the old copy's course_modules record
     * @param \stdClass $new the fresh copy's course_modules record
     * @return void
     */
    protected static function take_permissions(\stdClass $old, \stdClass $new): void {
        global $DB;

        $oldcontext = \context_module::instance($old->id);
        $newcontext = \context_module::instance($new->id);

        foreach ($DB->get_records('role_capabilities', ['contextid' => $oldcontext->id]) as $override) {
            assign_capability($override->capability, $override->permission, $override->roleid, $newcontext->id, true);
        }

        foreach ($DB->get_records('role_assignments', ['contextid' => $oldcontext->id, 'component' => '']) as $assignment) {
            role_assign($assignment->roleid, $assignment->userid, $newcontext->id);
        }
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
