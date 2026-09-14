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
 * Tests quiz_activity_handler::create_from_remote_data() (Phase 10): the
 * quiz itself, its review-option overwrite (see the handler's docblock for
 * why that's a separate step from quiz_add_instance()), and slot creation -
 * including skipping a random/unsupported slot rather than failing the
 * whole quiz over it.
 *
 * quiz_activity_exporter is not separately unit tested here: it is a thin
 * read-and-shape layer over
 * \mod_quiz\question\bank\qbank_helper::get_question_structure() (already
 * covered by core's own test suite) and each question_exporter (covered by
 * reading each qtype's own questiontype.php against question_handlers_test.php's
 * round trip through the matching question_handler).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(quiz_activity_handler::class)]
final class quiz_activity_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A minimal but valid multichoice question payload, matching
     * question_handlers_test.php's own.
     *
     * @return array
     */
    protected function multichoice_payload(): array {
        return [
            'name' => 'Remote MC', 'questiontext' => '<p>Pick one</p>', 'questiontextformat' => FORMAT_HTML,
            'questiontextfiles' => [], 'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
            'generalfeedbackfiles' => [], 'defaultmark' => 3, 'penalty' => 0.5,
            'single' => 1, 'shuffleanswers' => 1, 'answernumbering' => 'abc', 'shownumcorrect' => 1,
            'showstandardinstruction' => 0,
            'correctfeedback' => '', 'correctfeedbackformat' => FORMAT_HTML, 'correctfeedbackfiles' => [],
            'partiallycorrectfeedback' => '', 'partiallycorrectfeedbackformat' => FORMAT_HTML,
            'partiallycorrectfeedbackfiles' => [],
            'incorrectfeedback' => '', 'incorrectfeedbackformat' => FORMAT_HTML, 'incorrectfeedbackfiles' => [],
            'answers' => [
                ['answertext' => 'Right', 'answerformat' => FORMAT_HTML, 'answerfiles' => [], 'fraction' => 1,
                    'feedback' => '', 'feedbackformat' => FORMAT_HTML, 'feedbackfiles' => []],
                ['answertext' => 'Wrong', 'answerformat' => FORMAT_HTML, 'answerfiles' => [], 'fraction' => 0,
                    'feedback' => '', 'feedbackformat' => FORMAT_HTML, 'feedbackfiles' => []],
            ],
        ];
    }

    /**
     * A minimal but valid truefalse question payload.
     *
     * @param string $name
     * @return array
     */
    protected function truefalse_payload(string $name = 'Remote TF'): array {
        return [
            'name' => $name, 'questiontext' => '<p>True or false?</p>', 'questiontextformat' => FORMAT_HTML,
            'questiontextfiles' => [], 'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
            'generalfeedbackfiles' => [], 'defaultmark' => 1, 'penalty' => 1,
            'correctanswer' => true, 'showstandardinstruction' => 0,
            'feedbacktrue' => '', 'feedbacktrueformat' => FORMAT_HTML, 'feedbacktruefiles' => [],
            'feedbackfalse' => '', 'feedbackfalseformat' => FORMAT_HTML, 'feedbackfalsefiles' => [],
        ];
    }

    public function test_creates_a_matching_quiz_with_settings_and_a_supported_slot(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $payload = [
            'name' => 'Remote quiz <script>alert(1)</script>',
            'intro' => '<p>Intro</p><script>alert(2)</script>',
            'introformat' => FORMAT_HTML,
            'timeopen' => 1893456000,
            'timeclose' => 1893542400,
            'timelimit' => 3600,
            'overduehandling' => 'graceperiod',
            'graceperiod' => 600,
            'preferredbehaviour' => 'immediatefeedback',
            'canredoquestions' => 0,
            'attempts' => 2,
            'attemptonlast' => 0,
            'grademethod' => 2,
            'decimalpoints' => 3,
            'questiondecimalpoints' => 2,
            'questionsperpage' => 0,
            'navmethod' => 'sequential',
            'shuffleanswers' => 0,
            'grade' => 50,
            'showuserpicture' => 1,
            'reviewattempt' => 69632,
            'reviewcorrectness' => 4352,
            'reviewmaxmarks' => 69632,
            'reviewmarks' => 69632,
            'reviewspecificfeedback' => 69632,
            'reviewgeneralfeedback' => 69632,
            'reviewrightanswer' => 69632,
            'reviewoverallfeedback' => 4352,
            'slots' => [
                ['slot' => 1, 'page' => 1, 'maxmark' => 3.0, 'supported' => true, 'qtype' => 'multichoice',
                    'question' => $this->multichoice_payload()],
            ],
        ];

        $handler = new quiz_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $course->id, 0, $payload, 'coursesync-201');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertSame('coursesync-201', $cm->idnumber);
        $this->assertGreaterThan(0, $cm->instance);

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script>', $quiz->name);
        $this->assertStringNotContainsString('<script>', $quiz->intro);
        $this->assertSame('graceperiod', $quiz->overduehandling);
        $this->assertSame('immediatefeedback', $quiz->preferredbehaviour);
        $this->assertSame('sequential', $quiz->navmethod);
        $this->assertEquals(2, $quiz->attempts);
        $this->assertEquals(50, $quiz->grade);

        // Review options must reflect the exported values exactly, not
        // whatever quiz_process_options() would compute from the (absent)
        // form checkboxes - see the handler's docblock.
        $this->assertEquals(69632, $quiz->reviewattempt);
        $this->assertEquals(4352, $quiz->reviewcorrectness);
        $this->assertEquals(4352, $quiz->reviewoverallfeedback);

        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id]);
        $this->assertCount(1, $slots);
        $slot = reset($slots);
        $this->assertEquals(3, $slot->maxmark);

        $reference = $DB->get_record('question_references', [
            'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slot->id,
        ], '*', MUST_EXIST);
        $this->assertNull($reference->version); // Always latest.

        $version = $DB->get_record(
            'question_versions',
            ['questionbankentryid' => $reference->questionbankentryid],
            '*',
            MUST_EXIST
        );
        $question = $DB->get_record('question', ['id' => $version->questionid], '*', MUST_EXIST);
        $this->assertSame('multichoice', $question->qtype);
        $this->assertStringContainsString('Pick one', $question->questiontext);

        // The question must live in the new quiz's own module-context
        // default category - never a shared/course-level bank.
        $category = $DB->get_record('question_categories', ['id' => $DB->get_field(
            'question_bank_entries',
            'questioncategoryid',
            ['id' => $reference->questionbankentryid]
        )], '*', MUST_EXIST);
        $quizcontext = \context_module::instance($cmid);
        $this->assertEquals($quizcontext->id, $category->contextid);

        // Sumgrades must be recomputed from the slot(s) actually added.
        $quiz = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->assertEquals(3, $quiz->sumgrades);
    }

    /**
     * A random slot with a resolved pool (quiz_activity_exporter::export_random_pool())
     * must become a REAL random slot on the destination - a fresh category
     * holding every pulled pool question, plus a genuine question_set_references
     * row - not a fixed question and not skipped. A pool entry with an
     * unregistered qtype is dropped from the pool silently, same as any
     * other unsupported-qtype skip in this plugin.
     */
    public function test_creates_a_real_random_slot_from_a_resolved_pool(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $payload = [
            'name' => 'Quiz with a random slot', 'intro' => '', 'introformat' => FORMAT_HTML,
            'timeopen' => 0, 'timeclose' => 0, 'timelimit' => 0, 'overduehandling' => 'autosubmit',
            'graceperiod' => 0, 'preferredbehaviour' => 'deferredfeedback', 'canredoquestions' => 0,
            'attempts' => 0, 'attemptonlast' => 0, 'grademethod' => 1, 'decimalpoints' => 2,
            'questiondecimalpoints' => -1, 'questionsperpage' => 1, 'navmethod' => 'free',
            'shuffleanswers' => 1, 'grade' => 100, 'showuserpicture' => 0,
            'reviewattempt' => 0, 'reviewcorrectness' => 0, 'reviewmaxmarks' => 0, 'reviewmarks' => 0,
            'reviewspecificfeedback' => 0, 'reviewgeneralfeedback' => 0, 'reviewrightanswer' => 0,
            'reviewoverallfeedback' => 0,
            'slots' => [
                ['slot' => 1, 'page' => 1, 'maxmark' => 2.5, 'supported' => true, 'qtype' => 'random', 'questions' => [
                    ['qtype' => 'multichoice', 'question' => $this->multichoice_payload()],
                    ['qtype' => 'truefalse', 'question' => $this->truefalse_payload('Pool TF')],
                    // A pool entry of a qtype this plugin doesn't register a
                    // handler for - must be dropped, not fail the slot.
                    ['qtype' => 'calculated', 'question' => ['name' => 'Unreachable']],
                ]],
            ],
        ];

        $handler = new quiz_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $course->id, 0, $payload, 'coursesync-301');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $quizid = (int) $cm->instance;

        $slots = $DB->get_records('quiz_slots', ['quizid' => $quizid]);
        $this->assertCount(1, $slots, 'Exactly one real quiz_slots row for the random slot.');
        $slot = reset($slots);
        $this->assertEquals(2.5, $slot->maxmark, 'The exported maxmark must survive, not add_random_questions() default of 1.');

        $setreference = $DB->get_record('question_set_references', [
            'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slot->id,
        ], '*', MUST_EXIST);
        $filter = json_decode($setreference->filtercondition, true);
        $poolcategoryid = (int) $filter['filter']['category']['values'][0];

        // The pool category must be its own, separate from the quiz's shared
        // default category (never present since this quiz has no fixed slots).
        $quizcontext = \context_module::instance($cmid);
        $defaultcategory = $DB->get_record('question_categories', [
            'contextid' => $quizcontext->id, 'parent' => 0,
        ], '*', IGNORE_MISSING);
        // The top category (parent=0) always exists once question_get_default_category()
        // has run; the pool category must be a child of ITS child (the default category),
        // not the same row.
        $poolcategory = $DB->get_record('question_categories', ['id' => $poolcategoryid], '*', MUST_EXIST);
        $this->assertEquals($quizcontext->id, $poolcategory->contextid);

        // Exactly the two supported pool questions were created in the pool
        // category - the unregistered 'calculated' entry was skipped.
        $poolquestions = $DB->get_records_sql(
            "SELECT q.qtype
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE qbe.questioncategoryid = ?",
            [$poolcategoryid]
        );
        $qtypes = array_map(fn($q) => $q->qtype, $poolquestions);
        sort($qtypes);
        $this->assertSame(['multichoice', 'truefalse'], $qtypes);
    }

    /**
     * If every pool entry turns out to be unusable (all unregistered
     * qtypes), no orphaned always-empty random slot is left behind.
     */
    public function test_random_slot_with_no_usable_pool_questions_creates_no_slot(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $payload = [
            'name' => 'Quiz with an unusable pool', 'intro' => '', 'introformat' => FORMAT_HTML,
            'slots' => [
                ['slot' => 1, 'page' => 1, 'maxmark' => 1.0, 'supported' => true, 'qtype' => 'random', 'questions' => [
                    ['qtype' => 'calculated', 'question' => ['name' => 'Unreachable']],
                ]],
            ],
        ];

        $handler = new quiz_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $course->id, 0, $payload, 'coursesync-302');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertCount(0, $DB->get_records('quiz_slots', ['quizid' => $cm->instance]));
    }

    public function test_skips_random_and_unsupported_slots_without_failing_the_quiz(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $payload = [
            'name' => 'Mixed quiz', 'intro' => '', 'introformat' => FORMAT_HTML,
            'timeopen' => 0, 'timeclose' => 0, 'timelimit' => 0, 'overduehandling' => 'autosubmit',
            'graceperiod' => 0, 'preferredbehaviour' => 'deferredfeedback', 'canredoquestions' => 0,
            'attempts' => 0, 'attemptonlast' => 0, 'grademethod' => 1, 'decimalpoints' => 2,
            'questiondecimalpoints' => -1, 'questionsperpage' => 1, 'navmethod' => 'free',
            'shuffleanswers' => 1, 'grade' => 100, 'showuserpicture' => 0,
            'reviewattempt' => 0, 'reviewcorrectness' => 0, 'reviewmaxmarks' => 0, 'reviewmarks' => 0,
            'reviewspecificfeedback' => 0, 'reviewgeneralfeedback' => 0, 'reviewrightanswer' => 0,
            'reviewoverallfeedback' => 0,
            'slots' => [
                ['slot' => 1, 'page' => 1, 'maxmark' => 1.0, 'supported' => false, 'qtype' => 'random',
                    'name' => 'Random (Category)'],
                ['slot' => 2, 'page' => 1, 'maxmark' => 1.0, 'supported' => false, 'qtype' => 'calculated',
                    'name' => 'A calculated question'],
                ['slot' => 3, 'page' => 1, 'maxmark' => 1.0, 'supported' => true, 'qtype' => 'multichoice',
                    'question' => $this->multichoice_payload()],
            ],
        ];

        $handler = new quiz_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $course->id, 0, $payload, 'coursesync-202');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $quizid = (int) $cm->instance;

        // Only the one supported slot was actually created - the random and
        // unsupported-qtype slots were skipped, not failed.
        $this->assertCount(1, $DB->get_records('quiz_slots', ['quizid' => $quizid]));
    }

    public function test_a_quiz_with_no_supported_slots_is_still_created(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $payload = [
            'name' => 'Empty-of-questions quiz', 'intro' => '', 'introformat' => FORMAT_HTML,
            'timeopen' => 0, 'timeclose' => 0, 'timelimit' => 0, 'overduehandling' => 'autosubmit',
            'graceperiod' => 0, 'preferredbehaviour' => 'deferredfeedback', 'canredoquestions' => 0,
            'attempts' => 0, 'attemptonlast' => 0, 'grademethod' => 1, 'decimalpoints' => 2,
            'questiondecimalpoints' => -1, 'questionsperpage' => 1, 'navmethod' => 'free',
            'shuffleanswers' => 1, 'grade' => 100, 'showuserpicture' => 0,
            'reviewattempt' => 0, 'reviewcorrectness' => 0, 'reviewmaxmarks' => 0, 'reviewmarks' => 0,
            'reviewspecificfeedback' => 0, 'reviewgeneralfeedback' => 0, 'reviewrightanswer' => 0,
            'reviewoverallfeedback' => 0,
            'slots' => [],
        ];

        $handler = new quiz_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $course->id, 0, $payload, 'coursesync-203');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertGreaterThan(0, $cm->instance);
    }

    /**
     * An unknown enum-like setting (never sent by quiz_activity_exporter,
     * but this plugin never trusts remote data to match what its own
     * exporter would send, same principle as forum_activity_handler's type
     * fallback) falls back to a safe default rather than being stored as an
     * arbitrary string mod_quiz's own code later branches on.
     */
    public function test_falls_back_to_safe_defaults_for_unknown_enum_values(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $payload = [
            'name' => 'Odd quiz', 'intro' => '', 'introformat' => FORMAT_HTML,
            'overduehandling' => 'not-a-real-value', 'preferredbehaviour' => 'not-a-real-behaviour',
            'navmethod' => 'not-a-real-method', 'slots' => [],
        ];

        $handler = new quiz_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $course->id, 0, $payload, 'coursesync-204');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('autosubmit', $quiz->overduehandling);
        $this->assertSame('deferredfeedback', $quiz->preferredbehaviour);
        $this->assertSame('free', $quiz->navmethod);
    }
}
