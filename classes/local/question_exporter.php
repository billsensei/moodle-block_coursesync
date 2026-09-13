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
 * Source-side counterpart to question_handler: builds one question's
 * settings/content payload, for quiz_activity_exporter to embed in a quiz's
 * own payload (see that class's docblock). The question-bank equivalent of
 * activity_exporter - same registry pattern (question_exporter_registry),
 * same "opaque payload only this qtype's own question_handler reads back"
 * contract, just one level further in (a quiz's payload embeds a list of
 * these, one per slot, instead of this travelling as get_activity_content's
 * whole contentjson).
 *
 * EXTENDING THIS FOR A NEW QUESTION TYPE: add a `<qtype>_question_exporter`
 * implementing this interface and register it in
 * question_exporter_registry's $exporters map, plus a matching
 * `<qtype>_question_handler` (see that interface) registered in
 * question_handler_registry. A qtype not registered in either is detected by
 * quiz_activity_exporter (it still appears in the "not yet supported" list
 * quiz_activity_handler passes back, the same status an unsupported activity
 * type gets) but its slot is not pulled.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface question_exporter {
    /**
     * Builds one question's settings/content payload.
     *
     * The shape of the returned array is entirely up to the question type -
     * like activity_exporter's payload, it's only ever read back by this
     * same qtype's question_handler on the destination site.
     *
     * @param \stdClass $question The base `question` table row for this
     *     question (as merged onto a quiz slot by
     *     \mod_quiz\question\bank\qbank_helper::get_question_structure()) -
     *     id, name, questiontext, questiontextformat, generalfeedback,
     *     generalfeedbackformat, defaultmark, penalty, qtype.
     * @param \context $context The question's own category's context -
     *     needed to read qtype-specific file areas (component 'question' or
     *     'qtype_<name>').
     * @return array
     */
    public function export(\stdClass $question, \context $context): array;
}
