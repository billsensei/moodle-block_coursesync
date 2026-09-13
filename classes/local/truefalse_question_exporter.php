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
 * Exports a qtype_truefalse question - see qtype_truefalse::save_question_options()
 * for the exact shape this mirrors on the way back in. Unlike every other
 * qtype in this plugin, truefalse's two answers ("True"/"False", one of them
 * correct) are entirely implied by $options->trueanswer's fraction - there's
 * nothing else in question_answers worth exporting per-answer beyond each
 * side's own feedback.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class truefalse_question_exporter implements question_exporter {
    /**
     * Builds the payload truefalse_question_handler::create() expects.
     *
     * @param \stdClass $question
     * @param \context $context
     * @return array
     */
    public function export(\stdClass $question, \context $context): array {
        global $DB;

        $options = $DB->get_record('question_truefalse', ['question' => $question->id], '*', MUST_EXIST);
        $trueanswer = $DB->get_record('question_answers', ['id' => $options->trueanswer], '*', MUST_EXIST);
        $falseanswer = $DB->get_record('question_answers', ['id' => $options->falseanswer], '*', MUST_EXIST);

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
            // True if "True" is the correct answer - the fraction on the
            // true-answer row is 1 exactly when that's the case.
            'correctanswer' => $trueanswer->fraction > 0.99,
            'showstandardinstruction' => (int) $options->showstandardinstruction,
            'feedbacktrue' => (string) $trueanswer->feedback,
            'feedbacktrueformat' => (int) $trueanswer->feedbackformat,
            'feedbacktruefiles' => question_file_helper::export_files(
                $context->id,
                'question',
                'answerfeedback',
                $trueanswer->id
            ),
            'feedbackfalse' => (string) $falseanswer->feedback,
            'feedbackfalseformat' => (int) $falseanswer->feedbackformat,
            'feedbackfalsefiles' => question_file_helper::export_files(
                $context->id,
                'question',
                'answerfeedback',
                $falseanswer->id
            ),
        ];
    }
}
