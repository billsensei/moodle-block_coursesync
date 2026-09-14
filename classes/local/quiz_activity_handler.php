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

/**
 * Recreates a mod_quiz instance AND its questions from
 * quiz_activity_exporter's payload.
 *
 * Two core entry points are reused here, the same "let the real code do it"
 * principle every other activity_handler already follows for
 * `<modname>_add_instance()`:
 *
 * - `quiz_add_instance()` for the quiz itself. It does NOT set
 *   course_modules.instance itself (same quirk as url/label/forum/assign),
 *   so that's done explicitly below. Its call to quiz_process_options()
 *   recomputes reviewattempt/reviewcorrectness/... from a set of per-phase
 *   form checkboxes (attemptduring, correctnessopen, ...) this handler never
 *   supplies - rather than reverse-engineer that checkbox shape, the exported
 *   ints are written back afterwards with one direct update_record() call
 *   (apply_review_options()), which is simpler and exactly as correct.
 *   Quiz feedback boundaries (quiz_feedback) are not synced at all - leaving
 *   $quizdata->feedbacktext unset makes quiz_process_options() skip that
 *   whole path (feedbackboundarycount = -1), rather than reconstructing its
 *   own form shape for a secondary setting.
 * - `quiz_add_quiz_question($questionid, $quiz, $page, $maxmark)` for every
 *   slot - the same function mod/quiz/edit.php's own "add question(s) to
 *   quiz" actions call, and Moodle's own test generator uses to build quizzes
 *   for its test suite. It creates the quiz_slots row and the
 *   question_references row (version = null, "always latest") together,
 *   correctly, without this plugin needing to know that schema at all.
 *
 * A slot's own question is created first via that qtype's own
 * question_handler (see question_handler.php), through
 * \question_bank::get_qtype($qtype)->save_question() - the same reasoning
 * again, one level down.
 *
 * Every pulled question is created fresh in a category dedicated to this new
 * quiz (its own module context's default category -
 * question_get_default_category(), the same category a teacher's first
 * manually-added question would land in) rather than any shared/course-level
 * bank, so this never collides with anything already in the destination
 * course's own question banks.
 *
 * Not atomic across slots: {@see \question_type::save_question()} wraps
 * each individual question's own creation in a transaction, but if one
 * slot's question or quiz_add_quiz_question() call throws (e.g. malformed
 * remote data), slots already added are NOT rolled back - the exception
 * propagates to sync_runner::pull_and_create()'s catch, which reports the
 * whole quiz as failed, but a partially-populated quiz activity can be left
 * behind in the destination course. This is the same granularity every
 * other activity_handler already has (sync_runner has no concept of
 * "half-created"), just more visible here because a quiz has many
 * sub-items instead of one.
 *
 * A random slot (question_set_references - "Add > a random question") is
 * recreated as a real random slot too, not skipped: see create_random_slot()
 * for how quiz_activity_exporter's pulled pool of currently-eligible
 * questions becomes a fresh destination-only category plus a genuine random
 * quiz_slots row via \mod_quiz\structure::add_random_questions(), so the
 * destination quiz keeps picking a different question at random each
 * attempt, same as the source. Only a random slot the exporter couldn't
 * resolve at all (nothing eligible in its category, or an unrecognised
 * filter shape), and any fixed question whose qtype isn't registered in
 * question_handler_registry, are skipped rather than failed - see
 * quiz_activity_exporter's docblock for how those are marked in the payload.
 * Unlike an unsupported *activity* type, a skipped slot doesn't block
 * lastsync from advancing (sync_runner only inspects the top-level unsupported/
 * failed lists, and a quiz with only some slots skipped is still reported as
 * "created" - there is currently no plumbing to report a partial pull for
 * one activity, so a skipped slot is silently absent rather than visible in
 * the sync summary; this is a known gap, not a design goal).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_activity_handler implements activity_handler {
    /** Overdue handling values mod_quiz itself recognises. */
    protected const OVERDUE_HANDLING = ['autosubmit', 'graceperiod', 'autoabandon'];

    /** Navigation methods mod_quiz itself recognises. */
    protected const NAV_METHODS = ['free', 'sequential'];

    /** Core question behaviours a quiz can reasonably be set to use. */
    protected const BEHAVIOURS = [
        'deferredfeedback', 'adaptive', 'adaptivenopenalty', 'interactive',
        'interactivecountback', 'manualgraded', 'immediatefeedback', 'deferredcbm',
    ];

    /**
     * Creates the quiz course module, instance, and its questions.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from quiz_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/bank.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);

        $newcm = new \stdClass();
        $newcm->course = $courseid;
        $newcm->module = $moduleid;
        $newcm->instance = 0;
        $newcm->section = 0;
        $newcm->idnumber = $idnumber;
        $newcm->visible = 1;
        $newcm->visibleold = 1;
        $newcm->visibleoncoursepage = 1;
        $newcm->groupmode = 0;
        $newcm->groupingid = 0;
        $newcm->completion = 0;
        $newcm->showdescription = 0;

        $cmid = add_course_module($newcm);

        $quizdata = $this->build_quiz_data($courseid, $cmid, $data);

        // This call does NOT set course_modules.instance for $cmid - done explicitly below.
        $quizid = quiz_add_instance($quizdata);
        $DB->set_field('course_modules', 'instance', $quizid, ['id' => $cmid]);

        $this->apply_review_options($quizid, $data);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'quiz');
        rebuild_course_cache($courseid, true);

        $this->create_slots($quizid, $cmid, $courseid, $data['slots'] ?? []);

        return $cmid;
    }

    /**
     * Builds the quiz row's own settings, everything quiz_add_instance()
     * needs beyond what quiz_process_options() computes or defaults itself.
     *
     * @param int $courseid
     * @param int $cmid
     * @param array $data
     * @return \stdClass
     */
    protected function build_quiz_data(int $courseid, int $cmid, array $data): \stdClass {
        $quizdata = new \stdClass();
        $quizdata->course = $courseid;
        $quizdata->coursemodule = $cmid;
        $quizdata->name = sanitizer::text($data['name'] ?? '');
        $quizdata->intro = sanitizer::html($data['intro'] ?? '');
        $quizdata->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        // Renamed to ->password inside quiz_process_options(); never carried
        // over from the remote site (same reasoning as assign's
        // teamsubmissiongroupingid - a password protecting the source
        // quiz has no meaning for a freshly-created destination copy).
        $quizdata->quizpassword = '';
        $quizdata->subnet = '';
        $quizdata->browsersecurity = '';
        $quizdata->delay1 = 0;
        $quizdata->delay2 = 0;
        $quizdata->timeopen = sanitizer::integer($data['timeopen'] ?? 0);
        $quizdata->timeclose = sanitizer::integer($data['timeclose'] ?? 0);
        $quizdata->timelimit = sanitizer::integer($data['timelimit'] ?? 0);
        $quizdata->overduehandling = $this->sanitize_enum(
            $data['overduehandling'] ?? 'autosubmit',
            self::OVERDUE_HANDLING,
            'autosubmit'
        );
        $quizdata->graceperiod = sanitizer::integer($data['graceperiod'] ?? 0);
        $quizdata->preferredbehaviour = $this->sanitize_enum(
            $data['preferredbehaviour'] ?? 'deferredfeedback',
            self::BEHAVIOURS,
            'deferredfeedback'
        );
        $quizdata->canredoquestions = sanitizer::integer($data['canredoquestions'] ?? 0);
        $quizdata->attempts = sanitizer::integer($data['attempts'] ?? 0);
        $quizdata->attemptonlast = sanitizer::integer($data['attemptonlast'] ?? 0);
        $quizdata->grademethod = sanitizer::integer($data['grademethod'] ?? 1, 1);
        $quizdata->decimalpoints = sanitizer::integer($data['decimalpoints'] ?? 2, 2);
        $quizdata->questiondecimalpoints = sanitizer::integer($data['questiondecimalpoints'] ?? -1, -1);
        $quizdata->questionsperpage = sanitizer::integer($data['questionsperpage'] ?? 1, 1);
        $quizdata->navmethod = $this->sanitize_enum($data['navmethod'] ?? 'free', self::NAV_METHODS, 'free');
        $quizdata->shuffleanswers = sanitizer::integer($data['shuffleanswers'] ?? 1, 1);
        $quizdata->grade = sanitizer::float($data['grade'] ?? 100, 100.0);
        $quizdata->showuserpicture = sanitizer::integer($data['showuserpicture'] ?? 0);
        $quizdata->showblocks = 0;
        $quizdata->completionattemptsexhausted = 0;
        $quizdata->completionminattempts = 0;
        $quizdata->allowofflineattempts = 0;

        return $quizdata;
    }

    /**
     * Overwrites the review-option columns quiz_process_options() computed
     * from (absent) form checkboxes with the exported values instead - see
     * class docblock.
     *
     * @param int $quizid
     * @param array $data
     */
    protected function apply_review_options(int $quizid, array $data): void {
        global $DB;

        $update = new \stdClass();
        $update->id = $quizid;
        $update->reviewattempt = sanitizer::integer($data['reviewattempt'] ?? 0);
        $update->reviewcorrectness = sanitizer::integer($data['reviewcorrectness'] ?? 0);
        $update->reviewmaxmarks = sanitizer::integer($data['reviewmaxmarks'] ?? 0);
        $update->reviewmarks = sanitizer::integer($data['reviewmarks'] ?? 0);
        $update->reviewspecificfeedback = sanitizer::integer($data['reviewspecificfeedback'] ?? 0);
        $update->reviewgeneralfeedback = sanitizer::integer($data['reviewgeneralfeedback'] ?? 0);
        $update->reviewrightanswer = sanitizer::integer($data['reviewrightanswer'] ?? 0);
        $update->reviewoverallfeedback = sanitizer::integer($data['reviewoverallfeedback'] ?? 0);

        $DB->update_record('quiz', $update);
    }

    /**
     * Creates every supported slot's question and adds it to the quiz, in
     * source order, then recomputes the quiz's sumgrades from the slots just
     * added - the same two steps mod/quiz/edit.php's own "add question(s)"
     * action takes after quiz_add_quiz_question() (quiz_delete_previews() is
     * a no-op for a quiz this new, but cheap and harmless to call for
     * consistency with that same real flow).
     *
     * A random slot (qtype 'random', a 'questions' pool instead of a single
     * 'question' - see quiz_activity_exporter::export_random_pool()) is
     * handled separately by create_random_slot(), since it needs its own
     * category and a real random quiz_slots row, not a single fixed
     * question added via quiz_add_quiz_question().
     *
     * @param int $quizid
     * @param int $cmid
     * @param int $courseid
     * @param array $slots From quiz_activity_exporter::export_slots().
     */
    protected function create_slots(int $quizid, int $cmid, int $courseid, array $slots): void {
        global $DB;

        if (empty($slots)) {
            return;
        }

        $context = \context_module::instance($cmid);
        $category = question_get_default_category($context->id, true);
        $categoryspec = $category->id . ',' . $category->contextid;

        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $quiz->cmid = $cmid;

        foreach ($slots as $slot) {
            if (empty($slot['supported'])) {
                // A random slot that resolved to nothing pullable, or an
                // unregistered activity qtype - see quiz_activity_exporter's
                // docblock.
                continue;
            }

            if (($slot['qtype'] ?? '') === 'random' && isset($slot['questions'])) {
                $this->create_random_slot($quizid, $context, $category, $slot);
                continue;
            }

            if (!isset($slot['question'])) {
                continue;
            }

            $qtype = (string) ($slot['qtype'] ?? '');
            $handler = question_handler_registry::get_handler($qtype);
            if (!$handler) {
                continue;
            }

            $questionid = $handler->create($slot['question'], $categoryspec);
            $maxmark = sanitizer::float($slot['maxmark'] ?? 0);
            quiz_add_quiz_question($questionid, $quiz, 0, $maxmark);
        }

        quiz_delete_previews($quiz);
        \mod_quiz\quiz_settings::create($quizid)->get_grade_calculator()->recompute_quiz_sumgrades();
    }

    /**
     * Recreates a random slot: every pulled pool question
     * (quiz_activity_exporter::export_random_pool()) is created in a fresh
     * category dedicated to this one slot, then a real random quiz_slots row
     * is added pointing at it via \mod_quiz\structure::add_random_questions()
     * - the same core API mod/quiz/edit.php's own "Add > a random question"
     * action uses - so the destination quiz keeps genuinely picking a
     * different question at random each attempt, the same as the source.
     *
     * Each random slot gets its OWN category (create_random_pool_category()),
     * never the shared per-quiz default category fixed slots use: two random
     * slots that happened to reference the same source category would
     * otherwise end up sharing one destination category, silently merging
     * their pools (and a fixed slot's own question sitting in that shared
     * category would wrongly become part of the random pool too). The
     * trade-off is that N slots referencing the same source category get N
     * separate copies of it on the destination rather than one shared copy -
     * simpler and safe, at the cost of some duplication; deduplicating by
     * source category is a possible future improvement, not a correctness
     * requirement.
     *
     * Silently creates no slot at all if every pool question turns out to be
     * something this plugin couldn't recreate (shouldn't normally happen -
     * the exporter only ever includes qtypes question_handler_registry also
     * supports) rather than leaving an empty, always-fails random slot behind.
     *
     * @param int $quizid
     * @param \context_module $context The new quiz's own module context.
     * @param \stdClass $defaultcategory The per-quiz category fixed slots use - this slot's own category is filed under it.
     * @param array $slot One 'random' entry from quiz_activity_exporter::export_slots().
     */
    protected function create_random_slot(
        int $quizid,
        \context_module $context,
        \stdClass $defaultcategory,
        array $slot
    ): void {
        global $DB;

        $poolcategory = $this->create_random_pool_category($context, $defaultcategory, (int) ($slot['slot'] ?? 0));
        $categoryspec = $poolcategory->id . ',' . $poolcategory->contextid;

        $created = 0;
        foreach ($slot['questions'] as $entry) {
            $qtype = (string) ($entry['qtype'] ?? '');
            $handler = question_handler_registry::get_handler($qtype);
            if (!$handler || !isset($entry['question'])) {
                continue;
            }

            $handler->create($entry['question'], $categoryspec);
            $created++;
        }

        if ($created === 0) {
            $DB->delete_records('question_categories', ['id' => $poolcategory->id]);
            return;
        }

        $structure = \mod_quiz\quiz_settings::create($quizid)->get_structure();
        $structure->add_random_questions((int) ($slot['page'] ?? 1), 1, [
            'filter' => [
                'category' => [
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => [(string) $poolcategory->id],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
        ]);

        // The slot add_random_questions() just created always has maxmark=1 -
        // overwrite with the exported value, same reasoning as
        // apply_review_options() overwriting quiz_process_options()'s own
        // computed values.
        $newslot = $DB->get_records('quiz_slots', ['quizid' => $quizid], 'id DESC', 'id', 0, 1);
        if ($newslot) {
            $DB->set_field('quiz_slots', 'maxmark', sanitizer::float($slot['maxmark'] ?? 1, 1), [
                'id' => (int) array_key_first($newslot),
            ]);
        }
    }

    /**
     * Creates a fresh question category, under the per-quiz default category,
     * dedicated to one random slot's pool - see create_random_slot()'s
     * docblock for why each random slot gets its own rather than sharing.
     * Mirrors the minimal field set question_get_default_category() itself
     * uses to create a category.
     *
     * @param \context_module $context
     * @param \stdClass $defaultcategory
     * @param int $slotnumber For the category's own display name only.
     * @return \stdClass
     */
    protected function create_random_pool_category(
        \context_module $context,
        \stdClass $defaultcategory,
        int $slotnumber
    ): \stdClass {
        global $DB;

        $category = new \stdClass();
        $category->name = get_string('randompoolcategoryname', 'block_coursesync', $slotnumber);
        $category->contextid = $context->id;
        $category->info = '';
        $category->infoformat = FORMAT_HTML;
        $category->stamp = make_unique_id_code();
        $category->parent = $defaultcategory->id;
        $category->sortorder = 999;
        $category->id = $DB->insert_record('question_categories', $category);

        return $category;
    }

    /**
     * Validates a remote-supplied value against a fixed known set - same
     * approach as forum_activity_handler's type check and every similar
     * enum-like field this plugin sanitizes: falls back to a safe default
     * rather than storing an arbitrary string later code branches on.
     *
     * @param mixed $value
     * @param array $known
     * @param string $default
     * @return string
     */
    protected function sanitize_enum($value, array $known, string $default): string {
        $value = (string) $value;

        return in_array($value, $known, true) ? $value : $default;
    }
}
