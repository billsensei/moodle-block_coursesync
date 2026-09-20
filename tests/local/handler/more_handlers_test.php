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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Round-trip tests for the choice, glossary, feedback, database, workshop and
 * lesson handlers.
 *
 * Same pattern as handlers_test and new_handlers_test: export a real activity
 * through the source-side code, rebuild it through the destination-side code in
 * a second course, and compare. Four of these six have records that refer to
 * each other by id, so what these tests are really for is proving those
 * references were translated rather than copied.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(choice_handler::class)]
#[CoversClass(glossary_handler::class)]
#[CoversClass(feedback_handler::class)]
#[CoversClass(data_handler::class)]
#[CoversClass(workshop_handler::class)]
#[CoversClass(lesson_handler::class)]
#[CoversClass(h5pactivity_handler::class)]
final class more_handlers_test extends advanced_testcase {
    /**
     * Export an activity the way the source site would, and rebuild it in
     * another course the way the destination would.
     *
     * @param int $cmid the activity to export
     * @param \stdClass $target the course to rebuild it in
     * @param string $idnumber
     * @return array [the new course_modules record, the payload, the handler]
     */
    protected function round_trip(int $cmid, \stdClass $target, string $idnumber = 'coursesync-1'): array {
        $exported = get_activity::execute($cmid);
        $payload = activity_payload::from_response($exported);

        $handler = handler_registry::get($payload->modname);
        $this->assertNotNull($handler, "No handler for {$payload->modname}");
        $this->assertNull($handler->check_payload($payload));

        $cm = $handler->create_from_remote_data($target, $payload, $idnumber);

        return [$cm, $payload, $handler];
    }

    /**
     * Two courses to work between.
     *
     * @return array [source, destination]
     */
    protected function two_courses(): array {
        $this->resetAfterTest();
        $this->setAdminUser();

        return [
            $this->getDataGenerator()->create_course(),
            $this->getDataGenerator()->create_course(),
        ];
    }

    /**
     * A choice keeps its settings and the options people pick between.
     */
    public function test_choice_round_trip(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $choice = $this->getDataGenerator()->create_module('choice', [
            'course' => $source->id,
            'name' => 'Pick a workshop',
            'option' => ['Monday', 'Tuesday', 'Wednesday'],
            'limitanswers' => 1,
            'allowmultiple' => 1,
            'showresults' => 2,
            'timeopen' => 1750000000,
            'timeclose' => 1760000000,
        ]);
        $firstoption = array_values($DB->get_records('choice_options', ['choiceid' => $choice->id], 'id ASC'))[0];
        $DB->set_field('choice_options', 'maxanswers', 5, ['id' => $firstoption->id]);

        [$cm, $payload, $handler] = $this->round_trip($choice->cmid, $target);

        $copy = $DB->get_record('choice', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Pick a workshop', $copy->name);
        $this->assertSame(1, (int) $copy->limitanswers);
        $this->assertSame(1, (int) $copy->allowmultiple);
        $this->assertSame(2, (int) $copy->showresults);
        $this->assertSame(1750000000, (int) $copy->timeopen);
        $this->assertSame(1760000000, (int) $copy->timeclose);

        $options = array_values($DB->get_records('choice_options', ['choiceid' => $copy->id], 'id ASC'));

        $this->assertCount(3, $options);
        $this->assertSame(['Monday', 'Tuesday', 'Wednesday'], array_map(fn($o) => $o->text, $options));
        $this->assertSame(5, (int) $options[0]->maxanswers);

        $this->assertContains('syncchoicenoanswers', $handler->notes($payload));
        $this->assertSame(0, $DB->count_records('choice_answers', ['choiceid' => $copy->id]));
    }

    /**
     * A glossary keeps its settings, and a display format this site does not
     * have falls back rather than throwing.
     */
    public function test_glossary_round_trip(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $glossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $source->id,
            'name' => 'Key terms',
            'displayformat' => 'encyclopedia',
            'allowduplicatedentries' => 1,
            'allowcomments' => 1,
            'entbypage' => 25,
            'defaultapproval' => 0,
        ]);

        [$cm, $payload, $handler] = $this->round_trip($glossary->cmid, $target);

        $copy = $DB->get_record('glossary', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Key terms', $copy->name);
        $this->assertSame('encyclopedia', $copy->displayformat);
        $this->assertSame(1, (int) $copy->allowduplicatedentries);
        $this->assertSame(1, (int) $copy->allowcomments);
        $this->assertSame(25, (int) $copy->entbypage);
        $this->assertSame(0, (int) $copy->defaultapproval);

        // A glossary shared site-wide is a site-level decision, not something
        // acquired by being copied into a course.
        $this->assertSame(0, (int) $copy->globalglossary);

        $this->assertContains('syncglossarynoentries', $handler->notes($payload));
        $this->assertSame(0, $DB->count_records('glossary_entries', ['glossaryid' => $copy->id]));
    }

    /**
     * glossary_add_instance() throws on a display format it does not have, so
     * an unknown one is caught before it gets there.
     */
    public function test_glossary_unknown_display_format_falls_back(): void {
        $display = new \ReflectionMethod(glossary_handler::class, 'clean_display_format');
        $approval = new \ReflectionMethod(glossary_handler::class, 'clean_approval_format');

        $this->resetAfterTest();

        $this->assertSame('dictionary', $display->invoke(null, 'notaformat'));
        $this->assertSame('encyclopedia', $display->invoke(null, 'encyclopedia'));

        // The approval format additionally allows the literal word 'default'.
        $this->assertSame('default', $approval->invoke(null, 'default'));
        $this->assertSame('default', $approval->invoke(null, 'notaformat'));
        $this->assertSame('encyclopedia', $approval->invoke(null, 'encyclopedia'));
    }

    /**
     * A feedback keeps its questions, and a question that depends on another is
     * pointed at the copy of that question rather than at its old id.
     */
    public function test_feedback_round_trip_remaps_dependencies(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $feedback = $this->getDataGenerator()->create_module('feedback', [
            'course' => $source->id,
            'name' => 'Course feedback',
            'anonymous' => 2,
            'multiple_submit' => 0,
            'autonumbering' => 0,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_feedback');
        $first = $generator->create_item_multichoice($feedback, [
            'name' => 'Did you enjoy it?',
            'values' => "Yes\nNo",
        ]);
        $second = $generator->create_item_textarea($feedback, ['name' => 'Why not?']);

        // The second question only appears if the first was answered "No".
        $DB->set_field('feedback_item', 'dependitem', $first->id, ['id' => $second->id]);
        $DB->set_field('feedback_item', 'dependvalue', 'No', ['id' => $second->id]);

        [$cm, $payload, $handler] = $this->round_trip($feedback->cmid, $target);

        $copy = $DB->get_record('feedback', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Course feedback', $copy->name);
        $this->assertSame(2, (int) $copy->anonymous);
        $this->assertSame(0, (int) $copy->multiple_submit);
        $this->assertSame(0, (int) $copy->autonumbering);

        $items = array_values($DB->get_records('feedback_item', ['feedback' => $copy->id], 'position ASC'));

        $this->assertCount(2, $items);
        $this->assertSame('Did you enjoy it?', $items[0]->name);
        $this->assertSame('Why not?', $items[1]->name);

        // The dependency points at the copy of the first question here, which
        // is not the id it had on the source site.
        $this->assertSame((int) $items[0]->id, (int) $items[1]->dependitem);
        $this->assertNotSame((int) $first->id, (int) $items[1]->dependitem);
        $this->assertSame('No', $items[1]->dependvalue);

        // A template belongs to the other site; the copy is its own.
        $this->assertSame(0, (int) $items[0]->template);

        $this->assertContains('syncfeedbacknoresponses', $handler->notes($payload));
    }

    /**
     * A database keeps its fields and templates, and the sort order points at
     * the copy of the field it named.
     */
    public function test_data_round_trip_remaps_the_sort_field(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $data = $this->getDataGenerator()->create_module('data', [
            'course' => $source->id,
            'name' => 'Reading list',
            'intro' => '<p>Add what you read.</p>',
            'requiredentries' => 2,
            'approval' => 1,
            'maxentries' => 10,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $title = $generator->create_field(
            (object) ['type' => 'text', 'name' => 'Title', 'description' => 'The book'],
            $data
        );
        $generator->create_field(
            (object) ['type' => 'textarea', 'name' => 'Notes', 'description' => 'What you thought'],
            $data
        );

        $DB->set_field('data', 'defaultsort', $title->field->id, ['id' => $data->id]);
        $DB->set_field('data', 'singletemplate', '<h2>[[Title]]</h2><p>[[Notes]]</p>', ['id' => $data->id]);

        [$cm, $payload, $handler] = $this->round_trip($data->cmid, $target);

        $copy = $DB->get_record('data', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Reading list', $copy->name);
        $this->assertSame(2, (int) $copy->requiredentries);
        $this->assertSame(1, (int) $copy->approval);
        $this->assertSame(10, (int) $copy->maxentries);

        // Templates name fields by name, not by id, so they need no translating.
        $this->assertStringContainsString('[[Title]]', $copy->singletemplate);

        $fields = array_values($DB->get_records('data_fields', ['dataid' => $copy->id], 'id ASC'));

        $this->assertCount(2, $fields);
        $this->assertSame(['Title', 'Notes'], array_map(fn($f) => $f->name, $fields));
        $this->assertSame('text', $fields[0]->type);
        $this->assertSame('textarea', $fields[1]->type);

        // The sort order points at the copy of the field, not its old id.
        $this->assertSame((int) $fields[0]->id, (int) $copy->defaultsort);
        $this->assertNotSame((int) $title->field->id, (int) $copy->defaultsort);

        $this->assertContains('syncdatanoentries', $handler->notes($payload));
    }

    /**
     * A database's custom CSS and JavaScript are refused. They are served to
     * every visitor as a stylesheet and as script, so taking them from another
     * site would let that site run code here.
     */
    public function test_data_code_templates_are_refused(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $data = $this->getDataGenerator()->create_module('data', ['course' => $source->id]);

        $DB->set_field('data', 'jstemplate', 'alert(document.cookie);', ['id' => $data->id]);
        $DB->set_field('data', 'csstemplate', 'body { display: none; }', ['id' => $data->id]);

        [$cm, $payload, $handler] = $this->round_trip($data->cmid, $target);

        $copy = $DB->get_record('data', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('', (string) $copy->jstemplate);
        $this->assertSame('', (string) $copy->csstemplate);

        // And the teacher is told, rather than left to wonder why it looks
        // different.
        $this->assertContains('syncdatacodetemplates', $handler->notes($payload));
    }

    /**
     * A feedback label is a block of HTML that mod_feedback renders with
     * cleaning turned off, so it has to be cleaned on the way in. Nothing
     * downstream will do it.
     */
    public function test_feedback_label_presentation_is_cleaned(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $feedback = $this->getDataGenerator()->create_module('feedback', ['course' => $source->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_feedback');
        $label = $generator->create_item_label($feedback);

        $DB->set_field(
            'feedback_item',
            'presentation',
            '<p>Read this</p><script>alert(document.cookie)</script>',
            ['id' => $label->id]
        );

        [$cm] = $this->round_trip($feedback->cmid, $target);

        $copy = $DB->get_record('feedback_item', ['feedback' => $cm->instance, 'typ' => 'label'], '*', MUST_EXIST);

        $this->assertStringContainsString('Read this', $copy->presentation);
        $this->assertStringNotContainsString('<script', $copy->presentation);
        $this->assertStringNotContainsString('alert(', $copy->presentation);
    }

    /**
     * A multiple choice packs its options into the same field, separated by
     * markers that the HTML cleaner would mangle. Those must survive.
     */
    public function test_feedback_multichoice_presentation_survives_cleaning(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $feedback = $this->getDataGenerator()->create_module('feedback', ['course' => $source->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_feedback')->create_item_multichoice($feedback, [
            'name' => 'Pick one',
            'values' => "Yes\nNo\nMaybe",
        ]);

        $original = $DB->get_record('feedback_item', ['feedback' => $feedback->id, 'typ' => 'multichoice']);

        [$cm] = $this->round_trip($feedback->cmid, $target);

        $copy = $DB->get_record(
            'feedback_item',
            ['feedback' => $cm->instance, 'typ' => 'multichoice'],
            '*',
            MUST_EXIST
        );

        // Byte for byte: an escaped separator here means the question breaks.
        $this->assertSame($original->presentation, $copy->presentation);
        $this->assertStringNotContainsString('&gt;', $copy->presentation);
    }

    /**
     * A workshop keeps its settings and its assessment form, and a rubric level
     * ends up under the copy of the criterion it belonged to.
     */
    public function test_workshop_round_trip_remaps_rubric_levels(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $workshop = $this->getDataGenerator()->create_module('workshop', [
            'course' => $source->id,
            'name' => 'Peer review',
            'strategy' => 'rubric',
            'grade' => 60,
            'gradinggrade' => 40,
            'useselfassessment' => 1,
            'latesubmissions' => 1,
            'nattachments' => 3,
        ]);

        $criterion = (object) [
            'workshopid' => $workshop->id,
            'sort' => 1,
            'description' => 'Is the argument clear?',
            'descriptionformat' => FORMAT_HTML,
        ];
        $criterion->id = $DB->insert_record('workshopform_rubric', $criterion);

        foreach ([[0, 'Not at all'], [1, 'Somewhat'], [2, 'Very']] as [$grade, $definition]) {
            $DB->insert_record('workshopform_rubric_levels', (object) [
                'dimensionid' => $criterion->id,
                'grade' => $grade,
                'definition' => $definition,
                'definitionformat' => FORMAT_HTML,
            ]);
        }

        $DB->insert_record('workshopform_rubric_config', (object) [
            'workshopid' => $workshop->id,
            'layout' => 'grid',
        ]);

        [$cm, $payload, $handler] = $this->round_trip($workshop->cmid, $target);

        $copy = $DB->get_record('workshop', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Peer review', $copy->name);
        $this->assertSame('rubric', $copy->strategy);
        $this->assertEqualsWithDelta(60.0, (float) $copy->grade, 0.0001);
        $this->assertEqualsWithDelta(40.0, (float) $copy->gradinggrade, 0.0001);
        $this->assertSame(1, (int) $copy->useselfassessment);
        $this->assertSame(1, (int) $copy->latesubmissions);
        $this->assertSame(3, (int) $copy->nattachments);

        $criteria = array_values($DB->get_records('workshopform_rubric', ['workshopid' => $copy->id], 'sort ASC'));

        $this->assertCount(1, $criteria);
        $this->assertStringContainsString('argument clear', $criteria[0]->description);

        $levels = array_values($DB->get_records(
            'workshopform_rubric_levels',
            ['dimensionid' => $criteria[0]->id],
            'grade ASC'
        ));

        $this->assertCount(3, $levels);
        $this->assertSame(['Not at all', 'Somewhat', 'Very'], array_map(fn($l) => $l->definition, $levels));

        // The levels hang off the copy of the criterion, not its old id.
        $this->assertNotSame((int) $criterion->id, (int) $criteria[0]->id);

        $config = $DB->get_record('workshopform_rubric_config', ['workshopid' => $copy->id]);
        $this->assertSame('grid', $config->layout);

        $this->assertContains('syncworkshopnosubmissions', $handler->notes($payload));
        $this->assertSame(0, $DB->count_records('workshop_submissions', ['workshopid' => $copy->id]));
    }

    /**
     * A lesson keeps its pages, and every link between them points at the copy
     * of the page it meant rather than at a number from the other site.
     */
    public function test_lesson_round_trip_remaps_the_page_graph(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $lesson = $this->getDataGenerator()->create_module('lesson', [
            'course' => $source->id,
            'name' => 'Safety briefing',
            'practice' => 1,
            'retake' => 0,
            'maxattempts' => 3,
            'progressbar' => 1,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lesson');
        $first = $generator->create_content($lesson, ['title' => 'Welcome']);
        $second = $generator->create_question_truefalse($lesson, ['title' => 'Check']);

        [$cm, $payload, $handler] = $this->round_trip($lesson->cmid, $target);

        $copy = $DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Safety briefing', $copy->name);
        $this->assertSame(1, (int) $copy->practice);
        $this->assertSame(0, (int) $copy->retake);
        $this->assertSame(3, (int) $copy->maxattempts);
        $this->assertSame(1, (int) $copy->progressbar);

        $pages = array_values($DB->get_records('lesson_pages', ['lessonid' => $copy->id], 'id ASC'));

        $this->assertCount(2, $pages);
        $this->assertSame(['Welcome', 'Check'], array_map(fn($p) => $p->title, $pages));

        // The chain has the same shape as the source's. Said in titles rather
        // than ids, because the ids are exactly what is supposed to differ -
        // and because mod_lesson's generator inserts each new page at the front
        // of the chain, so the order is not the order they were created in.
        $this->assertSame($this->chain_by_title($lesson->id), $this->chain_by_title($copy->id));

        // And the ids really are this site's, not the source's.
        $this->assertNotSame((int) $first->id, (int) $pages[0]->id);
        $this->assertNotSame((int) $second->id, (int) $pages[1]->id);

        // Every jump either names a page that exists here or is one of
        // mod_lesson's own constants, which are the same on any site.
        $localpageids = array_map(fn($p) => (int) $p->id, $pages);

        foreach ($DB->get_records('lesson_answers', ['lessonid' => $copy->id]) as $answer) {
            if ((int) $answer->jumpto > 0) {
                $this->assertContains(
                    (int) $answer->jumpto,
                    $localpageids,
                    'A jump points at a page that is not in this lesson'
                );
            }
        }

        $this->assertContains('synclessonnoattempts', $handler->notes($payload));
        $this->assertSame(0, $DB->count_records('lesson_attempts', ['lessonid' => $copy->id]));
    }

    /**
     * An H5P activity keeps its settings, and its package really arrives.
     *
     * The package is the activity: without it there is nothing to open. So this
     * checks the bytes, not just that a file exists.
     */
    public function test_h5pactivity_round_trip(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $h5p = $this->getDataGenerator()->create_module('h5pactivity', [
            'course' => $source->id,
            'name' => 'Interactive exercise',
            'intro' => '<p>Work through this.</p>',
            'grade' => 75,
            'enabletracking' => 1,
            'grademethod' => \mod_h5pactivity\local\manager::GRADELASTATTEMPT,
            'reviewmode' => \mod_h5pactivity\local\manager::REVIEWNONE,
        ]);

        $original = $DB->get_record('h5pactivity', ['id' => $h5p->id], '*', MUST_EXIST);

        [$cm, $payload, $handler] = $this->round_trip($h5p->cmid, $target);

        // The package travels as a file, so the syncer has to move it. Here
        // there is no second site, so it is copied directly into the area the
        // handler declared.
        $this->copy_declared_files($h5p->cmid, $cm, $handler);

        $copy = $DB->get_record('h5pactivity', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Interactive exercise', $copy->name);
        $this->assertSame(75, (int) $copy->grade);
        $this->assertSame(1, (int) $copy->enabletracking);
        $this->assertSame(
            (int) \mod_h5pactivity\local\manager::GRADELASTATTEMPT,
            (int) $copy->grademethod
        );
        $this->assertSame(
            (int) \mod_h5pactivity\local\manager::REVIEWNONE,
            (int) $copy->reviewmode
        );
        $this->assertSame((int) $original->displayoptions, (int) $copy->displayoptions);

        // The package is there, and it is the same package.
        $sourcefile = $this->package_of($h5p->cmid);
        $copyfile = $this->package_of((int) $cm->id);

        $this->assertNotNull($copyfile, 'The copy has no H5P package');
        $this->assertSame($sourcefile->get_filename(), $copyfile->get_filename());
        $this->assertSame($sourcefile->get_contenthash(), $copyfile->get_contenthash());

        $this->assertContains('synch5pnoattempts', $handler->notes($payload));
        $this->assertSame(0, $DB->count_records('h5pactivity_attempts', ['h5pactivityid' => $copy->id]));
    }

    /**
     * An H5P activity without its package is refused rather than created empty.
     *
     * A copy that exists but cannot be opened is worse than none: the next run
     * would see it as already synced and never come back to it.
     */
    public function test_h5pactivity_without_a_package_is_refused(): void {
        [$source, $target] = $this->two_courses();

        $h5p = $this->getDataGenerator()->create_module('h5pactivity', ['course' => $source->id]);

        $exported = get_activity::execute($h5p->cmid);
        $this->assertNotEmpty($exported['files'], 'The fixture should have a package');

        // What the destination would see if the package had gone missing.
        $exported['files'] = [];
        $payload = activity_payload::from_response($exported);

        $this->assertSame('errornopackage', (new h5pactivity_handler())->check_payload($payload));
    }

    /**
     * A grading method or review mode this site does not offer falls back to one
     * it does, rather than being stored and read back as nothing.
     */
    public function test_h5pactivity_unknown_options_fall_back(): void {
        $this->resetAfterTest();

        $method = new \ReflectionMethod(h5pactivity_handler::class, 'clean_grade_method');
        $mode = new \ReflectionMethod(h5pactivity_handler::class, 'clean_review_mode');

        $this->assertSame(
            (int) \mod_h5pactivity\local\manager::GRADEHIGHESTATTEMPT,
            $method->invoke(null, 999)
        );
        $this->assertSame(
            (int) \mod_h5pactivity\local\manager::GRADEAVERAGEATTEMPT,
            $method->invoke(null, (int) \mod_h5pactivity\local\manager::GRADEAVERAGEATTEMPT)
        );
        $this->assertSame(
            (int) \mod_h5pactivity\local\manager::REVIEWCOMPLETION,
            $mode->invoke(null, 999)
        );
        $this->assertSame(
            (int) \mod_h5pactivity\local\manager::REVIEWNONE,
            $mode->invoke(null, (int) \mod_h5pactivity\local\manager::REVIEWNONE)
        );
    }

    /**
     * The H5P package stored against a course module, if it has one.
     *
     * @param int $cmid
     * @return \stored_file|null
     */
    protected function package_of(int $cmid): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_module::instance($cmid)->id,
            'mod_h5pactivity',
            'package',
            0,
            'filename',
            false
        );

        return $files === [] ? null : reset($files);
    }

    /**
     * Copy an activity's files into its copy, the way the syncer would.
     *
     * The real path fetches them from the other site a chunk at a time, which
     * needs a second site. What matters here is that the handler declared the
     * area and files it holds land in the right place, so the transport is cut
     * out and the same areas are copied straight across.
     *
     * @param int $sourcecmid the activity to copy from
     * @param \stdClass $cm the course module copied to
     * @param activity_handler $handler
     * @return void
     */
    protected function copy_declared_files(int $sourcecmid, \stdClass $cm, activity_handler $handler): void {
        $fs = get_file_storage();
        $from = \context_module::instance($sourcecmid);
        $to = \context_module::instance($cm->id);
        $modname = $handler::get_modname();

        foreach ($handler->get_file_areas() as $area) {
            $itemid = !empty($area['anyitemid']) ? false : (int) ($area['itemid'] ?? 0);

            foreach ($fs->get_area_files($from->id, 'mod_' . $modname, $area['filearea'], $itemid, 'id', false) as $file) {
                $fs->create_file_from_storedfile(['contextid' => $to->id], $file);
            }
        }
    }

    /**
     * A lesson's page chain, written in titles instead of ids.
     *
     * Ids are what a sync is supposed to change, so comparing them across two
     * sites proves nothing. What has to survive is the shape: which page each
     * page leads to and comes from.
     *
     * @param int $lessonid
     * @return array<string, array{prev: string, next: string}>
     */
    protected function chain_by_title(int $lessonid): array {
        global $DB;

        $pages = $DB->get_records('lesson_pages', ['lessonid' => $lessonid]);
        $titles = [];

        foreach ($pages as $page) {
            $titles[(int) $page->id] = $page->title;
        }

        $chain = [];

        foreach ($pages as $page) {
            $chain[$page->title] = [
                'prev' => $titles[(int) $page->prevpageid] ?? '',
                'next' => $titles[(int) $page->nextpageid] ?? '',
            ];
        }

        ksort($chain);

        return $chain;
    }

    /**
     * A lesson's password does not travel, and the setting goes with it so the
     * copy is not left letting anybody in with a blank password.
     */
    public function test_lesson_password_is_not_carried(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $source->id]);
        $DB->set_field('lesson', 'usepassword', 1, ['id' => $lesson->id]);
        $DB->set_field('lesson', 'password', 'letmein', ['id' => $lesson->id]);

        $exported = get_activity::execute($lesson->cmid);

        foreach ($exported['settings'] as $setting) {
            $this->assertNotSame('letmein', $setting['value']);
            $this->assertNotSame('password', $setting['name']);
        }

        [$cm, $payload, $handler] = $this->round_trip($lesson->cmid, $target);

        $copy = $DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame(0, (int) $copy->usepassword);
        $this->assertSame('', (string) $copy->password);

        // Silently dropping the protection would be worse than saying so.
        $this->assertContains('synclessonnopassword', $handler->notes($payload));
    }

    /**
     * A lesson points at another activity by an id that means nothing here, so
     * the copy has neither link rather than a link to whatever holds that id.
     */
    public function test_lesson_local_references_are_dropped(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $source->id]);
        $DB->set_field('lesson', 'dependency', 4242, ['id' => $lesson->id]);
        $DB->set_field('lesson', 'activitylink', 4243, ['id' => $lesson->id]);

        [$cm] = $this->round_trip($lesson->cmid, $target);

        $copy = $DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame(0, (int) $copy->dependency);
        $this->assertSame(0, (int) $copy->activitylink);
    }

    /**
     * A lesson's files are sorted out by which area they came from: page
     * pictures to the page they belong to, answer pictures to the answer.
     */
    public function test_lesson_files_are_filed_by_area(): void {
        global $DB;

        [$source, $target] = $this->two_courses();

        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $source->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lesson');
        $page = $generator->create_content($lesson, ['title' => 'Welcome']);

        [$cm, $payload, $handler] = $this->round_trip($lesson->cmid, $target);

        $localpage = $DB->get_record('lesson_pages', ['lessonid' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame(
            (int) $localpage->id,
            $handler->map_file_itemid($payload, [
                'filearea' => 'page_contents',
                'itemid' => (int) $page->id,
            ], $cm)
        );

        // One media file for the whole lesson, always under the same id.
        $this->assertSame(
            0,
            $handler->map_file_itemid($payload, ['filearea' => 'mediafile', 'itemid' => 0], $cm)
        );

        // A page that did not arrive has nothing to hang a file off.
        $this->assertNull(
            $handler->map_file_itemid($payload, ['filearea' => 'page_contents', 'itemid' => 999999], $cm)
        );

        // And an area the handler never declared is refused outright.
        $this->assertNull(
            $handler->map_file_itemid($payload, ['filearea' => 'somewhere_else', 'itemid' => 0], $cm)
        );
    }
}
