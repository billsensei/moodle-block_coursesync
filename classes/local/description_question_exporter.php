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
 * Exports a qtype_description "question" - not really a question at all
 * (qtype_description::is_real_question_type() returns false), just a block
 * of text shown between real questions in a quiz. No answers, no options
 * table, no separate defaultmark (qtype_description::save_question() forces
 * it to 0 regardless of what's passed in).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class description_question_exporter implements question_exporter {
    /**
     * Builds the payload description_question_handler::create() expects.
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
        ];
    }
}
