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
 * Recreates a qtype_multianswer (Cloze) question by rebuilding its raw
 * source markup and handing it to `qtype_multianswer`'s own save path -
 * the same "let the real code do it" principle as every other
 * activity_handler/question_handler in this plugin, just aimed one level
 * further down at a qtype that saves ALL of its sub-questions from one
 * shared text blob rather than one flat settings array.
 *
 * Why this shape, not the usual "build a $form matching that qtype's
 * edit_..._form.php" pattern every other question_handler follows:
 * `qtype_multianswer::save_question()` OVERRIDES the base
 * `question_type::save_question()` and, before doing anything else, calls
 * `qtype_multianswer_extract_question($form->questiontext)` - a regex
 * parser that finds every `{N:TYPE:...}` fragment in the raw text, rewrites
 * the text to `{#1}`, `{#2}`, ... placeholders, and builds one form-shaped
 * sub-question object per fragment (of whatever qtype the fragment names -
 * shortanswer/numerical/multichoice/...). It then rewrites `$form` from
 * that result and calls the base class's own `save_question()`, whose
 * `save_question_options()` step creates every sub-question via
 * `question_bank::get_qtype($wrapped->qtype)->save_question(...)` and
 * writes the `question_multianswer` sequence row - all real core code.
 * So the ONE thing this handler needs to supply correctly is the exact same
 * raw text a teacher would have typed - reconstruct_source_text() rebuilds
 * it from multianswer_question_exporter's payload (the outer text's `{#N}`
 * placeholders, and each fragment's own already-raw-format questiontext) -
 * then this is a two-line call into core, not a hand-built $form mirroring
 * qtype_multianswer's internal structures. Since parsing dispatches to
 * `question_bank::get_qtype()` for whatever fragment types are present,
 * this works for any embeddable qtype the destination site has installed,
 * not only the qtypes this plugin's own question_handler_registry lists.
 *
 * Known gap: an image embedded directly in the OUTER question text (outside
 * any `{N:TYPE:...}` fragment) is not pulled. The raw-source save path
 * core itself uses for this qtype has no file-import mechanism for that
 * field (`edit_multianswer_form.php`'s own questiontext field doesn't offer
 * one either - the raw-markup textarea has no image button), so this isn't
 * a shortcut this handler takes so much as a real limitation of the format
 * itself. A fragment's own text has no file mechanism at all, in any qtype
 * or any editing path - not a gap, just how Cloze fragments work.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multianswer_question_handler implements question_handler {
    /**
     * Creates the question.
     *
     * @param array $data Payload from multianswer_question_exporter::export().
     * @param string $categoryspec
     * @return int The new (outer) question's id.
     */
    public function create(array $data, string $categoryspec): int {
        global $CFG;
        require_once($CFG->dirroot . '/question/type/multianswer/questiontype.php');

        $sourcetext = $this->reconstruct_source_text(
            (string) ($data['questiontext'] ?? ''),
            $data['fragments'] ?? []
        );

        $question = new \stdClass();
        $question->qtype = 'multianswer';

        $form = new \stdClass();
        $form->category = $categoryspec;
        $form->name = sanitizer::text($data['name'] ?? '');
        // Sanitised as one blob, after reconstruction: the {N:TYPE:...}
        // syntax is plain text to an HTML cleaner (no tags of its own), so
        // this still strips anything dangerous hiding inside a fragment
        // (e.g. a MULTICHOICE option's own text) before it ever reaches
        // qtype_multianswer_extract_question()'s regex parser.
        $form->questiontext = [
            'text' => sanitizer::html($sourcetext),
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $form->generalfeedback = [
            'text' => sanitizer::html($data['generalfeedback'] ?? ''),
            'format' => sanitizer::textformat($data['generalfeedbackformat'] ?? FORMAT_HTML),
            'itemid' => question_file_helper::store_in_draft_area($data['generalfeedbackfiles'] ?? []),
        ];
        $form->penalty = sanitizer::float($data['penalty'] ?? 1, 1.0);

        $result = \question_bank::get_qtype('multianswer')->save_question($question, $form);

        return (int) $result->id;
    }

    /**
     * Rebuilds the raw Cloze source text: each "{#N}" placeholder in the
     * outer text is replaced with that position's fragment, in order - the
     * exact inverse of what qtype_multianswer_extract_question() does when
     * a question is first saved, so feeding the result back into it
     * reproduces the original parse.
     *
     * @param string $outertext Still holding "{#1}", "{#2}", ... placeholders.
     * @param array $fragments Each position's raw "{N:TYPE:...}" markup, in order.
     * @return string
     */
    protected function reconstruct_source_text(string $outertext, array $fragments): string {
        $replacements = [];
        foreach (array_values($fragments) as $index => $fragment) {
            $replacements['{#' . ($index + 1) . '}'] = (string) $fragment;
        }

        return strtr($outertext, $replacements);
    }
}
