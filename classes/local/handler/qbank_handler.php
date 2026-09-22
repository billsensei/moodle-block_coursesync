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
 *    quiz_handler's random-slot support is the one place in this plugin
 *    that now does check something extra, and for a different reason - see
 *    that class and SECURITY.md.
 *
 * Only seven question types are rebuilt - multiple choice, true/false,
 * short answer, matching, essay, numerical, multianswer (cloze) - because
 * those cover most real question banks and each one's shape has actually
 * been checked against this handler. Multianswer needs nothing special
 * here despite its embedded sub-questions: qformat_xml's own reader
 * already resolves them from the parent's questiontext before
 * save_question() ever sees it, and qtype_multianswer::save_question_options()
 * saves them itself, the same one call this handler already makes for
 * every other type. A question of any other type is left out and counted;
 * see notes(). Only the current ready version of each question is copied -
 * no drafts, no hidden versions, no history - matching how every other
 * type in this plugin carries current state rather than a log of changes.
 *
 * The category/question export and rebuild logic lives in
 * question_bank_sync_trait, shared with quiz_handler - a quiz's slots can
 * reference questions and categories too, and they land in the destination
 * course's own shared System Bank rather than a dedicated activity. This
 * class is the "whole activity" half: everything under one module's own
 * context, all at once.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbank_handler extends activity_handler {
    use question_bank_sync_trait;

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
        global $DB;

        $context = \context_module::instance($cm->id);
        $categoryids = array_keys(
            $DB->get_records('question_categories', ['contextid' => $context->id], 'id ASC', 'id')
        );

        return $this->export_question_bank_children($categoryids);
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
            $this->sync_question_bank_categories($modcontext, $payload);
            $this->sync_question_bank_questions($modcontext, $payload);
        } catch (\Throwable $e) {
            \question_delete_context($modcontext->id);
            $DB->delete_records('qbank', ['id' => $instanceid]);
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        return $this->finish_creation($course, $cmid, $sectionnum);
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
