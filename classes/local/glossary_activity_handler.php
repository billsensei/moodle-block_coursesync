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
 * Recreates a mod_glossary instance AND its entries from
 * glossary_activity_exporter's payload.
 *
 * `glossary_add_instance()` does NOT set course_modules.instance itself -
 * same quirk as url/label/forum/assign - so that's done explicitly below,
 * following the same pattern as every other activity_handler. It also
 * throws if handed a `displayformat`/`approvaldisplayformat` it doesn't
 * recognise (it calls `get_list_of_plugins('mod/glossary/formats', ...)`
 * itself and rejects anything not in that list) - unlike a plain enum
 * column this plugin's own sanitizer::* methods can validate against a
 * fixed known set, this needs the DESTINATION site's own installed format
 * plugins, so sanitize_displayformat() below checks against that instead.
 *
 * Every entry is created via `glossary_edit_entry()` (mod/glossary/lib.php)
 * - the same function the "add entry" web service and the glossary's own
 * "Add a new entry" form both call - which:
 * - attributes the entry to whoever is running Sync now (`$USER`), the same
 *   as any other content this plugin creates isn't attributed to the
 *   original remote author (that user doesn't exist on this site);
 * - auto-approves it if the destination glossary's own defaultapproval
 *   setting or the current user's own mod/glossary:approve capability says
 *   so - the normal "who can post without moderation" rule, applied to the
 *   Sync now user like any other content they create;
 * - moves definitionfiles/attachments out of draft areas into their final
 *   place itself, and writes the glossary_entries_categories/glossary_alias
 *   rows - so this handler only ever needs to stage draft areas and build
 *   the $entry object, never touch those tables directly.
 *
 * Categories are created first (create_categories()), in the same order
 * they were exported, so each entry's own `categoryindexes` (positions into
 * that list - see the exporter's docblock for why position, not id) can be
 * resolved to the new destination category ids before any entry is created.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class glossary_activity_handler implements activity_handler {
    /**
     * Creates the glossary course module, instance, categories, and entries.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from glossary_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/glossary/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'glossary'], MUST_EXIST);

        $newcm = new \stdClass();
        $newcm->course = $courseid;
        $newcm->module = $moduleid;
        $newcm->instance = 0;
        $newcm->section = 0;
        $newcm->idnumber = $idnumber;
        $newcm->visible = 1;
        $newcm->visibleold = 1;
        $newcm->visibleoncoursepage = 1;
        $newcm->groupmode = 0;
        $newcm->groupingid = 0;
        $newcm->completion = 0;
        $newcm->showdescription = 0;

        $cmid = add_course_module($newcm);

        $glossarydata = $this->build_glossary_data($courseid, $cmid, $idnumber, $data);
        $glossaryid = glossary_add_instance($glossarydata);
        // This call does NOT set course_modules.instance for $cmid - done explicitly below.
        $DB->set_field('course_modules', 'instance', $glossaryid, ['id' => $cmid]);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'glossary');
        rebuild_course_cache($courseid, true);

        $this->create_entries($courseid, $cmid, $glossaryid, $data['categories'] ?? [], $data['entries'] ?? []);

        return $cmid;
    }

    /**
     * Builds the glossary row's own settings.
     *
     * @param int $courseid
     * @param int $cmid
     * @param string $idnumber
     * @param array $data
     * @return \stdClass
     */
    protected function build_glossary_data(int $courseid, int $cmid, string $idnumber, array $data): \stdClass {
        $glossary = new \stdClass();
        $glossary->course = $courseid;
        $glossary->coursemodule = $cmid;
        // Core's glossary_grade_item_update() (called by glossary_add_instance())
        // reads ->cmidnumber directly with no isset() guard - always present
        // on a real form submission, same as forum_activity_handler's own
        // ->cmidnumber. Mirrors the course_modules idnumber.
        $glossary->cmidnumber = $idnumber;
        $glossary->name = sanitizer::text($data['name'] ?? '');
        $glossary->intro = sanitizer::html($data['intro'] ?? '');
        $glossary->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $glossary->allowduplicatedentries = sanitizer::integer($data['allowduplicatedentries'] ?? 0);
        $glossary->displayformat = $this->sanitize_displayformat($data['displayformat'] ?? 'dictionary', false);
        $glossary->showspecial = sanitizer::integer($data['showspecial'] ?? 1, 1);
        $glossary->showalphabet = sanitizer::integer($data['showalphabet'] ?? 1, 1);
        $glossary->showall = sanitizer::integer($data['showall'] ?? 1, 1);
        $glossary->allowcomments = sanitizer::integer($data['allowcomments'] ?? 0);
        $glossary->allowprintview = sanitizer::integer($data['allowprintview'] ?? 1, 1);
        $glossary->usedynalink = sanitizer::integer($data['usedynalink'] ?? 1, 1);
        $glossary->defaultapproval = sanitizer::integer($data['defaultapproval'] ?? 1, 1);
        $glossary->approvaldisplayformat = $this->sanitize_displayformat($data['approvaldisplayformat'] ?? 'default', true);
        // Never carried over from the remote site - see exporter's docblock.
        $glossary->globalglossary = 0;
        $glossary->mainglossary = 0;
        $glossary->entbypage = sanitizer::integer($data['entbypage'] ?? 10, 10);
        $glossary->editalways = sanitizer::integer($data['editalways'] ?? 0);
        $glossary->rsstype = 0;
        $glossary->rssarticles = 0;
        // Ratings are never synced (no data to rate anyway - see exporter's docblock).
        $glossary->assessed = 0;
        $glossary->assesstimestart = 0;
        $glossary->assesstimefinish = 0;
        $glossary->scale = 0;
        $glossary->completionentries = 0;

        return $glossary;
    }

    /**
     * Validates a display format against the DESTINATION site's own
     * installed mod/glossary/formats plugins - the same check
     * glossary_add_instance() itself makes (and throws on failure), so this
     * runs it first and substitutes a safe default instead of ever handing
     * it an unrecognised value.
     *
     * @param mixed $value
     * @param bool $allowdefault Whether "default" (approvaldisplayformat only) is also valid.
     * @return string
     */
    protected function sanitize_displayformat($value, bool $allowdefault): string {
        $value = (string) $value;
        $known = get_list_of_plugins('mod/glossary/formats', 'TEMPLATE');

        if ($allowdefault && $value === 'default') {
            return 'default';
        }

        return in_array($value, $known, true) ? $value : ($allowdefault ? 'default' : 'dictionary');
    }

    /**
     * Creates every category, then every entry, in source order.
     *
     * @param int $courseid
     * @param int $cmid
     * @param int $glossaryid
     * @param array $categories From glossary_activity_exporter::export_categories().
     * @param array $entries From glossary_activity_exporter::export_entries().
     */
    protected function create_entries(int $courseid, int $cmid, int $glossaryid, array $categories, array $entries): void {
        global $DB;

        if (empty($entries)) {
            return;
        }

        $context = \context_module::instance($cmid);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('glossary', $cmid, 0, false, MUST_EXIST);
        $glossary = $DB->get_record('glossary', ['id' => $glossaryid], '*', MUST_EXIST);

        $categoryids = $this->create_categories($glossaryid, $categories);

        foreach ($entries as $entrydata) {
            $entry = new \stdClass();
            // Explicit null, same as the real add_entry web service
            // (classes/external.php) does - glossary_edit_entry() checks
            // empty($entry->id) to decide "new vs. update", and
            // glossary_get_editor_and_attachment_options() reads ->id
            // (for a subdirs check) before that decision is even made.
            $entry->id = null;
            $entry->concept = sanitizer::text($entrydata['concept'] ?? '');
            $entry->definition_editor = [
                'text' => sanitizer::html($entrydata['definition'] ?? ''),
                'format' => sanitizer::textformat($entrydata['definitionformat'] ?? FORMAT_HTML),
                'itemid' => $this->store_files_in_draft_area($entrydata['definitionfiles'] ?? []),
            ];

            $attachmentitemid = $this->store_files_in_draft_area($entrydata['attachments'] ?? []);
            if ($attachmentitemid) {
                $entry->attachment_filemanager = $attachmentitemid;
            }

            $entry->usedynalink = sanitizer::integer($entrydata['usedynalink'] ?? 1, 1);
            $entry->casesensitive = sanitizer::integer($entrydata['casesensitive'] ?? 0);
            $entry->fullmatch = sanitizer::integer($entrydata['fullmatch'] ?? 1, 1);
            $entry->aliases = implode("\n", array_map(
                fn($alias): string => sanitizer::text($alias),
                $entrydata['aliases'] ?? []
            ));

            $entry->categories = [];
            foreach ($entrydata['categoryindexes'] ?? [] as $index) {
                if (isset($categoryids[$index])) {
                    $entry->categories[] = $categoryids[$index];
                }
            }

            glossary_edit_entry($entry, $course, $cm, $glossary, $context);
        }
    }

    /**
     * Creates every category, in source order, returning a position => new
     * id map for create_entries() to resolve categoryindexes against.
     *
     * @param int $glossaryid
     * @param array $categories From glossary_activity_exporter::export_categories().
     * @return array<int, int>
     */
    protected function create_categories(int $glossaryid, array $categories): array {
        global $DB;

        $ids = [];
        foreach (array_values($categories) as $index => $categorydata) {
            $category = new \stdClass();
            $category->glossaryid = $glossaryid;
            $category->name = sanitizer::text($categorydata['name'] ?? '');
            $category->usedynalink = sanitizer::integer($categorydata['usedynalink'] ?? 1, 1);
            $ids[$index] = $DB->insert_record('glossary_categories', $category);
        }

        return $ids;
    }

    /**
     * Writes exported files into a fresh draft area belonging to the
     * current user, ready for glossary_edit_entry()'s own
     * file_postupdate_standard_editor()/file_postupdate_standard_filemanager()
     * calls to move into their final place - same pattern as
     * h5pactivity_activity_handler's store_package_in_draft_area(), just
     * supporting more than one file (a glossary entry's definition or
     * attachments can have several).
     *
     * @param array<int, array{filename: string, contentbase64: string}> $files
     * @return int A draft itemid with the files in it, or 0 (falsy) if $files is empty.
     */
    protected function store_files_in_draft_area(array $files): int {
        if (empty($files)) {
            return 0;
        }

        global $USER;

        $usercontext = \context_user::instance($USER->id);
        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();

        foreach ($files as $filedata) {
            $content = base64_decode($filedata['contentbase64'] ?? '', true);
            if ($content === false) {
                // Malformed payload for this one file - skip it rather than
                // fail the whole entry, same as resource_activity_handler
                // does per-file.
                continue;
            }

            $fs->create_file_from_string([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => sanitizer::filename($filedata['filename'] ?? 'file'),
            ], $content);
        }

        return $draftitemid;
    }
}
