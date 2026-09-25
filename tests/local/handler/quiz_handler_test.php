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

namespace block_coursesync\local\handler;

use advanced_testcase;
use block_coursesync\activity_payload;
use block_coursesync\external\get_activity;
use block_coursesync\syncer;
use core_question\local\bank\question_bank_helper;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Round-trip tests for quiz_handler's question pulling: a quiz's fixed and
 * random slots, exported the way the source site would and rebuilt into the
 * destination course's shared System Bank the way the destination would.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(quiz_handler::class)]
final class quiz_handler_test extends advanced_testcase {
    /**
     * Export a quiz the way the source site would, and rebuild it in
     * another course the way the destination would.
     *
     * @param int $cmid the quiz to export
     * @param \stdClass $target the course to rebuild it in
     * @param string $idnumber
     * @return array [the new course_modules record, the payload, the handler]
     */
    protected function round_trip(int $cmid, \stdClass $target, string $idnumber = 'coursesync-1'): array {
        $exported = get_activity::execute($cmid);
        $payload = activity_payload::from_response($exported);

        $handler = new quiz_handler();
        $this->assertNull($handler->check_payload($payload));

        $cm = $handler->create_from_remote_data($target, $payload, $idnumber);

        return [$cm, $payload, $handler];
    }

    /**
     * The overall feedback arrives band for band: each band's text, and its
     * grade range exactly as stored - not re-derived from the percentages the
     * teacher typed. A quiz with no feedback gets none.
     */
    public function test_overall_feedback_is_copied_band_for_band(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        // As the edit form sends it: two boundaries make three bands.
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $source->id,
            'grade' => 10,
            'feedbackboundaries' => ['70%', '40%'],
            'feedbacktext' => [
                ['text' => '<p>Excellent.</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                ['text' => '<p>Good - review chapter 3.</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                ['text' => '<p>Please see your tutor.</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
            ],
        ]);

        $bands = fn(int $quizid) => array_values(array_map(
            fn($row) => [
                (string) $row->feedbacktext,
                (int) $row->feedbacktextformat,
                (float) $row->mingrade,
                (float) $row->maxgrade,
            ],
            $DB->get_records('quiz_feedback', ['quizid' => $quizid], 'mingrade DESC')
        ));

        $original = $bands((int) $quiz->id);
        $this->assertCount(3, $original, 'the generator should have made three bands');
        $this->assertSame([7.0, 11.0], array_slice($original[0], 2), 'top band: 70% up to one more than the grade');

        [$cm] = $this->round_trip((int) $quiz->cmid, $target);
        $this->assertEquals($original, $bands((int) $cm->instance));

        // And what a copied quiz shows a student at 5 out of 10 is the same.
        $this->assertSame('<p>Good - review chapter 3.</p>', $DB->get_field_select(
            'quiz_feedback',
            'feedbacktext',
            'quizid = ? AND mingrade <= ? AND maxgrade > ?',
            [$cm->instance, 5, 5]
        ));

        // No feedback on the source, none here.
        $plain = $this->getDataGenerator()->create_module('quiz', ['course' => $source->id]);
        [$plaincm] = $this->round_trip((int) $plain->cmid, $target, 'coursesync-2');
        $this->assertSame(0, $DB->count_records('quiz_feedback', ['quizid' => $plaincm->instance]));
    }

    /**
     * A quiz with fixed slots across several supported types, one
     * unsupported-type fixed slot, a random slot without subcategories, one
     * with subcategories, and one filtered by tags - all copied in one sync.
     */
    public function test_quiz_round_trip(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $source->id,
            'name' => 'Mixed quiz',
            'questionsperpage' => 0,
        ]);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $parent = $qgen->create_question_category(['name' => 'Fixed questions']);
        $randomcat = $qgen->create_question_category(['name' => 'Random pool']);
        $subcat = $qgen->create_question_category(['name' => 'Random pool sub', 'parent' => $randomcat->id]);
        $tagcat = $qgen->create_question_category(['name' => 'Tagged pool']);

        // Fixed slots, weighted differently from their own default mark.
        $truefalse = $qgen->create_question('truefalse', null, ['category' => $parent->id]);
        \quiz_add_quiz_question($truefalse->id, $quiz, 0, 3.0);

        $shortanswer = $qgen->create_question('shortanswer', null, ['category' => $parent->id]);
        \quiz_add_quiz_question($shortanswer->id, $quiz, 0, 2.5);

        // A fixed slot of a type this handler does not rebuild.
        // Added as the short-answer question it starts as: a quiz refuses a
        // type it does not know, so it becomes one only once it is in a slot,
        // as a quiz on a site that later lost the plugin would be.
        $uninstalled = $qgen->create_question('shortanswer', null, ['category' => $parent->id]);
        \quiz_add_quiz_question($uninstalled->id, $quiz, 0, 1.0);
        $this->relabel_as_uninstalled_type((int) $uninstalled->id);

        // Random slot: one category, no subcategories, two questions in the pool.
        $qgen->create_question('shortanswer', null, ['category' => $randomcat->id, 'name' => 'Pool Q1']);
        $qgen->create_question('numerical', null, ['category' => $randomcat->id, 'name' => 'Pool Q2']);
        $this->add_random_slot($quiz->id, $randomcat->id, false, 1.5);

        // Random slot: subcategories included, one question only in the subcategory.
        $qgen->create_question('shortanswer', null, ['category' => $subcat->id, 'name' => 'Subpool Q']);
        $this->add_random_slot($quiz->id, $randomcat->id, true, 1.0);

        // Random slot: filtered by a tag - one whose own name contains a
        // comma, proving the tag list travels as JSON rather than a
        // comma-joined string that would split this one tag into two.
        $tagged = $qgen->create_question('shortanswer', null, ['category' => $tagcat->id, 'name' => 'Tagged Q']);
        $qgen->create_question_tag(['questionid' => $tagged->id, 'tag' => 'week1, priority']);
        $this->add_random_slot($quiz->id, $tagcat->id, false, 2.0, ['week1, priority']);

        [$cm, $payload, $handler] = $this->round_trip($quiz->cmid, $target);

        $newquiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $newslots = $DB->get_records('quiz_slots', ['quizid' => $newquiz->id], 'slot ASC');
        $this->assertCount(5, $newslots, 'two fixed slots plus three random slots, uninstalled-type slot skipped');

        $bankcm = question_bank_helper::get_default_open_instance_system_type($target, false);
        $this->assertNotNull($bankcm, 'the System Bank should have been created');
        $bankcontext = \context_module::instance($bankcm->id);

        // Fixed slots: right question, right mark.
        $newtruefalse = $this->find_slot_question($DB, $newquiz->id, 'truefalse');
        $this->assertNotNull($newtruefalse);
        $this->assertSame(3.0, (float) $newtruefalse->maxmark);

        $newshortanswer = $this->find_fixed_slot_by_question_name($DB, $newquiz->id, $shortanswer->name);
        $this->assertNotNull($newshortanswer);
        $this->assertSame(2.5, (float) $newshortanswer->maxmark);

        // The unsupported type was left out and counted, not silently dropped.
        $notes = $handler->notes($payload);
        $this->assertContains(['syncqbankunsupportedcount', 1], $notes);

        // That one fixed slot is not counted a second time under the
        // separate "unresolved slot" note - every random slot above did
        // resolve, so nothing should be unresolved here at all.
        foreach ($notes as $note) {
            if (is_array($note) && $note[0] === 'syncquizunresolvedslotcount') {
                $this->fail('an unsupported-type fixed slot should not also be counted as unresolved');
            }
        }

        // Random slots: filter conditions decode to the right local category
        // and, where relevant, subcategories and a resolved local tag id.
        $randomslots = $this->find_random_slots($DB, $newquiz->id, $bankcontext);
        $this->assertCount(3, $randomslots);

        $newrandomcat = $DB->get_record(
            'question_categories',
            ['contextid' => $bankcontext->id, 'name' => 'Random pool'],
            '*',
            MUST_EXIST
        );
        $newsubcat = $DB->get_record(
            'question_categories',
            ['contextid' => $bankcontext->id, 'name' => 'Random pool sub'],
            '*',
            MUST_EXIST
        );
        $newtagcat = $DB->get_record(
            'question_categories',
            ['contextid' => $bankcontext->id, 'name' => 'Tagged pool'],
            '*',
            MUST_EXIST
        );

        $plainslot = $this->find_random_slot_for_category($randomslots, (int) $newrandomcat->id, false);
        $this->assertNotNull($plainslot);
        $this->assertSame(1.5, (float) $plainslot->maxmark);

        $subslot = $this->find_random_slot_for_category($randomslots, (int) $newrandomcat->id, true);
        $this->assertNotNull($subslot);
        $this->assertSame(1.0, (float) $subslot->maxmark);
        // The subcategory's own question travelled too - proving the whole
        // subtree was exported, not just the named category.
        $this->assertTrue($DB->record_exists('question_categories', [
            'id' => $newsubcat->id, 'parent' => $newrandomcat->id,
        ]));
        $this->assertTrue($DB->record_exists('question', ['name' => 'Subpool Q']));

        $tagslot = $this->find_random_slot_for_category($randomslots, (int) $newtagcat->id, false);
        $this->assertNotNull($tagslot);
        $this->assertSame(2.0, (float) $tagslot->maxmark);
        $filter = json_decode((string) $tagslot->filtercondition, true);
        $this->assertCount(
            1,
            $filter['filter']['qtagids']['values'] ?? [],
            'the comma inside the tag name must not split it in two'
        );
        $localtagid = $filter['filter']['qtagids']['values'][0];
        $this->assertSame('week1, priority', $DB->get_field('tag', 'name', ['id' => $localtagid]));

        // Sumgrades reflects the real per-slot marks, not just a count.
        $this->assertSame(3.0 + 2.5 + 1.5 + 1.0 + 2.0, (float) $newquiz->sumgrades);
    }

    /**
     * A fixed slot naming a multianswer (cloze) question - a real question
     * this quiz actually holds, not one of the fabricated shapes above -
     * arrives with its embedded sub-questions intact, since it is just
     * another entry in SUPPORTED_QTYPES to the shared trait; nothing here
     * is multianswer-specific.
     */
    public function test_quiz_with_a_cloze_fixed_slot(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $source->id,
            'name' => 'Cloze quiz',
        ]);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category();
        $cloze = $qgen->create_question('multianswer', 'twosubq', ['category' => $cat->id]);
        \quiz_add_quiz_question($cloze->id, $quiz, 0, 3.0);

        [$cm, $payload, $handler] = $this->round_trip($quiz->cmid, $target);

        $newquiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $newslots = $DB->get_records('quiz_slots', ['quizid' => $newquiz->id]);
        $this->assertCount(1, $newslots);

        $notes = $handler->notes($payload);
        $this->assertContains('syncqbanknoattempts', $notes);
        foreach ($notes as $note) {
            $this->assertFalse(
                is_array($note) && $note[0] === 'syncqbankunsupportedcount',
                'cloze is supported, so it must not be counted as an unsupported type'
            );
        }

        $newslot = reset($newslots);
        $qref = $DB->get_record('question_references', [
            'itemid' => $newslot->id, 'component' => 'mod_quiz', 'questionarea' => 'slot',
        ], '*', MUST_EXIST);
        $newquestion = $DB->get_record_sql(
            'SELECT q.* FROM {question} q JOIN {question_versions} qv ON qv.questionid = q.id
              WHERE qv.questionbankentryid = ?',
            [$qref->questionbankentryid],
            MUST_EXIST
        );
        $this->assertSame('multianswer', $newquestion->qtype);
        $this->assertSame(3.0, (float) $newslot->maxmark);

        $sequence = $DB->get_field('question_multianswer', 'sequence', ['question' => $newquestion->id], MUST_EXIST);
        $this->assertCount(2, explode(',', $sequence));
    }

    /**
     * The same source question, used by two different quizzes, is only
     * copied once into the shared System Bank - both quizzes reference the
     * one local question.
     */
    public function test_two_quizzes_sharing_a_question_reuse_it(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $quizgen = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiza = $quizgen->create_instance(['course' => $source->id, 'name' => 'Quiz A']);
        $quizb = $quizgen->create_instance(['course' => $source->id, 'name' => 'Quiz B']);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category();
        $shared = $qgen->create_question('shortanswer', null, ['category' => $cat->id]);

        \quiz_add_quiz_question($shared->id, $quiza, 0, 1.0);
        \quiz_add_quiz_question($shared->id, $quizb, 0, 1.0);

        [$cma] = $this->round_trip($quiza->cmid, $target, 'coursesync-1');
        [$cmb] = $this->round_trip($quizb->cmid, $target, 'coursesync-2');

        // The source's own original question has no idnumber - only a
        // synced copy is stamped 'coursesync-<remote entry id>' - so this
        // finds the one real source entry unambiguously.
        $sourceentryid = $DB->get_field_sql(
            'SELECT qbe.id
               FROM {question_bank_entries} qbe
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE q.name = ? AND qbe.idnumber IS NULL',
            [$shared->name],
            MUST_EXIST
        );
        $syncedidnumber = 'coursesync-' . $sourceentryid;

        // Exactly one synced copy should exist - both quizzes reused it
        // rather than each creating their own.
        $this->assertSame(1, $DB->count_records('question_bank_entries', ['idnumber' => $syncedidnumber]));

        $entryid = $DB->get_field('question_bank_entries', 'id', ['idnumber' => $syncedidnumber], MUST_EXIST);

        foreach ([$cma, $cmb] as $cm) {
            $quizid = $DB->get_field('quiz', 'id', ['id' => $cm->instance], MUST_EXIST);
            $referenced = $DB->get_record_sql(
                'SELECT qr.questionbankentryid
                   FROM {question_references} qr
                   JOIN {quiz_slots} slot ON slot.id = qr.itemid
                  WHERE slot.quizid = ? AND qr.component = ? AND qr.questionarea = ?',
                [$quizid, 'mod_quiz', 'slot'],
                MUST_EXIST
            );
            $this->assertSame((int) $entryid, (int) $referenced->questionbankentryid);
        }
    }

    /**
     * If something throws while rebuilding a quiz's questions - here, a
     * destination teacher explicitly refused moodle/question:useall, the
     * one capability this plugin ever checks beyond its own - the quiz this
     * call already created is still returned rather than silently orphaned,
     * and notes() says plainly that something went wrong instead of the
     * sync looking like a clean, empty success.
     */
    public function test_a_failure_rebuilding_questions_still_returns_the_quiz(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $quizgen = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $quizgen->create_instance(['course' => $source->id, 'name' => 'Doomed quiz']);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category();
        $qgen->create_question('shortanswer', null, ['category' => $cat->id, 'name' => 'Pool question']);

        $this->add_random_slot($quiz->id, $cat->id, false, 1.0);

        // Exported as admin - this is the source site's own service account
        // in real use, unrelated to the destination teacher's capabilities
        // being restricted below.
        $exported = get_activity::execute($quiz->cmid);
        $payload = activity_payload::from_response($exported);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $target->id, 'editingteacher');

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(
            'moodle/question:useall',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($target->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($teacher);

        $handler = new quiz_handler();
        $this->assertNull($handler->check_payload($payload));
        $cm = $handler->create_from_remote_data($target, $payload, 'coursesync-1');

        // The caught exception is deliberately logged for a developer to
        // find, not silenced outright.
        $this->assertDebuggingCalled();

        $this->assertNotNull($cm, 'the quiz must still be returned, not orphaned');
        $this->assertSame('quiz', $DB->get_field('modules', 'name', [
            'id' => $DB->get_field('course_modules', 'module', ['id' => $cm->id]),
        ]));

        $notes = $handler->notes($payload);
        $this->assertContains('syncquizquestionsyncfailed', $notes);

        // Its own settings are real and usable even though the question
        // rebuild broke - this is what "not orphaned" means in practice.
        $this->assertSame('Doomed quiz', $DB->get_field('quiz', 'name', ['id' => $cm->instance]));
    }

    /**
     * Two halves of "delete a synced quiz, then sync it back", proven
     * together: syncer::find_existing() - unit-tested on its own in
     * syncer_test.php - really would let a repeat sync through once the old
     * module is gone (properly deleted, or only flagged
     * deletioninprogress = 1), and create_from_remote_data() - exercised
     * directly here rather than through the HTTP-mocked syncer::run() a
     * full quiz payload is impractical to build for - reuses the
     * already-synced question rather than duplicating it when it is asked
     * for the same quiz a second and third time.
     */
    public function test_deleting_and_resyncing_a_quiz_reuses_its_bank_content(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $quizgen = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $quizgen->create_instance(['course' => $source->id, 'name' => 'Repeatable quiz']);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category();
        $question = $qgen->create_question('shortanswer', null, ['category' => $cat->id]);
        \quiz_add_quiz_question($question->id, $quiz, 0, 1.0);

        $remoteidnumber = 'coursesync-1';
        [$firstcm] = $this->round_trip($quiz->cmid, $target, $remoteidnumber);

        $entryid = (int) $DB->get_field_sql(
            'SELECT qbe.id
               FROM {question_bank_entries} qbe
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE q.name = ? AND qbe.idnumber IS NOT NULL',
            [$question->name],
            MUST_EXIST
        );

        // A teacher's "Delete" only flags the row deletioninprogress = 1
        // and leaves the real cleanup to an adhoc task - not actually gone
        // yet, but syncer::find_existing() must already treat it as gone,
        // same as the syncer_test.php unit test proves in isolation.
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $firstcm->id]);
        $this->assertSame(0, syncer::find_existing($target->id, 1));

        [$secondcm] = $this->round_trip($quiz->cmid, $target, $remoteidnumber);
        $this->assertNotSame((int) $firstcm->id, (int) $secondcm->id, 'a real new course module, not the flagged one');

        // Still only one synced copy of the question - the resync reused it.
        $this->assertSame(1, $DB->count_records('question_bank_entries', ['id' => $entryid]));

        $newquizid = $DB->get_field('quiz', 'id', ['id' => $secondcm->instance], MUST_EXIST);
        $referenced = $DB->get_record_sql(
            'SELECT qr.questionbankentryid
               FROM {question_references} qr
               JOIN {quiz_slots} slot ON slot.id = qr.itemid
              WHERE slot.quizid = ? AND qr.component = ? AND qr.questionarea = ?',
            [$newquizid, 'mod_quiz', 'slot'],
            MUST_EXIST
        );
        $this->assertSame($entryid, (int) $referenced->questionbankentryid);

        // Now the properly-completed case: the flagged module actually
        // gone, not just marked - find_existing() must still say so, and a
        // second resync must still reuse rather than duplicate.
        \course_delete_module($secondcm->id, false);
        $this->assertSame(0, syncer::find_existing($target->id, 1));

        [$thirdcm] = $this->round_trip($quiz->cmid, $target, $remoteidnumber);
        $this->assertSame(1, $DB->count_records('question_bank_entries', ['id' => $entryid]));

        $newestquizid = $DB->get_field('quiz', 'id', ['id' => $thirdcm->instance], MUST_EXIST);
        $referenced = $DB->get_record_sql(
            'SELECT qr.questionbankentryid
               FROM {question_references} qr
               JOIN {quiz_slots} slot ON slot.id = qr.itemid
              WHERE slot.quizid = ? AND qr.component = ? AND qr.questionarea = ?',
            [$newestquizid, 'mod_quiz', 'slot'],
            MUST_EXIST
        );
        $this->assertSame($entryid, (int) $referenced->questionbankentryid);
    }

    /**
     * Turn a question into one of a type this site does not have installed -
     * what a third-party question type looks like to a site without that
     * plugin, and the one kind of question every core type being supported
     * leaves unsupported.
     *
     * @param int $questionid
     * @return void
     */
    protected function relabel_as_uninstalled_type(int $questionid): void {
        global $DB;

        $DB->set_field('question', 'qtype', 'notinstalled', ['id' => $questionid]);
        \question_bank::notify_question_edited($questionid);
    }

    /**
     * Add a random slot to a quiz using the same filter-condition shape and
     * helper pattern mod_quiz's own tests use.
     *
     * @param int $quizid
     * @param int $categoryid
     * @param bool $includesubcategories
     * @param float $maxmark
     * @param string[] $tagnames
     */
    protected function add_random_slot(
        int $quizid,
        int $categoryid,
        bool $includesubcategories,
        float $maxmark,
        array $tagnames = []
    ): void {
        global $DB;

        $structure = \mod_quiz\quiz_settings::create($quizid)->get_structure();
        $filtercondition = [
            'filter' => [
                'category' => [
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => [$categoryid],
                    'filteroptions' => ['includesubcategories' => $includesubcategories],
                ],
            ],
        ];

        if ($tagnames !== []) {
            $collectionid = \core_tag_area::get_collection('core_question', 'question');
            $tagids = array_map(
                static fn(\core_tag_tag $tag): int => (int) $tag->id,
                \core_tag_tag::create_if_missing($collectionid, $tagnames)
            );
            $filtercondition['filter']['qtagids'] = [
                'jointype' => \qbank_tagquestion\tag_condition::JOINTYPE_DEFAULT,
                'values' => $tagids,
            ];
        }

        $structure->add_random_questions(0, 1, $filtercondition);

        // Core's add_random_questions() hardcodes maxmark to 1, same as the
        // handler being tested works around - matched here for real marks.
        $newslotid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {quiz_slots} WHERE quizid = ?', [$quizid]);
        $DB->set_field('quiz_slots', 'maxmark', $maxmark, ['id' => $newslotid]);
    }

    /**
     * Find a fixed slot's quiz_slots row by the qtype of the question it
     * points at.
     *
     * @param \moodle_database $db
     * @param int $quizid
     * @param string $qtype the question type to look for
     * @return \stdClass|null the quiz_slots row, or null if none
     */
    protected function find_slot_question(\moodle_database $db, int $quizid, string $qtype): ?\stdClass {
        return $db->get_record_sql(
            'SELECT slot.*
               FROM {quiz_slots} slot
               JOIN {question_references} qr ON qr.itemid = slot.id
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE slot.quizid = ? AND qr.component = ? AND qr.questionarea = ? AND q.qtype = ?',
            [$quizid, 'mod_quiz', 'slot', $qtype]
        ) ?: null;
    }

    /**
     * Find a fixed slot's quiz_slots row by the name of the question it
     * points at.
     *
     * @param \moodle_database $db
     * @param int $quizid
     * @param string $name the question name to look for
     * @return \stdClass|null the quiz_slots row, or null if none
     */
    protected function find_fixed_slot_by_question_name(\moodle_database $db, int $quizid, string $name): ?\stdClass {
        return $db->get_record_sql(
            'SELECT slot.*
               FROM {quiz_slots} slot
               JOIN {question_references} qr ON qr.itemid = slot.id
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE slot.quizid = ? AND qr.component = ? AND qr.questionarea = ? AND q.name = ?',
            [$quizid, 'mod_quiz', 'slot', $name]
        ) ?: null;
    }

    /**
     * Every random slot for a quiz, with its filter condition decoded
     * category checked against the given bank context.
     *
     * @param \moodle_database $db
     * @param int $quizid
     * @param \context $bankcontext the question bank the slots should draw from
     * @return \stdClass[] each with an extra ->filtercondition (raw string, as stored)
     */
    protected function find_random_slots(\moodle_database $db, int $quizid, \context $bankcontext): array {
        return $db->get_records_sql(
            'SELECT slot.id AS slotid, slot.maxmark, qsr.filtercondition
               FROM {quiz_slots} slot
               JOIN {question_set_references} qsr ON qsr.itemid = slot.id
              WHERE slot.quizid = ? AND qsr.component = ? AND qsr.questionarea = ? AND qsr.questionscontextid = ?',
            [$quizid, 'mod_quiz', 'slot', $bankcontext->id]
        );
    }

    /**
     * Pick out the one random slot whose filter condition points at the
     * given category with the given includesubcategories flag.
     *
     * @param \stdClass[] $slots from find_random_slots()
     * @param int $categoryid
     * @param bool $includesub the includesubcategories flag to match
     * @return \stdClass|null the matching slot, or null if none
     */
    protected function find_random_slot_for_category(array $slots, int $categoryid, bool $includesub): ?\stdClass {
        foreach ($slots as $slot) {
            $filter = json_decode((string) $slot->filtercondition, true);
            $filtercat = (int) ($filter['filter']['category']['values'][0] ?? 0);
            $filterincludesub = !empty($filter['filter']['category']['filteroptions']['includesubcategories']);

            if ($filtercat === $categoryid && $filterincludesub === $includesub) {
                return $slot;
            }
        }

        return null;
    }
}
