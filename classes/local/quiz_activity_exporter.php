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
 * randomly from category X" rather than one fixed question) is exported as
 * unsupported rather than resolved to any particular question - there's no
 * single question on the source that could travel across; see
 * quiz_activity_handler's docblock for how the destination reports this.
 * Quiz feedback boundaries (quiz_feedback - the "well done" / "please
 * revise" messages shown for a grade range) are not exported either - see
 * quiz_activity_handler's docblock for why.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_activity_exporter implements activity_exporter {
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
     * @param int $quizid
     * @param \context_module $context
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
                // A "pick randomly from category X" reference - no single
                // question to export. Reported the same way an unsupported
                // qtype is, below.
                $exported[] = $entry + ['supported' => false, 'qtype' => 'random', 'name' => (string) $slot->name];
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

            $question = new \stdClass();
            $question->id = (int) $slot->questionid;
            $question->name = $slot->name;
            $question->questiontext = $slot->questiontext;
            $question->questiontextformat = (int) $slot->questiontextformat;
            $question->generalfeedback = $slot->generalfeedback;
            $question->generalfeedbackformat = (int) $slot->generalfeedbackformat;
            $question->defaultmark = (float) $slot->defaultmark;
            $question->penalty = (float) $slot->penalty;
            $question->qtype = $slot->qtype;

            $payload = question_exporter_registry::get_exporter($slot->qtype)->export($question, $context);

            $exported[] = $entry + ['supported' => true, 'qtype' => (string) $slot->qtype, 'question' => $payload];
        }

        return $exported;
    }
}
