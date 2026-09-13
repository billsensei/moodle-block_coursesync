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
 * Exports a qtype_numerical question - answers (with their own tolerance)
 * and feedback. Deliberately does NOT export custom units
 * (question_numerical_units/question_numerical_options) - see
 * numerical_question_handler's docblock; a question using them still syncs,
 * just without unit handling, same "settings only, common case" scope cut
 * this plugin already makes elsewhere (e.g. assign's submission sub-plugins).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class numerical_question_exporter implements question_exporter {
    /**
     * Builds the payload numerical_question_handler::create() expects.
     *
     * @param \stdClass $question
     * @param \context $context
     * @return array
     */
    public function export(\stdClass $question, \context $context): array {
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
            'answers' => $this->export_answers($question->id, $context),
        ];
    }

    /**
     * Exports every accepted answer, in order, joined to its own tolerance
     * (question_numerical is a one-to-one extension of question_answers,
     * keyed by answer id, not by question id - see that table's own
     * install.xml).
     *
     * @param int $questionid
     * @param \context $context
     * @return array<int, array>
     */
    protected function export_answers(int $questionid, \context $context): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT a.*, COALESCE(n.tolerance, '0') AS tolerance
               FROM {question_answers} a
          LEFT JOIN {question_numerical} n ON n.answer = a.id
              WHERE a.question = ?
           ORDER BY a.id ASC",
            [$questionid]
        );

        $exported = [];
        foreach ($rows as $row) {
            $exported[] = [
                'answer' => (string) $row->answer,
                'tolerance' => (string) $row->tolerance,
                'fraction' => (float) $row->fraction,
                'feedback' => (string) $row->feedback,
                'feedbackformat' => (int) $row->feedbackformat,
                'feedbackfiles' => question_file_helper::export_files(
                    $context->id,
                    'question',
                    'answerfeedback',
                    $row->id
                ),
            ];
        }

        return $exported;
    }
}
