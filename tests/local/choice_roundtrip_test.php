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
 * End-to-end round trip: a real choice with three options and answers from
 * a handful of students, built with Moodle's own mod_choice generator on a
 * "source" course, exported with choice_activity_exporter (with and
 * without includeanswers), and recreated with choice_activity_handler on a
 * fresh "destination" course - the same two classes sync_runner wires
 * together in production, through the same JSON encode/decode the real web
 * service transport uses (see quiz_roundtrip_test.php for why that step
 * matters).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(choice_activity_exporter::class)]
#[CoversClass(choice_activity_handler::class)]
final class choice_roundtrip_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Builds a source choice with three options and two students' answers
     * (one picking two options, since allowmultiple is on) - deliberately
     * below activity_exporter_with_options::MIN_RESPONDENTS_FOR_BREAKDOWN,
     * for tests exercising the "too few respondents" path.
     *
     * @return array{0: \stdClass, 1: \stdClass} [course, choice]
     */
    protected function build_source(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $choice = $this->getDataGenerator()->create_module('choice', [
            'course' => $course->id,
            'name' => 'Pick your project <script>alert(1)</script>',
            'intro' => '<p>Choose one or more.</p>',
            'allowmultiple' => 1,
            'option' => ['Volcanoes', 'Robots', 'Poetry'],
        ]);

        $options = $DB->get_records('choice_options', ['choiceid' => $choice->id], 'id ASC');
        [$volcanoes, $robots] = array_values($options);

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();

        $DB->insert_record('choice_answers', (object) [
            'choiceid' => $choice->id, 'userid' => $student1->id, 'optionid' => $volcanoes->id, 'timemodified' => time(),
        ]);
        $DB->insert_record('choice_answers', (object) [
            'choiceid' => $choice->id, 'userid' => $student2->id, 'optionid' => $volcanoes->id, 'timemodified' => time(),
        ]);
        $DB->insert_record('choice_answers', (object) [
            'choiceid' => $choice->id, 'userid' => $student2->id, 'optionid' => $robots->id, 'timemodified' => time(),
        ]);
        // Poetry gets zero answers - must still appear in a shown breakdown at 0, not be dropped.

        return [$course, $choice];
    }

    /**
     * Builds a source choice with five DISTINCT respondents - at
     * MIN_RESPONDENTS_FOR_BREAKDOWN - for tests exercising the "breakdown
     * is shown" path.
     *
     * @return array{0: \stdClass, 1: \stdClass} [course, choice]
     */
    protected function build_source_with_five_respondents(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $choice = $this->getDataGenerator()->create_module('choice', [
            'course' => $course->id,
            'name' => 'Pick your project',
            'intro' => '<p>Choose one.</p>',
            'option' => ['Volcanoes', 'Robots', 'Poetry'],
        ]);

        $options = $DB->get_records('choice_options', ['choiceid' => $choice->id], 'id ASC');
        [$volcanoes, $robots] = array_values($options);

        // Three respondents pick Volcanoes, two pick Robots, none pick
        // Poetry - five distinct respondents in total.
        $picks = [$volcanoes, $volcanoes, $volcanoes, $robots, $robots];
        foreach ($picks as $option) {
            $student = $this->getDataGenerator()->create_user();
            $DB->insert_record('choice_answers', (object) [
                'choiceid' => $choice->id, 'userid' => $student->id, 'optionid' => $option->id, 'timemodified' => time(),
            ]);
        }

        return [$course, $choice];
    }

    /**
     * Without includeanswers, no answersummary key at all - and the round
     * trip recreates the choice and its three options, XSS-cleaned.
     */
    public function test_options_sync_without_answers_by_default(): void {
        global $DB;

        [$sourcecourse, $sourcechoice] = $this->build_source();
        $sourcecm = get_fast_modinfo($sourcecourse)->get_cm($sourcechoice->cmid);

        $exporter = new choice_activity_exporter();
        $payload = $exporter->export($sourcecm);

        $this->assertArrayNotHasKey('answersummary', $payload);
        $this->assertCount(3, $payload['options']);
        $this->assertEqualsCanonicalizing(
            ['Volcanoes', 'Robots', 'Poetry'],
            array_column($payload['options'], 'text')
        );

        $decoded = json_decode(json_encode($payload), true);
        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new choice_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-201');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertGreaterThan(0, $cm->instance);
        $choice = $DB->get_record('choice', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script>', $choice->name);
        $this->assertStringNotContainsString('<script>', $choice->intro);

        $destoptions = $DB->get_records('choice_options', ['choiceid' => $choice->id]);
        $this->assertCount(3, $destoptions);
        $this->assertEquals(0, $DB->count_records('choice_answers', ['choiceid' => $choice->id]));
    }

    /**
     * With includeanswers on but fewer than MIN_RESPONDENTS_FOR_BREAKDOWN
     * (5) distinct respondents (2 here), the per-option breakdown is
     * suppressed entirely - not the real counts, and not fabricated zeros
     * either - only the bare total crosses sites, and the destination shows
     * an explicit "not shown" note. See
     * activity_exporter_with_options::MIN_RESPONDENTS_FOR_BREAKDOWN's
     * docblock for why: at this n, a real breakdown would fully reveal
     * which option a specific respondent picked.
     */
    public function test_includeanswers_suppresses_breakdown_below_threshold(): void {
        global $DB;

        [$sourcecourse, $sourcechoice] = $this->build_source();
        $sourcecm = get_fast_modinfo($sourcecourse)->get_cm($sourcechoice->cmid);

        $exporter = new choice_activity_exporter();
        $payload = $exporter->export_with_options($sourcecm, ['includeanswers' => true]);

        $this->assertArrayHasKey('answersummary', $payload);
        // Two distinct respondents, even though three answer rows exist.
        $this->assertSame(2, $payload['answersummary']['totalresponses']);
        $this->assertNull($payload['answersummary']['options']);

        $decoded = json_decode(json_encode($payload), true);
        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new choice_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-202');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $choice = $DB->get_record('choice', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertStringContainsString('Total responses: 2', $choice->intro);
        $this->assertStringContainsString('Per-option breakdown not shown', $choice->intro);
        $this->assertStringNotContainsString('Volcanoes:', $choice->intro);
        $this->assertStringNotContainsString('Robots:', $choice->intro);
        $this->assertStringNotContainsString('Poetry:', $choice->intro);

        $this->assertEquals(0, $DB->count_records('choice_answers', ['choiceid' => $choice->id]));
    }

    /**
     * At or above MIN_RESPONDENTS_FOR_BREAKDOWN (5 distinct respondents
     * here), the exporter attaches the real per-option counts, and the
     * handler renders them into the intro as a read-only note rather than
     * writing any choice_answers rows.
     */
    public function test_includeanswers_shows_breakdown_at_or_above_threshold(): void {
        global $DB;

        [$sourcecourse, $sourcechoice] = $this->build_source_with_five_respondents();
        $sourcecm = get_fast_modinfo($sourcecourse)->get_cm($sourcechoice->cmid);

        $exporter = new choice_activity_exporter();
        $payload = $exporter->export_with_options($sourcecm, ['includeanswers' => true]);

        $this->assertSame(5, $payload['answersummary']['totalresponses']);

        $countsbylabel = array_combine(
            array_column($payload['options'], 'text'),
            $payload['answersummary']['options']
        );
        $this->assertSame(3, $countsbylabel['Volcanoes']);
        $this->assertSame(2, $countsbylabel['Robots']);
        $this->assertSame(0, $countsbylabel['Poetry']);

        $decoded = json_decode(json_encode($payload), true);
        $destinationcourse = $this->getDataGenerator()->create_course();
        $handler = new choice_activity_handler();
        $cmid = $handler->create_from_remote_data((int) $destinationcourse->id, 0, $decoded, 'coursesync-203');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $choice = $DB->get_record('choice', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertStringContainsString('Volcanoes: 3', $choice->intro);
        $this->assertStringContainsString('Robots: 2', $choice->intro);
        $this->assertStringContainsString('Poetry: 0', $choice->intro);
        $this->assertStringContainsString('Total responses: 5', $choice->intro);
        $this->assertStringNotContainsString('breakdown not shown', $choice->intro);

        // The one privacy property that actually matters: no answer rows,
        // and no student's name/id anywhere in what got created.
        $this->assertEquals(0, $DB->count_records('choice_answers', ['choiceid' => $choice->id]));
    }
}
