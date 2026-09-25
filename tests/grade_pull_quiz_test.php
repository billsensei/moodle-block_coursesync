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

namespace block_coursesync;

use advanced_testcase;
use core\http_client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local/quiz_on_the_source.php');

/**
 * Tests for a grade pull handing a quiz over to its own grade, once the
 * source's attempts come into it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(grade_pull::class)]
final class grade_pull_quiz_test extends advanced_testcase {
    use local\quiz_on_the_source;

    /**
     * The shared fixture: see quiz_on_the_source.
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->libdir . '/gradelib.php');
        $this->set_up_quiz_source();
    }

    /**
     * The copy's grade item.
     *
     * @return \grade_item
     */
    protected function copy_item(): \grade_item {
        return \grade_item::fetch([
            'courseid' => $this->course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $this->copycm->instance,
            'itemnumber' => 0,
        ]);
    }

    /**
     * A student's grade in the copy, fresh from the database.
     *
     * @param int|null $userid defaults to the student
     * @return \grade_grade|false
     */
    protected function grade_here(?int $userid = null) {
        return \grade_grade::fetch(['itemid' => $this->copy_item()->id, 'userid' => $userid ?? $this->student->id]);
    }

    /**
     * An override on the copy, recorded as an earlier grade pull records one.
     *
     * @param int $userid
     * @param float $grade
     * @return void
     */
    protected function earlier_pull_wrote(int $userid, float $grade): void {
        global $DB;

        $item = $this->copy_item();
        $item->update_final_grade($userid, $grade, grade_pull::SOURCE, 'From there', FORMAT_HTML);
        $DB->insert_record('block_coursesync_grade', (object) [
            'blockinstanceid' => $this->instanceid,
            'courseid' => $this->course->id,
            'userid' => $userid,
            'gradeitemid' => $item->id,
            'remotecmid' => $this->quiz->cmid,
            'itemnumber' => 0,
            'finalgrade' => $grade,
            'feedbackhash' => sha1('From there'),
            'remotetime' => 1,
            'timepulled' => 1,
        ]);
    }

    /**
     * The entries of one kind.
     *
     * @param grade_pull_result $result
     * @param string $kind
     * @return \stdClass[]
     */
    protected function of_kind(grade_pull_result $result, string $kind): array {
        $this->assertTrue($result->success, (string) $result->errorkey);

        return array_values(array_filter($result->entries, static fn($e) => $e->kind === $kind));
    }

    /** @var float The student's quiz grade from their attempt: 9.4 of 11 marks, out of 10. */
    protected const QUIZ_GRADE = 9.4 / 11 * 10;

    /**
     * One pull brings the attempt, and the quiz grade comes from it - not
     * an override.
     */
    public function test_the_quiz_grade_comes_from_its_attempts(): void {
        $this->source_attempt($this->student, $this->answers(), 4);

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->local_source());

        $attempts = $this->of_kind($result, grade_pull_result::KIND_ATTEMPT);
        $this->assertSame([grade_pull_result::ADD], array_column($attempts, 'outcome'));
        $this->assertSame([], $this->of_kind($result, grade_pull_result::KIND_GRADE));
        $this->assertArrayHasKey((int) $this->copycm->id, $result->attemptquizzes);

        $grade = $this->grade_here();
        $this->assertEmpty($grade->overridden);
        $this->assertEqualsWithDelta(self::QUIZ_GRADE, $grade->finalgrade, 0.00001);

        // The history keeps the attempts' counts apart from the grades'.
        $run = history::get_runs($this->instanceid)[0];
        $this->assertSame(history::KIND_GRADES, $run->kind);
        $this->assertEquals(1, $run->pulledcount);
        $this->assertSame(
            [[grade_pull_result::KIND_ATTEMPT, 1]],
            array_map(static fn(array $row) => [$row['kind'], $row['add']], $run->pulled)
        );
    }

    /**
     * An override an earlier pull wrote - before the attempts could come -
     * is taken away, feedback and all, so the quiz's own grade shows. A
     * preview only says so.
     */
    public function test_an_earlier_pulls_override_is_released(): void {
        global $DB;

        $this->earlier_pull_wrote((int) $this->student->id, 8);
        $this->source_attempt($this->student, $this->answers(), 4);

        $preview = grade_pull::preview($this->instanceid, $this->course->id, $this->local_source());
        $this->assertSame(
            [grade_pull_result::RELEASED],
            array_column($this->of_kind($preview, grade_pull_result::KIND_GRADE), 'outcome')
        );
        $this->assertNotEmpty($this->grade_here()->overridden);

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->local_source());

        $released = $this->of_kind($result, grade_pull_result::KIND_GRADE);
        $this->assertSame([grade_pull_result::RELEASED], array_column($released, 'outcome'));
        $this->assertEquals(8, $released[0]->localgrade);

        $grade = $this->grade_here();
        $this->assertEmpty($grade->overridden);
        $this->assertEqualsWithDelta(self::QUIZ_GRADE, $grade->finalgrade, 0.00001);
        $this->assertEmpty($grade->feedback);
        $this->assertFalse($DB->record_exists('block_coursesync_grade', ['userid' => $this->student->id]));
    }

    /**
     * A grade someone here set is theirs: the attempt still comes, but the
     * override stays.
     */
    public function test_a_teachers_override_stays(): void {
        $this->copy_item()->update_final_grade($this->student->id, 5, 'gradebook');
        $this->source_attempt($this->student, $this->answers(), 4);

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->local_source());

        $this->assertCount(1, $this->of_kind($result, grade_pull_result::KIND_ATTEMPT));
        $this->assertSame([], $this->of_kind($result, grade_pull_result::KIND_GRADE));
        $grade = $this->grade_here();
        $this->assertNotEmpty($grade->overridden);
        $this->assertEquals(5, $grade->finalgrade);
    }

    /**
     * A student with a grade on the source but no attempts there - an
     * override given by hand - has no attempt to bring, so the grade still
     * comes as an override.
     */
    public function test_a_grade_without_attempts_still_comes(): void {
        $pat = $this->getDataGenerator()->create_and_enrol($this->source, 'student', ['username' => 'pat']);
        $this->getDataGenerator()->enrol_user($pat->id, $this->course->id, 'student');
        \grade_item::fetch([
            'courseid' => $this->source->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $this->quiz->id,
            'itemnumber' => 0,
        ])->update_final_grade($pat->id, 6, 'gradebook');

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->local_source());

        $grades = $this->of_kind($result, grade_pull_result::KIND_GRADE);
        $this->assertSame([[grade_pull_result::ADD, 'pat']], array_map(static fn($e) => [$e->outcome, $e->username], $grades));
        $this->assertNotEmpty($this->grade_here((int) $pat->id)->overridden);
        $this->assertEquals(6, $this->grade_here((int) $pat->id)->finalgrade);
    }

    /**
     * A copy that no longer lines up cannot take the attempts, so its grade
     * comes as an override, as before.
     */
    public function test_a_changed_copy_keeps_to_overrides(): void {
        global $DB;

        $this->source_attempt($this->student, $this->answers(), 4);
        $DB->set_field('quiz_slots', 'maxmark', 4, ['quizid' => $this->copycm->instance, 'slot' => 1]);

        $result = grade_pull::run($this->instanceid, $this->course->id, $this->local_source());

        $this->assertSame(['attemptskipchanged'], array_column($this->of_kind($result, grade_pull_result::KIND_ATTEMPT), 'reason'));
        $this->assertSame([], $result->attemptquizzes);
        $this->assertSame(
            [grade_pull_result::ADD],
            array_column($this->of_kind($result, grade_pull_result::KIND_GRADE), 'outcome')
        );
        $this->assertNotEmpty($this->grade_here()->overridden);
    }

    /**
     * A source too old to share attempts still shares grades: they come as
     * overrides, and the teacher is told why.
     */
    public function test_a_source_without_attempts(): void {
        $this->source_attempt($this->student, $this->answers(), 4);

        $older = new http_client(['mock' => function (RequestInterface $request) {
            parse_str((string) $request->getBody(), $params);

            $body = $params['wsfunction'] === 'block_coursesync_get_quiz_attempts'
                ? ['exception' => 'dml_missing_record_exception', 'errorcode' => 'invalidrecord']
                : $this->answer($params['wsfunction'], $params);

            return Create::promiseFor(new Response(200, [], json_encode($body)));
        }]);

        $result = grade_pull::run($this->instanceid, $this->course->id, $older);

        $this->assertSame(['attemptsunavailable'], $result->notes);
        $this->assertSame([], $this->copy_attempts());
        $this->assertSame(
            [grade_pull_result::ADD],
            array_column($this->of_kind($result, grade_pull_result::KIND_GRADE), 'outcome')
        );
        $this->assertNotEmpty($this->grade_here()->overridden);
    }
}
