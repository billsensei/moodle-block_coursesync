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
 * End-to-end round trip: a real feedback with a multichoice item, a
 * free-text item, a numeric item, and (in the smaller fixture) an item
 * depending on the multichoice one's answer, plus students' responses,
 * built with Moodle's own mod_feedback generator on a "source" course,
 * exported with feedback_activity_exporter (with and without
 * includeanswers), and recreated with feedback_activity_handler on a fresh
 * "destination" course - the same two classes sync_runner wires together
 * in production, through the same JSON encode/decode the real web service
 * transport uses (see quiz_roundtrip_test.php for why that step matters).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(feedback_activity_exporter::class)]
#[CoversClass(feedback_activity_handler::class)]
final class feedback_roundtrip_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Builds a source feedback with four items (multichoice, textfield,
     * numeric, and a fourth depending on the multichoice item's answer)
     * and two students' responses - deliberately below
     * activity_exporter_with_options::MIN_RESPONDENTS_FOR_BREAKDOWN, for
     * tests exercising the "too few responses" path.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: array<string, \stdClass>} [course, feedback cm, items by name]
     */
    protected function build_source(): array {
        $course = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $sourcefeedback = $this->getDataGenerator()->create_module('feedback', [
            'course' => $course->id,
            'name' => 'End of unit feedback <script>alert(1)</script>',
            'intro' => '<p>Tell us how it went.</p>',
        ]);
        $cm = get_fast_modinfo($course)->get_cm($sourcefeedback->cmid);

        /** @var \mod_feedback_generator $feedbackgenerator */
        $feedbackgenerator = $this->getDataGenerator()->get_plugin_generator('mod_feedback');

        $choiceitem = $feedbackgenerator->create_item_multichoice($sourcefeedback, [
            'name' => 'Favourite topic',
            'values' => "Volcanoes\nRobots\nPoetry",
        ]);
        $textitem = $feedbackgenerator->create_item_textfield($sourcefeedback, ['name' => 'Any comments']);
        $numericitem = $feedbackgenerator->create_item_numeric($sourcefeedback, ['name' => 'Rate it out of 10']);
        $dependentitem = $feedbackgenerator->create_item_textfield($sourcefeedback, [
            'name' => 'Why volcanoes',
            'dependitem' => $choiceitem->id,
            'dependvalue' => 'Volcanoes',
        ]);

        $feedbackgenerator->create_response([
            'userid' => $student1->id,
            'cmid' => $cm->id,
            'anonymous' => false,
            $choiceitem->name => 'Volcanoes',
            $textitem->name => 'Loved it.',
            $numericitem->name => '8',
        ]);
        $feedbackgenerator->create_response([
            'userid' => $student2->id,
            'cmid' => $cm->id,
            'anonymous' => false,
            $choiceitem->name => 'Robots',
            $textitem->name => 'Could be better.',
            $numericitem->name => '5',
        ]);

        $items = [
            'choice' => $choiceitem,
            'text' => $textitem,
            'numeric' => $numericitem,
            'dependent' => $dependentitem,
        ];

        return [$course, $cm, $items];
    }

    /**
     * Builds a source feedback with a multichoice, a free-text, and a
     * numeric item, and FIVE students' responses - at
     * MIN_RESPONDENTS_FOR_BREAKDOWN - for tests exercising the "breakdown
     * is shown" path.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: string[]} [course, feedback cm, the five free-text comments]
     */
    protected function build_source_with_five_respondents(): array {
        $course = $this->getDataGenerator()->create_course();

        $sourcefeedback = $this->getDataGenerator()->create_module('feedback', [
            'course' => $course->id,
            'name' => 'End of unit feedback',
            'intro' => '<p>Tell us how it went.</p>',
        ]);
        $cm = get_fast_modinfo($course)->get_cm($sourcefeedback->cmid);

        /** @var \mod_feedback_generator $feedbackgenerator */
        $feedbackgenerator = $this->getDataGenerator()->get_plugin_generator('mod_feedback');

        $choiceitem = $feedbackgenerator->create_item_multichoice($sourcefeedback, [
            'name' => 'Favourite topic',
            'values' => "Volcanoes\nRobots\nPoetry",
        ]);
        $textitem = $feedbackgenerator->create_item_textfield($sourcefeedback, ['name' => 'Any comments']);
        $numericitem = $feedbackgenerator->create_item_numeric($sourcefeedback, ['name' => 'Rate it out of 10']);

        // Three respondents pick Volcanoes, two pick Robots - five distinct
        // respondents in total, at the threshold.
        $picks = ['Volcanoes', 'Volcanoes', 'Volcanoes', 'Robots', 'Robots'];
        $ratings = ['8', '6', '10', '5', '7']; // Sum 36, average 7.2, min 5, max 10.
        $comments = ['Loved it.', 'Great.', 'Amazing.', 'Meh.', 'Could be better.'];

        foreach ($picks as $index => $pick) {
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $feedbackgenerator->create_response([
                'userid' => $student->id,
                'cmid' => $cm->id,
                'anonymous' => false,
                $choiceitem->name => $pick,
                $textitem->name => $comments[$index],
                $numericitem->name => $ratings[$index],
            ]);
        }

        return [$course, $cm, $comments];
    }

    /**
     * Without includeanswers, no answersummary anywhere, items (including
     * the dependent one's dependitemindex) still round-trip correctly.
     */
    public function test_items_and_dependency_sync_without_answers_by_default(): void {
        global $DB;

        [, $sourcecm] = $this->build_source();

        $exporter = new feedback_activity_exporter();
        $payload = $exporter->export($sourcecm);

        $this->assertArrayNotHasKey('answersummary', $payload);
        $this->assertCount(4, $payload['items']);
        foreach ($payload['items'] as $item) {
            $this->assertArrayNotHasKey('answersummary', $item);
        }

        // The dependent item (position 4, index 3) depends on the
        // multichoice item (position 1, index 0).
        $this->assertSame(0, $payload['items'][3]['dependitemindex']);
        $this->assertSame('Volcanoes', $payload['items'][3]['dependvalue']);
        $this->assertSame(-1, $payload['items'][0]['dependitemindex']);

        $decoded = json_decode(json_encode($payload), true);
        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new feedback_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-301');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertGreaterThan(0, $cm->instance);
        $feedback = $DB->get_record('feedback', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script>', $feedback->name);

        $destitems = $DB->get_records('feedback_item', ['feedback' => $feedback->id], 'position ASC');
        $this->assertCount(4, $destitems);
        [$destchoice, , , $destdependent] = array_values($destitems);

        $this->assertSame('Favourite topic', $destchoice->name);
        $this->assertSame('multichoice', $destchoice->typ);
        // The dependency must point at the NEW choice item's id, not the
        // (meaningless-here) source id.
        $this->assertEquals($destchoice->id, $destdependent->dependitem);
        $this->assertSame('Volcanoes', $destdependent->dependvalue);

        // No responses recreated on the destination at all.
        $this->assertEquals(0, $DB->count_records('feedback_completed', ['feedback' => $feedback->id]));
        $this->assertEquals(0, $DB->count_records('feedback_value', ['course_id' => $destinationcourse->id]));
    }

    /**
     * With includeanswers on but fewer than MIN_RESPONDENTS_FOR_BREAKDOWN
     * (5) responses to any item (2 here), no breakdown - per-option counts
     * or average/min/max - is exported for that item: only the bare
     * response count, and (regardless of respondent count, as always) never
     * a free-text answer's own content.
     */
    public function test_includeanswers_suppresses_breakdown_below_threshold(): void {
        global $DB;

        [, $sourcecm] = $this->build_source();

        $exporter = new feedback_activity_exporter();
        $payload = $exporter->export_with_options($sourcecm, ['includeanswers' => true]);

        // The overall total is still shown even though no item's own
        // breakdown is - see MIN_RESPONDENTS_FOR_BREAKDOWN's docblock.
        $this->assertSame(2, $payload['answersummary']['totalresponses']);

        [$choicepayload, $textpayload, $numericpayload, $dependentpayload] = $payload['items'];

        $this->assertSame(2, $choicepayload['answersummary']['responsecount']);
        $this->assertNull($choicepayload['answersummary']['optioncounts']);

        // The whole point: the free-text answers' own content ("Loved it.",
        // "Could be better.") must never appear anywhere in the payload.
        $this->assertSame(2, $textpayload['answersummary']['responsecount']);
        $this->assertNull($textpayload['answersummary']['optioncounts']);
        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('Loved it', $encoded);
        $this->assertStringNotContainsString('Could be better', $encoded);

        $this->assertSame(2, $numericpayload['answersummary']['responsecount']);
        $this->assertNull($numericpayload['answersummary']['average']);

        // The dependent item was never answered by anyone.
        $this->assertSame(0, $dependentpayload['answersummary']['responsecount']);

        $decoded = json_decode($encoded, true);
        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new feedback_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-302');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $feedback = $DB->get_record('feedback', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertStringContainsString('Total responses: 2', $feedback->intro);
        // No breakdown for any item at n=2 - not the real counts, and not
        // fabricated zeros either.
        $this->assertStringNotContainsString('Volcanoes:', $feedback->intro);
        $this->assertStringNotContainsString('Robots:', $feedback->intro);
        $this->assertStringNotContainsString('Loved it', $feedback->intro);
        $this->assertStringNotContainsString('Could be better', $feedback->intro);

        $this->assertEquals(0, $DB->count_records('feedback_completed', ['feedback' => $feedback->id]));
    }

    /**
     * At or above MIN_RESPONDENTS_FOR_BREAKDOWN (5 responses here), the
     * multichoice item gets its real per-option breakdown and the numeric
     * item its real average/min/max, decoded/computed from the underlying
     * feedback_value rows - but the free-text item still NEVER exposes any
     * respondent's actual written answer, at any respondent count.
     */
    public function test_includeanswers_shows_breakdown_at_or_above_threshold(): void {
        global $DB;

        [, $sourcecm, $comments] = $this->build_source_with_five_respondents();

        $exporter = new feedback_activity_exporter();
        $payload = $exporter->export_with_options($sourcecm, ['includeanswers' => true]);

        $this->assertSame(5, $payload['answersummary']['totalresponses']);

        [$choicepayload, $textpayload, $numericpayload] = $payload['items'];

        $optioncounts = array_combine(
            array_column($choicepayload['answersummary']['optioncounts'], 'text'),
            array_column($choicepayload['answersummary']['optioncounts'], 'count')
        );
        $this->assertSame(3, $optioncounts['Volcanoes']);
        $this->assertSame(2, $optioncounts['Robots']);
        $this->assertSame(0, $optioncounts['Poetry']);

        $this->assertSame(5, $textpayload['answersummary']['responsecount']);
        $this->assertNull($textpayload['answersummary']['optioncounts']);
        $encoded = json_encode($payload);
        foreach ($comments as $comment) {
            $this->assertStringNotContainsString($comment, $encoded);
        }

        $this->assertEqualsWithDelta(7.2, $numericpayload['answersummary']['average'], 0.001);
        $this->assertSame(5.0, $numericpayload['answersummary']['min']);
        $this->assertSame(10.0, $numericpayload['answersummary']['max']);

        $decoded = json_decode($encoded, true);
        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new feedback_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-303');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $feedback = $DB->get_record('feedback', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertStringContainsString('Total responses: 5', $feedback->intro);
        $this->assertStringContainsString('Volcanoes: 3', $feedback->intro);
        $this->assertStringContainsString('Robots: 2', $feedback->intro);
        foreach ($comments as $comment) {
            $this->assertStringNotContainsString($comment, $feedback->intro);
        }

        $this->assertEquals(0, $DB->count_records('feedback_completed', ['feedback' => $feedback->id]));
    }
}
