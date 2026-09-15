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
 * Recreates a mod_book instance AND its chapters from
 * book_activity_exporter's payload.
 *
 * Chapters are inserted directly into `book_chapters` (content column
 * included verbatim, once sanitized) rather than through mod/book/edit.php's
 * interactive form flow (`file_prepare_standard_editor()`/
 * `file_postupdate_standard_editor()`, which exist to stage a user's
 * in-progress edits through a draft file area) - there's no editing session
 * here to stage, and core's OWN restore code does exactly this same direct
 * insert (`mod/book/backup/moodle2/restore_book_stepslib.php`'s
 * `process_book_chapter()`, followed by `add_related_files()` for the
 * files - not a novel pattern). This works with NO `@@PLUGINFILE@@`
 * rewriting at all, for the same reason wiki's pages need none (see
 * `wiki_activity_exporter`'s docblock) but via a different mechanism:
 * `@@PLUGINFILE@@/<path>` is an itemid-AGNOSTIC placeholder
 * (`file_rewrite_pluginfile_urls()`, lib/filelib.php - it only ever
 * consults the CURRENT context/component/filearea/itemid passed to it at
 * render time, never anything encoded in the text itself), so the exact
 * same stored `content` string that worked against the source chapter's
 * itemid keeps working against the destination chapter's own (different)
 * itemid, as long as a same-named file actually exists in ITS `chapter`
 * filearea - which `store_chapter_files()` below guarantees, writing
 * directly into `[context, mod_book, chapter, <new chapter id>]`, no
 * draft area involved (same reasoning, same shape, as
 * `resource_activity_handler::store_files()`).
 *
 * `book_preload_chapters()` (mod/book/locallib.php - core's own "fix the
 * structure" helper, called after any real chapter add/edit too) is run
 * once after every chapter is created: it renumbers `pagenum` into a clean
 * 1..N sequence and forces every subchapter of a hidden chapter to be
 * hidden too, persisting either change itself.
 *
 * `book_add_instance()` does NOT set course_modules.instance itself - same
 * quirk as url/label/forum/assign/glossary/choice/feedback, handled the
 * same explicit way.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_activity_handler implements activity_handler {
    /**
     * Creates the book course module, instance, and chapters.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from book_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/book/lib.php');
        require_once($CFG->dirroot . '/mod/book/locallib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'book'], MUST_EXIST);

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

        $bookdata = $this->build_book_data($courseid, $cmid, $data);
        $bookid = book_add_instance($bookdata, null);
        // This call does NOT set course_modules.instance for $cmid - done explicitly below.
        $DB->set_field('course_modules', 'instance', $bookid, ['id' => $cmid]);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'book');
        rebuild_course_cache($courseid, true);

        $this->create_chapters((int) $bookid, \context_module::instance($cmid), $data['chapters'] ?? []);

        return $cmid;
    }

    /**
     * Builds the book row's own settings.
     *
     * @param int $courseid
     * @param int $cmid
     * @param array $data
     * @return \stdClass
     */
    protected function build_book_data(int $courseid, int $cmid, array $data): \stdClass {
        $book = new \stdClass();
        $book->course = $courseid;
        $book->coursemodule = $cmid;
        $book->name = sanitizer::text($data['name'] ?? '');
        $book->intro = sanitizer::html($data['intro'] ?? '');
        $book->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $book->numbering = sanitizer::integer($data['numbering'] ?? 0);
        $book->navstyle = sanitizer::integer($data['navstyle'] ?? 1, 1);
        $book->customtitles = sanitizer::integer($data['customtitles'] ?? 0);

        return $book;
    }

    /**
     * Inserts every chapter (content sanitized, pagenum assigned
     * sequentially in payload order), writes its embedded files into its
     * own new itemid, then lets book_preload_chapters() fix up numbering
     * and the hidden-subchapter cascade exactly as a real edit would - see
     * class docblock.
     *
     * @param int $bookid
     * @param \context_module $context
     * @param array $chapters From book_activity_exporter::export_chapters().
     */
    protected function create_chapters(int $bookid, \context_module $context, array $chapters): void {
        global $DB;

        if (empty($chapters)) {
            return;
        }

        foreach (array_values($chapters) as $index => $chapterdata) {
            $chapter = new \stdClass();
            $chapter->bookid = $bookid;
            $chapter->pagenum = $index + 1;
            $chapter->subchapter = sanitizer::integer($chapterdata['subchapter'] ?? 0);
            $chapter->title = sanitizer::text($chapterdata['title'] ?? '');
            $chapter->content = sanitizer::html($chapterdata['content'] ?? '');
            $chapter->contentformat = sanitizer::textformat($chapterdata['contentformat'] ?? FORMAT_HTML);
            $chapter->hidden = sanitizer::integer($chapterdata['hidden'] ?? 0);
            $chapter->timecreated = time();
            $chapter->timemodified = time();
            $chapter->importsrc = '';

            $chapterid = $DB->insert_record('book_chapters', $chapter);

            $this->store_chapter_files($context->id, $chapterid, $chapterdata['files'] ?? []);
        }

        $book = $DB->get_record('book', ['id' => $bookid], '*', MUST_EXIST);
        $DB->set_field('book', 'revision', $book->revision + 1, ['id' => $bookid]);
        // Renumbers pagenum into a clean sequence and cascades hidden onto
        // every subchapter of a hidden chapter - see class docblock.
        book_preload_chapters($book);
    }

    /**
     * Writes exported files directly into one chapter's own `chapter`
     * filearea - see class docblock for why no draft area/rewriting is
     * needed. Same per-file error handling as
     * resource_activity_handler::store_files(): a malformed file is
     * skipped, not a whole-chapter failure.
     *
     * @param int $contextid
     * @param int $chapterid
     * @param array $files From book_activity_exporter::export_files().
     */
    protected function store_chapter_files(int $contextid, int $chapterid, array $files): void {
        $fs = get_file_storage();

        foreach ($files as $filedata) {
            $content = base64_decode($filedata['contentbase64'] ?? '', true);
            if ($content === false || strlen($content) > sanitizer::MAX_EMBEDDED_FILE_BYTES) {
                continue;
            }

            $fs->create_file_from_string([
                'contextid' => $contextid,
                'component' => 'mod_book',
                'filearea' => 'chapter',
                'itemid' => $chapterid,
                'filepath' => sanitizer::filepath($filedata['filepath'] ?? '/'),
                'filename' => sanitizer::filename($filedata['filename'] ?? 'file'),
            ], $content);
        }
    }
}
