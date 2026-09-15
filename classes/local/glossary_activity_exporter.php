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
 * Exports a mod_glossary instance's settings AND its entries - unlike
 * forum/assign (settings only), a glossary's whole point IS its entries, so
 * settings-only would be as unsatisfying as Quiz without its questions (see
 * quiz_activity_exporter's docblock for that same lesson). Exports:
 *
 * - The glossary's own settings (display format, entries-per-page, ...).
 * - Every APPROVED entry (concept, definition and its embedded files,
 *   attachment files, aliases, and which category/categories it's in) -
 *   an entry still awaiting moderation on the source is a draft, not
 *   published content, so it's left out, same spirit as a forum's
 *   unposted content never being in scope.
 * - Every category (name only - glossary_categories has no content of its
 *   own beyond a name and the usedynalink flag).
 *
 * Categories are exported as a plain ordered list and each entry references
 * the ones it belongs to by POSITION in that list (`categoryindexes`), not
 * by the source's own category id - the destination creates fresh
 * categories with entirely new ids, so the source ids are meaningless
 * there; position is simple, unambiguous (unlike matching by name, where
 * two categories could share a name), and needs no id-remapping step.
 *
 * Not exported: ratings/comments on entries (never synced - same
 * user-generated-content cut as forum posts/assignment submissions), and
 * the global-glossary flag (a sitewide "usable from any course" designation
 * that's this SITE's own admin decision, not something a copy on a
 * different site should inherit automatically).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class glossary_activity_exporter implements activity_exporter {
    /**
     * Builds the payload glossary_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $DB;

        $glossary = $DB->get_record('glossary', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        [$categories, $categoryindexbyid] = $this->export_categories((int) $glossary->id);

        return [
            'name' => $glossary->name,
            'intro' => (string) $glossary->intro,
            'introformat' => (int) $glossary->introformat,
            'allowduplicatedentries' => (int) $glossary->allowduplicatedentries,
            'displayformat' => (string) $glossary->displayformat,
            'showspecial' => (int) $glossary->showspecial,
            'showalphabet' => (int) $glossary->showalphabet,
            'showall' => (int) $glossary->showall,
            'allowcomments' => (int) $glossary->allowcomments,
            'allowprintview' => (int) $glossary->allowprintview,
            'usedynalink' => (int) $glossary->usedynalink,
            'defaultapproval' => (int) $glossary->defaultapproval,
            'approvaldisplayformat' => (string) $glossary->approvaldisplayformat,
            'entbypage' => (int) $glossary->entbypage,
            'editalways' => (int) $glossary->editalways,
            'categories' => $categories,
            'entries' => $this->export_entries((int) $glossary->id, $context, $categoryindexbyid),
        ];
    }

    /**
     * Exports every category as a plain {name, usedynalink} list, alongside
     * a sourcecategoryid => position lookup for export_entries() to resolve
     * each entry's own category membership against - see class docblock for
     * why position, not id, travels in the payload itself.
     *
     * @param int $glossaryid
     * @return array{0: array<int, array{name: string, usedynalink: int}>, 1: array<int, int>}
     */
    protected function export_categories(int $glossaryid): array {
        global $DB;

        $categories = $DB->get_records('glossary_categories', ['glossaryid' => $glossaryid], 'id ASC');

        $exported = [];
        $indexbyid = [];
        foreach (array_values($categories) as $index => $category) {
            $exported[] = [
                'name' => (string) $category->name,
                'usedynalink' => (int) $category->usedynalink,
            ];
            $indexbyid[(int) $category->id] = $index;
        }

        return [$exported, $indexbyid];
    }

    /**
     * Exports every approved entry.
     *
     * @param int $glossaryid
     * @param \context_module $context
     * @param array<int, int> $categoryindexbyid From export_categories().
     * @return array<int, array>
     */
    protected function export_entries(int $glossaryid, \context_module $context, array $categoryindexbyid): array {
        global $DB;

        $entries = $DB->get_records('glossary_entries', ['glossaryid' => $glossaryid, 'approved' => 1], 'concept ASC');

        $exported = [];
        foreach ($entries as $entry) {
            $categoryindexes = [];
            $entrycategories = $DB->get_records('glossary_entries_categories', ['entryid' => $entry->id]);
            foreach ($entrycategories as $entrycategory) {
                if (isset($categoryindexbyid[(int) $entrycategory->categoryid])) {
                    $categoryindexes[] = $categoryindexbyid[(int) $entrycategory->categoryid];
                }
            }

            $exported[] = [
                'concept' => (string) $entry->concept,
                'definition' => (string) $entry->definition,
                'definitionformat' => (int) $entry->definitionformat,
                'definitionfiles' => $this->export_files($context->id, 'entry', (int) $entry->id),
                'attachments' => $this->export_files($context->id, 'attachment', (int) $entry->id),
                'usedynalink' => (int) $entry->usedynalink,
                'casesensitive' => (int) $entry->casesensitive,
                'fullmatch' => (int) $entry->fullmatch,
                'aliases' => $this->export_aliases((int) $entry->id),
                'categoryindexes' => $categoryindexes,
            ];
        }

        return $exported;
    }

    /**
     * Exports every alias of one entry, in a plain ordered list.
     *
     * @param int $entryid
     * @return array<int, string>
     */
    protected function export_aliases(int $entryid): array {
        global $DB;

        $aliases = $DB->get_records('glossary_alias', ['entryid' => $entryid], 'id ASC');

        return array_values(array_map(fn($alias): string => (string) $alias->alias, $aliases));
    }

    /**
     * Exports every file in one entry's file area (component mod_glossary
     * is fixed - this exporter only ever reads its own activity type's
     * files) - the same {filename, contentbase64} shape every other
     * exporter in this plugin uses for embedded/attached files.
     *
     * @param int $contextid
     * @param string $filearea 'entry' (embedded in the definition) or 'attachment'.
     * @param int $itemid The entry's own id.
     * @return array<int, array{filename: string, contentbase64: string}>
     */
    protected function export_files(int $contextid, string $filearea, int $itemid): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'mod_glossary', $filearea, $itemid, 'sortorder', false);

        $exported = [];
        foreach ($files as $file) {
            $exported[] = [
                'filename' => $file->get_filename(),
                'contentbase64' => base64_encode($file->get_content()),
            ];
        }

        return $exported;
    }
}
