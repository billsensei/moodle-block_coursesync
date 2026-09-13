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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests every v1 question_handler's create(): each creates a real question
 * (and its qtype-specific rows) from a synthetic remote payload, following
 * the same pattern activity_handlers_test.php uses for activity_handler -
 * see question_handler.php's docblock for what a new handler must do.
 *
 * Also covers sanitizer's involvement (Phase 7's principle applied one level
 * down, Phase 10): a script tag in a payload here must never reach the
 * stored row intact.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(multichoice_question_handler::class)]
#[CoversClass(truefalse_question_handler::class)]
#[CoversClass(shortanswer_question_handler::class)]
#[CoversClass(numerical_question_handler::class)]
#[CoversClass(essay_question_handler::class)]
#[CoversClass(match_question_handler::class)]
#[CoversClass(description_question_handler::class)]
final class question_handlers_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A fresh quiz's own module-context default category, the same target
     * quiz_activity_handler itself uses.
     *
     * @return string "{$categoryid},{$contextid}"
     */
    protected function categoryspec(): string {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = \context_module::instance($quiz->cmid);
        $category = question_get_default_category($context->id, true);

        return $category->id . ',' . $category->contextid;
    }

    public function test_multichoice_handler_creates_a_matching_question(): void {
        global $DB;

        $payload = [
            'name' => 'Remote MC <script>alert(1)</script>',
            'questiontext' => '<p>Pick one</p><script>alert(2)</script>',
            'questiontextformat' => FORMAT_HTML,
            'questiontextfiles' => [],
            'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML,
            'generalfeedbackfiles' => [],
            'defaultmark' => 2,
            'penalty' => 0.5,
            'single' => 1,
            'shuffleanswers' => 1,
            'answernumbering' => 'ABCD',
            'shownumcorrect' => 1,
            'showstandardinstruction' => 0,
            'correctfeedback' => 'Well done', 'correctfeedbackformat' => FORMAT_HTML, 'correctfeedbackfiles' => [],
            'partiallycorrectfeedback' => '', 'partiallycorrectfeedbackformat' => FORMAT_HTML,
            'partiallycorrectfeedbackfiles' => [],
            'incorrectfeedback' => '', 'incorrectfeedbackformat' => FORMAT_HTML, 'incorrectfeedbackfiles' => [],
            'answers' => [
                [
                    'answertext' => 'Correct <script>alert(3)</script>', 'answerformat' => FORMAT_HTML,
                    'answerfiles' => [], 'fraction' => 1, 'feedback' => '', 'feedbackformat' => FORMAT_HTML,
                    'feedbackfiles' => [],
                ],
                [
                    'answertext' => 'Wrong', 'answerformat' => FORMAT_HTML, 'answerfiles' => [],
                    'fraction' => 0, 'feedback' => '', 'feedbackformat' => FORMAT_HTML, 'feedbackfiles' => [],
                ],
            ],
        ];

        $handler = new multichoice_question_handler();
        $questionid = $handler->create($payload, $this->categoryspec());

        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertSame('multichoice', $question->qtype);
        $this->assertStringNotContainsString('<script>', $question->name);
        $this->assertStringNotContainsString('<script>', $question->questiontext);
        $this->assertStringContainsString('Pick one', $question->questiontext);
        $this->assertEquals(2, $question->defaultmark);

        $options = $DB->get_record('qtype_multichoice_options', ['questionid' => $questionid], '*', MUST_EXIST);
        $this->assertEquals(1, $options->single);
        $this->assertSame('ABCD', $options->answernumbering);
        $this->assertSame('Well done', $options->correctfeedback);

        $answers = array_values($DB->get_records('question_answers', ['question' => $questionid], 'id ASC'));
        $this->assertCount(2, $answers);
        $this->assertStringNotContainsString('<script>', $answers[0]->answer);
        $this->assertStringContainsString('Correct', $answers[0]->answer);
        $this->assertEquals(1, $answers[0]->fraction);
        $this->assertEquals(0, $answers[1]->fraction);
    }

    public function test_truefalse_handler_creates_a_matching_question(): void {
        global $DB;

        $payload = [
            'name' => 'Remote T/F', 'questiontext' => '<p>Is this true?</p>', 'questiontextformat' => FORMAT_HTML,
            'questiontextfiles' => [], 'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
            'generalfeedbackfiles' => [], 'defaultmark' => 1, 'penalty' => 1,
            'correctanswer' => true, 'showstandardinstruction' => 0,
            'feedbacktrue' => 'Correct!', 'feedbacktrueformat' => FORMAT_HTML, 'feedbacktruefiles' => [],
            'feedbackfalse' => 'Wrong <script>alert(1)</script>', 'feedbackfalseformat' => FORMAT_HTML,
            'feedbackfalsefiles' => [],
        ];

        $handler = new truefalse_question_handler();
        $questionid = $handler->create($payload, $this->categoryspec());

        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertSame('truefalse', $question->qtype);

        $options = $DB->get_record('question_truefalse', ['question' => $questionid], '*', MUST_EXIST);
        $trueanswer = $DB->get_record('question_answers', ['id' => $options->trueanswer], '*', MUST_EXIST);
        $falseanswer = $DB->get_record('question_answers', ['id' => $options->falseanswer], '*', MUST_EXIST);
        $this->assertEquals(1, $trueanswer->fraction);
        $this->assertEquals(0, $falseanswer->fraction);
        $this->assertStringNotContainsString('<script>', $falseanswer->feedback);
    }

    public function test_shortanswer_handler_creates_a_matching_question(): void {
        global $DB;

        $payload = [
            'name' => 'Remote SA <script>alert(1)</script>', 'questiontext' => '<p>Capital of France?</p>',
            'questiontextformat' => FORMAT_HTML, 'questiontextfiles' => [], 'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML, 'generalfeedbackfiles' => [], 'defaultmark' => 1,
            'penalty' => 0.3333333, 'usecase' => 0,
            'answers' => [
                ['answer' => 'Paris', 'fraction' => 1, 'feedback' => '', 'feedbackformat' => FORMAT_HTML,
                    'feedbackfiles' => []],
                ['answer' => '*', 'fraction' => 0, 'feedback' => 'Try again', 'feedbackformat' => FORMAT_HTML,
                    'feedbackfiles' => []],
            ],
        ];

        $handler = new shortanswer_question_handler();
        $questionid = $handler->create($payload, $this->categoryspec());

        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertSame('shortanswer', $question->qtype);
        $this->assertStringNotContainsString('<script>', $question->name);

        $options = $DB->get_record('qtype_shortanswer_options', ['questionid' => $questionid], '*', MUST_EXIST);
        $this->assertEquals(0, $options->usecase);

        $answers = array_values($DB->get_records('question_answers', ['question' => $questionid], 'id ASC'));
        $this->assertCount(2, $answers);
        $this->assertSame('Paris', $answers[0]->answer);
        $this->assertEquals(1, $answers[0]->fraction);
        $this->assertSame('*', $answers[1]->answer);
    }

    public function test_numerical_handler_creates_a_matching_question(): void {
        global $DB;

        $payload = [
            'name' => 'Remote numerical', 'questiontext' => '<p>2 + 2?</p>', 'questiontextformat' => FORMAT_HTML,
            'questiontextfiles' => [], 'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
            'generalfeedbackfiles' => [], 'defaultmark' => 1, 'penalty' => 0.3333333,
            'answers' => [
                ['answer' => '4', 'tolerance' => '0', 'fraction' => 1, 'feedback' => '',
                    'feedbackformat' => FORMAT_HTML, 'feedbackfiles' => []],
            ],
        ];

        $handler = new numerical_question_handler();
        $questionid = $handler->create($payload, $this->categoryspec());

        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertSame('numerical', $question->qtype);

        $answer = $DB->get_record('question_answers', ['question' => $questionid], '*', MUST_EXIST);
        $this->assertEquals(4, (float) $answer->answer);
        $this->assertEquals(1, $answer->fraction);

        $numerical = $DB->get_record('question_numerical', ['answer' => $answer->id], '*', MUST_EXIST);
        $this->assertEquals(0, (float) $numerical->tolerance);

        // Units are deliberately switched off in v1 - see the handler's docblock.
        $options = $DB->get_record('question_numerical_options', ['question' => $questionid], '*', MUST_EXIST);
        $this->assertEquals(3, $options->showunits);
        $this->assertCount(0, $DB->get_records('question_numerical_units', ['question' => $questionid]));
    }

    public function test_essay_handler_creates_a_matching_question(): void {
        global $DB;

        $payload = [
            'name' => 'Remote essay', 'questiontext' => '<p>Discuss.</p>', 'questiontextformat' => FORMAT_HTML,
            'questiontextfiles' => [], 'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
            'generalfeedbackfiles' => [], 'defaultmark' => 5, 'penalty' => 0,
            'responseformat' => 'editor', 'responserequired' => 1, 'responsefieldlines' => 15,
            'minwordlimit' => 50, 'maxwordlimit' => 500, 'attachments' => 1, 'attachmentsrequired' => 0,
            'maxbytes' => 0, 'filetypeslist' => null,
            'graderinfo' => 'Look for X <script>alert(1)</script>', 'graderinfoformat' => FORMAT_HTML,
            'graderinfofiles' => [], 'responsetemplate' => 'Dear ...', 'responsetemplateformat' => FORMAT_HTML,
        ];

        $handler = new essay_question_handler();
        $questionid = $handler->create($payload, $this->categoryspec());

        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertSame('essay', $question->qtype);
        $this->assertEquals(5, $question->defaultmark);

        $options = $DB->get_record('qtype_essay_options', ['questionid' => $questionid], '*', MUST_EXIST);
        $this->assertEquals(50, $options->minwordlimit);
        $this->assertEquals(500, $options->maxwordlimit);
        $this->assertStringNotContainsString('<script>', $options->graderinfo);
        $this->assertSame('Dear ...', $options->responsetemplate);
    }

    public function test_match_handler_creates_a_matching_question(): void {
        global $DB;

        $payload = [
            'name' => 'Remote match', 'questiontext' => '<p>Match them.</p>', 'questiontextformat' => FORMAT_HTML,
            'questiontextfiles' => [], 'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
            'generalfeedbackfiles' => [], 'defaultmark' => 1, 'penalty' => 0.3333333,
            'shuffleanswers' => 1, 'shownumcorrect' => 1,
            'correctfeedback' => '', 'correctfeedbackformat' => FORMAT_HTML, 'correctfeedbackfiles' => [],
            'partiallycorrectfeedback' => '', 'partiallycorrectfeedbackformat' => FORMAT_HTML,
            'partiallycorrectfeedbackfiles' => [],
            'incorrectfeedback' => '', 'incorrectfeedbackformat' => FORMAT_HTML, 'incorrectfeedbackfiles' => [],
            'subquestions' => [
                ['questiontext' => 'Dog <script>alert(1)</script>', 'questiontextformat' => FORMAT_HTML,
                    'questiontextfiles' => [], 'answertext' => 'Animal'],
                ['questiontext' => 'Rose', 'questiontextformat' => FORMAT_HTML, 'questiontextfiles' => [],
                    'answertext' => 'Flower'],
                ['questiontext' => 'Blank answer - should be dropped', 'questiontextformat' => FORMAT_HTML,
                    'questiontextfiles' => [], 'answertext' => ''],
            ],
        ];

        $handler = new match_question_handler();
        $questionid = $handler->create($payload, $this->categoryspec());

        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertSame('match', $question->qtype);

        // The blank-answer subquestion must be dropped, not passed through to
        // qtype_match's own save_question_options() - see the handler's docblock
        // for why that specific input would otherwise be dangerous.
        $subquestions = array_values($DB->get_records('qtype_match_subquestions', ['questionid' => $questionid], 'id ASC'));
        $this->assertCount(2, $subquestions);
        $this->assertStringNotContainsString('<script>', $subquestions[0]->questiontext);
        $this->assertSame('Animal', $subquestions[0]->answertext);
        $this->assertSame('Flower', $subquestions[1]->answertext);
    }

    public function test_description_handler_creates_a_matching_question(): void {
        global $DB;

        $payload = [
            'name' => 'Remote description', 'questiontext' => '<p>Read this first.</p>',
            'questiontextformat' => FORMAT_HTML, 'questiontextfiles' => [], 'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML, 'generalfeedbackfiles' => [],
        ];

        $handler = new description_question_handler();
        $questionid = $handler->create($payload, $this->categoryspec());

        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertSame('description', $question->qtype);
        $this->assertEquals(0, $question->defaultmark);
        $this->assertStringContainsString('Read this first.', $question->questiontext);
    }
}
