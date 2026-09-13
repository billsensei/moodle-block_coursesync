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
 * Recreates a qtype_match question from match_question_exporter's payload,
 * via \question_bank::get_qtype('match')->save_question().
 *
 * Defensive filtering that matters here specifically: if
 * qtype_match::save_question_options() is given a subquestion with non-empty
 * text but a blank answer, it doesn't throw - it calls the legacy notice()
 * helper, which prints a page and calls exit() (see lib/weblib.php). That
 * would take down the whole sync request, not just fail this one activity
 * like every other error path in this plugin does. A properly-authored
 * source question can't have such a pair (its own edit form validation
 * blocks it), but this plugin never assumes remote data matches what its own
 * exporter would produce (same principle as forum_activity_handler's type
 * fallback) - so apply_subquestions() drops any pair with a blank answer
 * before it ever reaches match's own code, making that call unreachable here.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class match_question_handler implements question_handler {
    /**
     * Creates the question.
     *
     * @param array $data Payload from match_question_exporter::export().
     * @param string $categoryspec
     * @return int The new question's id.
     */
    public function create(array $data, string $categoryspec): int {
        $question = new \stdClass();
        $question->qtype = 'match';

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

        $form->shuffleanswers = sanitizer::integer($data['shuffleanswers'] ?? 1, 1);
        $form->shownumcorrect = sanitizer::integer($data['shownumcorrect'] ?? 0);
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

        $this->apply_subquestions($form, $data['subquestions'] ?? []);

        $result = \question_bank::get_qtype('match')->save_question($question, $form);

        return (int) $result->id;
    }

    /**
     * Sets $form->subquestions/subanswers from the exported subquestions
     * list - dropping any pair with a blank answer (see class docblock).
     *
     * @param \stdClass $form Modified in place.
     * @param array $subquestions From match_question_exporter::export_subquestions().
     */
    protected function apply_subquestions(\stdClass $form, array $subquestions): void {
        $form->subquestions = [];
        $form->subanswers = [];

        $key = 0;
        foreach ($subquestions as $sub) {
            $answertext = sanitizer::text($sub['answertext'] ?? '');
            if ($answertext === '') {
                // Unusable either way, and a non-blank question text paired
                // with a blank answer would otherwise hit qtype_match's own
                // notice()-and-exit() path - see class docblock.
                continue;
            }

            $form->subquestions[$key] = question_file_helper::richfield(
                $sub['questiontext'] ?? '',
                $sub['questiontextformat'] ?? FORMAT_HTML,
                $sub['questiontextfiles'] ?? []
            );
            $form->subanswers[$key] = $answertext;
            $key++;
        }
    }
}
