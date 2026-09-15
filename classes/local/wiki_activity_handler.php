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
 * Recreates a mod_wiki instance AND its pages/files from
 * wiki_activity_exporter's payload.
 *
 * Schema recap (see mod/wiki/db/install.xml): one `wiki` row owns one or
 * more `wiki_subwikis` rows (a course-wide one, or one per group, or one
 * per user, or one per user-per-group, depending on wikimode/group mode -
 * see wiki_activity_exporter's docblock for which of these travel), each
 * subwiki owns its own `wiki_pages`, and each page's actual text lives in
 * `wiki_versions` (one row per edit; only the latest is synced).
 *
 * `wiki_add_instance()` does NOT set course_modules.instance itself - same
 * quirk as url/label/forum/assign/glossary - done explicitly below.
 *
 * Every page is created via mod/wiki/locallib.php's own
 * `wiki_create_page()` + `wiki_save_page()` - the same two functions the
 * wiki's own edit form and web services use - rather than writing
 * wiki_pages/wiki_versions rows directly, because wiki_save_page() is also
 * what regenerates `wiki_pages.cachedcontent` (via wiki_refresh_cachedcontent()
 * -> the real Creole/NWiki/HTML parser) and rebuilds wiki_links - state this
 * handler has no reason to reimplement. Attribution goes to whoever is
 * running Sync now ($USER), same as every other activity_handler in this
 * plugin - the remote page's own author doesn't exist on this site.
 *
 * Group subwikis are matched to a destination group BY NAME: an existing
 * group on the destination course with the same name is reused, otherwise
 * one is created - see resolve_group(). This plugin has no cross-site group
 * id mapping anywhere else, so name is the simplest unambiguous key
 * available, the same spirit as idnumber-based activity matching elsewhere
 * in this plugin (just no idnumber equivalent on groups that's reliably
 * set up on both sites).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wiki_activity_handler implements activity_handler {
    /** @var array<string> Wiki page/content markup formats mod_wiki actually ships - see mod/wiki/locallib.php's wiki_get_formats(). */
    protected const KNOWN_FORMATS = ['html', 'creole', 'nwiki'];

    /** @var array<string> Wiki modes mod_wiki actually recognises - see classes/wiki_mode.php. */
    protected const KNOWN_MODES = ['collaborative', 'individual'];

    /**
     * Creates the wiki course module, instance, subwikis, pages, and files.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from wiki_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/wiki/lib.php');
        require_once($CFG->dirroot . '/mod/wiki/locallib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'wiki'], MUST_EXIST);

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

        $wikidata = $this->build_wiki_data($courseid, $cmid, $data);
        $wikiid = wiki_add_instance($wikidata);
        // This call does NOT set course_modules.instance for $cmid - done explicitly.
        $DB->set_field('course_modules', 'instance', $wikiid, ['id' => $cmid]);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'wiki');
        rebuild_course_cache($courseid, true);

        $this->create_subwikis($courseid, $wikiid, $data['subwikis'] ?? []);

        return $cmid;
    }

    /**
     * Builds the wiki row's own settings.
     *
     * @param int $courseid
     * @param int $cmid
     * @param array $data
     * @return \stdClass
     */
    protected function build_wiki_data(int $courseid, int $cmid, array $data): \stdClass {
        $wiki = new \stdClass();
        $wiki->course = $courseid;
        $wiki->coursemodule = $cmid;
        $wiki->name = sanitizer::text($data['name'] ?? '');
        $wiki->intro = sanitizer::html($data['intro'] ?? '');
        $wiki->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $wiki->firstpagetitle = sanitizer::text($data['firstpagetitle'] ?? 'First Page');
        // Carried over unchanged, including 'individual' - the sync itself
        // still pulls zero pages for it (see exporter's docblock), but
        // preserving the setting means a teacher who reconfigures the
        // destination wiki isn't fighting a mode this plugin silently
        // downgraded to 'collaborative'.
        $wiki->wikimode = $this->sanitize_enum($data['wikimode'] ?? 'collaborative', self::KNOWN_MODES, 'collaborative');
        $wiki->defaultformat = $this->sanitize_enum($data['defaultformat'] ?? 'creole', self::KNOWN_FORMATS, 'creole');
        $wiki->forceformat = sanitizer::integer($data['forceformat'] ?? 1, 1);
        $wiki->editbegin = sanitizer::integer($data['editbegin'] ?? 0);
        $wiki->editend = sanitizer::integer($data['editend'] ?? 0);

        return $wiki;
    }

    /**
     * Validates a value against a fixed known set, same pattern as
     * glossary_activity_handler::sanitize_displayformat() - unlike a plain
     * enum sanitizer::* can validate everywhere, wikimode/contentformat's
     * valid values are mod_wiki's own, not something worth adding to the
     * shared sanitizer for one activity type.
     *
     * @param mixed $value
     * @param array<string> $known
     * @param string $default
     * @return string
     */
    protected function sanitize_enum($value, array $known, string $default): string {
        $value = (string) $value;

        return in_array($value, $known, true) ? $value : $default;
    }

    /**
     * Creates every subwiki (course-wide or group), its files, then its
     * pages, in that order - files go in FIRST so that a page's own
     * markup, which references a file by plain filename (see exporter's
     * docblock), resolves correctly the moment wiki_save_page() below
     * caches the parsed content.
     *
     * @param int $courseid
     * @param int $wikiid
     * @param array $subwikis From wiki_activity_exporter::export_subwikis().
     */
    protected function create_subwikis(int $courseid, int $wikiid, array $subwikis): void {
        global $USER;

        foreach ($subwikis as $subwikidata) {
            $groupid = 0;
            if (!empty($subwikidata['groupname'])) {
                $groupid = $this->resolve_group($courseid, sanitizer::text($subwikidata['groupname']));
            }

            $swid = wiki_add_subwiki($wikiid, $groupid, 0);

            $this->store_files($swid, $subwikidata['files'] ?? []);

            foreach ($subwikidata['pages'] ?? [] as $pagedata) {
                $title = sanitizer::text($pagedata['title'] ?? '');
                if ($title === '') {
                    continue;
                }

                $format = $this->sanitize_enum($pagedata['contentformat'] ?? 'html', self::KNOWN_FORMATS, 'html');
                // Every content format is sanitized with sanitizer::html()
                // regardless of $format: 'html' pages are proven (see
                // mod/wiki/parser/markups/html.php) to reach rendered
                // output with no cleaning of their own, and this plugin
                // has no reliable guarantee that every OTHER format's own
                // parser escapes embedded raw HTML either (creole's does -
                // see markups/creole.php's htmlspecialchars() call -
                // nwiki's isn't provably the same) - so storage-time
                // cleaning here doesn't rely on which format a given page
                // happens to use, or on that staying true after a future
                // core change. The tradeoff is the same one sanitizer.php's
                // own docblock already accepts: occasional over-cleaning of
                // literal '<'/'>' text in a non-HTML page, in exchange for
                // never trusting a remote site's raw bytes into a format
                // this plugin can't fully verify is safe unescaped.
                $content = sanitizer::html($pagedata['content'] ?? '');

                $pageid = wiki_create_page($swid, $title, $format, (int) $USER->id);
                $wikipage = wiki_get_page($pageid);
                if ($wikipage) {
                    wiki_save_page($wikipage, $content, (int) $USER->id);
                }
            }
        }
    }

    /**
     * Finds or creates a destination-course group with this name - see
     * class docblock for why name is the matching key.
     *
     * @param int $courseid
     * @param string $name
     * @return int The group id.
     */
    protected function resolve_group(int $courseid, string $name): int {
        global $CFG, $DB;

        $existing = $DB->get_record('groups', ['courseid' => $courseid, 'name' => $name]);
        if ($existing) {
            return (int) $existing->id;
        }

        require_once($CFG->dirroot . '/group/lib.php');

        $group = new \stdClass();
        $group->courseid = $courseid;
        $group->name = $name;

        return (int) groups_create_group($group);
    }

    /**
     * Writes exported files directly into the new subwiki's own
     * mod_wiki/attachments filearea (itemid = the subwiki id) - no draft
     * area needed, same direct-write approach as
     * resource_activity_handler::store_files(), since a wiki page's own
     * markup references these by plain filename rather than the usual
     * draft-area PLUGINFILE-token itemid.
     *
     * @param int $subwikiid
     * @param array<int, array{filename: string, contentbase64: string}> $files
     */
    protected function store_files(int $subwikiid, array $files): void {
        global $DB;

        if (empty($files)) {
            return;
        }

        $subwiki = $DB->get_record('wiki_subwikis', ['id' => $subwikiid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('wiki', $subwiki->wikiid);
        $context = \context_module::instance($cm->id);

        $fs = get_file_storage();
        foreach ($files as $filedata) {
            $content = base64_decode($filedata['contentbase64'] ?? '', true);
            if ($content === false) {
                // Malformed payload for this one file - skip it rather than
                // fail the whole subwiki, same per-file leniency as every
                // other file-carrying handler in this plugin.
                continue;
            }

            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_wiki',
                'filearea' => 'attachments',
                'itemid' => $subwikiid,
                'filepath' => '/',
                'filename' => sanitizer::filename($filedata['filename'] ?? 'file'),
            ], $content);
        }
    }
}
