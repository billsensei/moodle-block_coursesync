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
 * Exports a qtype_multichoice question - settings, combined feedback, and
 * every answer (including each answer's own feedback) - see
 * qtype_multichoice::save_question_options() for the exact shape this
 * mirrors on the way back in.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multichoice_question_exporter implements question_exporter {
    /**
     * Builds the payload multichoice_question_handler::create() expects.
     *
     * @param \stdClass $question
     * @param \context $context
     * @return array
     */
    public function export(\stdClass $question, \context $context): array {
        global $DB;

        $options = $DB->get_record('qtype_multichoice_options', ['questionid' => $question->id], '*', MUST_EXIST);

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
            'single' => (int) $options->single,
            'shuffleanswers' => (int) $options->shuffleanswers,
            'answernumbering' => (string) $options->answernumbering,
            'shownumcorrect' => (int) $options->shownumcorrect,
            'showstandardinstruction' => (int) $options->showstandardinstruction,
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
            'answers' => $this->export_answers($question->id, $context),
        ];
    }

    /**
     * Exports every answer, in order, with its own text and feedback files.
     *
     * @param int $questionid
     * @param \context $context
     * @return array<int, array>
     */
    protected function export_answers(int $questionid, \context $context): array {
        global $DB;

        $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id ASC');

        $exported = [];
        foreach ($answers as $answer) {
            $exported[] = [
                'answertext' => (string) $answer->answer,
                'answerformat' => (int) $answer->answerformat,
                'answerfiles' => question_file_helper::export_files(
                    $context->id,
                    'question',
                    'answer',
                    $answer->id
                ),
                'fraction' => (float) $answer->fraction,
                'feedback' => (string) $answer->feedback,
                'feedbackformat' => (int) $answer->feedbackformat,
                'feedbackfiles' => question_file_helper::export_files(
                    $context->id,
                    'question',
                    'answerfeedback',
                    $answer->id
                ),
            ];
        }

        return $exported;
    }
}
