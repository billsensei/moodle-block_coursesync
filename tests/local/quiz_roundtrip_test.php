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
}
