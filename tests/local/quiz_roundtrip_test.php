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
 * End-to-end round trip (Phase 10): a real quiz with real questions, built
 * with Moodle's own core_question/mod_quiz generators on a "source" course,
 * exported with quiz_activity_exporter, and recreated with
 * quiz_activity_handler on a fresh "destination" course - the same two
 * classes sync_runner wires together in production, exercised here without
 * a network hop in between (remote_client/web services are already covered
 * by this plugin's other tests).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(quiz_activity_exporter::class)]
#[CoversClass(quiz_activity_handler::class)]
#[CoversClass(multianswer_question_exporter::class)]
#[CoversClass(multianswer_question_handler::class)]
final class quiz_roundtrip_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    public function test_a_quiz_with_two_question_types_survives_the_round_trip(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $sourcecourse = $this->getDataGenerator()->create_course();
        $sourcequiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $sourcecourse->id,
            'name' => 'Source quiz',
            'intro' => '<p>Round trip check.</p>',
        ]);
        $sourcecontext = \context_module::instance($sourcequiz->cmid);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $sourcecontext->id]);

        $tf = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id, 'name' => 'TF question', 'questiontext' => ['text' => 'The sky is blue.'],
            'correctanswer' => 1,
        ]);
        $mc = $questiongenerator->create_question('multichoice', 'two_of_four', [
            'category' => $category->id, 'name' => 'MC question',
        ]);

        $quizforadd = $DB->get_record('quiz', ['id' => $sourcequiz->id], '*', MUST_EXIST);
        quiz_add_quiz_question($tf->id, $quizforadd);
        quiz_add_quiz_question($mc->id, $quizforadd);

        $sourcecm = get_fast_modinfo($sourcecourse)->get_cm($sourcequiz->cmid);

        $exporter = new quiz_activity_exporter();
        $payload = $exporter->export($sourcecm);

        $this->assertSame('Source quiz', $payload['name']);
        $this->assertCount(2, $payload['slots']);
        foreach ($payload['slots'] as $slot) {
            $this->assertTrue($slot['supported'], 'Both truefalse and multichoice must be exported as supported.');
        }

        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new quiz_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $payload, 'coursesync-301');

        $destinationcm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $destinationquiz = $DB->get_record('quiz', ['id' => $destinationcm->instance], '*', MUST_EXIST);
        $this->assertSame('Source quiz', $destinationquiz->name);
        $this->assertStringContainsString('Round trip check.', $destinationquiz->intro);

        $destinationslots = $DB->get_records('quiz_slots', ['quizid' => $destinationquiz->id]);
        $this->assertCount(2, $destinationslots);

        $destinationqtypes = [];
        foreach ($destinationslots as $slot) {
            $reference = $DB->get_record('question_references', [
                'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slot->id,
            ], '*', MUST_EXIST);
            $version = $DB->get_record('question_versions', [
                'questionbankentryid' => $reference->questionbankentryid,
            ], '*', MUST_EXIST);
            $question = $DB->get_record('question', ['id' => $version->questionid], '*', MUST_EXIST);
            $destinationqtypes[] = $question->qtype;
        }
        sort($destinationqtypes);
        $this->assertSame(['multichoice', 'truefalse'], $destinationqtypes);
    }

    /**
     * The scenario that motivated this test: a quiz built the standard way
     * real teachers build one - "Add > a random question" pulling from a
     * shared question-bank category - not just fixed questions added one at
     * a time. Before this, quiz_activity_exporter reported every such slot
     * as unsupported and quiz_activity_handler silently skipped it, so a
     * quiz built entirely from random slots synced with NO questions at all
     * despite reporting success.
     */
    public function test_a_random_slot_pulls_its_pool_and_survives_the_round_trip(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $sourcecourse = $this->getDataGenerator()->create_course();
        $sourcequiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $sourcecourse->id,
            'name' => 'Quiz with a random slot',
        ]);

        // The real-world shape: a shared, course-level category (not the
        // quiz's own module-context category) that a random slot draws from.
        $coursecontext = \context_course::instance($sourcecourse->id);
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $pool = $questiongenerator->create_question_category(['contextid' => $coursecontext->id]);

        $questiongenerator->create_question('truefalse', null, ['category' => $pool->id, 'name' => 'Pool Q1']);
        $questiongenerator->create_question('shortanswer', null, ['category' => $pool->id, 'name' => 'Pool Q2']);

        $quizobj = \mod_quiz\quiz_settings::create($sourcequiz->id);
        $quizobj->get_structure()->add_random_questions(1, 1, [
            'filter' => [
                'category' => [
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => [$pool->id],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
        ]);

        $sourcecm = get_fast_modinfo($sourcecourse)->get_cm($sourcequiz->cmid);

        $exporter = new quiz_activity_exporter();
        $payload = $exporter->export($sourcecm);

        $this->assertCount(1, $payload['slots']);
        $slot = $payload['slots'][0];
        $this->assertTrue($slot['supported'], 'A resolvable random slot must be reported as supported.');
        $this->assertSame('random', $slot['qtype']);
        $this->assertCount(2, $slot['questions']);

        // Simulate the actual wire transport (get_activity_content JSON-encodes,
        // sync_runner JSON-decodes) rather than passing the PHP array straight
        // through, since that round trip is part of what shipped broken.
        $decoded = json_decode(json_encode($payload), true);

        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new quiz_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-302');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $destinationslots = $DB->get_records('quiz_slots', ['quizid' => $cm->instance]);
        $this->assertCount(1, $destinationslots);
        $destinationslot = reset($destinationslots);

        $setreference = $DB->get_record('question_set_references', [
            'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $destinationslot->id,
        ], '*', MUST_EXIST);
        $filter = json_decode($setreference->filtercondition, true);
        $poolcategoryid = (int) $filter['filter']['category']['values'][0];

        $poolquestioncount = $DB->count_records_sql(
            'SELECT COUNT(1) FROM {question_bank_entries} WHERE questioncategoryid = ?',
            [$poolcategoryid]
        );
        $this->assertSame(2, $poolquestioncount, 'Both pool questions must have been recreated on the destination.');
    }

    /**
     * The actual real-world bug report this test exists to pin down: a quiz
     * whose only slot is a FIXED reference to a multianswer (Cloze) question
     * synced with the quiz shell created but zero questions in it, because
     * multianswer wasn't a registered qtype at all until now. Builds the
     * source question the same way a teacher's own save would (raw Cloze
     * markup through qtype_multianswer's real save path, not a synthetic
     * payload), with a mix of shortanswer and multichoice fragments.
     */
    public function test_a_multianswer_slot_survives_the_round_trip(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/type/multianswer/questiontype.php');

        $sourcecourse = $this->getDataGenerator()->create_course();
        $sourcequiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $sourcecourse->id,
            'name' => 'Quiz with a Cloze question',
        ]);

        $coursecontext = \context_course::instance($sourcecourse->id);
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $coursecontext->id]);

        // Built via qtype_multianswer's own real save path, from raw Cloze
        // source text - exactly what saving this question through the
        // question bank editor would have produced, not a shape this
        // plugin invented for testing.
        $mcquestion = new \stdClass();
        $mcquestion->qtype = 'multianswer';
        $mcform = new \stdClass();
        $mcform->category = $category->id . ',' . $category->contextid;
        $mcform->name = 'Source Cloze';
        $mcform->questiontext = [
            'text' => '<p>The capital of France is {1:SHORTANSWER:=Paris}. ' .
                'Pick the odd number: {1:MULTICHOICE:=Three#Right~Four#Wrong}.</p>',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $mcform->generalfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $mcform->penalty = 1;
        $saved = \question_bank::get_qtype('multianswer')->save_question($mcquestion, $mcform);

        $quizforadd = $DB->get_record('quiz', ['id' => $sourcequiz->id], '*', MUST_EXIST);
        quiz_add_quiz_question((int) $saved->id, $quizforadd);

        $sourcecm = get_fast_modinfo($sourcecourse)->get_cm($sourcequiz->cmid);

        $exporter = new quiz_activity_exporter();
        $payload = $exporter->export($sourcecm);

        $this->assertCount(1, $payload['slots']);
        $this->assertTrue($payload['slots'][0]['supported'], 'A multianswer slot must now be reported as supported.');
        $this->assertSame('multianswer', $payload['slots'][0]['qtype']);

        // Full production path: JSON encode/decode, same as the real web
        // service transport.
        $decoded = json_decode(json_encode($payload), true);

        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new quiz_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-303');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $destinationslots = $DB->get_records('quiz_slots', ['quizid' => $cm->instance]);
        $this->assertCount(1, $destinationslots, 'The quiz must not be left with zero questions.');
        $destinationslot = reset($destinationslots);

        $reference = $DB->get_record('question_references', [
            'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $destinationslot->id,
        ], '*', MUST_EXIST);
        $version = $DB->get_record(
            'question_versions',
            ['questionbankentryid' => $reference->questionbankentryid],
            '*',
            MUST_EXIST
        );
        $destinationquestion = $DB->get_record('question', ['id' => $version->questionid], '*', MUST_EXIST);
        $this->assertSame('multianswer', $destinationquestion->qtype);

        $sequence = $DB->get_field(
            'question_multianswer',
            'sequence',
            ['question' => $destinationquestion->id],
            MUST_EXIST
        );
        $subids = explode(',', $sequence);
        $this->assertCount(2, $subids, 'Both embedded fragments (shortanswer and multichoice) must be recreated.');

        $subtypes = [];
        foreach ($subids as $subid) {
            $subtypes[] = $DB->get_field('question', 'qtype', ['id' => $subid], MUST_EXIST);
        }
        sort($subtypes);
        $this->assertSame(['multichoice', 'shortanswer'], $subtypes);
    }
}
