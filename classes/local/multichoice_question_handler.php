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
 * Recreates a qtype_multichoice question from
 * multichoice_question_exporter's payload, via
 * \question_bank::get_qtype('multichoice')->save_question() - the same core
 * entry point the question bank's own edit form submits to (see
 * question_handler's docblock for why this, rather than hand-written
 * inserts, is used).
 *
 * $form->answer[$key] must be the ['text','format','files'] shape itself
 * (not a separate wrapper): qtype_multichoice::save_question_options() reads
 * $question->answer[$key] directly as the argument to
 * import_or_save_files() - see that method's body.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multichoice_question_handler implements question_handler {
    /**
     * Creates the question.
     *
     * @param array $data Payload from multichoice_question_exporter::export().
     * @param string $categoryspec
     * @return int The new question's id.
     */
    public function create(array $data, string $categoryspec): int {
        $question = new \stdClass();
        $question->qtype = 'multichoice';

        $form = new \stdClass();
        $form->category = $categoryspec;
        $form->name = sanitizer::text($data['name'] ?? '');
        $form->questiontext = [
            'text' => sanitizer::html($data['questiontext'] ?? ''),
            'format' => sanitizer::textformat($data['questiontextformat'] ?? FORMAT_HTML),
            'itemid' => question_file_helper::store_in_draft_area($data['questiontextfiles'] ?? []),
        ];
        $form->generalfeedback = [
            'text' => sanitizer::html($data['generalfeedback'] ?? ''),
            'format' => sanitizer::textformat($data['generalfeedbackformat'] ?? FORMAT_HTML),
            'itemid' => question_file_helper::store_in_draft_area($data['generalfeedbackfiles'] ?? []),
        ];
        $form->defaultmark = sanitizer::float($data['defaultmark'] ?? 1, 1.0);
        $form->penalty = sanitizer::float($data['penalty'] ?? 0.3333333, 0.3333333);

        $form->single = sanitizer::integer($data['single'] ?? 1, 1);
        $form->shuffleanswers = sanitizer::integer($data['shuffleanswers'] ?? 1, 1);
        $form->answernumbering = $this->sanitize_answernumbering($data['answernumbering'] ?? 'abc');
        $form->shownumcorrect = sanitizer::integer($data['shownumcorrect'] ?? 0);
        $form->showstandardinstruction = sanitizer::integer($data['showstandardinstruction'] ?? 0);

        $form->correctfeedback = question_file_helper::richfield(
            $data['correctfeedback'] ?? '',
            $data['correctfeedbackformat'] ?? FORMAT_HTML,
            $data['correctfeedbackfiles'] ?? []
        );
        $form->partiallycorrectfeedback = question_file_helper::richfield(
            $data['partiallycorrectfeedback'] ?? '',
            $data['partiallycorrectfeedbackformat'] ?? FORMAT_HTML,
            $data['partiallycorrectfeedbackfiles'] ?? []
        );
        $form->incorrectfeedback = question_file_helper::richfield(
            $data['incorrectfeedback'] ?? '',
            $data['incorrectfeedbackformat'] ?? FORMAT_HTML,
            $data['incorrectfeedbackfiles'] ?? []
        );

        $this->apply_answers($form, $data['answers'] ?? []);

        $result = \question_bank::get_qtype('multichoice')->save_question($question, $form);

        return (int) $result->id;
    }

    /**
     * Sets $form->answer/fraction/feedback from the exported answers list.
     *
     * @param \stdClass $form Modified in place.
     * @param array $answers From multichoice_question_exporter::export_answers().
     */
    protected function apply_answers(\stdClass $form, array $answers): void {
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];

        foreach (array_values($answers) as $key => $answer) {
            $form->answer[$key] = question_file_helper::richfield(
                $answer['answertext'] ?? '',
                $answer['answerformat'] ?? FORMAT_HTML,
                $answer['answerfiles'] ?? []
            );
            $form->fraction[$key] = sanitizer::float($answer['fraction'] ?? 0);
            $form->feedback[$key] = question_file_helper::richfield(
                $answer['feedback'] ?? '',
                $answer['feedbackformat'] ?? FORMAT_HTML,
                $answer['feedbackfiles'] ?? []
            );
        }
    }

    /**
     * Validates a remote-supplied answernumbering against the fixed set
     * qtype_multichoice itself recognises (its own install.xml column
     * definition), same approach as forum_activity_handler's type check -
     * falls back to the safe default rather than storing an arbitrary
     * string later code branches on.
     *
     * @param mixed $value
     * @return string
     */
    protected function sanitize_answernumbering($value): string {
        $known = ['abc', 'ABCD', '123', 'iii', 'IIII', 'none'];
        $value = (string) $value;

        return in_array($value, $known, true) ? $value : 'abc';
    }
}
