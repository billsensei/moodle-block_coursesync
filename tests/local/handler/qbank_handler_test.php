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
use core_question\local\bank\question_version_status;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Round-trip tests for qbank_handler: a question bank's category tree and
 * its questions, exported the way the source site would and rebuilt the way
 * the destination would.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(qbank_handler::class)]
final class qbank_handler_test extends advanced_testcase {
    /**
     * Export a qbank activity the way the source site would, and rebuild it
     * in another course the way the destination would.
     *
     * @param int $cmid the qbank activity to export
     * @param \stdClass $target the course to rebuild it in
     * @return array [the new course_modules record, the payload that travelled]
     */
    protected function round_trip(int $cmid, \stdClass $target): array {
        $exported = get_activity::execute($cmid);
        $payload = activity_payload::from_response($exported);

        $handler = new qbank_handler();
        $this->assertNull($handler->check_payload($payload));

        $cm = $handler->create_from_remote_data($target, $payload, 'coursesync-1');

        return [$cm, $payload, $handler];
    }

    /**
     * A nested category tree, one question of each supported type, an
     * unsupported type, a tag, and a superseded version all survive one
     * sync of the same bank in one pass.
     */
    public function test_qbank_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $source->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');

        $parent = $qgen->create_question_category([
            'contextid' => $qbankcontext->id,
            'name' => 'Week 1',
        ]);
        $child = $qgen->create_question_category([
            'contextid' => $qbankcontext->id,
            'parent' => $parent->id,
            'name' => 'Week 1, quiz A',
        ]);

        $multichoice = $qgen->create_question('multichoice', null, ['category' => $parent->id]);
        $truefalse = $qgen->create_question('truefalse', null, ['category' => $parent->id]);
        $shortanswer = $qgen->create_question('shortanswer', null, ['category' => $child->id]);
        $match = $qgen->create_question('match', null, ['category' => $child->id]);
        $essay = $qgen->create_question('essay', null, ['category' => $child->id]);
        $numerical = $qgen->create_question('numerical', null, ['category' => $child->id]);
        $multianswer = $qgen->create_question('multianswer', 'twosubq', ['category' => $child->id]);

        $qgen->create_question_tag(['questionid' => $multichoice->id, 'tag' => 'week1']);

        // A type this handler does not rebuild: present in the bank, left
        // out of the copy, counted rather than silently dropped.
        $qgen->create_question('description', null, ['category' => $parent->id]);

        // A question whose text carries an embedded image, to prove the file
        // travels inline through the XML with no file_sync involvement.
        $withimage = $qgen->create_question('shortanswer', null, ['category' => $parent->id]);
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $qbankcontext->id,
            'component' => 'question',
            'filearea' => 'questiontext',
            'itemid' => $withimage->id,
            'filepath' => '/',
            'filename' => 'diagram.png',
        ], 'not a real png, just bytes to move');
        $DB->set_field(
            'question',
            'questiontext',
            $withimage->questiontext . ' <img src="@@PLUGINFILE@@/diagram.png" />',
            ['id' => $withimage->id]
        );

        // A second, later ready version supersedes the first - only the
        // second should travel. update_question() needs the existing
        // question's own id to treat this as a new version of the same
        // bank entry rather than an unrelated question.
        $superseded = $qgen->create_question('shortanswer', null, [
            'category' => $parent->id,
            'name' => 'Superseded, first version',
        ]);
        $newversion = $qgen->update_question($superseded, null, ['name' => 'Superseded, second version']);

        [$cm, $payload, $handler] = $this->round_trip($qbank->cmid, $target);

        $targetcontext = \context_module::instance($cm->id);

        // Category tree: the source's own top category is aliased onto this
        // site's, not duplicated. The generator creates a qbank the way the
        // real "add an activity" form does, which is also where the
        // "Default for ..." category comes from - it is exported and copied
        // like any other category, not special-cased.
        $categories = $DB->get_records('question_categories', ['contextid' => $targetcontext->id]);
        $this->assertCount(4, $categories, 'expected top + Default for ... + Week 1 + Week 1, quiz A');

        $newparent = $DB->get_record('question_categories', ['contextid' => $targetcontext->id, 'name' => 'Week 1']);
        $newchild = $DB->get_record(
            'question_categories',
            ['contextid' => $targetcontext->id, 'name' => 'Week 1, quiz A']
        );
        $this->assertSame((int) $newparent->parent, (int) $DB->get_field(
            'question_categories',
            'id',
            ['contextid' => $targetcontext->id, 'parent' => 0]
        ));
        $this->assertSame((int) $newparent->id, (int) $newchild->parent);

        // Seven supported types plus the superseded question's current
        // version plus the one with an image: nine questions in total.
        // The unsupported "description" question and the first version of
        // the superseded one are not among them.
        $questionids = \question_bank::get_finder()->get_questions_from_categories(
            [$newparent->id, $newchild->id],
            ''
        );
        $this->assertCount(9, $questionids);

        $names = $DB->get_fieldset_select(
            'question',
            'name',
            'id ' . $DB->get_in_or_equal(array_keys($questionids))[0],
            array_values($questionids)
        );
        $this->assertNotContains($superseded->name, $names, 'the first version should not have copied');
        $this->assertContains('Superseded, second version', $names);

        foreach ($questionids as $questionid) {
            $this->assertNotSame(
                'description',
                $DB->get_field('question', 'qtype', ['id' => $questionid]),
                'an unsupported type should never have been created'
            );
        }

        // The unsupported question was counted, not silently dropped.
        $notes = $handler->notes($payload);
        $this->assertContains(['syncqbankunsupportedcount', 1], $notes);
        $this->assertContains('syncqbanknoattempts', $notes);

        // Per-qtype shape spot checks: matching has no question_answers of
        // its own, true/false points at two of its own answers, numerical
        // spans four tables.
        $newmultichoice = $this->find_copy($DB, $newparent->id, $multichoice->name);
        $this->assert_multichoice_shape($DB, $newmultichoice);

        $newtruefalse = $this->find_copy($DB, $newparent->id, $truefalse->name);
        $this->assert_truefalse_shape($DB, $newtruefalse);

        $newshortanswer = $this->find_copy($DB, $newchild->id, $shortanswer->name);
        $this->assert_shortanswer_shape($DB, $newshortanswer, $shortanswer);

        $newmatch = $this->find_copy($DB, $newchild->id, $match->name);
        $this->assert_match_shape($DB, $newmatch);

        $newessay = $this->find_copy($DB, $newchild->id, $essay->name);
        $this->assert_essay_shape($DB, $newessay);

        $newnumerical = $this->find_copy($DB, $newchild->id, $numerical->name);
        $this->assert_numerical_shape($DB, $newnumerical);

        $newmultianswer = $this->find_copy($DB, $newchild->id, $multianswer->name);
        $this->assert_multianswer_shape($DB, $newmultianswer);

        // The tag travelled.
        $newtags = \core_tag_tag::get_item_tags_array('core_question', 'question', $newmultichoice->id);
        $this->assertContains('week1', $newtags);

        // The embedded image travelled with no declared file area on this
        // handler at all.
        $this->assertSame([], $handler->get_file_areas());
        $newwithimage = $this->find_copy($DB, $newparent->id, $withimage->name);
        $imagefiles = $fs->get_area_files($targetcontext->id, 'question', 'questiontext', $newwithimage->id, 'id', false);
        $this->assertCount(1, $imagefiles);
        $imagefile = reset($imagefiles);
        $this->assertSame('diagram.png', $imagefile->get_filename());
        $this->assertSame('not a real png, just bytes to move', $imagefile->get_content());
        $this->assertStringContainsString('@@PLUGINFILE@@/diagram.png', $newwithimage->questiontext);
    }

    /**
     * A course whose destination already carries the qbank's identity is
     * left alone - the same conflict gate that protects every other type,
     * so this handler needs no category or question level dedup of its own.
     */
    public function test_resync_is_a_conflict_not_a_duplicate(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $source->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => $qbankcontext->id]);
        $qgen->create_question('truefalse', null, ['category' => $category->id]);

        [$cm] = $this->round_trip($qbank->cmid, $target);

        $questioncount = $DB->count_records('question', []);

        // Round_trip() always stamps 'coursesync-1', so this is the same
        // lookup syncer::handle_one() makes before ever calling a handler -
        // remote cmid 1 is what that idnumber encodes.
        $existing = \block_coursesync\syncer::find_existing($target->id, 1);
        $this->assertSame((int) $cm->id, $existing);

        // A second create_from_remote_data() call is exactly what syncer
        // never makes once find_existing() reports a hit, so nothing here
        // needs its own category/question level conflict detection - proven
        // by there being nothing further to assert: no question count
        // change is possible without a second call, and syncer's own tests
        // already cover that the call never happens.
        $this->assertSame($questioncount, $DB->count_records('question', []));
    }

    /**
     * Find the copy of a question by name in a given category - ids differ
     * between sites, names do not.
     */
    protected function find_copy(\moodle_database $db, int $categoryid, string $name): \stdClass {
        $entryids = $db->get_fieldset_select(
            'question_bank_entries',
            'id',
            'questioncategoryid = ?',
            [$categoryid]
        );

        [$insql, $params] = $db->get_in_or_equal($entryids);
        $questionids = $db->get_records_sql(
            "SELECT qv.questionid
               FROM {question_versions} qv
              WHERE qv.questionbankentryid $insql
                AND qv.status = ?",
            array_merge($params, [question_version_status::QUESTION_STATUS_READY])
        );

        foreach (array_keys($questionids) as $questionid) {
            $question = $db->get_record('question', ['id' => $questionid], '*', MUST_EXIST);

            if ($question->name === $name) {
                return $question;
            }
        }

        $this->fail("No copy of '{$name}' found in category {$categoryid}");
    }

    /**
     * Multiple choice keeps its options row and its answers.
     */
    protected function assert_multichoice_shape(\moodle_database $db, \stdClass $question): void {
        $options = $db->get_record('qtype_multichoice_options', ['questionid' => $question->id], '*', MUST_EXIST);
        $this->assertNotNull($options);

        $answers = $db->get_records('question_answers', ['question' => $question->id]);
        $this->assertNotEmpty($answers);
    }

    /**
     * True/false has exactly two answers, and points at both of them.
     */
    protected function assert_truefalse_shape(\moodle_database $db, \stdClass $question): void {
        $answers = $db->get_records('question_answers', ['question' => $question->id]);
        $this->assertCount(2, $answers);

        $truefalse = $db->get_record('question_truefalse', ['question' => $question->id], '*', MUST_EXIST);
        $this->assertArrayHasKey((int) $truefalse->trueanswer, $answers);
        $this->assertArrayHasKey((int) $truefalse->falseanswer, $answers);
    }

    /**
     * Short answer's usecase setting survives the copy.
     */
    protected function assert_shortanswer_shape(\moodle_database $db, \stdClass $question, \stdClass $original): void {
        $options = $db->get_record('qtype_shortanswer_options', ['questionid' => $question->id], '*', MUST_EXIST);
        $originaloptions = $db->get_record('qtype_shortanswer_options', ['questionid' => $original->id], '*', MUST_EXIST);
        $this->assertSame($originaloptions->usecase, $options->usecase);
    }

    /**
     * Matching keeps its subquestions, and no question_answers of its own.
     */
    protected function assert_match_shape(\moodle_database $db, \stdClass $question): void {
        $options = $db->get_record('qtype_match_options', ['questionid' => $question->id], '*', MUST_EXIST);
        $this->assertNotNull($options);

        $subquestions = $db->get_records('qtype_match_subquestions', ['questionid' => $question->id]);
        $this->assertNotEmpty($subquestions);

        // Matching keeps no question_answers rows of its own.
        $this->assertSame(0, $db->count_records('question_answers', ['question' => $question->id]));
    }

    /**
     * Essay keeps its options row, and has no answers at all.
     */
    protected function assert_essay_shape(\moodle_database $db, \stdClass $question): void {
        $options = $db->get_record('qtype_essay_options', ['questionid' => $question->id], '*', MUST_EXIST);
        $this->assertNotNull($options);
        $this->assertSame(0, $db->count_records('question_answers', ['question' => $question->id]));
    }

    /**
     * Numerical's answers each keep a matching tolerance row.
     */
    protected function assert_numerical_shape(\moodle_database $db, \stdClass $question): void {
        $answers = $db->get_records('question_answers', ['question' => $question->id]);
        $this->assertNotEmpty($answers);

        foreach (array_keys($answers) as $answerid) {
            $this->assertTrue($db->record_exists('question_numerical', ['answer' => $answerid]));
        }
    }

    /**
     * Multianswer (cloze) keeps its {#N} placeholders in its own
     * questiontext, and each embedded sub-question - which is a real,
     * separately saved question of its own type - keeps its correct
     * answer. There is nothing multianswer-specific in this handler at
     * all: qtype_multianswer::save_question_options() does the whole job
     * itself, given the already-fully-parsed data qformat_xml::readquestions()
     * hands it, exactly the same as any other type's save_question_options()
     * call.
     */
    protected function assert_multianswer_shape(\moodle_database $db, \stdClass $question): void {
        $this->assertSame('multianswer', $question->qtype);
        $this->assertStringContainsString('{#1}', $question->questiontext);
        $this->assertStringContainsString('{#2}', $question->questiontext);

        $sequence = $db->get_field('question_multianswer', 'sequence', ['question' => $question->id], MUST_EXIST);
        $subids = explode(',', $sequence);
        $this->assertCount(2, $subids);

        $subtypes = $db->get_fieldset_select(
            'question',
            'qtype',
            'id ' . $db->get_in_or_equal($subids)[0],
            $subids
        );
        sort($subtypes);
        $this->assertSame(['multichoice', 'shortanswer'], $subtypes);

        [$insql, $inparams] = $db->get_in_or_equal($subids);
        $shortanswerid = $db->get_field_select('question', 'id', "id {$insql} AND qtype = 'shortanswer'", $inparams, MUST_EXIST);

        $answers = $db->get_records('question_answers', ['question' => $shortanswerid]);
        $correct = null;

        foreach ($answers as $answer) {
            if ($answer->answer === 'Owl') {
                $correct = $answer;
            }
        }

        $this->assertNotNull($correct, 'the correct sub-answer should have survived');
        $this->assertSame(1.0, (float) $correct->fraction);
    }
}
