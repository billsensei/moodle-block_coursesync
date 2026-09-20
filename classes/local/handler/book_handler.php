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
 * Handles mod_book, including its chapters.
 *
 * A book is the first type whose content is not in its own row. The text a
 * reader sees lives in the book_chapters table, so the chapters travel as child
 * records and are recreated here in the order the source site had them.
 *
 * That makes it the first type whose files are not all filed under one item id
 * either: a chapter's images are stored against that chapter's id, and those
 * ids are this site's, not the source's. So the handler declares its chapter
 * area with 'anyitemid' and translates each file's item id in
 * map_file_itemid(), using the id map the base class keeps.
 *
 * Chapter content is written by teachers and is HTML, so it is cleaned on the
 * way in exactly as an activity description is.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'book';
    }

    /**
     * A book's files hang off its chapters, one item id each.
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'chapter', 'anyitemid' => true],
        ];
    }

    /**
     * SOURCE SIDE. How the book is presented.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the book table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        return [
            'numbering' => (string) ($instance->numbering ?? 0),
            'navstyle' => (string) ($instance->navstyle ?? 1),
            'customtitles' => (string) ($instance->customtitles ?? 0),
        ];
    }

    /**
     * SOURCE SIDE. The book's chapters.
     *
     * The chapter's own id travels as a field because it is what the file
     * metadata is keyed by; it is a handle for matching files to chapters, not
     * something this site ever stores.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the book table
     * @return array[]
     */
    public function export_children(\cm_info $cm, \stdClass $instance): array {
        global $DB;

        $chapters = $DB->get_records('book_chapters', ['bookid' => $instance->id], 'pagenum ASC');
        $children = [];

        foreach ($chapters as $chapter) {
            $children[] = [
                'type' => 'chapter',
                'sortorder' => (int) $chapter->pagenum,
                'fields' => [
                    'remoteid' => (int) $chapter->id,
                    'title' => (string) $chapter->title,
                    'content' => (string) $chapter->content,
                    'contentformat' => (int) $chapter->contentformat,
                    'subchapter' => (int) $chapter->subchapter,
                    'hidden' => (int) $chapter->hidden,
                ],
            ];
        }

        return $children;
    }

    /**
     * DESTINATION SIDE. Build the book, and its chapters, in a local course.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload what the source site sent
     * @param string $idnumber the ID number that marks this as synced
     * @return \stdClass the new course_modules record
     */
    public function create_from_remote_data(
        \stdClass $course,
        activity_payload $payload,
        string $idnumber
    ): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/book/lib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);
        $data->numbering = $payload->setting_int('numbering', 0);
        $data->navstyle = $payload->setting_int('navstyle', 1);
        $data->customtitles = $payload->setting_int('customtitles', 0) ? 1 : 0;
        $data->revision = 1;

        $instanceid = \book_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        // A book whose chapters did not all arrive is worse than no book: the
        // next run would see it as already synced and never come back to it.
        try {
            $this->create_chapters((int) $instanceid, $payload);
        } catch (\Throwable $e) {
            $DB->delete_records('book_chapters', ['bookid' => $instanceid]);
            $DB->delete_records('book', ['id' => $instanceid]);
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Recreate the book's chapters, remembering which id each one ended up with.
     *
     * @param int $bookid the book on this site
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function create_chapters(int $bookid, activity_payload $payload): void {
        global $DB;

        $pagenum = 0;
        $now = time();

        foreach ($payload->children('chapter') as $chapter) {
            $format = activity_payload::child_int($chapter, 'contentformat', FORMAT_HTML);

            $record = (object) [
                'bookid' => $bookid,
                // Page numbers are renumbered from one rather than trusted, so
                // a gap or a repeat on the source site cannot produce a book
                // whose chapters cannot be navigated here.
                'pagenum' => ++$pagenum,
                'subchapter' => activity_payload::child_int($chapter, 'subchapter', 0) ? 1 : 0,
                'title' => clean_param(activity_payload::child_field($chapter, 'title'), PARAM_TEXT),
                'content' => activity_payload::child_html($chapter, 'content', $format),
                'contentformat' => $format,
                'hidden' => activity_payload::child_int($chapter, 'hidden', 0) ? 1 : 0,
                'timecreated' => $now,
                'timemodified' => $now,
                'importsrc' => '',
            ];

            $localid = $DB->insert_record('book_chapters', $record);
            $remoteid = activity_payload::child_int($chapter, 'remoteid', 0);

            $this->remember_id('chapter', $remoteid, (int) $localid);
        }
    }

    /**
     * DESTINATION SIDE. Point a chapter's file at the chapter created for it.
     *
     * @param activity_payload $payload what the source site sent
     * @param array $file the file's metadata from the payload
     * @param \stdClass $cm the course module just created here
     * @return int|null the local chapter id, or null to leave the file out
     */
    public function map_file_itemid(activity_payload $payload, array $file, \stdClass $cm): ?int {
        // A file belonging to a chapter that is not here has nowhere to go.
        // Storing it under the source's id would attach it to whichever local
        // chapter happened to take that number, so it is left behind instead.
        return $this->local_id('chapter', (int) ($file['itemid'] ?? 0));
    }
}
