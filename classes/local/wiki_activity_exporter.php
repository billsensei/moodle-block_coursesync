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
 * Exports a mod_wiki instance's settings AND its pages - same "settings
 * alone would be unsatisfying" reasoning as glossary_activity_exporter's
 * docblock, since a wiki's whole point is its pages.
 *
 * A wiki isn't one flat list of pages, it's one or more SUBWIKIS - see
 * {@see wiki_activity_handler}'s class docblock for the schema
 * (wiki -> wiki_subwikis -> wiki_pages -> wiki_versions) and exactly which
 * subwikis this plugin pulls:
 *
 * - The single course-wide subwiki (collaborative mode, no groups), and one
 *   subwiki per group (collaborative or individual mode WITH separate/
 *   visible groups) travel - `wiki_subwikis.userid = 0` selects exactly
 *   these, because a group's shared subwiki is still shared course
 *   material, the same spirit as a glossary's entries or a quiz's
 *   questions.
 * - Every INDIVIDUAL per-user subwiki (`userid > 0` - one student's own
 *   wiki, whether or not it's also split by group) is never exported. This
 *   is the same "personal content stays on the source" cut this plugin
 *   already makes for assignment submissions and forum posts/discussions
 *   - see assign_activity_exporter's docblock for the precedent. A wiki
 *   whose mode is 'individual' therefore syncs its OWN settings (including
 *   `wikimode` itself, unchanged) but zero subwikis/pages, the same as an
 *   empty glossary syncing cleanly with zero entries - not a failure, just
 *   nothing eligible to pull.
 *
 * Each page travels as its CURRENT content only (the latest wiki_versions
 * row) - no revision history, the same "current state, not history" choice
 * already made for quiz (no use-count balancing) and glossary (no
 * moderation/revision trail).
 *
 * Images/audio/video/other files embedded in a page's content are NOT
 * itemised per page: unlike every other activity type in this plugin, a
 * wiki page's own markup references an attached file by plain FILENAME
 * (e.g. `{{picture.png}}` in Creole, or a bare relative `<img src>` in HTML
 * format - see mod/wiki/locallib.php's wiki_parser_real_path()), resolved
 * at render time against the whole SUBWIKI's shared file area, not a
 * per-page one. So this exporter mirrors that shape: `files` is exported
 * once per subwiki (component mod_wiki, filearea attachments, itemid =
 * the subwiki's own id - see mod/wiki/lib.php's wiki_pluginfile()), and as
 * long as wiki_activity_handler recreates every file under that same
 * subwiki with its original filename, every page's own markup keeps
 * resolving correctly with NO URL/reference rewriting needed - unlike the
 * draft-area-and-PLUGINFILE-token dance every other file-carrying exporter
 * in this plugin has to do.
 *
 * Not exported: page comments (mod_wiki's own comments.php feature - never
 * synced, same user-generated-content cut as forum posts/glossary
 * comments), page locks (transient editing state, meaningless once copied
 * to another site), internal wiki_links (rebuilt automatically by the
 * destination's own wiki_save_page() -> wiki_refresh_cachedcontent() call
 * when each page is recreated - see the handler), and wiki_synonyms
 * (title aliases - no supported creation path outside the wiki's own UI,
 * and rare enough not to be worth one yet).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wiki_activity_exporter implements activity_exporter {
    /**
     * Builds the payload wiki_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $DB;

        $wiki = $DB->get_record('wiki', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return [
            'name' => $wiki->name,
            'intro' => (string) $wiki->intro,
            'introformat' => (int) $wiki->introformat,
            'firstpagetitle' => (string) $wiki->firstpagetitle,
            'wikimode' => (string) $wiki->wikimode,
            'defaultformat' => (string) $wiki->defaultformat,
            'forceformat' => (int) $wiki->forceformat,
            'editbegin' => (int) $wiki->editbegin,
            'editend' => (int) $wiki->editend,
            'subwikis' => $this->export_subwikis((int) $wiki->id, $context),
        ];
    }

    /**
     * Exports every course-wide/group subwiki that has at least one page -
     * see class docblock for exactly which subwikis qualify and why.
     *
     * @param int $wikiid
     * @param \context_module $context
     * @return array<int, array{groupname: ?string, pages: array, files: array}>
     */
    protected function export_subwikis(int $wikiid, \context_module $context): array {
        global $DB;

        // Userid = 0 selects the course-wide subwiki (groupid = 0) and every
        // group's own subwiki (groupid > 0) - see class docblock.
        $subwikis = $DB->get_records('wiki_subwikis', ['wikiid' => $wikiid, 'userid' => 0], 'id ASC');

        $exported = [];
        foreach ($subwikis as $subwiki) {
            $groupname = null;
            if ($subwiki->groupid) {
                $groupname = $DB->get_field('groups', 'name', ['id' => $subwiki->groupid]);
                if ($groupname === false) {
                    // The group has since been deleted - an orphaned subwiki
                    // with no way to identify which group it belonged to.
                    continue;
                }
            }

            $pages = $this->export_pages((int) $subwiki->id);
            if (empty($pages)) {
                // Nothing to sync - e.g. a subwiki created just by a group
                // member visiting it, before anyone wrote a page.
                continue;
            }

            $exported[] = [
                // Null => the single course-wide subwiki. A string => that
                // group's own subwiki, matched/created by name on the
                // destination course - see wiki_activity_handler::resolve_group().
                'groupname' => $groupname !== null ? (string) $groupname : null,
                'pages' => $pages,
                'files' => $this->export_files($context->id, (int) $subwiki->id),
            ];
        }

        return $exported;
    }

    /**
     * Exports every page in one subwiki, each as its current content only.
     *
     * @param int $subwikiid
     * @return array<int, array{title: string, content: string, contentformat: string}>
     */
    protected function export_pages(int $subwikiid): array {
        global $DB;

        $pages = $DB->get_records('wiki_pages', ['subwikiid' => $subwikiid], 'id ASC');

        $exported = [];
        foreach ($pages as $page) {
            // The current version is the highest ->version for this page -
            // same definition mod/wiki/locallib.php's own
            // wiki_get_current_version() uses, read directly here rather
            // than requiring locallib.php for one query, matching every
            // other exporter in this plugin's "direct $DB reads" style.
            $versions = $DB->get_records('wiki_versions', ['pageid' => $page->id], 'version DESC', '*', 0, 1);
            $version = reset($versions);

            $exported[] = [
                'title' => (string) $page->title,
                'content' => $version ? (string) $version->content : '',
                'contentformat' => $version ? (string) $version->contentformat : 'html',
            ];
        }

        return $exported;
    }

    /**
     * Exports every file in one subwiki's shared attachments area - see
     * class docblock for why this is per-subwiki, not per-page.
     *
     * @param int $contextid
     * @param int $subwikiid
     * @return array<int, array{filename: string, contentbase64: string}>
     */
    protected function export_files(int $contextid, int $subwikiid): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'mod_wiki', 'attachments', $subwikiid, 'sortorder', false);

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
