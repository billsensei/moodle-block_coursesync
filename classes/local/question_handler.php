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
 * Destination-side counterpart to question_exporter: recreates one question
 * in the destination quiz's own question bank category, from the payload
 * its matching question_exporter produced. The question-bank equivalent of
 * activity_handler.
 *
 * Every implementation goes through the same core entry point real question
 * bank screens use to save a question -
 * `\question_bank::get_qtype($qtype)->save_question($question, $form)` - the
 * same reasoning every activity_handler already follows for
 * `<modname>_add_instance()`: reuse the type's own real "create" code rather
 * than hand-writing inserts across `question`, `question_bank_entries`,
 * `question_versions`, and every qtype-specific table. $form must be shaped
 * like that qtype's own edit_question_form.php would produce - see each
 * `<qtype>_question_handler`'s docblock for the fields it sets, verified
 * against that qtype's own questiontype.php::save_question_options().
 *
 * EXTENDING THIS FOR A NEW QUESTION TYPE: see question_exporter's docblock.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface question_handler {
    /**
     * Creates this question type in the given category from remote data.
     *
     * @param array $data The payload this qtype's question_exporter produced on the source.
     * @param string $categoryspec "{$categoryid},{$contextid}" - the same
     *     comma-joined form question_get_default_category() and the question
     *     bank UI itself use for a $form->category value.
     * @return int The new question's id (NOT its question_bank_entries id).
     */
    public function create(array $data, string $categoryspec): int;
}
