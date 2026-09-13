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
 * Recreates a qtype_essay question from essay_question_exporter's payload,
 * via \question_bank::get_qtype('essay')->save_question().
 *
 * responsetemplate is set as a plain ['text', 'format'] pair with no
 * 'files' key: qtype_essay::save_question_options() reads
 * $formdata->responsetemplate['text']/['format'] directly, never passing it
 * through import_or_save_files() - see the exporter's docblock.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class essay_question_handler implements question_handler {
    /** Response formats qtype_essay itself recognises (its own response_formats()). */
    protected const RESPONSE_FORMATS = ['editor', 'editorfilepicker', 'plain', 'monospaced', 'noinline'];

    /**
     * Creates the question.
     *
     * @param array $data Payload from essay_question_exporter::export().
     * @param string $categoryspec
     * @return int The new question's id.
     */
    public function create(array $data, string $categoryspec): int {
        $question = new \stdClass();
        $question->qtype = 'essay';

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
        $form->penalty = sanitizer::float($data['penalty'] ?? 0);

        $form->responseformat = $this->sanitize_responseformat($data['responseformat'] ?? 'editor');
        $form->responserequired = sanitizer::integer($data['responserequired'] ?? 1, 1);
        $form->responsefieldlines = sanitizer::integer($data['responsefieldlines'] ?? 15, 15);
        if (($data['minwordlimit'] ?? null) !== null) {
            $form->minwordenabled = 1;
            $form->minwordlimit = sanitizer::integer($data['minwordlimit']);
        }
        if (($data['maxwordlimit'] ?? null) !== null) {
            $form->maxwordenabled = 1;
            $form->maxwordlimit = sanitizer::integer($data['maxwordlimit']);
        }
        $form->attachments = sanitizer::integer($data['attachments'] ?? 0);
        $form->attachmentsrequired = sanitizer::integer($data['attachmentsrequired'] ?? 0);
        $form->maxbytes = sanitizer::integer($data['maxbytes'] ?? 0);
        if (($data['filetypeslist'] ?? null) !== null) {
            $form->filetypeslist = sanitizer::text($data['filetypeslist']);
        }
        $form->graderinfo = question_file_helper::richfield(
            $data['graderinfo'] ?? '',
            $data['graderinfoformat'] ?? FORMAT_HTML,
            $data['graderinfofiles'] ?? []
        );
        $form->responsetemplate = [
            'text' => sanitizer::html($data['responsetemplate'] ?? ''),
            'format' => sanitizer::textformat($data['responsetemplateformat'] ?? FORMAT_HTML),
        ];

        $result = \question_bank::get_qtype('essay')->save_question($question, $form);

        return (int) $result->id;
    }

    /**
     * Validates a remote-supplied responseformat against the fixed set
     * qtype_essay itself recognises, same approach as forum_activity_handler's
     * type check.
     *
     * @param mixed $value
     * @return string
     */
    protected function sanitize_responseformat($value): string {
        $value = (string) $value;

        return in_array($value, self::RESPONSE_FORMATS, true) ? $value : 'editor';
    }
}
