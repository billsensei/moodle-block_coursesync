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
 * Exports a qtype_match question - combined feedback and every
 * question/answer pair (subquestion) - see qtype_match::save_question_options()
 * for the exact shape this mirrors on the way back in.
 *
 * A subquestion's own text carries files (component 'qtype_match', filearea
 * 'subquestion'); its answertext is plain PARAM_TEXT, same reasoning as
 * shortanswer's answer field - qtype_match::save_question_options() assigns
 * $question->subanswers[$key] straight to the DB column with no
 * import_or_save_files() call.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class match_question_exporter implements question_exporter {
    /**
     * Builds the payload match_question_handler::create() expects.
     *
     * @param \stdClass $question
     * @param \context $context
     * @return array
     */
    public function export(\stdClass $question, \context $context): array {
        global $DB;

        $options = $DB->get_record('qtype_match_options', ['questionid' => $question->id], '*', MUST_EXIST);

        return [
            'name' => $question->name,
            'questiontext' => (string) $question->questiontext,
            'questiontextformat' => (int) $question->questiontextformat,
            'questiontextfiles' => question_file_helper::export_files(
                $context->id,
                'question',
                'questiontext',
                $question->id
            ),
            'generalfeedback' => (string) $question->generalfeedback,
            'generalfeedbackformat' => (int) $question->generalfeedbackformat,
            'generalfeedbackfiles' => question_file_helper::export_files(
                $context->id,
                'question',
                'generalfeedback',
                $question->id
            ),
            'defaultmark' => (float) $question->defaultmark,
            'penalty' => (float) $question->penalty,
            'shuffleanswers' => (int) $options->shuffleanswers,
            'shownumcorrect' => (int) $options->shownumcorrect,
            'correctfeedback' => (string) $options->correctfeedback,
            'correctfeedbackformat' => (int) $options->correctfeedbackformat,
            'correctfeedbackfiles' => question_file_helper::export_files(
                $context->id,
                'question',
                'correctfeedback',
                $question->id
            ),
            'partiallycorrectfeedback' => (string) $options->partiallycorrectfeedback,
            'partiallycorrectfeedbackformat' => (int) $options->partiallycorrectfeedbackformat,
            'partiallycorrectfeedbackfiles' => question_file_helper::export_files(
                $context->id,
                'question',
                'partiallycorrectfeedback',
                $question->id
            ),
            'incorrectfeedback' => (string) $options->incorrectfeedback,
            'incorrectfeedbackformat' => (int) $options->incorrectfeedbackformat,
            'incorrectfeedbackfiles' => question_file_helper::export_files(
                $context->id,
                'question',
                'incorrectfeedback',
                $question->id
            ),
            'subquestions' => $this->export_subquestions($question->id, $context),
        ];
    }

    /**
     * Exports every subquestion (question/answer pair), in order.
     *
     * @param int $questionid
     * @param \context $context
     * @return array<int, array>
     */
    protected function export_subquestions(int $questionid, \context $context): array {
        global $DB;

        $subquestions = $DB->get_records('qtype_match_subquestions', ['questionid' => $questionid], 'id ASC');

        $exported = [];
        foreach ($subquestions as $sub) {
            $exported[] = [
                'questiontext' => (string) $sub->questiontext,
                'questiontextformat' => (int) $sub->questiontextformat,
                'questiontextfiles' => question_file_helper::export_files(
                    $context->id,
                    'qtype_match',
                    'subquestion',
                    $sub->id
                ),
                'answertext' => (string) $sub->answertext,
            ];
        }

        return $exported;
    }
}
