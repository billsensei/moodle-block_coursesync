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
 * Exports a qtype_multianswer (Cloze) question.
 *
 * Unlike every other question_exporter, this one does NOT try to rebuild a
 * structured settings array a handler assembles piece by piece - a
 * multianswer question's real, canonical source of truth is the raw Cloze
 * MARKUP TEXT (`{1:SHORTANSWER:=farmer}`-style fragments embedded in the
 * question text), which `qtype_multianswer`'s own save path already knows
 * how to parse into as many sub-questions as it contains, of whatever
 * mixture of types (shortanswer/numerical/multichoice). So this exporter's
 * only job is to reassemble that raw text, and multianswer_question_handler
 * hands it straight back to `qtype_multianswer`'s own code to do the actual
 * work - see that class's docblock for exactly how.
 *
 * Once saved, `question.questiontext` for the OUTER question no longer
 * holds the raw `{1:SHORTANSWER:...}` markup - it's rewritten to positional
 * placeholders (`{#1}`, `{#2}`, ...), and each embedded fragment becomes a
 * separate, real row in `question` (its own qtype, e.g. shortanswer) whose
 * OWN `questiontext` holds that one raw fragment verbatim, unchanged from
 * what a teacher originally typed - linked back to the outer question via
 * `question_multianswer.sequence` (a comma list of sub-question ids, in
 * position order) and `question.parent` (set to the outer question's id,
 * which is also how core hides these from ordinary question bank listings).
 * So reading `question_multianswer.sequence` plus each sub-question's own
 * `questiontext` is enough to reconstruct the exact original raw source -
 * no need to re-derive or re-serialise a fragment's `{TYPE:...}` syntax
 * ourselves, since it was never rewritten away in the first place.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multianswer_question_exporter implements question_exporter {
    /**
     * Builds the payload multianswer_question_handler::create() expects.
     *
     * @param \stdClass $question
     * @param \context $context
     * @return array
     */
    public function export(\stdClass $question, \context $context): array {
        global $DB;

        $sequence = (string) $DB->get_field('question_multianswer', 'sequence', ['question' => $question->id]);

        $fragments = [];
        foreach (array_filter(explode(',', $sequence)) as $subquestionid) {
            // Each fragment's own questiontext IS the raw "{N:TYPE:...}"
            // markup, verbatim - see class docblock. Files aren't a concern
            // here: the fragment mini-language has no file/image mechanism
            // of its own to export.
            $fragments[] = (string) $DB->get_field(
                'question',
                'questiontext',
                ['id' => (int) $subquestionid],
                MUST_EXIST
            );
        }

        return [
            'name' => $question->name,
            // Still holds "{#1}", "{#2}", ... placeholders at this point -
            // reassembled with $fragments on the destination, not used
            // as-is (see multianswer_question_handler).
            'questiontext' => (string) $question->questiontext,
            'fragments' => $fragments,
            'generalfeedback' => (string) $question->generalfeedback,
            'generalfeedbackformat' => (int) $question->generalfeedbackformat,
            'generalfeedbackfiles' => question_file_helper::export_files(
                $context->id,
                'question',
                'generalfeedback',
                $question->id
            ),
            'penalty' => (float) $question->penalty,
        ];
    }
}
