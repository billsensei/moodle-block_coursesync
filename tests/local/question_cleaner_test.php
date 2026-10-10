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

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The cleaning of a question the importer built from another site's XML.
 *
 * The question is built by hand in the shapes qformat_xml produces, so every
 * place text can sit is covered without a source site.
 *
 * @package    block_coursesync
 * @category   test
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(question_cleaner::class)]
final class question_cleaner_test extends advanced_testcase {
    /** @var string Script that must never survive. */
    private const SCRIPT = '<script>alert(1)</script>';

    /** @var string An event handler that must never survive. */
    private const HANDLER = '<img src="x" onerror="alert(2)">';

    /**
     * Text with a format, in the array shape and in the string-next-to-format shape.
     *
     * @param string $text
     * @return array
     */
    private function array_text(string $text): array {
        return ['text' => $text . self::SCRIPT . self::HANDLER, 'format' => FORMAT_HTML, 'files' => []];
    }

    /**
     * Every formatted text in a question is cleaned, at every depth, and the name too.
     */
    public function test_formatted_text_is_cleaned_wherever_it_sits(): void {
        $question = (object) [
            'name' => '<b>Capital</b>' . self::SCRIPT,
            'questiontext' => '<p>Which?</p>' . self::SCRIPT,
            'questiontextformat' => FORMAT_HTML,
            'generalfeedback' => '<p>See</p>' . self::HANDLER,
            'generalfeedbackformat' => FORMAT_HTML,
            'answer' => [$this->array_text('<p>Paris</p>'), $this->array_text('<p>Rome</p>')],
            'feedback' => [$this->array_text('<p>Yes</p>'), $this->array_text('<p>No</p>')],
            'hint' => [$this->array_text('<p>Think</p>')],
            'nested' => (object) ['correctfeedback' => $this->array_text('<p>Right</p>')],
            'penalty' => '0.3333333',
            'defaultmark' => 1,
        ];

        question_cleaner::clean($question);

        $json = json_encode($question);
        $this->assertStringNotContainsString('<script', $json);
        $this->assertStringNotContainsString('onerror', $json);

        // And what is good is still there.
        $this->assertStringContainsString('Capital', $question->name);
        $this->assertStringNotContainsString('<b>', $question->name);
        $this->assertStringContainsString('<p>Which?</p>', $question->questiontext);
        $this->assertStringContainsString('<p>See</p>', $question->generalfeedback);
        $this->assertStringContainsString('Paris', $question->answer[0]['text']);
        $this->assertStringContainsString('Rome', $question->answer[1]['text']);
        $this->assertStringContainsString('Yes', $question->feedback[0]['text']);
        $this->assertStringContainsString('Think', $question->hint[0]['text']);
        $this->assertStringContainsString('Right', $question->nested->correctfeedback['text']);
        $this->assertSame('0.3333333', $question->penalty);
        $this->assertSame(1, $question->defaultmark);
    }

    /**
     * Each text is cleaned by its own format: plain text loses its tags.
     */
    public function test_each_text_is_cleaned_by_its_own_format(): void {
        $question = (object) [
            'name' => 'Q',
            'questiontext' => 'a <b>bold</b> claim' . self::SCRIPT,
            'questiontextformat' => FORMAT_PLAIN,
        ];

        question_cleaner::clean($question);

        $this->assertStringNotContainsString('<', $question->questiontext);
        $this->assertStringContainsString('bold', $question->questiontext);
    }

    /**
     * Files travel separately and are not walked; a string with no format
     * beside it is plain data and is left to the question type.
     */
    public function test_files_and_unformatted_strings_are_left_alone(): void {
        $question = (object) [
            'name' => 'Q',
            'questiontext' => '<p>t</p>',
            'questiontextformat' => FORMAT_HTML,
            'questiontextfiles' => [['name' => '<i>x</i>.png', 'content' => 'AAAA']],
            'files' => [['text' => self::SCRIPT, 'format' => FORMAT_HTML]],
            'qtype' => 'multichoice',
        ];

        question_cleaner::clean($question);

        $this->assertSame('multichoice', $question->qtype);
        $this->assertSame('AAAA', $question->questiontextfiles[0]['content']);
        // Under a key called files nothing is cleaned, as the importer never reads it as text.
        $this->assertSame(self::SCRIPT, $question->files[0]['text']);
    }

    /**
     * A question with only a name, or already clean, comes through unchanged.
     */
    public function test_nothing_to_clean(): void {
        $question = (object) ['name' => 'Plain', 'questiontext' => '<p>ok</p>', 'questiontextformat' => FORMAT_HTML];

        question_cleaner::clean($question);

        $this->assertSame('Plain', $question->name);
        $this->assertSame('<p>ok</p>', $question->questiontext);
    }
}
