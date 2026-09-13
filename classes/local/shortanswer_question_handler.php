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
 * Recreates a qtype_shortanswer question from
 * shortanswer_question_exporter's payload, via
 * \question_bank::get_qtype('shortanswer')->save_question().
 *
 * $form->answer[$key] is plain text (PARAM_TEXT via sanitizer::text()), not
 * HTML - see the exporter's docblock for why.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shortanswer_question_handler implements question_handler {
    /**
     * Creates the question.
     *
     * @param array $data Payload from shortanswer_question_exporter::export().
     * @param string $categoryspec
     * @return int The new question's id.
     */
    public function create(array $data, string $categoryspec): int {
        $question = new \stdClass();
        $question->qtype = 'shortanswer';

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
        $form->usecase = sanitizer::integer($data['usecase'] ?? 0);

        $this->apply_answers($form, $data['answers'] ?? []);

        $result = \question_bank::get_qtype('shortanswer')->save_question($question, $form);

        return (int) $result->id;
    }

    /**
     * Sets $form->answer/fraction/feedback from the exported answers list.
     *
     * @param \stdClass $form Modified in place.
     * @param array $answers From shortanswer_question_exporter::export_answers().
     */
    protected function apply_answers(\stdClass $form, array $answers): void {
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];

        foreach (array_values($answers) as $key => $answer) {
            $form->answer[$key] = sanitizer::text($answer['answer'] ?? '');
            $form->fraction[$key] = sanitizer::float($answer['fraction'] ?? 0);
            $form->feedback[$key] = question_file_helper::richfield(
                $answer['feedback'] ?? '',
                $answer['feedbackformat'] ?? FORMAT_HTML,
                $answer['feedbackfiles'] ?? []
            );
        }
    }
}
