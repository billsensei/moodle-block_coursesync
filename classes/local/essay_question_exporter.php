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
 * Exports a qtype_essay question - settings only, same scope as this
 * plugin's other "manually graded" exporters (e.g. assign): no student
 * responses exist yet at export time, there's just the question itself.
 *
 * graderinfo carries its own files (component 'qtype_essay', not 'question' -
 * see qtype_essay::move_files()); responsetemplate does not - see
 * essay_question_handler's docblock.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class essay_question_exporter implements question_exporter {
    /**
     * Builds the payload essay_question_handler::create() expects.
     *
     * @param \stdClass $question
     * @param \context $context
     * @return array
     */
    public function export(\stdClass $question, \context $context): array {
        global $DB;

        $options = $DB->get_record('qtype_essay_options', ['questionid' => $question->id], '*', MUST_EXIST);

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
            'responseformat' => (string) $options->responseformat,
            'responserequired' => (int) $options->responserequired,
            'responsefieldlines' => (int) $options->responsefieldlines,
            'minwordlimit' => $options->minwordlimit === null ? null : (int) $options->minwordlimit,
            'maxwordlimit' => $options->maxwordlimit === null ? null : (int) $options->maxwordlimit,
            'attachments' => (int) $options->attachments,
            'attachmentsrequired' => (int) $options->attachmentsrequired,
            'maxbytes' => (int) $options->maxbytes,
            'filetypeslist' => $options->filetypeslist === null ? null : (string) $options->filetypeslist,
            'graderinfo' => (string) $options->graderinfo,
            'graderinfoformat' => (int) $options->graderinfoformat,
            'graderinfofiles' => question_file_helper::export_files(
                $context->id,
                'qtype_essay',
                'graderinfo',
                $question->id
            ),
            'responsetemplate' => (string) $options->responsetemplate,
            'responsetemplateformat' => (int) $options->responsetemplateformat,
        ];
    }
}
