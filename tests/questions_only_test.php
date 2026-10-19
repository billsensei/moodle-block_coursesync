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
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local/quiz_on_the_source.php');

/**
 * Copying a quiz's questions without the quiz.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(syncer::class)]
#[CoversClass(sync_result::class)]
final class questions_only_test extends advanced_testcase {
    use local\quiz_on_the_source;

    /**
     * Take away the quiz the set-up copied, and the questions it brought.
     *
     * @return void
     */
    protected function start_with_nothing_here(): void {
        global $DB;

        $this->set_up_quiz_source();

        local\course_modules::delete((int) $this->copycm->id);
        $DB->delete_records('block_coursesync_run');
        rebuild_course_cache($this->course->id, true);

        // The destination's synced questions go, so the run has to make them again.
        $entries = $DB->get_fieldset_select('question_bank_entries', 'id', "idnumber LIKE 'coursesync-%'");

        if ($entries !== []) {
            $DB->delete_records_list('question_versions', 'questionbankentryid', $entries);
            $DB->delete_records_list('question_bank_entries', 'id', $entries);
            $DB->delete_records('block_coursesync_question');
        }
    }

    /**
     * How many synced questions are in this course's shared bank.
     *
     * @return int
     */
    protected function synced_question_count(): int {
        global $DB;

        return $DB->count_records_select('question_bank_entries', "idnumber LIKE 'coursesync-%'");
    }

    /**
     * How many versions the synced questions have between them.
     *
     * @return int
     */
    protected function synced_version_count(): int {
        global $DB;

        return $DB->count_records_sql(
            "SELECT COUNT(1) FROM {question_versions} qv
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE qbe.idnumber LIKE 'coursesync-%'"
        );
    }

    /**
     * The questions arrive, the quiz does not, and the quiz stays on offer as new.
     */
    public function test_the_questions_arrive_without_the_quiz(): void {
        $this->start_with_nothing_here();
        $this->assertSame(0, $this->synced_question_count());

        $result = syncer::run($this->instanceid, $this->course->id, true, $this->local_source(), [], [(int) $this->quiz->cmid]);

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->count('questions'));
        $this->assertSame(0, $result->count('created'));
        $this->assertSame(0, $result->count('failed'));
        $this->assertSame(0, syncer::find_existing($this->course->id, (int) $this->quiz->cmid));
        $this->assertSame([], get_fast_modinfo($this->course->id)->get_instances_of('quiz'));
        $this->assertGreaterThan(0, $this->synced_question_count());
        $this->assertStringContainsString('1', $result->get_message());

        // The quiz was not copied, so the run must not claim everything was.
        $this->assertTrue($result->has_deselected());
        $this->assertFalse($result->lastsyncupdated);
    }

    /**
     * Asking again after the source edited a question gives it a new version.
     */
    public function test_a_second_run_after_an_edit_adds_a_version(): void {
        global $DB;

        $this->start_with_nothing_here();
        $cmid = [(int) $this->quiz->cmid];

        syncer::run($this->instanceid, $this->course->id, true, $this->local_source(), [], $cmid);
        $before = $this->synced_version_count();

        syncer::run($this->instanceid, $this->course->id, true, $this->local_source(), [], $cmid);
        $this->assertSame($before, $this->synced_version_count(), 'nothing changed, nothing added');

        // The source's essay: the one that is not a synced copy.
        $source = $DB->get_record_sql(
            "SELECT q.id FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE q.qtype = 'essay' AND (qbe.idnumber IS NULL OR qbe.idnumber NOT LIKE 'coursesync-%')"
        );
        $this->getDataGenerator()->get_plugin_generator('core_question')->update_question(
            \question_bank::load_question_data($source->id),
            null,
            ['name' => 'Edited on the source', 'timemodified' => time() + 100]
        );

        syncer::run($this->instanceid, $this->course->id, true, $this->local_source(), [], $cmid);
        $this->assertSame($before + 1, $this->synced_version_count());
    }

    /**
     * Ticking the quiz as well copies the quiz, and the questions go with it.
     */
    public function test_ticking_the_quiz_too_copies_the_quiz(): void {
        $this->start_with_nothing_here();
        $cmid = (int) $this->quiz->cmid;

        $result = syncer::run($this->instanceid, $this->course->id, true, $this->local_source(), [$cmid], [$cmid]);

        $this->assertSame(1, $result->count('created'));
        $this->assertSame(0, $result->count('questions'));
        $this->assertGreaterThan(0, syncer::find_existing($this->course->id, $cmid));
    }

    /**
     * Only a quiz can have its questions copied alone.
     */
    public function test_another_activity_type_is_not_affected(): void {
        $this->start_with_nothing_here();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id]);

        $result = syncer::run($this->instanceid, $this->course->id, true, $this->local_source(), [], [(int) $page->cmid]);

        $this->assertSame(0, $result->count('questions'));
        $this->assertSame(0, syncer::find_existing($this->course->id, (int) $page->cmid));
    }
}
