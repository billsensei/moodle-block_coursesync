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
 * Base class for handling one activity type, at both ends of a sync.
 *
 * ---------------------------------------------------------------------------
 * ADDING A NEW ACTIVITY TYPE
 * ---------------------------------------------------------------------------
 *
 * Everything an activity type needs lives in one subclass of this file. To add
 * support for, say, mod_url:
 *
 *   1. Create classes/local/handler/url_handler.php extending this class.
 *   2. Implement the four abstract methods below.
 *   3. Add the class name to handler_registry::HANDLERS.
 *
 * Nothing else has to change. The external function, the syncer, the block UI
 * and the language strings are all type-agnostic: they ask the registry which
 * types are supported and hand the work to whichever handler answers.
 *
 * ---------------------------------------------------------------------------
 * HOW THE TWO ENDS FIT TOGETHER
 * ---------------------------------------------------------------------------
 *
 * The same handler class runs on both sites, doing a different half of the job
 * at each end:
 *
 *   SOURCE                                  DESTINATION
 *   ------                                  -----------
 *   export_settings()                       create_from_remote_data()
 *     reads the activity's own table          builds the activity locally
 *     returns a flat name => value map        from the payload it is given
 *
 * The payload travels as a fixed envelope (name, intro, introformat, section,
 * visible) plus a bag of type-specific settings. Keeping the type-specific part
 * as a flat string map is what lets one external function serve every activity
 * type without its return structure changing when a type is added.
 *
 * Settings values are converted to strings in transit, so implementations must
 * cast on the way back in. A handler should not assume a setting is present:
 * a payload may come from a site running an older version of this plugin.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class activity_handler {
    /**
     * The activity type this handler is responsible for, e.g. 'page'.
     *
     * Must match the directory name under mod/ and the value of
     * course_modules.modname for that type.
     *
     * @return string
     */
    abstract public static function get_modname(): string;

    /**
     * SOURCE SIDE. Collect the type-specific settings needed to rebuild this
     * activity somewhere else.
     *
     * The common fields - name, intro, introformat, visible, section - are
     * collected by the external function and must not be repeated here. Return
     * only what is particular to this activity type.
     *
     * Values must be scalars. Anything structured should be encoded, and
     * decoded again in create_from_remote_data().
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the activity's own table
     * @return array setting name => scalar value
     */
    abstract public function export_settings(\cm_info $cm, \stdClass $instance): array;

    /**
     * DESTINATION SIDE. Create the activity in a local course.
     *
     * Implementations are responsible for the whole creation sequence, which
     * for a typical activity is:
     *
     *   1. build the course_modules record and insert it with add_course_module()
     *   2. call {modname}_add_instance() with the settings from the payload
     *   3. place it with course_add_cm_to_section()
     *   4. rebuild_course_cache() and fire course_module_created
     *
     * create_course_module() and finish_creation() below do steps 1, 3 and 4 so
     * a handler normally only has to assemble the data for step 2.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload what the source site sent
     * @param string $idnumber the ID number to stamp on the new activity, used
     *                         to recognise it as already synced next time
     * @return \stdClass the new course_modules record
     */
    abstract public function create_from_remote_data(
        \stdClass $course,
        activity_payload $payload,
        string $idnumber
    ): \stdClass;

    /**
     * SOURCE AND DESTINATION. Which file areas belong to this activity type.
     *
     * A handler that returns areas here gets its files collected on the source
     * and written back on the destination without writing any transfer code:
     * the external function lists whatever is in these areas, and file_sync
     * fetches and stores them.
     *
     * The component is always "mod_" plus the activity type, so only the area
     * and item id are named here. Return an empty array for an activity type
     * that keeps no files, which is the default.
     *
     * Declaring an area is also what authorises reading it. The source refuses
     * to hand over a file that is not in an area its handler has declared, so a
     * caller cannot use this to read arbitrary files.
     *
     * An area whose files are keyed by something that differs per activity - a
     * book's chapters, say - declares 'anyitemid' => true instead of naming an
     * item id, and implements map_file_itemid() to say where each file belongs
     * once the activity has been rebuilt here.
     *
     * An area that belongs to a subplugin rather than to the activity itself -
     * a workshop grading strategy's criterion descriptions, say - also names
     * its 'component'. Without one, the component is "mod_" plus the type.
     *
     * The description every activity has ('intro') is not declared here: every
     * type has it, so file_areas() adds it for all of them.
     *
     * @return array[] each entry ['filearea' => string, 'itemid' => int] or
     *                 ['filearea' => string, 'anyitemid' => true], optionally
     *                 with 'component' => string
     */
    public function get_file_areas(): array {
        return [];
    }

    /**
     * SOURCE AND DESTINATION. Every file area this activity's files may be in.
     *
     * The description's own area first, which every activity type has and
     * whose embedded files - an image in a page's description, or anywhere in
     * a text and media area, whose description is all it has - would otherwise
     * arrive as broken links. Then whatever the handler declared, each with its
     * component filled in.
     *
     * @return array[] as get_file_areas(), always with 'component'
     */
    final public function file_areas(): array {
        $default = 'mod_' . static::get_modname();
        $areas = [['component' => $default, 'filearea' => 'intro', 'itemid' => 0]];

        foreach ($this->get_file_areas() as $area) {
            $areas[] = ['component' => (string) ($area['component'] ?? $default)] + $area;
        }

        return $areas;
    }

    /**
     * SOURCE AND DESTINATION. Is a file in this place one this activity declares?
     *
     * On the source it is what keeps the file function from being a way to read
     * any file at all. Here it is what keeps a source from writing a file into
     * any area it names.
     *
     * @param string $component
     * @param string $filearea
     * @param int|null $itemid null to accept any item id in the area
     * @return bool
     */
    final public function declares_file_area(string $component, string $filearea, ?int $itemid = null): bool {
        foreach ($this->file_areas() as $area) {
            if ($area['component'] !== $component || ($area['filearea'] ?? '') !== $filearea) {
                continue;
            }

            // An area keyed by child records cannot name its item ids up front,
            // so the area itself is what the handler vouches for. That is still
            // one named area of one activity the caller may already read.
            if ($itemid === null || !empty($area['anyitemid']) || (int) ($area['itemid'] ?? 0) === $itemid) {
                return true;
            }
        }

        return false;
    }

    /**
     * DESTINATION SIDE. The item id a file is stored under here.
     *
     * The description's files are always item 0, whatever the type, so they
     * are settled here rather than left to every map_file_itemid() - several
     * of which only know about their own child records' areas.
     *
     * @param activity_payload $payload
     * @param array $file the file's metadata from the payload
     * @param \stdClass $cm the course module just created here
     * @return int|null null to leave the file out
     */
    final public function local_file_itemid(activity_payload $payload, array $file, \stdClass $cm): ?int {
        if (($file['component'] ?? '') === 'mod_' . static::get_modname() && ($file['filearea'] ?? '') === 'intro') {
            return 0;
        }

        return $this->map_file_itemid($payload, $file, $cm);
    }

    /**
     * @var array<string, array<int, int>> Record id on the source site => id here,
     *      grouped by what kind of record it is. Filled while an activity is built.
     */
    private array $idmap = [];

    /**
     * Remember which local record was created for one of the source's.
     *
     * An activity that is more than a single row usually has those rows
     * referring to each other: a lesson page points at the next one, a feedback
     * question depends on another question, a rubric level belongs to a
     * criterion. Those references are ids, and ids are local to the site they
     * came from, so every one has to be translated before it means anything
     * here.
     *
     * The pattern is always the same: create the records, remembering each
     * pairing, then go back and fix the references once every id is known - a
     * reference can point forward as easily as backward.
     *
     * @param string $kind what sort of record, for example 'page'
     * @param int $remoteid the id on the source site
     * @param int $localid the id here
     * @return void
     */
    protected function remember_id(string $kind, int $remoteid, int $localid): void {
        if ($remoteid > 0) {
            $this->idmap[$kind][$remoteid] = $localid;
        }
    }

    /**
     * The local record created for one of the source's, if there is one.
     *
     * @param string $kind what sort of record, for example 'page'
     * @param int $remoteid the id on the source site
     * @return int|null null when nothing was created for it
     */
    protected function local_id(string $kind, int $remoteid): ?int {
        return $this->idmap[$kind][$remoteid] ?? null;
    }

    /**
     * Translate a reference to another record, keeping a missing one harmless.
     *
     * @param string $kind what sort of record the reference points at
     * @param int $remoteid the id on the source site, or 0 for no reference
     * @return int the id here, or 0 when there is nothing to point at
     */
    protected function mapped_id(string $kind, int $remoteid): int {
        return $remoteid > 0 ? ($this->local_id($kind, $remoteid) ?? 0) : 0;
    }

    /**
     * SOURCE SIDE. The child records that belong to this activity.
     *
     * Most activities are one row and have none. A book is its chapters and an
     * assignment carries a row per submission and feedback plugin setting, so
     * those travel alongside the settings as a flat list, which is what lets
     * one external function carry every activity type unchanged.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the activity's own table
     * @return array[] each ['type' => string, 'sortorder' => int, 'fields' => [name => scalar]]
     */
    public function export_children(\cm_info $cm, \stdClass $instance): array {
        return [];
    }

    /**
     * DESTINATION SIDE. Where one of the source's files belongs on this site.
     *
     * Most activities keep their files under a fixed item id, so the default
     * keeps whatever the source used. An activity whose files hang off child
     * records - a book's chapters - has to translate the source's ids into the
     * ones this site just created.
     *
     * @param activity_payload $payload what the source site sent
     * @param array $file the file's metadata from the payload
     * @param \stdClass $cm the course module just created here
     * @return int|null the local item id, or null to leave the file out
     */
    public function map_file_itemid(activity_payload $payload, array $file, \stdClass $cm): ?int {
        return (int) ($file['itemid'] ?? 0);
    }

    /**
     * DESTINATION SIDE. Anything that has to happen once the files are in place.
     *
     * Called after create_from_remote_data() and after any files have been
     * copied. A resource uses it to mark which file it opens; most types do not
     * need it.
     *
     * @param \stdClass $cm the course module
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    public function post_files(\stdClass $cm, activity_payload $payload): void {
    }

    /**
     * Whether this handler can rebuild the payload it has been given.
     *
     * The default accepts anything of the right activity type. Override to
     * refuse payloads a handler cannot faithfully reproduce - for instance one
     * that depends on a subplugin this site does not have.
     *
     * @param activity_payload $payload
     * @return string|null a language string identifier explaining the refusal,
     *                     or null if the payload can be rebuilt
     */
    public function check_payload(activity_payload $payload): ?string {
        if ($payload->modname !== static::get_modname()) {
            return 'errorwronghandler';
        }

        return null;
    }

    /**
     * Step 1 of creation: insert the course_modules row.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload
     * @param string $idnumber
     * @return int the new course module id
     */
    protected function create_course_module(\stdClass $course, activity_payload $payload, string $idnumber): int {
        global $CFG, $DB;

        // The functions add_course_module() and course_add_cm_to_section() live in course/lib.php,
        // which Moodle only loads as a side effect of other calls - get_fast_modinfo()
        // pulls it in, for instance. A handler must not depend on something else
        // having happened to load it first.
        require_once($CFG->dirroot . '/course/lib.php');

        $module = $DB->get_record('modules', ['name' => static::get_modname()], '*', MUST_EXIST);

        $newcm = new \stdClass();
        $newcm->course = $course->id;
        $newcm->module = $module->id;
        $newcm->instance = 0;
        $newcm->section = 0;
        $newcm->idnumber = $idnumber;
        $newcm->visible = $payload->visible ? 1 : 0;
        $newcm->visibleold = $newcm->visible;
        $newcm->visibleoncoursepage = 1;
        $newcm->groupmode = 0;
        $newcm->groupingid = 0;
        $newcm->completion = 0;
        $newcm->completionview = 0;
        $newcm->completionexpected = 0;
        $newcm->completiongradeitemnumber = null;
        $newcm->showdescription = 0;

        $cmid = \add_course_module($newcm);

        if (!$cmid) {
            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        return $cmid;
    }

    /**
     * The fields every module's add-instance function expects.
     *
     * Handlers should start from this and add their own settings, rather than
     * assembling it by hand. In particular it sets cmidnumber, which several
     * modules read when they create a grade item - mod_forum among them - and
     * which is easy to leave out because nothing complains until a warning
     * appears deep inside another module.
     *
     * @param \stdClass $course the destination course
     * @param int $cmid the course module just created
     * @param activity_payload $payload what the source site sent
     * @param string $idnumber the ID number stamped on the course module
     * @return \stdClass
     */
    protected function make_instance_data(
        \stdClass $course,
        int $cmid,
        activity_payload $payload,
        string $idnumber
    ): \stdClass {
        $data = new \stdClass();
        $data->course = $course->id;
        $data->coursemodule = $cmid;
        $data->cmidnumber = $idnumber;
        $data->name = $payload->name;
        $data->intro = $payload->intro;
        $data->introformat = $payload->introformat;
        $data->visible = $payload->visible ? 1 : 0;
        $data->completion = 0;
        $data->completionview = 0;
        $data->completionexpected = 0;

        return $data;
    }

    /**
     * Steps 3 and 4 of creation: place the activity and tell Moodle about it.    /**
     * Steps 3 and 4 of creation: place the activity and tell Moodle about it.
     *
     * @param \stdClass $course the destination course
     * @param int $cmid the new course module id
     * @param int $sectionnum which section to drop it in
     * @return \stdClass the course_modules record
     */
    protected function finish_creation(\stdClass $course, int $cmid, int $sectionnum): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        \course_add_cm_to_section($course, $cmid, $sectionnum);
        \rebuild_course_cache($course->id, true);

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $modcontext = \context_module::instance($cmid);

        $event = \core\event\course_module_created::create([
            'courseid' => $course->id,
            'context' => $modcontext,
            'objectid' => $cmid,
            'other' => [
                'modulename' => static::get_modname(),
                'instanceid' => $cm->instance,
                'name' => $DB->get_field(static::get_modname(), 'name', ['id' => $cm->instance]),
            ],
        ]);
        $event->trigger();

        return $cm;
    }

    /**
     * Anything a teacher should be told about this activity once it is created.
     *
     * A handler that cannot bring part of an activity across says so here, and
     * the run reports it against that activity. Each entry is either a
     * language string key in this plugin, or a [key, $a] pair when the string
     * needs a parameter (a count, say). Render either shape with
     * sync_result::describe_note() rather than calling get_string() directly.
     *
     * @param activity_payload $payload what the source site sent
     * @return array<string|array{0:string,1:mixed}>
     */
    public function notes(activity_payload $payload): array {
        return [];
    }

    /**
     * Whether this handler can rebuild the payload in this particular course.
     *
     * check_payload() is what can be told from the activity alone; this is
     * for what depends on where it is going - whether this course can use
     * the external tool it needs, say. Asked before anything is created, so
     * a refusal here is an expected outcome, not a failure partway through.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload
     * @return string|null a language string identifier explaining the refusal,
     *                     or null if the payload can be rebuilt here
     */
    public function check_destination(\stdClass $course, activity_payload $payload): ?string {
        return null;
    }

    /**
     * Is a changed copy of this type updated where it stands, rather than
     * replaced by a fresh copy (see copy_update)?
     *
     * Only for a type whose replacement would take more with it than itself:
     * a subsection's contents go when it does.
     *
     * @return bool
     */
    public function updates_in_place(): bool {
        return false;
    }

    /**
     * DESTINATION SIDE. Bring a copy up to date where it stands.
     *
     * Only called when updates_in_place() says so.
     *
     * @param \stdClass $cm the copy here
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    public function update_in_place(\stdClass $cm, activity_payload $payload): void {
        throw new \coding_exception(static::class . ' does not update in place');
    }

    /**
     * Anything a person needs, beyond the reason, to act on a refusal.
     *
     * A failure is reported by a fixed message; this is for what the message
     * cannot know - which external tool an administrator would have to add,
     * say. Same shape as notes().
     *
     * @param activity_payload $payload what the source site sent
     * @param string $reason the language string identifier the activity failed with
     * @return array<string|array{0:string,1:mixed}>
     */
    public function failure_notes(activity_payload $payload, string $reason): array {
        return [];
    }

    /**
     * Did anything have to be left out because this site cannot express it?
     *
     * A grading scale is the usual case: it is named on the source site and may
     * simply not exist here. The activity is still created, but the run says so
     * rather than quietly changing how it is graded.
     *
     * @param activity_payload $payload what the source site sent
     * @return bool
     */
    public function lost_scale(activity_payload $payload): bool {
        return false;
    }

    /**
     * The name of a scale, when a grading field refers to one.
     *
     * A grading value is site-local: a positive number is a maximum point score
     * and means the same anywhere, but a negative number is minus the id of a
     * row in this site's scale table, which means something different
     * elsewhere. Sending the name alongside is what lets the other end match it.
     *
     * @param int $value a grading value: positive is points, negative is minus a scale id
     * @return string the scale's name, or an empty string when no scale is involved
     */
    public static function scale_name(int $value): string {
        global $DB;

        if ($value >= 0) {
            return '';
        }

        return (string) $DB->get_field('scale', 'name', ['id' => -$value], IGNORE_MISSING);
    }

    /**
     * Turn a grading value from another site into one that means the same here.
     *
     * @param int $value the value the source site sent
     * @param string $scalename the name of the scale it referred to, if any
     * @return int a grading value for this site, or 0 for ungraded
     */
    public static function resolve_scale(int $value, string $scalename): int {
        global $DB;

        if ($value >= 0) {
            return $value;
        }

        if ($scalename === '') {
            return 0;
        }

        $localid = $DB->get_field('scale', 'id', ['name' => $scalename], IGNORE_MISSING);

        // No scale of that name here, so grading is dropped rather than pointed
        // at whatever scale happens to hold that id.
        return $localid ? -((int) $localid) : 0;
    }

    /**
     * Did a grading setting have to be dropped because its scale is not here?
     *
     * @param activity_payload $payload
     * @param array[] $fields pairs of [value setting name, scale name setting name]
     * @return bool
     */
    public static function scale_was_dropped(activity_payload $payload, array $fields): bool {
        foreach ($fields as [$valuekey, $namekey]) {
            $value = $payload->setting_int($valuekey, 0);

            if ($value < 0 && self::resolve_scale($value, $payload->setting($namekey)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which section the activity should land in.    /**
     * Which section the activity should land in.
     *
     * The source's section number is used where the destination course has one,
     * so a course that mirrors another keeps its shape. Anything beyond the end
     * of the destination course goes into the last section that exists, rather
     * than creating sections as a side effect of syncing.
     *
     * @param \stdClass $course the destination course
     * @param int $wanted the section number on the source site
     * @return int
     */
    protected function resolve_section(\stdClass $course, int $wanted): int {
        global $DB;

        if ($wanted < 0) {
            return 0;
        }

        // Ordinary sections only. A delegated section - a subsection's - is
        // numbered after them, so clamping to the highest number of all could
        // put the activity inside some unrelated subsection.
        $maxsection = (int) $DB->get_field_sql(
            'SELECT MAX(section) FROM {course_sections} WHERE course = ? AND component IS NULL',
            [$course->id]
        );

        return min($wanted, $maxsection);
    }

    /**
     * DESTINATION SIDE. The section number to create an activity in.
     *
     * In this course's copy of the subsection that holds it on the source, if
     * that has been copied here; otherwise in the ordinary section it (or its
     * subsection) is in, as resolve_section() finds it.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload
     * @return int
     */
    protected function target_section(\stdClass $course, activity_payload $payload): int {
        $subsection = self::local_subsection_section($course, $payload);

        return $subsection ?? $this->resolve_section($course, $payload->sectionnum);
    }

    /**
     * The section number of this course's copy of the payload's subsection.
     *
     * @param \stdClass $course
     * @param activity_payload $payload
     * @return int|null null when the activity is in no subsection, or its
     *                  subsection has not been copied here
     */
    public static function local_subsection_section(\stdClass $course, activity_payload $payload): ?int {
        if ($payload->subsectioncmid === 0) {
            return null;
        }

        $cmid = \block_coursesync\syncer::find_existing((int) $course->id, $payload->subsectioncmid);

        if ($cmid === 0) {
            return null;
        }

        $modinfo = get_fast_modinfo($course->id);
        $cm = $modinfo->get_cm($cmid);

        if ($cm->modname !== 'subsection') {
            return null;
        }

        $section = $modinfo->get_section_info_by_component('mod_subsection', (int) $cm->instance);

        return $section ? (int) $section->section : null;
    }
}
