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
 * Handles mod_qbank: a course's own question bank, as its own activity.
 *
 * A question bank is a category tree, and each category holds questions of
 * many different, unrelated shapes - a true/false question and a numerical
 * one share almost no columns. Three things about this handler are
 * deliberately different from every other one in this plugin, and each is
 * different for a reason, not by oversight:
 *
 * 1. get_file_areas() returns nothing. A question's own files (its text,
 *    feedback, answers) are not fetched through this plugin's chunked file
 *    transfer at all - qformat_xml::writequestion() already inlines them as
 *    base64 into the question's own XML, because that is what Moodle's own
 *    question export format does. Piping the same bytes through a second
 *    transfer mechanism would be strictly more code for nothing.
 *
 * 2. A question travels as one opaque XML fragment (the 'xml' field on a
 *    'question' child), not as flattened settings. Six supported types
 *    means six different column layouts - true/false points at two of its
 *    own answer rows, matching has no answer rows at all, numerical spans
 *    four tables. qformat_xml already solves exactly this, tags and answer
 *    files included, so this handler serialises with it rather than
 *    re-deriving each type's shape by hand.
 *
 * 3. No new capability is checked. Every other check in this plugin is
 *    block/coursesync:sync at the course context - see SECURITY.md for why
 *    checking anything more specific was rejected on purpose. The core
 *    calls this handler makes (get_qtype()->save_question_options() and
 *    friends) do no capability enforcement themselves; those checks live in
 *    the question bank's editing UI, which this handler never goes through.
 *
 * Only six question types are rebuilt - multiple choice, true/false, short
 * answer, matching, essay, numerical - because those cover most real
 * question banks and each one's shape has actually been checked against
 * this handler. A question of any other type is left out and counted; see
 * notes(). Only the current ready version of each question is copied - no
 * drafts, no hidden versions, no history - matching how every other type in
 * this plugin carries current state rather than a log of changes.
 *
 * mod_quiz does not pull in the questions its slots reference. A quiz still
 * syncs with none of its questions, exactly as before this handler existed;
 * see quiz_handler. That is a separate piece of work.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbank_handler extends activity_handler {
    /** @var string[] The question types this handler knows how to rebuild. */
    protected const SUPPORTED_QTYPES = [
        'multichoice', 'truefalse', 'shortanswer', 'match', 'essay', 'numerical',
    ];

    /** @var int Questions that arrived as a type this handler does not rebuild. */
    protected int $unsupportedcount = 0;

    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'qbank';
    }

    /**
     * SOURCE SIDE. A question bank has one setting of its own: which kind of
     * bank it is (standard, or one of the special system-managed kinds).
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the qbank table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        return [
            'type' => (string) ($instance->type ?? \core_question\local\bank\question_bank_helper::TYPE_STANDARD),
        ];
    }

    /**
     * SOURCE SIDE. Every category in this bank, and every question in a
     * type this handler can rebuild, in each category.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the qbank table
     * @return array[]
     */
    public function export_children(\cm_info $cm, \stdClass $instance): array {
        global $DB, $CFG;

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/bank.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $context = \context_module::instance($cm->id);
        $categories = $DB->get_records('question_categories', ['contextid' => $context->id], 'id ASC');

        $children = [];
        $order = 0;
        $writer = new \qformat_xml();

        foreach ($categories as $category) {
            $children[] = [
                'type' => 'category',
                'sortorder' => $order++,
                'fields' => [
                    'remoteid' => (int) $category->id,
                    'parentid' => (int) $category->parent,
                    'name' => (string) $category->name,
                    'info' => (string) $category->info,
                    'infoformat' => (int) $category->infoformat,
                ],
            ];

            // The current ready version only - no drafts, no hidden ones, no
            // history - and only top-level rows, which excludes things like
            // cloze subquestions that should never be offered on their own.
            $questionids = \question_bank::get_finder()->get_questions_from_categories([(int) $category->id], '');

            foreach ($questionids as $questionid) {
                // Already carries contextid and a fully loaded ->options,
                // which is everything writequestion() needs; tags travel
                // inside the XML it produces, with no extra code here.
                $question = \question_bank::load_question_data($questionid);

                $children[] = [
                    'type' => 'question',
                    'sortorder' => $order++,
                    'fields' => [
                        'remoteid' => (int) $question->questionbankentryid,
                        'categoryid' => (int) $category->id,
                        'qtype' => (string) $question->qtype,
                        'xml' => $writer->writequestion($question),
                    ],
                ];
            }
        }

        return $children;
    }

    /**
     * DESTINATION SIDE. Create the activity, then rebuild its category tree
     * and questions.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload what the source site sent
     * @param string $idnumber the ID number to stamp on the new activity
     * @return \stdClass
     */
    public function create_from_remote_data(
        \stdClass $course,
        activity_payload $payload,
        string $idnumber
    ): \stdClass {
        global $CFG, $DB;

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/bank.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        require_once($CFG->dirroot . '/mod/qbank/lib.php');

        // A qbank does not declare FEATURE_CAN_DISPLAY, so Moodle never shows
        // it on the course page and only ever creates one in section 0 -
        // mod_qbank_generator enforces the same rule. The source's section
        // number is not carried; there is nowhere else for this to go.
        $sectionnum = 0;
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);
        $data->type = $payload->setting('type', \core_question\local\bank\question_bank_helper::TYPE_STANDARD);

        $instanceid = \qbank_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        $modcontext = \context_module::instance($cmid);

        // A bank half full of categories, or questions pointing at a category
        // that never arrived, is worse than an empty one: it looks synced and
        // is quietly missing things. Anything created under this context is
        // torn down on failure, the same as every other multi-row handler.
        try {
            $this->create_categories($modcontext, $payload);
            $this->create_questions($modcontext, $payload);
        } catch (\Throwable $e) {
            \question_delete_context($modcontext->id);
            $DB->delete_records('qbank', ['id' => $instanceid]);
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Rebuild the category tree: the top category this site already made
     * for the new module, plus every other category the source sent, in two
     * passes because a category's parent can be created after it is.
     *
     * @param \context_module $modcontext the new qbank's own context
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function create_categories(\context_module $modcontext, activity_payload $payload): void {
        global $DB;

        $localtop = \question_get_top_category($modcontext->id, true);

        // Pass 1: every category but the source's own top one, parented at
        // the top for now. The source's top category is not recreated - it
        // aliases the one this site already has, so nothing downstream has
        // to special-case "is this the top row".
        foreach ($payload->children('category') as $child) {
            $remoteid = activity_payload::child_int($child, 'remoteid', 0);
            $parentid = activity_payload::child_int($child, 'parentid', 0);

            if ($parentid === 0) {
                $this->remember_id('category', $remoteid, (int) $localtop->id);

                continue;
            }

            $record = (object) [
                'name' => activity_payload::child_field($child, 'name'),
                'info' => activity_payload::child_field($child, 'info'),
                'infoformat' => activity_payload::child_int($child, 'infoformat', FORMAT_HTML),
                'contextid' => $modcontext->id,
                'parent' => $localtop->id,
                'sortorder' => 999,
                'stamp' => \make_unique_id_code(),
                'idnumber' => 'coursesync-' . $remoteid,
            ];

            $this->remember_id('category', $remoteid, (int) $DB->insert_record('question_categories', $record));
        }

        // Pass 2: now every category has a local id, fix the parent links.
        foreach ($payload->children('category') as $child) {
            $remoteid = activity_payload::child_int($child, 'remoteid', 0);
            $parentid = activity_payload::child_int($child, 'parentid', 0);

            if ($parentid === 0) {
                continue;
            }

            $localid = $this->local_id('category', $remoteid);

            if ($localid === null) {
                continue;
            }

            $localparent = $this->mapped_id('category', $parentid);

            $DB->set_field(
                'question_categories',
                'parent',
                $localparent > 0 ? $localparent : $localtop->id,
                ['id' => $localid]
            );
        }
    }

    /**
     * Rebuild every question of a supported type, once every category
     * exists to hold it.
     *
     * @param \context_module $modcontext the new qbank's own context
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function create_questions(\context_module $modcontext, activity_payload $payload): void {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/lib/classes/event/question_created.php');

        $reader = new \qformat_xml();

        foreach ($payload->children('question') as $child) {
            $categoryid = $this->mapped_id('category', activity_payload::child_int($child, 'categoryid', 0));

            // A question whose category never arrived belongs nowhere.
            if ($categoryid === 0) {
                continue;
            }

            $qtype = activity_payload::child_field($child, 'qtype');

            if (!in_array($qtype, self::SUPPORTED_QTYPES, true)) {
                $this->unsupportedcount++;

                continue;
            }

            $remoteid = activity_payload::child_int($child, 'remoteid', 0);
            $fragment = activity_payload::child_field($child, 'xml');

            // The XML reader only parses a whole file, so a single fragment
            // is wrapped the same way a one-question export file would be.
            // readquestions() returns false on malformed XML rather than
            // throwing, so this is a fragment this handler could not rebuild
            // rather than a reason to fail the whole bank.
            $parsed = $reader->readquestions(['<?xml version="1.0" encoding="UTF-8"?><quiz>' . $fragment . '</quiz>']);
            $question = is_array($parsed) ? reset($parsed) : false;

            if (!$question || $question->qtype !== $qtype) {
                $this->unsupportedcount++;

                continue;
            }

            $this->save_question($modcontext, $categoryid, $remoteid, $question);
        }
    }

    /**
     * Save one already-parsed question, following the same sequence
     * qformat_default::importprocess() uses for a whole file, minus the
     * parts that only make sense there: cross-question grade validation,
     * category-switch markers (categories are this handler's own child
     * type instead), and echoing progress for a browser to display.
     *
     * @param \context_module $modcontext the new qbank's own context
     * @param int $categoryid the local category this question belongs in
     * @param int $remoteid the question's id on the source site
     * @param \stdClass $question parsed by qformat_xml::readquestions()
     * @return void
     */
    protected function save_question(
        \context_module $modcontext,
        int $categoryid,
        int $remoteid,
        \stdClass $question
    ): void {
        global $DB, $USER;

        $question->category = $categoryid;
        $question->context = $modcontext;
        $question->contextid = $modcontext->id;
        $question->stamp = \make_unique_id_code();
        $question->createdby = $USER->id;
        $question->timecreated = time();
        $question->modifiedby = $USER->id;
        $question->timemodified = time();
        $question->idnumber = 'coursesync-' . $remoteid;

        if (
            $DB->record_exists('question_bank_entries', [
            'idnumber' => $question->idnumber,
            'questioncategoryid' => $categoryid,
            ])
        ) {
            // Cannot happen in practice - each source id is only ever sent
            // once per sync - but a duplicate idnumber is a hard database
            // error, not something worth failing the whole bank over.
            unset($question->idnumber);
        }

        $fileoptions = ['subdirs' => true, 'maxfiles' => -1, 'maxbytes' => 0];

        $question->id = $DB->insert_record('question', $question);

        $entry = (object) [
            'questioncategoryid' => $categoryid,
            'idnumber' => $question->idnumber ?? null,
            'ownerid' => $question->createdby,
            'nextversion' => 2,
        ];
        $entry->id = $DB->insert_record('question_bank_entries', $entry);

        $version = (object) [
            'questionbankentryid' => $entry->id,
            'questionid' => $question->id,
            'version' => 1,
            'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
        ];
        $DB->insert_record('question_versions', $version);

        if (isset($question->questiontextitemid)) {
            $question->questiontext = \file_save_draft_area_files(
                $question->questiontextitemid,
                $modcontext->id,
                'question',
                'questiontext',
                $question->id,
                $fileoptions,
                $question->questiontext
            );
            \file_clear_draft_area($question->questiontextitemid);
        } else if (isset($question->questiontextfiles)) {
            foreach ($question->questiontextfiles as $file) {
                \question_bank::get_qtype($question->qtype)->import_file(
                    $modcontext,
                    'question',
                    'questiontext',
                    $question->id,
                    $file
                );
            }
        }

        if (isset($question->generalfeedbackitemid)) {
            $question->generalfeedback = \file_save_draft_area_files(
                $question->generalfeedbackitemid,
                $modcontext->id,
                'question',
                'generalfeedback',
                $question->id,
                $fileoptions,
                $question->generalfeedback
            );
            \file_clear_draft_area($question->generalfeedbackitemid);
        } else if (isset($question->generalfeedbackfiles)) {
            foreach ($question->generalfeedbackfiles as $file) {
                \question_bank::get_qtype($question->qtype)->import_file(
                    $modcontext,
                    'question',
                    'generalfeedback',
                    $question->id,
                    $file
                );
            }
        }

        $DB->update_record('question', $question);

        \question_bank::get_qtype($question->qtype)->save_question_options($question);

        \core\event\question_created::create_from_question_instance($question, $modcontext)->trigger();

        if (\core_tag_tag::is_enabled('core_question', 'question')) {
            $tags = array_merge($question->coursetags ?? [], $question->tags ?? []);
            \core_tag_tag::set_item_tags('core_question', 'question', $question->id, $modcontext, $tags);
        }
    }

    /**
     * Anything a teacher should be told about this activity once it is
     * created.
     *
     * @param activity_payload $payload what the source site sent
     * @return array<string|array{0:string,1:mixed}>
     */
    public function notes(activity_payload $payload): array {
        $notes = ['syncqbanknoattempts'];

        if ($this->unsupportedcount > 0) {
            $notes[] = ['syncqbankunsupportedcount', $this->unsupportedcount];
        }

        return $notes;
    }
}
