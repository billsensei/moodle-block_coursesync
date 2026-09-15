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
 * Exports a mod_book instance's settings AND its chapters (`book_chapters`)
 * - always, same "the activity's whole point is its content" reasoning as
 * glossary's entries/quiz's questions/wiki's pages. Every chapter carries
 * its own embedded files (component `mod_book`, filearea `chapter`, itemid
 * = the chapter's own id - used by an `<img>`/similar tag's `@@PLUGINFILE@@`
 * reference in its `content`).
 *
 * Chapters are exported in `pagenum` order, WITHOUT the source's own
 * `pagenum`/`importsrc` - the destination gets entirely new chapter ids and
 * a compact 1..N pagenum sequence, the same renumbering
 * `book_activity_handler::create_from_remote_data()` ends every sync with
 * anyway via `book_preload_chapters()` (core's own "fix the structure"
 * helper, called after any real edit too - see mod/book/edit.php).
 * `subchapter` and `hidden` DO travel - a hidden chapter is still authored
 * content the teacher chose to temporarily hide, not someone else's
 * unpublished draft (unlike a glossary entry awaiting moderation), so it's
 * synced the same as a visible one, hidden state included.
 *
 * Chapter TAGS (`core_tag_tag`, set via `core_tag_tag::set_item_tags()` in
 * mod/book/edit.php) are NOT exported - this plugin doesn't sync tags for
 * any activity type yet.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_activity_exporter implements activity_exporter {
    /**
     * Builds the payload book_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $DB;

        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return [
            'name' => $book->name,
            'intro' => (string) $book->intro,
            'introformat' => (int) $book->introformat,
            'numbering' => (int) $book->numbering,
            'navstyle' => (int) $book->navstyle,
            'customtitles' => (int) $book->customtitles,
            'chapters' => $this->export_chapters((int) $book->id, $context),
        ];
    }

    /**
     * Exports every chapter, in pagenum order.
     *
     * @param int $bookid
     * @param \context_module $context
     * @return array<int, array>
     */
    protected function export_chapters(int $bookid, \context_module $context): array {
        global $DB;

        $chapters = $DB->get_records('book_chapters', ['bookid' => $bookid], 'pagenum ASC');

        $exported = [];
        foreach ($chapters as $chapter) {
            $exported[] = [
                'subchapter' => (int) $chapter->subchapter,
                'title' => (string) $chapter->title,
                'content' => (string) $chapter->content,
                'contentformat' => (int) $chapter->contentformat,
                'hidden' => (int) $chapter->hidden,
                'files' => $this->export_files($context->id, (int) $chapter->id),
            ];
        }

        return $exported;
    }

    /**
     * Exports every file embedded in one chapter's own `chapter` filearea -
     * the same {filename, filepath, mimetype, sortorder, contentbase64}
     * shape every file-carrying exporter in this plugin uses.
     *
     * @param int $contextid
     * @param int $chapterid
     * @return array<int, array{filename: string, filepath: string, mimetype: ?string,
     *                          sortorder: int, contentbase64: string}>
     */
    protected function export_files(int $contextid, int $chapterid): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'mod_book', 'chapter', $chapterid, 'sortorder', false);

        $exported = [];
        foreach ($files as $file) {
            $exported[] = [
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'mimetype' => $file->get_mimetype(),
                'sortorder' => (int) $file->get_sortorder(),
                'contentbase64' => base64_encode($file->get_content()),
            ];
        }

        return $exported;
    }
}
