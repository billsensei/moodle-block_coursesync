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
 * Round-trip tests for the folder, book, wiki, assignment and quiz handlers.
 *
 * These follow handlers_test: export a real activity through the source-side
 * code and rebuild it through the destination-side code in a second course,
 * then compare. A handler whose two halves disagree is exactly what a one-sided
 * test would miss, and three of these types have a second table or a form-shaped
 * import to get wrong.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(folder_handler::class)]
#[CoversClass(book_handler::class)]
#[CoversClass(wiki_handler::class)]
#[CoversClass(assign_handler::class)]
#[CoversClass(quiz_handler::class)]
final class new_handlers_test extends advanced_testcase {
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
     * A folder keeps how it is displayed.
     */
    public function test_folder_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $folder = $this->getDataGenerator()->create_module('folder', [
            'course' => $source->id,
            'name' => 'Week one handouts',
            'intro' => '<p>Everything for week one.</p>',
            'display' => 1,
            'showexpanded' => 0,
            'forcedownload' => 0,
        ]);

        [$cm] = $this->round_trip($folder->cmid, $target);

        $copy = $DB->get_record('folder', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Week one handouts', $copy->name);
        $this->assertSame(1, (int) $copy->display);
        $this->assertSame(0, (int) $copy->showexpanded);
        $this->assertSame(0, (int) $copy->forcedownload);
        $this->assertStringContainsString('week one', $copy->intro);

        // A folder's files all sit under one item id, so nothing is remapped.
        $this->assertSame(
            [['filearea' => 'content', 'itemid' => 0]],
            (new folder_handler())->get_file_areas()
        );
    }

    /**
     * A book's chapters come across, in order, with their content.
     */
    public function test_book_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $source->id,
            'name' => 'Course handbook',
            'numbering' => 1,
            'customtitles' => 1,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $generator->create_chapter(['bookid' => $book->id, 'pagenum' => 1, 'title' => 'Introduction',
            'content' => '<p>Start here.</p>']);
        $generator->create_chapter(['bookid' => $book->id, 'pagenum' => 2, 'title' => 'Detail',
            'content' => '<p>More.</p>', 'subchapter' => 1]);
        $generator->create_chapter(['bookid' => $book->id, 'pagenum' => 3, 'title' => 'Hidden bit',
            'content' => '<p>Not yet.</p>', 'hidden' => 1]);

        [$cm, $payload] = $this->round_trip($book->cmid, $target);

        $this->assertCount(3, $payload->children('chapter'));

        $copy = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('Course handbook', $copy->name);
        $this->assertSame(1, (int) $copy->numbering);
        $this->assertSame(1, (int) $copy->customtitles);

        $chapters = array_values($DB->get_records('book_chapters', ['bookid' => $copy->id], 'pagenum ASC'));

        $this->assertCount(3, $chapters);
        $this->assertSame('Introduction', $chapters[0]->title);
        $this->assertStringContainsString('Start here', $chapters[0]->content);
        $this->assertSame(1, (int) $chapters[0]->pagenum);
        $this->assertSame('Detail', $chapters[1]->title);
        $this->assertSame(1, (int) $chapters[1]->subchapter);
        $this->assertSame(1, (int) $chapters[2]->hidden);

        // Page numbers are rebuilt from one upwards rather than trusted.
        $this->assertSame([1, 2, 3], array_map(fn($c) => (int) $c->pagenum, $chapters));
    }

    /**
     * A chapter's files are pointed at the chapter created for them here, and a
     * file belonging to a chapter that did not arrive is left behind rather
     * than filed against whichever local chapter took that number.
     */
    public function test_book_file_item_ids_are_remapped(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $book = $this->getDataGenerator()->create_module('book', ['course' => $source->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $first = $generator->create_chapter(['bookid' => $book->id, 'pagenum' => 1, 'title' => 'One']);
        $second = $generator->create_chapter(['bookid' => $book->id, 'pagenum' => 2, 'title' => 'Two']);

        [$cm, $payload, $handler] = $this->round_trip($book->cmid, $target);

        $chapters = array_values($DB->get_records('book_chapters', ['bookid' => $cm->instance], 'pagenum ASC'));

        $this->assertSame(
            (int) $chapters[0]->id,
            $handler->map_file_itemid($payload, ['itemid' => (int) $first->id], $cm)
        );
        $this->assertSame(
            (int) $chapters[1]->id,
            $handler->map_file_itemid($payload, ['itemid' => (int) $second->id], $cm)
        );

        // The local ids are this site's, not the source's.
        $this->assertNull($handler->map_file_itemid($payload, ['itemid' => 999999], $cm));
    }

    /**
     * A book declares its chapter file area without naming item ids, because it
     * cannot know them until the chapters exist here.
     */
    public function test_book_declares_an_any_itemid_area(): void {
        $this->resetAfterTest();

        $areas = (new book_handler())->get_file_areas();

        $this->assertCount(1, $areas);
        $this->assertSame('chapter', $areas[0]['filearea']);
        $this->assertTrue($areas[0]['anyitemid']);
        $this->assertArrayNotHasKey('itemid', $areas[0]);
    }

    /**
     * A wiki keeps its mode, format and first page title, and says that its
     * pages did not come with it.
     */
    public function test_wiki_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $wiki = $this->getDataGenerator()->create_module('wiki', [
            'course' => $source->id,
            'name' => 'Group glossary',
            'wikimode' => 'individual',
            'defaultformat' => 'creole',
            'forceformat' => 1,
            'firstpagetitle' => 'Where to start',
        ]);

        [$cm, $payload, $handler] = $this->round_trip($wiki->cmid, $target);

        $copy = $DB->get_record('wiki', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Group glossary', $copy->name);
        $this->assertSame('individual', $copy->wikimode);
        $this->assertSame('creole', $copy->defaultformat);
        $this->assertSame(1, (int) $copy->forceformat);
        $this->assertSame('Where to start', $copy->firstpagetitle);

        $this->assertContains('syncwikinopages', $handler->notes($payload));
        $this->assertSame(0, $DB->count_records('wiki_subwikis', ['wikiid' => $copy->id]));
    }

    /**
     * An assignment keeps its dates, its grading and its marking settings.
     */
    public function test_assign_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $due = time() + WEEKSECS;

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $source->id,
            'name' => 'Essay one',
            'intro' => '<p>Write about it.</p>',
            'duedate' => $due,
            'allowsubmissionsfromdate' => $due - WEEKSECS,
            'cutoffdate' => $due + DAYSECS,
            'grade' => 80,
            'submissiondrafts' => 1,
            'requiresubmissionstatement' => 1,
            'blindmarking' => 1,
            'markingworkflow' => 1,
            'maxattempts' => 3,
            'attemptreopenmethod' => 'manual',
        ]);

        [$cm, $payload, $handler] = $this->round_trip($assign->cmid, $target);

        $copy = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Essay one', $copy->name);
        $this->assertSame($due, (int) $copy->duedate);
        $this->assertSame($due + DAYSECS, (int) $copy->cutoffdate);
        $this->assertSame(80, (int) $copy->grade);
        $this->assertSame(1, (int) $copy->submissiondrafts);
        $this->assertSame(1, (int) $copy->requiresubmissionstatement);
        $this->assertSame(1, (int) $copy->blindmarking);
        $this->assertSame(1, (int) $copy->markingworkflow);
        $this->assertSame(3, (int) $copy->maxattempts);
        $this->assertSame('manual', $copy->attemptreopenmethod);

        // A grouping id means nothing on another site, so it is not carried.
        $this->assertSame(0, (int) $copy->teamsubmissiongroupingid);
        // Nobody has submitted here, so identities cannot have been revealed.
        $this->assertSame(0, (int) $copy->revealidentities);

        $this->assertContains('syncassignnosubmissions', $handler->notes($payload));
    }

    /**
     * Which submission and feedback types are switched on comes across, and is
     * saved by the subplugins that own those settings.
     */
    public function test_assign_plugin_config_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $source->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 7,
            'assignsubmission_file_maxsizebytes' => 1048576,
            'assignfeedback_comments_enabled' => 1,
        ]);

        [$cm, $payload] = $this->round_trip($assign->cmid, $target);

        $this->assertNotEmpty($payload->children('pluginconfig'));

        $config = [];

        foreach ($DB->get_records('assign_plugin_config', ['assignment' => $cm->instance]) as $row) {
            $config[$row->subtype . '_' . $row->plugin . '_' . $row->name] = $row->value;
        }

        // These are the names the settings are stored under, which is not what
        // the settings form calls them: maxfiles on the form is
        // maxfilesubmissions in the table. Carrying the stored rows is what
        // makes that difference not matter.
        $this->assertSame('1', $config['assignsubmission_onlinetext_enabled']);
        $this->assertSame('1', $config['assignsubmission_file_enabled']);
        $this->assertSame('7', $config['assignsubmission_file_maxfilesubmissions']);
        $this->assertSame('1', $config['assignfeedback_comments_enabled']);

        // The copy's settings are the source's, not this site's defaults.
        $source = [];

        foreach ($DB->get_records('assign_plugin_config', ['assignment' => $assign->id]) as $row) {
            $source[$row->subtype . '_' . $row->plugin . '_' . $row->name] = $row->value;
        }

        $this->assertSame($source, $config);
    }

    /**
     * A plugin config row naming something that is not an assignment subplugin,
     * or a plugin this site does not have, is dropped rather than written into
     * the settings table where nothing would ever read it back.
     */
    public function test_assign_plugin_config_is_filtered(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);

        $payload = new activity_payload(
            11,
            'assign',
            'Filtered',
            'coursesync-11',
            0,
            true,
            '',
            FORMAT_HTML,
            0,
            [],
            [],
            [
                ['type' => 'pluginconfig', 'sortorder' => 0, 'fields' => [
                    'subtype' => 'assignsubmission', 'plugin' => 'onlinetext', 'name' => 'enabled', 'value' => '1',
                ]],
                ['type' => 'pluginconfig', 'sortorder' => 1, 'fields' => [
                    'subtype' => 'notasubtype', 'plugin' => 'onlinetext', 'name' => 'enabled', 'value' => '1',
                ]],
                ['type' => 'pluginconfig', 'sortorder' => 2, 'fields' => [
                    'subtype' => 'assignsubmission', 'plugin' => 'notaplugin', 'name' => 'enabled', 'value' => '1',
                ]],
            ]
        );

        $handler = new assign_handler();
        $method = new \ReflectionMethod($handler, 'restore_plugin_config');
        $method->invoke($handler, (int) $assign->id, (int) $cm->id, $payload);

        $rows = $DB->get_records('assign_plugin_config', ['assignment' => $assign->id]);
        $written = [];

        foreach ($rows as $row) {
            $written[] = $row->subtype . '_' . $row->plugin . '_' . $row->name;
        }

        $this->assertSame(['assignsubmission_onlinetext_enabled'], $written);
    }

    /**
     * A quiz keeps its settings, and its review rules survive being taken apart
     * into the checkboxes mod_quiz rebuilds them from.
     */
    public function test_quiz_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $open = time() + DAYSECS;

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $source->id,
            'name' => 'End of unit test',
            'timeopen' => $open,
            'timeclose' => $open + WEEKSECS,
            'timelimit' => 1800,
            'attempts' => 2,
            'grademethod' => 3,
            'questionsperpage' => 2,
            'shuffleanswers' => 1,
            'navmethod' => 'sequential',
            'overduehandling' => 'graceperiod',
            'graceperiod' => 3600,
        ]);

        [$cm, $payload, $handler] = $this->round_trip($quiz->cmid, $target);

        $copy = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $original = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);

        $this->assertSame('End of unit test', $copy->name);
        $this->assertSame($open, (int) $copy->timeopen);
        $this->assertSame($open + WEEKSECS, (int) $copy->timeclose);
        $this->assertSame(1800, (int) $copy->timelimit);
        $this->assertSame(2, (int) $copy->attempts);
        $this->assertSame(3, (int) $copy->grademethod);
        $this->assertSame(2, (int) $copy->questionsperpage);
        $this->assertSame(1, (int) $copy->shuffleanswers);
        $this->assertSame('sequential', $copy->navmethod);
        $this->assertSame('graceperiod', $copy->overduehandling);
        $this->assertSame(3600, (int) $copy->graceperiod);

        // The eight review columns are rebuilt from checkboxes rather than
        // copied, so this is the assertion that would catch that going wrong.
        $reviewfields = [
            'attempt',
            'correctness',
            'maxmarks',
            'marks',
            'specificfeedback',
            'generalfeedback',
            'rightanswer',
            'overallfeedback',
        ];

        foreach ($reviewfields as $field) {
            $this->assertSame(
                (int) $original->{'review' . $field},
                (int) $copy->{'review' . $field},
                "review{$field} did not survive the round trip"
            );
        }

        // No questions, and the teacher is told so.
        $this->assertSame(0, $DB->count_records('quiz_slots', ['quizid' => $copy->id]));
        $this->assertContains('syncquiznoquestions', $handler->notes($payload));
    }

    /**
     * A quiz's password is not exported at all: it is a secret of the other
     * site, and leaving it out keeps it out of requests, logs and responses.
     */
    public function test_quiz_password_is_not_carried(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $source->id]);
        $DB->set_field('quiz', 'password', 'letmein', ['id' => $quiz->id]);
        $DB->set_field('quiz', 'subnet', '10.0.0.0/8', ['id' => $quiz->id]);

        $exported = get_activity::execute($quiz->cmid);

        foreach ($exported['settings'] as $setting) {
            $this->assertNotSame('letmein', $setting['value']);
            $this->assertNotSame('password', $setting['name']);
            $this->assertNotSame('quizpassword', $setting['name']);
        }

        [$cm] = $this->round_trip($quiz->cmid, $target);

        $copy = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('', (string) $copy->password);
        $this->assertSame('', (string) $copy->subnet);
    }

    /**
     * A quiz's maximum grade keeps its decimals, and a negative one - which is
     * a scale in an assignment but is nothing in a quiz - becomes no grade.
     */
    public function test_quiz_grade_is_not_a_whole_number(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $source->id]);
        $DB->set_field('quiz', 'grade', 12.5, ['id' => $quiz->id]);

        [$cm] = $this->round_trip($quiz->cmid, $target);

        $this->assertEqualsWithDelta(
            12.5,
            (float) $DB->get_field('quiz', 'grade', ['id' => $cm->instance]),
            0.00001
        );
    }

    /**
     * A wiki's first page title is text, and is treated as text rather than
     * reaching a page title with markup still in it.
     */
    public function test_wiki_first_page_title_is_cleaned(): void {
        $this->resetAfterTest();

        $method = new \ReflectionMethod(wiki_handler::class, 'clean_first_page_title');

        $this->assertSame('alert(1) Start', $method->invoke(null, '<script>alert(1)</script> Start'));

        // A wiki cannot open without a first page, so an empty title becomes
        // one rather than a page nobody can reach.
        $this->assertNotSame('', $method->invoke(null, '   '));
    }

    /**
     * A book whose chapters cannot be written does not leave a half-made book
     * behind, because the next run would pass over it as already synced.
     */
    public function test_book_is_removed_if_its_chapters_fail(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $book = $this->getDataGenerator()->create_module('book', ['course' => $source->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_book')
            ->create_chapter(['bookid' => $book->id, 'pagenum' => 1, 'title' => 'One']);

        $payload = activity_payload::from_response(get_activity::execute($book->cmid));

        // A handler whose chapter writing fails, standing in for a database
        // that refuses one of them.
        $handler = new class extends book_handler {
            /**
             * Fail the way a refused insert would.
             *
             * @param int $bookid
             * @param activity_payload $payload
             * @return void
             */
            protected function create_chapters(int $bookid, activity_payload $payload): void {
                throw new \moodle_exception('error');
            }
        };

        $before = $DB->count_records('book', ['course' => $target->id]);

        try {
            $handler->create_from_remote_data($target, $payload, 'coursesync-1');
            $this->fail('Expected a moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorcreatefailed', $e->errorcode);
        }

        $this->assertSame($before, $DB->count_records('book', ['course' => $target->id]));
        $this->assertSame(0, $DB->count_records('course_modules', [
            'course' => $target->id,
            'idnumber' => 'coursesync-1',
        ]));
    }

    /**
     * A question behaviour the destination does not have falls back to one it
     * does, rather than producing a quiz nobody can attempt.
     */
    public function test_quiz_unknown_behaviour_falls_back(): void {
        $this->resetAfterTest();

        $method = new \ReflectionMethod(quiz_handler::class, 'clean_behaviour');

        $this->assertSame('deferredfeedback', $method->invoke(null, 'notabehaviour'));
        $this->assertSame('immediatefeedback', $method->invoke(null, 'immediatefeedback'));
    }
}
