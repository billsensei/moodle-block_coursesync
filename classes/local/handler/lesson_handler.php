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
 * Handles mod_lesson, including its pages and the paths between them.
 *
 * A lesson is a graph, not a list. Each page names the one before and after it,
 * and every answer carries a jump saying where choosing it leads. All of those
 * are page ids, and an id means nothing on another site, so every one has to be
 * translated: the pages are created first, then the links between them are
 * fixed, then the answers, then their jumps. A reference can point forward as
 * easily as backward, which is why it cannot be done in a single pass.
 *
 * A jump is only a page id when it is positive. Zero and the negative values are
 * mod_lesson's own constants - next page, end of lesson, an unseen branch - and
 * those mean the same everywhere, so they are left alone. That is the same rule
 * mod_lesson's own restore uses.
 *
 * Files are the other consequence of a lesson being a graph: a page's pictures
 * are stored under that page's id and an answer's under the answer's, so this
 * handler declares three areas without naming item ids and sorts each file out
 * by which area it came from.
 *
 * What does not come across is anybody's progress: attempts, grades, timers and
 * branch history all stay where they were made. Nor does the lesson's password,
 * for the same reason a quiz's does not.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'lesson';
    }

    /**
     * Where a lesson keeps files.
     *
     * The media file is one per lesson, so it can name its item id. The other
     * three hang off pages and answers, whose ids are made here.
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'mediafile', 'itemid' => 0],
            ['filearea' => 'page_contents', 'anyitemid' => true],
            ['filearea' => 'page_answers', 'anyitemid' => true],
            ['filearea' => 'page_responses', 'anyitemid' => true],
        ];
    }

    /**
     * SOURCE SIDE. How the lesson is set up.
     *
     * The three conditions for a dependency are stored serialised in one column
     * but are separate fields on the settings form, and lesson_add_instance()
     * expects the form's shape, so they are taken apart here and put back
     * together on the way in.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the lesson table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        $settings['bgcolor'] = (string) ($instance->bgcolor ?? '#FFFFFF');
        $settings['scalename'] = self::scale_name((int) ($instance->grade ?? 0));
        // Said plainly rather than left for a teacher to discover.
        $settings['haspassword'] = !empty($instance->usepassword) ? '1' : '0';

        $conditions = self::unpack_conditions((string) ($instance->conditions ?? ''));

        foreach ($conditions as $name => $value) {
            $settings[$name] = (string) $value;
        }

        return $settings;
    }

    /**
     * SOURCE SIDE. The pages and the answers on them.
     *
     * Each record's own id travels, because that is what the links between them
     * are made of and what their files are filed under.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the lesson table
     * @return array[]
     */
    public function export_children(\cm_info $cm, \stdClass $instance): array {
        global $DB;

        $children = [];
        $order = 0;

        foreach ($DB->get_records('lesson_pages', ['lessonid' => $instance->id], 'id ASC') as $page) {
            $children[] = [
                'type' => 'page',
                'sortorder' => $order++,
                'fields' => [
                    'remoteid' => (int) $page->id,
                    'prevpageid' => (int) $page->prevpageid,
                    'nextpageid' => (int) $page->nextpageid,
                    'qtype' => (int) $page->qtype,
                    'qoption' => (int) $page->qoption,
                    'layout' => (int) $page->layout,
                    'display' => (int) $page->display,
                    'title' => (string) $page->title,
                    'contents' => (string) $page->contents,
                    'contentsformat' => (int) $page->contentsformat,
                ],
            ];
        }

        $order = 0;

        foreach ($DB->get_records('lesson_answers', ['lessonid' => $instance->id], 'id ASC') as $answer) {
            $children[] = [
                'type' => 'answer',
                'sortorder' => $order++,
                'fields' => [
                    'remoteid' => (int) $answer->id,
                    'pageid' => (int) $answer->pageid,
                    'jumpto' => (int) $answer->jumpto,
                    'grade' => (int) $answer->grade,
                    'score' => (int) $answer->score,
                    'flags' => (int) $answer->flags,
                    'answer' => (string) $answer->answer,
                    'answerformat' => (int) $answer->answerformat,
                    'response' => (string) $answer->response,
                    'responseformat' => (int) $answer->responseformat,
                ],
            ];
        }

        return $children;
    }

    /**
     * DESTINATION SIDE. Build the lesson and everything in it.
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

        require_once($CFG->dirroot . '/mod/lesson/lib.php');
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');

        $sectionnum = $this->target_section($course, $payload);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        $data->bgcolor = self::clean_colour($payload->setting('bgcolor'));
        $data->grade = self::resolve_scale(
            $payload->setting_int('grade', 0),
            $payload->setting('scalename')
        );

        // A lesson's password is a secret of the other site, so it does not
        // travel. Carrying the setting without it would leave a lesson that
        // lets anybody in with a blank password, so the setting goes too.
        $data->usepassword = 0;
        $data->password = '';

        // Both of these name another activity by its id in the other course,
        // which means nothing here. Rather than pointing them at whatever holds
        // that number, the copy simply has neither.
        $data->dependency = 0;
        $data->activitylink = 0;

        // The serialised conditions column is built by
        // lesson_process_pre_save() out of these three, which it then removes,
        // so this is the shape it wants rather than the shape it stores.
        $data->timespent = $payload->setting_int('timespent', 0);
        $data->completed = $payload->setting_int('completed', 0);
        $data->gradebetterthan = $payload->setting_int('gradebetterthan', 0);

        // Read as a draft area id. There is none here; the media file is
        // written straight into the real area afterwards and post_files() then
        // points the lesson at it.
        $data->mediafile = 0;

        $instanceid = \lesson_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        // A lesson missing pages, or with a jump pointing at the wrong one, is
        // worse than no lesson: it leads a student somewhere the teacher did not
        // mean, and the next run would pass over it as already synced.
        try {
            $this->create_pages((int) $instanceid, $payload);
        } catch (\Throwable $e) {
            $DB->delete_records('lesson_answers', ['lessonid' => $instanceid]);
            $DB->delete_records('lesson_pages', ['lessonid' => $instanceid]);
            $DB->delete_records('lesson', ['id' => $instanceid]);
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Recreate the pages and answers, then repair every link between them.
     *
     * @param int $lessonid the lesson on this site
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function create_pages(int $lessonid, activity_payload $payload): void {
        global $DB;

        $this->create_lesson_pages($lessonid, $payload);
        $this->create_lesson_answers($lessonid, $payload);
    }

    /**
     * Recreate the pages, then repair the chain that runs through them.
     *
     * @param int $lessonid the lesson on this site
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function create_lesson_pages(int $lessonid, activity_payload $payload): void {
        global $DB;

        $now = time();
        $qtypes = $this->installed_qtypes($lessonid);

        // First pass: the pages themselves, with their links left empty.
        foreach ($payload->children('page') as $page) {
            $qtype = activity_payload::child_int($page, 'qtype', 0);

            // A page of a kind this site cannot display would be a dead end.
            if (!in_array($qtype, $qtypes, true)) {
                continue;
            }

            $format = activity_payload::child_int($page, 'contentsformat', FORMAT_HTML);

            $record = (object) [
                'lessonid' => $lessonid,
                'prevpageid' => 0,
                'nextpageid' => 0,
                'qtype' => $qtype,
                'qoption' => activity_payload::child_int($page, 'qoption', 0),
                'layout' => activity_payload::child_int($page, 'layout', 1),
                'display' => activity_payload::child_int($page, 'display', 1),
                'timecreated' => $now,
                'timemodified' => $now,
                'title' => clean_param(activity_payload::child_field($page, 'title'), PARAM_TEXT),
                'contents' => activity_payload::child_html($page, 'contents', $format),
                'contentsformat' => $format,
            ];

            $this->remember_id(
                'page',
                activity_payload::child_int($page, 'remoteid', 0),
                (int) $DB->insert_record('lesson_pages', $record)
            );
        }

        // Second pass: the links, now every page has an id here.
        foreach ($payload->children('page') as $page) {
            $localid = $this->local_id('page', activity_payload::child_int($page, 'remoteid', 0));

            if ($localid === null) {
                continue;
            }

            $DB->update_record('lesson_pages', (object) [
                'id' => $localid,
                'prevpageid' => $this->mapped_id('page', activity_payload::child_int($page, 'prevpageid', 0)),
                'nextpageid' => $this->mapped_id('page', activity_payload::child_int($page, 'nextpageid', 0)),
            ]);
        }
    }

    /**
     * Recreate the answers, then repair where each one leads.
     *
     * @param int $lessonid the lesson on this site
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function create_lesson_answers(int $lessonid, activity_payload $payload): void {
        global $DB;

        $now = time();

        // The answers, each on the page it belongs to.
        foreach ($payload->children('answer') as $answer) {
            $pageid = $this->local_id('page', activity_payload::child_int($answer, 'pageid', 0));

            // An answer whose page did not arrive belongs to nothing.
            if ($pageid === null) {
                continue;
            }

            $answerformat = activity_payload::child_int($answer, 'answerformat', FORMAT_HTML);
            $responseformat = activity_payload::child_int($answer, 'responseformat', FORMAT_HTML);

            $record = (object) [
                'lessonid' => $lessonid,
                'pageid' => $pageid,
                // Filled in by the last pass, once every page is known.
                'jumpto' => 0,
                'grade' => activity_payload::child_int($answer, 'grade', 0),
                'score' => activity_payload::child_int($answer, 'score', 0),
                'flags' => activity_payload::child_int($answer, 'flags', 0),
                'timecreated' => $now,
                'timemodified' => $now,
                'answer' => activity_payload::child_html($answer, 'answer', $answerformat),
                'answerformat' => $answerformat,
                'response' => activity_payload::child_html($answer, 'response', $responseformat),
                'responseformat' => $responseformat,
            ];

            $this->remember_id(
                'answer',
                activity_payload::child_int($answer, 'remoteid', 0),
                (int) $DB->insert_record('lesson_answers', $record)
            );
        }

        // And now, with every page known, where each one leads.
        foreach ($payload->children('answer') as $answer) {
            $localid = $this->local_id('answer', activity_payload::child_int($answer, 'remoteid', 0));

            if ($localid === null) {
                continue;
            }

            $DB->set_field(
                'lesson_answers',
                'jumpto',
                $this->resolve_jump(activity_payload::child_int($answer, 'jumpto', 0)),
                ['id' => $localid]
            );
        }
    }

    /**
     * DESTINATION SIDE. Point the lesson at its media file once it has arrived.
     *
     * The lesson stores the media file's name in a column of its own, and
     * mod_lesson works that out by looking in the file area, which is only
     * populated after the activity exists.
     *
     * @param \stdClass $cm the course module
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    public function post_files(\stdClass $cm, activity_payload $payload): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/lesson/lib.php');

        \lesson_update_media_file($cm->instance, \context_module::instance($cm->id), 0);
    }

    /**
     * DESTINATION SIDE. Work out which record a file belongs to here.
     *
     * @param activity_payload $payload what the source site sent
     * @param array $file the file's metadata from the payload
     * @param \stdClass $cm the course module just created here
     * @return int|null the local item id, or null to leave the file out
     */
    public function map_file_itemid(activity_payload $payload, array $file, \stdClass $cm): ?int {
        $remoteid = (int) ($file['itemid'] ?? 0);

        return match ((string) ($file['filearea'] ?? '')) {
            // One media file for the whole lesson, always under the same id.
            'mediafile' => 0,
            'page_contents' => $this->local_id('page', $remoteid),
            'page_answers', 'page_responses' => $this->local_id('answer', $remoteid),
            default => null,
        };
    }

    /**
     * Say what did not come with the copy.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys to show against this activity
     */
    public function notes(activity_payload $payload): array {
        $notes = ['synclessonnoattempts'];

        if ($payload->setting_int('haspassword', 0)) {
            $notes[] = 'synclessonnopassword';
        }

        return $notes;
    }

    /**
     * Was a grading scale dropped because this site does not have it?
     *
     * @param activity_payload $payload what the source site sent
     * @return bool
     */
    public function lost_scale(activity_payload $payload): bool {
        return self::scale_was_dropped($payload, [['grade', 'scalename']]);
    }

    /**
     * Where an answer leads, said in this site's page ids.
     *
     * Only a positive jump is a page. Everything else is one of mod_lesson's
     * own constants - next page, end of lesson, an unseen branch - which mean
     * the same on any site and are left alone. An answer that jumped to a page
     * that did not arrive is sent to the next page rather than nowhere.
     *
     * @param int $jumpto what the source site sent
     * @return int
     */
    protected function resolve_jump(int $jumpto): int {
        if ($jumpto <= 0) {
            return $jumpto;
        }

        return $this->local_id('page', $jumpto) ?? LESSON_NEXTPAGE;
    }

    /**
     * The kinds of page this site can actually display.
     *
     * Asked of mod_lesson rather than worked out from the files, so a site with
     * an extra page type installed, or one removed, gets the right answer.
     *
     * @param int $lessonid the lesson on this site
     * @return int[]
     */
    protected function installed_qtypes(int $lessonid): array {
        global $DB;

        $lesson = new \lesson($DB->get_record('lesson', ['id' => $lessonid], '*', MUST_EXIST));
        $manager = \lesson_page_type_manager::get($lesson);

        return array_map('intval', array_keys($manager->get_page_type_strings()));
    }

    /**
     * Keep the background colour to something that is actually a colour.
     *
     * @param string $colour what the source site sent
     * @return string
     */
    protected static function clean_colour(string $colour): string {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $colour) ? $colour : '#FFFFFF';
    }

    /**
     * Take the serialised dependency conditions apart into their three fields.
     *
     * @param string $conditions the stored column
     * @return array<string, int>
     */
    protected static function unpack_conditions(string $conditions): array {
        $defaults = ['timespent' => 0, 'completed' => 0, 'gradebetterthan' => 0];

        if ($conditions === '') {
            return $defaults;
        }

        $unpacked = @unserialize($conditions, ['allowed_classes' => false]);

        if (!is_object($unpacked) && !is_array($unpacked)) {
            return $defaults;
        }

        $unpacked = (array) $unpacked;
        $out = [];

        foreach ($defaults as $name => $default) {
            $out[$name] = (int) ($unpacked[$name] ?? $default);
        }

        return $out;
    }

    /**
     * The lesson settings that are carried, and what to assume without them.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'practice' => 0,
            'modattempts' => 0,
            'grade' => 0,
            'custom' => 1,
            'ongoing' => 0,
            'usemaxgrade' => 0,
            'maxanswers' => 4,
            'maxattempts' => 1,
            'review' => 0,
            'nextpagedefault' => 0,
            'feedback' => 1,
            'minquestions' => 0,
            'maxpages' => 0,
            'timelimit' => 0,
            'retake' => 1,
            'mediaheight' => 480,
            'mediawidth' => 640,
            'mediaclose' => 0,
            'slideshow' => 0,
            'width' => 640,
            'height' => 480,
            'displayleft' => 0,
            'displayleftif' => 0,
            'progressbar' => 0,
            'available' => 0,
            'deadline' => 0,
            'completionendreached' => 0,
            'completiontimespent' => 0,
            'allowofflineattempts' => 0,
        ];
    }
}
