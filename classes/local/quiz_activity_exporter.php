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
 * Exports a mod_quiz instance's settings AND its slots (Phase 10) - the
 * first exporter whose payload itself embeds other exporters' payloads: each
 * slot with a fixed question reference gets that question's own
 * question_exporter payload nested inside it (see question_exporter.php).
 *
 * Settings only for the quiz itself, same scope as this plugin's other
 * "settings, not content people generated while using the activity"
 * exporters (assign, forum): no attempts, grades, or overrides are ever
 * read. A slot using a random reference (question_set_references - "pick
 * randomly from category X" rather than one fixed question, the standard
 * "Add > a random question" flow) has no single question to export, but IS
 * still synced: every currently-eligible question in that category (same
 * filter - category/subcategories/tags - core's own random_question_loader
 * uses to pick one at attempt time, resolved via
 * \core_question\local\bank\filter_condition_manager the same way) is
 * exported as a pool, and quiz_activity_handler recreates a real random slot
 * on the destination drawing from a synced copy of that pool - see
 * export_random_pool() and quiz_activity_handler's docblock for exactly what
 * this does and doesn't preserve. Only when nothing in the category resolves
 * (an empty/deleted category, every question in it an unsupported qtype, or
 * a filter shape this plugin doesn't understand) does a random slot fall
 * back to being reported as unsupported, same as before.
 * Quiz feedback boundaries (quiz_feedback - the "well done" / "please
 * revise" messages shown for a grade range) are not exported either - see
 * quiz_activity_handler's docblock for why.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_activity_exporter implements activity_exporter {
    /** Safety cap on how many questions one random slot's pool export pulls - see export_random_pool(). */
    protected const MAX_RANDOM_POOL = 200;

    /**
     * Builds the payload quiz_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/classes/question/bank/qbank_helper.php');

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return [
            'name' => $quiz->name,
            'intro' => (string) $quiz->intro,
            'introformat' => (int) $quiz->introformat,
            'timeopen' => (int) $quiz->timeopen,
            'timeclose' => (int) $quiz->timeclose,
            'timelimit' => (int) $quiz->timelimit,
            'overduehandling' => (string) $quiz->overduehandling,
            'graceperiod' => (int) $quiz->graceperiod,
            'preferredbehaviour' => (string) $quiz->preferredbehaviour,
            'canredoquestions' => (int) $quiz->canredoquestions,
            'attempts' => (int) $quiz->attempts,
            'attemptonlast' => (int) $quiz->attemptonlast,
            'grademethod' => (int) $quiz->grademethod,
            'decimalpoints' => (int) $quiz->decimalpoints,
            'questiondecimalpoints' => (int) $quiz->questiondecimalpoints,
            'questionsperpage' => (int) $quiz->questionsperpage,
            'navmethod' => (string) $quiz->navmethod,
            'shuffleanswers' => (int) $quiz->shuffleanswers,
            'grade' => (float) $quiz->grade,
            'showuserpicture' => (int) $quiz->showuserpicture,
            'reviewattempt' => (int) $quiz->reviewattempt,
            'reviewcorrectness' => (int) $quiz->reviewcorrectness,
            'reviewmaxmarks' => (int) $quiz->reviewmaxmarks,
            'reviewmarks' => (int) $quiz->reviewmarks,
            'reviewspecificfeedback' => (int) $quiz->reviewspecificfeedback,
            'reviewgeneralfeedback' => (int) $quiz->reviewgeneralfeedback,
            'reviewrightanswer' => (int) $quiz->reviewrightanswer,
            'reviewoverallfeedback' => (int) $quiz->reviewoverallfeedback,
            'slots' => $this->export_slots((int) $cm->instance, $context),
        ];
    }

    /**
     * Exports every slot, in order, resolving each fixed-question reference
     * to that question's own qtype payload via
     * \mod_quiz\question\bank\qbank_helper::get_question_structure() - the
     * same core helper the quiz editing screen itself uses to work out what
     * question is really in each slot (handling "always latest version" vs.
     * a pinned version, and random references, correctly - see that
     * method's own docblock).
     *
     * $context here is the QUIZ's own module context - only needed for
     * get_question_structure()'s usingcontextid join (a slot's question
     * reference always lives in the quiz's own context, regardless of which
     * category/context the underlying question itself is filed under). Each
     * question's own file areas are read from ITS category's context
     * instead (get_question_structure() already resolves this per slot as
     * ->contextid - see question_exporter's own docblock) - conflating the
     * two used to silently drop embedded question/answer/feedback files for
     * any question living outside the quiz's own context (i.e. anything
     * pulled from a shared course-level bank, the normal case).
     *
     * @param int $quizid
     * @param \context_module $context The quiz's own module context.
     * @return array<int, array>
     */
    protected function export_slots(int $quizid, \context_module $context): array {
        $slotdata = \mod_quiz\question\bank\qbank_helper::get_question_structure($quizid, $context);

        $exported = [];
        foreach ($slotdata as $slot) {
            $entry = [
                'slot' => (int) $slot->slot,
                'page' => (int) $slot->page,
                'maxmark' => (float) $slot->maxmark,
            ];

            if (!empty($slot->random)) {
                $pool = $this->export_random_pool($slot);
                if (empty($pool)) {
                    // Nothing resolvable - an empty/deleted category, every
                    // question in it an unsupported qtype, or a filter shape
                    // this plugin doesn't understand. Reported the same way
                    // an unsupported qtype is, below.
                    $exported[] = $entry + ['supported' => false, 'qtype' => 'random', 'name' => (string) $slot->name];
                } else {
                    $exported[] = $entry + ['supported' => true, 'qtype' => 'random', 'questions' => $pool];
                }
                continue;
            }

            if (!question_exporter_registry::is_supported($slot->qtype)) {
                $exported[] = $entry + [
                    'supported' => false,
                    'qtype' => (string) $slot->qtype,
                    'name' => (string) ($slot->name ?? ''),
                ];
                continue;
            }

            $payload = $this->export_question(
                (int) $slot->questionid,
                $slot->name,
                $slot->questiontext,
                (int) $slot->questiontextformat,
                $slot->generalfeedback,
                (int) $slot->generalfeedbackformat,
                (float) $slot->defaultmark,
                (float) $slot->penalty,
                (string) $slot->qtype,
                (int) $slot->contextid
            );

            $exported[] = $entry + ['supported' => true, 'qtype' => (string) $slot->qtype, 'question' => $payload];
        }

        return $exported;
    }

    /**
     * Builds one question's own payload via its qtype's question_exporter,
     * reading its file areas from ITS OWN category's context - shared by a
     * fixed slot's single question (export_slots()) and every question in a
     * random slot's pool (export_random_pool()), which is exactly the same
     * operation on a different source of question ids.
     *
     * @param int $questionid
     * @param string $name
     * @param string $questiontext
     * @param int $questiontextformat
     * @param string $generalfeedback
     * @param int $generalfeedbackformat
     * @param float $defaultmark
     * @param float $penalty
     * @param string $qtype
     * @param int $contextid The question's own category's context id.
     * @return array
     */
    protected function export_question(
        int $questionid,
        string $name,
        string $questiontext,
        int $questiontextformat,
        string $generalfeedback,
        int $generalfeedbackformat,
        float $defaultmark,
        float $penalty,
        string $qtype,
        int $contextid
    ): array {
        $question = new \stdClass();
        $question->id = $questionid;
        $question->name = $name;
        $question->questiontext = $questiontext;
        $question->questiontextformat = $questiontextformat;
        $question->generalfeedback = $generalfeedback;
        $question->generalfeedbackformat = $generalfeedbackformat;
        $question->defaultmark = $defaultmark;
        $question->penalty = $penalty;
        $question->qtype = $qtype;

        $questioncontext = \core\context::instance_by_id($contextid);

        return question_exporter_registry::get_exporter($qtype)->export($question, $questioncontext);
    }

    /**
     * Resolves a random slot's current candidate pool - every question that
     * \core_question\local\bank\random_question_loader would be eligible to
     * pick for this exact slot right now - and exports each one with a
     * supported qtype, keyed with its own qtype since a pool can (and often
     * does) mix several question types.
     *
     * Uses the identical filter-condition machinery core's own
     * random_question_loader does (every registered qbank plugin's
     * condition class, run against the slot's own decoded filtercondition,
     * see \core_question\local\bank\filter_condition_manager), so this
     * respects the same category/subcategories/tag/... criteria a real
     * attempt's random pick would - not just a bare category id.
     *
     * What this deliberately does NOT preserve: the source's own use-count
     * balancing (random_question_loader prefers less-used questions; a
     * fresh destination pool has no attempt history to balance against
     * anyway), and a slot referencing the exact same source category as
     * another slot in the same quiz gets its own independent copy of that
     * category's pool on the destination rather than sharing one - see
     * quiz_activity_handler::create_random_slot()'s docblock for why.
     * Capped at self::MAX_RANDOM_POOL questions (ordered by id) as a
     * defensive limit against an unexpectedly huge shared category.
     *
     * @param \stdClass $slot One entry from qbank_helper::get_question_structure(), with ->random true.
     * @return array<int, array{qtype: string, question: array}>
     */
    protected function export_random_pool(\stdClass $slot): array {
        global $DB;

        $filters = $slot->filtercondition['filter'] ?? [];
        if (empty($filters)) {
            return [];
        }

        $params = [];
        $conditions = [];
        foreach (\core_question\local\bank\filter_condition_manager::get_condition_classes() as $conditionclass) {
            $filter = $conditionclass::get_filter_from_list($filters);
            if ($filter === null) {
                continue;
            }
            [$where, $whereparams] = $conditionclass::build_query_from_filter($filter);
            if (!empty($where)) {
                $conditions[] = '(' . $where . ')';
            }
            if (!empty($whereparams)) {
                $params = array_merge($params, $whereparams);
            }
        }

        if (empty($conditions)) {
            // No condition class recognised this filter shape at all - refuse
            // to export an unfiltered "every question on the site" pool.
            return [];
        }

        $conditionsql = implode(' AND ', $conditions);
        $params += [
            'noparent' => 0,
            'ready' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
        ];

        $rows = $DB->get_records_sql("
                SELECT q.*, qc.contextid AS categorycontextid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE q.parent = :noparent
                   AND $conditionsql
                   AND qv.version = (
                         SELECT MAX(version)
                           FROM {question_versions}
                          WHERE questionbankentryid = qbe.id
                            AND status = :ready
                       )
              ORDER BY q.id
        ", $params, 0, self::MAX_RANDOM_POOL);

        $pool = [];
        foreach ($rows as $row) {
            if (!question_exporter_registry::is_supported($row->qtype)) {
                // Same "each has its own schema, not pulled" scope cut as an
                // unsupported qtype anywhere else - skipped per-question
                // rather than dropping the whole pool.
                continue;
            }

            $pool[] = [
                'qtype' => (string) $row->qtype,
                'question' => $this->export_question(
                    (int) $row->id,
                    (string) $row->name,
                    (string) $row->questiontext,
                    (int) $row->questiontextformat,
                    (string) $row->generalfeedback,
                    (int) $row->generalfeedbackformat,
                    (float) $row->defaultmark,
                    (float) $row->penalty,
                    (string) $row->qtype,
                    (int) $row->categorycontextid
                ),
            ];
        }

        return $pool;
    }
}
