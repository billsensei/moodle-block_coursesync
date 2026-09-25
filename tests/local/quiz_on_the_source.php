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

use block_coursesync\connection;
use block_coursesync\course_result;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/source_on_this_site.php');

/**
 * A quiz on a "source" course of this same site, copied into a destination
 * course by the real quiz handler, with a Course Sync block there mapped to
 * the source - and helpers for students' attempts at it.
 *
 * Call set_up_quiz_source() from setUp(). The quiz has four slots:
 * shortanswer (2 marks), shortanswer (3), essay (5) and a random true/false (1).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait quiz_on_the_source {
    use source_on_this_site;

    /** @var \stdClass The source course. */
    protected \stdClass $source;

    /** @var \stdClass The destination course. */
    protected \stdClass $course;

    /** @var int The Course Sync block in the destination. */
    protected int $instanceid;

    /** @var \stdClass The source quiz: shortanswer (2), shortanswer (3), essay (5), random true/false (1). */
    protected \stdClass $quiz;

    /** @var \stdClass The destination's copy of it (course_modules record). */
    protected \stdClass $copycm;

    /** @var \stdClass A student in both courses, username "sam". */
    protected \stdClass $student;

    /**
     * Source and destination courses, a quiz copied between them, a mapped
     * Course Sync block, and grade sync switched on both ways.
     *
     * @return void
     */
    protected function set_up_quiz_source(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allowgradeexport', 1, 'block_coursesync');
        set_config('allowgradepull', 1, 'block_coursesync');

        $generator = $this->getDataGenerator();
        $this->source = $generator->create_course();
        $this->course = $generator->create_course();
        $this->student = $generator->create_and_enrol($this->source, 'student', ['username' => 'sam']);
        $generator->enrol_user($this->student->id, $this->course->id, 'student');

        $this->quiz = $generator->create_module('quiz', [
            'course' => $this->source->id,
            'grade' => 10,
            'questionsperpage' => 0,
        ]);
        $qgen = $generator->get_plugin_generator('core_question');
        $category = $qgen->create_question_category();
        $pool = $qgen->create_question_category(['name' => 'Pool']);
        \quiz_add_quiz_question($qgen->create_question('shortanswer', null, ['category' => $category->id])->id, $this->quiz, 0, 2);
        \quiz_add_quiz_question($qgen->create_question('shortanswer', null, ['category' => $category->id])->id, $this->quiz, 0, 3);
        \quiz_add_quiz_question($qgen->create_question('essay', null, ['category' => $category->id])->id, $this->quiz, 0, 5);
        $qgen->create_question('truefalse', null, ['category' => $pool->id]);
        quiz_settings::create($this->quiz->id)->get_structure()->add_random_questions(0, 1, [
            'filter' => [
                'category' => [
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => [$pool->id],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
        ]);
        quiz_settings::create($this->quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

        [$this->copycm] = $this->copy((int) $this->quiz->cmid, $this->course);

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($this->course->id));
        $page->set_course($this->course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $this->course->format);
        $page->set_url('/course/view.php', ['id' => $this->course->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');
        $this->instanceid = (int) $DB->get_field('block_instances', 'id', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($this->course->id)->id,
        ], MUST_EXIST);

        connection::set_url($this->instanceid, $this->course->id, 'https://source.example.edu');
        connection::set_token($this->instanceid, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            $this->instanceid,
            'SRC',
            course_result::success((int) $this->source->id, 'SRC', 'Source', true, 1)
        );
        $DB->set_field('block_coursesync_connection', 'remotesitename', 'Source Site', ['blockinstanceid' => $this->instanceid]);
    }

    /**
     * A student's attempt at the source quiz, answered, submitted and graded,
     * with the essay marked by hand if a mark is given.
     *
     * @param \stdClass $user
     * @param array $responses slot => simulated response
     * @param float|null $essaymark
     * @return \stdClass the quiz_attempts record
     */
    protected function source_attempt(\stdClass $user, array $responses, ?float $essaymark = null): \stdClass {
        global $DB;

        $this->setUser($user);
        $quizobj = quiz_settings::create($this->quiz->id, $user->id);
        $number = 1 + $DB->count_records('quiz_attempts', ['quiz' => $this->quiz->id, 'userid' => $user->id]);
        $time = 1750000000 + 1000 * $number;

        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($quizobj, $number, null, $time, false, $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, $number, $time);
        quiz_attempt_save_started($quizobj, $quba, $attempt, $time);

        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($time + 10, false, $responses);
        $attemptobj->process_submit($time + 600, false);
        $attemptobj->process_grade_submission($time + 600);
        $this->setAdminUser();

        if ($essaymark !== null) {
            $this->mark_source_essay((int) $attempt->id, $essaymark);
        }

        return $DB->get_record('quiz_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
    }

    /**
     * Mark a source attempt's essay by hand, as the quiz's grading page does.
     *
     * @param int $attemptid
     * @param float $mark
     * @return void
     */
    protected function mark_source_essay(int $attemptid, float $mark): void {
        global $DB;

        $record = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $quba = \question_engine::load_questions_usage_by_activity($record->uniqueid);
        $quba->manual_grade(3, 'Marked', $mark, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($quba);
        $DB->set_field('quiz_attempts', 'sumgrades', $quba->get_total_mark(), ['id' => $attemptid]);
        quiz_settings::create($this->quiz->id)->get_grade_calculator()->recompute_final_grade((int) $record->userid);
    }

    /**
     * Every answer right except the second short answer (80%) - the essay
     * as given.
     *
     * @return array
     */
    protected function answers(): array {
        return [
            1 => ['answer' => 'frog'],
            2 => ['answer' => 'toad'],
            3 => ['answer' => 'My essay', 'answerformat' => FORMAT_HTML],
            4 => ['answer' => 1],
        ];
    }

    /**
     * The destination's quiz record.
     *
     * @return \stdClass
     */
    protected function copy_quiz(): \stdClass {
        global $DB;

        return $DB->get_record('quiz', ['id' => $this->copycm->instance], '*', MUST_EXIST);
    }

    /**
     * The student's attempts at the copy, oldest first.
     *
     * @return \stdClass[]
     */
    protected function copy_attempts(): array {
        global $DB;

        return array_values($DB->get_records(
            'quiz_attempts',
            ['quiz' => $this->copycm->instance, 'userid' => $this->student->id],
            'attempt ASC'
        ));
    }

    /**
     * The mark of each question in an attempt here.
     *
     * @param \stdClass $attempt
     * @return array slot => mark or null
     */
    protected function marks_here(\stdClass $attempt): array {
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $marks = [];

        foreach ($quba->get_slots() as $slot) {
            $marks[$slot] = $quba->get_question_mark($slot);
        }

        return $marks;
    }
}
