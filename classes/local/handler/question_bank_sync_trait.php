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
 * Exporting and rebuilding question bank categories and questions, shared
 * between qbank_handler (a whole Question Bank activity) and quiz_handler (a
 * quiz's referenced categories and questions, landing in the destination
 * course's shared System Bank instead of a dedicated activity).
 *
 * Both handlers already extend activity_handler, so this trait can call its
 * protected remember_id()/local_id()/mapped_id() freely - what it adds is
 * everything about categories and questions specifically, kept in one place
 * so the two handlers cannot quietly drift apart on how a question is
 * serialised or saved.
 *
 * Question categories and question_bank_entries can now legitimately be
 * asked for twice - two quizzes can share a question, or a quiz and a
 * question bank sync can both want the same one, and both can land in the
 * same course's shared System Bank. Every insert here is therefore preceded
 * by an idnumber lookup: find and reuse rather than duplicate. That single
 * change is also why save_question() records a local id for every question
 * it is asked for, reused or freshly created - qbank_handler never needed a
 * question's own id before, only quiz_handler's slot-wiring does.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait question_bank_sync_trait {
    /** @var string[] The question types this handler knows how to rebuild. */
    protected const SUPPORTED_QTYPES = [
        'multichoice', 'truefalse', 'shortanswer', 'match', 'essay', 'numerical', 'multianswer',
        'ddwtos', 'ddimageortext', 'ddmarker',
    ];

    /** @var int Questions that arrived as a type this handler does not rebuild. */
    protected int $unsupportedcount = 0;

    /**
     * SOURCE SIDE. Every one of the given categories, and every question in
     * a supported type in each, as a flat children list.
     *
     * Callers resolve which category ids they need first: qbank_handler
     * wants every category under its own module context, quiz_handler wants
     * only the categories its slots actually reference.
     *
     * @param int[] $categoryids
     * @param int $order sortorder to start counting from
     * @return array[]
     */
    protected function export_question_bank_children(array $categoryids, int $order = 0): array {
        global $DB, $CFG;

        if ($categoryids === []) {
            return [];
        }

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/bank.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        [$insql, $inparams] = $DB->get_in_or_equal($categoryids);
        $categories = $DB->get_records_select('question_categories', "id {$insql}", $inparams, 'id ASC');

        $children = [];
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
     * DESTINATION SIDE. Rebuild a category tree under the given context: the
     * target's own top category, plus every other category the source sent,
     * in two passes because a category's parent can be created after it is.
     *
     * @param \context $targetcontext where the categories belong
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function sync_question_bank_categories(\context $targetcontext, activity_payload $payload): void {
        global $DB;

        $localtop = \question_get_top_category($targetcontext->id, true);

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

            $idnumber = 'coursesync-' . $remoteid;

            // Already here, from an earlier sync into this same shared
            // context - reuse it rather than creating a second category
            // with an idnumber this context's own unique index would
            // refuse anyway.
            $existingid = $DB->get_field('question_categories', 'id', [
                'contextid' => $targetcontext->id,
                'idnumber' => $idnumber,
            ]);

            if ($existingid) {
                $this->remember_id('category', $remoteid, (int) $existingid);

                continue;
            }

            $record = (object) [
                'name' => activity_payload::child_field($child, 'name'),
                'info' => activity_payload::child_field($child, 'info'),
                'infoformat' => activity_payload::child_int($child, 'infoformat', FORMAT_HTML),
                'contextid' => $targetcontext->id,
                'parent' => $localtop->id,
                'sortorder' => 999,
                'stamp' => \make_unique_id_code(),
                'idnumber' => $idnumber,
            ];

            $this->remember_id('category', $remoteid, (int) $DB->insert_record('question_categories', $record));
        }

        // Pass 2: now every category has a local id, fix the parent links.
        // A category reused above already has the right parent from
        // whichever sync created it first, so only categories this call
        // just inserted need fixing - reusing a category never has stale
        // fields to correct, only a possibly-missing one to alias.
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
     * DESTINATION SIDE. Rebuild every question of a supported type, once
     * every category exists to hold it.
     *
     * @param \context $targetcontext where the categories live
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function sync_question_bank_questions(\context $targetcontext, activity_payload $payload): void {
        global $CFG;

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

            if ($this->reuse_existing_question($targetcontext, $categoryid, $remoteid)) {
                continue;
            }

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

            $this->save_question($targetcontext, $categoryid, $remoteid, $question);
        }
    }

    /**
     * DESTINATION SIDE. If this question already exists in this category -
     * from an earlier sync into the same shared context - remember its
     * local id and report that there is nothing left to do.
     *
     * @param \context $targetcontext where the category lives
     * @param int $categoryid the local category
     * @param int $remoteid the question's id on the source site
     * @return bool true if an existing question was found and remembered
     */
    protected function reuse_existing_question(\context $targetcontext, int $categoryid, int $remoteid): bool {
        global $DB;

        $entry = $DB->get_record('question_bank_entries', [
            'idnumber' => 'coursesync-' . $remoteid,
            'questioncategoryid' => $categoryid,
        ]);

        if (!$entry) {
            return false;
        }

        $versions = $DB->get_records(
            'question_versions',
            [
                'questionbankentryid' => $entry->id,
                'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
            ],
            'version DESC',
            'id, questionid',
            0,
            1
        );
        $version = reset($versions);

        if ($version) {
            $this->remember_id('question', $remoteid, (int) $version->questionid);
        }

        return true;
    }

    /**
     * DESTINATION SIDE. Save one already-parsed question, following the
     * same sequence qformat_default::importprocess() uses for a whole file,
     * minus the parts that only make sense there: cross-question grade
     * validation, category-switch markers (categories are this handler's
     * own child type instead), and echoing progress for a browser to
     * display.
     *
     * @param \context $targetcontext where the category lives
     * @param int $categoryid the local category this question belongs in
     * @param int $remoteid the question's id on the source site
     * @param \stdClass $question parsed by qformat_xml::readquestions()
     * @return void
     */
    protected function save_question(
        \context $targetcontext,
        int $categoryid,
        int $remoteid,
        \stdClass $question
    ): void {
        global $DB, $USER;

        $question->category = $categoryid;
        $question->context = $targetcontext;
        $question->contextid = $targetcontext->id;
        $question->stamp = \make_unique_id_code();
        $question->createdby = $USER->id;
        $question->timecreated = time();
        $question->modifiedby = $USER->id;
        $question->timemodified = time();
        $question->idnumber = 'coursesync-' . $remoteid;

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
                $targetcontext->id,
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
                    $targetcontext,
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
                $targetcontext->id,
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
                    $targetcontext,
                    'question',
                    'generalfeedback',
                    $question->id,
                    $file
                );
            }
        }

        $DB->update_record('question', $question);

        \question_bank::get_qtype($question->qtype)->save_question_options($question);

        \core\event\question_created::create_from_question_instance($question, $targetcontext)->trigger();

        if (\core_tag_tag::is_enabled('core_question', 'question')) {
            $tags = array_merge($question->coursetags ?? [], $question->tags ?? []);
            \core_tag_tag::set_item_tags('core_question', 'question', $question->id, $targetcontext, $tags);
        }

        $this->remember_id('question', $remoteid, $question->id);
    }
}
