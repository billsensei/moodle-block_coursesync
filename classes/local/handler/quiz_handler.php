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

use block_coursesync\activity_payload;
use mod_quiz\question\display_options;

/**
 * Handles mod_quiz: the quiz's settings, and the questions its slots use.
 *
 * A quiz does not own its questions - it holds references into a question
 * bank. This handler follows both kinds of reference a slot can carry: a
 * fixed question (question_references), or a random draw from a category
 * (question_set_references). The categories and questions themselves are
 * rebuilt using the same code qbank_handler uses (question_bank_sync_trait) -
 * fourteen supported types, current ready version only, question content
 * travelling as a qformat_xml fragment - but they land in the destination
 * course's own shared System Bank rather than a dedicated activity, exactly
 * where Moodle itself puts a question a teacher adds straight to a quiz with
 * no bank picked. Only the questions and categories are copied; nobody's
 * attempts or usage statistics come with them.
 *
 * A slot whose question is of an unsupported type, or whose category never
 * arrived, is left out rather than left as a broken reference - see
 * notes(). The former is already counted by the shared trait's
 * unsupported-type note; the latter gets its own, since it is not
 * explained by anything already reported. Only current-ready versions of
 * the fourteen supported types are ever attempted; that is qbank_handler's own
 * limit, inherited here rather than restated differently.
 *
 * Building a random slot is the one place in this plugin that checks a
 * capability beyond block/coursesync:sync: core's own
 * mod_quiz\structure::add_random_questions() requires
 * moodle/question:useall on the category's context, checked against the
 * destination teacher's own session - the same check that would run if they
 * added a random question by hand. The editingteacher archetype holds it by
 * default at the course context, which covers the System Bank since it is a
 * module within that same course. See SECURITY.md.
 *
 * The other thing to know is that quiz_add_instance() does not take a quiz as
 * it is stored. It runs the data through quiz_process_options() first, which
 * expects what the quiz settings form submits. Two things follow from that: the
 * password arrives as "quizpassword", and the eight review columns are not read
 * at all - they are rebuilt from four checkboxes each. So the stored bitmasks
 * are taken apart into those checkboxes here; see review_checkboxes().
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_handler extends activity_handler {
    use question_bank_sync_trait;

    /**
     * The eight things a student may be shown when reviewing an attempt.
     *
     * Each is stored as one column holding a bitmask of when it is shown.
     */
    protected const REVIEW_FIELDS = [
        'attempt',
        'correctness',
        'maxmarks',
        'marks',
        'specificfeedback',
        'generalfeedback',
        'rightanswer',
        'overallfeedback',
    ];

    /**
     * @var int Random slots whose category could not be resolved. A fixed
     * slot whose question could not be resolved is not counted here - it is
     * always an unsupported type, already counted by the shared trait.
     */
    protected int $unresolvedslotcount = 0;

    /**
     * @var bool Something unexpected stopped the questions rebuilding -
     * unlike an unsupported type or an unresolved slot, this is not a
     * normal, expected gap, so it gets its own, more alarming note.
     */
    protected bool $questionsyncfailed = false;

    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'quiz';
    }

    /**
     * SOURCE SIDE. How the quiz is set up.
     *
     * The password is deliberately not exported. It is a shared secret for
     * sitting the quiz, the destination teacher can set their own, and the
     * point of not sending it is that it does not then exist in a request, a
     * log or a response on either site.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the quiz table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        foreach (self::REVIEW_FIELDS as $field) {
            $settings['review' . $field] = (string) ($instance->{'review' . $field} ?? 0);
        }

        // A quiz's maximum grade is not a whole number: it is stored to five
        // decimal places, so it travels as a number rather than through the
        // loop above.
        $settings['grade'] = (string) (float) ($instance->grade ?? 0);

        // The settings that are words rather than numbers. Each is checked
        // against what this site has when it is read back.
        $settings['overduehandling'] = (string) ($instance->overduehandling ?? 'autoabandon');
        $settings['navmethod'] = (string) ($instance->navmethod ?? 'free');
        $settings['preferredbehaviour'] = (string) ($instance->preferredbehaviour ?? 'deferredfeedback');

        return $settings;
    }

    /**
     * SOURCE SIDE. Every slot in the quiz, in order, and the categories and
     * questions those slots actually need.
     *
     * A fixed slot needs its one question and that question's category. A
     * random slot needs the whole category it draws from - and its whole
     * subtree, when the slot includes subcategories - because the point of a
     * random slot is drawing from the full pool at attempt time; exporting a
     * subset would silently narrow that pool in a way nobody would ever see.
     * Both kinds of slot funnel into the same needed-category set, so a
     * category referenced by more than one slot is only exported once.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the quiz table
     * @return array[]
     */
    public function export_children(\cm_info $cm, \stdClass $instance): array {
        global $DB, $CFG;

        require_once($CFG->libdir . '/questionlib.php');

        $slots = $DB->get_records('quiz_slots', ['quizid' => $instance->id], 'slot ASC');
        $modcontextid = \context_module::instance($cm->id)->id;

        $neededcategoryids = [];
        $slotchildren = [];
        $order = 0;

        foreach ($slots as $slot) {
            // Fixed and random references are mutually exclusive per slot -
            // core's own structure::remove_slot() defensively deletes from
            // both tables for exactly this reason.
            $fixed = $DB->get_record_sql(
                'SELECT qr.questionbankentryid, qbe.questioncategoryid AS categoryid
                   FROM {question_references} qr
                   JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  WHERE qr.component = ? AND qr.questionarea = ? AND qr.itemid = ? AND qr.usingcontextid = ?',
                ['mod_quiz', 'slot', $slot->id, $modcontextid]
            );

            if ($fixed) {
                $neededcategoryids[(int) $fixed->categoryid] = true;

                $slotchildren[] = [
                    'type' => 'slot',
                    'sortorder' => $order++,
                    'fields' => [
                        'kind' => 'fixed',
                        'maxmark' => (string) (float) $slot->maxmark,
                        'questionref' => (int) $fixed->questionbankentryid,
                        'categoryref' => 0,
                        'includesubcategories' => 0,
                        'catjointype' => 0,
                        'tagnames' => '[]',
                        'tagjointype' => 0,
                    ],
                ];

                continue;
            }

            $setref = $DB->get_record('question_set_references', [
                'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slot->id,
            ]);

            if (!$setref) {
                // Neither reference kind exists - a dangling slot. Nothing to
                // export for it; the import side counts it as unresolved.
                continue;
            }

            $filter = json_decode((string) $setref->filtercondition, true) ?: [];
            $categoryid = (int) ($filter['filter']['category']['values'][0] ?? 0);

            if ($categoryid === 0 || !$DB->record_exists('question_categories', ['id' => $categoryid])) {
                continue;
            }

            $includesub = !empty($filter['filter']['category']['filteroptions']['includesubcategories']);
            $neededcategoryids[$categoryid] = true;

            if ($includesub) {
                foreach (\question_categorylist($categoryid) as $descendantid) {
                    $neededcategoryids[(int) $descendantid] = true;
                }
            }

            // Tag ids are site-local; the name is what travels, resolved
            // back to an id (creating it if missing) on the way in - the
            // same pattern core itself uses when migrating this filter
            // shape (question_reference_manager::convert_legacy_set_reference_filter_condition()).
            // Carried as JSON rather than a comma-joined string - a tag name
            // is free text and PARAM_TAG does not forbid a comma in one, so
            // joining would risk splitting one real tag into two on import.
            $tagids = $filter['filter']['qtagids']['values'] ?? [];
            $tagnames = [];

            foreach (\core_tag_tag::get_bulk($tagids) as $tag) {
                $tagnames[] = $tag->name;
            }

            $slotchildren[] = [
                'type' => 'slot',
                'sortorder' => $order++,
                'fields' => [
                    'kind' => 'random',
                    'maxmark' => (string) (float) $slot->maxmark,
                    'questionref' => 0,
                    'categoryref' => $categoryid,
                    'includesubcategories' => $includesub ? 1 : 0,
                    'catjointype' => (int) ($filter['filter']['category']['jointype']
                        ?? \qbank_managecategories\category_condition::JOINTYPE_DEFAULT),
                    'tagnames' => json_encode($tagnames),
                    'tagjointype' => (int) ($filter['filter']['qtagids']['jointype']
                        ?? \qbank_tagquestion\tag_condition::JOINTYPE_DEFAULT),
                ],
            ];
        }

        return array_merge(
            $slotchildren,
            $this->export_question_bank_children(array_keys($neededcategoryids), $order)
        );
    }

    /**
     * DESTINATION SIDE. Build the quiz in a local course.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload what the source site sent
     * @param string $idnumber the ID number that marks this as synced
     * @return \stdClass the new course_modules record
     */
    public function create_from_remote_data(
        \stdClass $course,
        activity_payload $payload,
        string $idnumber
    ): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/bank.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        // Clamped as well as cast: a negative maximum grade is not a scale here
        // the way it is in an assignment, it is simply not a grade.
        $data->grade = max(0.0, (float) $payload->setting('grade', '0'));

        $data->overduehandling = self::clean_choice(
            $payload->setting('overduehandling'),
            ['autosubmit', 'graceperiod', 'autoabandon'],
            'autoabandon'
        );
        $data->navmethod = self::clean_choice($payload->setting('navmethod'), ['free', 'sequential'], 'free');
        $data->preferredbehaviour = self::clean_behaviour($payload->setting('preferredbehaviour'));

        // Starting point before any slots exist; sync_quiz_slots() below
        // recomputes this once the real questions are in place.
        $data->sumgrades = 0;

        // Access rule settings that name something local, or are a secret of
        // the source site, do not travel; the teacher sets them here.
        $data->quizpassword = '';
        $data->subnet = '';
        $data->browsersecurity = '-';

        foreach (self::review_checkboxes($payload) as $field => $value) {
            $data->$field = $value;
        }

        $instanceid = \quiz_add_instance($data);

        // Instead of an id, quiz_add_instance() returns a message when it
        // refuses the settings, so anything that is not a number is a failure.
        if (!$instanceid || !is_numeric($instanceid)) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        // The quiz has to be a real, findable course module - placed in its
        // section, with the course's module cache rebuilt - before anything
        // here can ask quiz_settings::create() for it; that call resolves
        // the quiz through get_fast_modinfo(), which knows nothing about a
        // course_modules row that has not been placed and cached yet.
        $cm = $this->finish_creation($course, $cmid, $sectionnum);

        // A quiz whose questions did not fully arrive is still a real quiz -
        // unlike qbank_handler's own module, nothing here is torn down on
        // failure. This is deliberately caught here, inside this call,
        // rather than left to propagate to syncer::handle_one(): that
        // caller only assigns its own $cm once this whole method returns,
        // so an exception here would leave it null there, skip its
        // cleanup entirely (it is conditional on $cm !== null), and orphan
        // the quiz this call already created - unreported as a failure and
        // uncounted as a success. Catching it here, and always returning
        // $cm, keeps the guarantee this class actually wants: a category or
        // question already added to the shared System Bank before a later
        // failure is left exactly as if a teacher had added a question by
        // hand and then deleted the quiz - an unreferenced bank question is
        // a normal state, not a broken one - and the quiz itself is
        // reported as created, with notes() saying plainly that something
        // went wrong, rather than silently vanishing from the sync results.
        try {
            $bankcm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
            $bankcontext = \context_module::instance($bankcm->id);

            $this->sync_question_bank_categories($bankcontext, $payload);
            $this->sync_question_bank_questions($bankcontext, $payload);
            $this->sync_quiz_slots((int) $instanceid, $cmid, $course->id, $data->questionsperpage, $payload);

            \mod_quiz\quiz_settings::create((int) $instanceid)->get_grade_calculator()->recompute_quiz_sumgrades();
        } catch (\Throwable $e) {
            debugging(
                'block_coursesync: quiz cmid ' . $cmid . ' was created, but rebuilding its questions failed: '
                    . $e->getMessage(),
                DEBUG_DEVELOPER
            );

            $this->questionsyncfailed = true;
        }

        return $cm;
    }

    /**
     * DESTINATION SIDE. Rebuild every slot in order: a fixed slot points at
     * one already-synced question, a random slot draws from an
     * already-synced category.
     *
     * @param int $instanceid the new quiz's own id
     * @param int $cmid the new quiz's course module id
     * @param int $courseid the destination course
     * @param int $questionsperpage the new quiz's own paging setting
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function sync_quiz_slots(
        int $instanceid,
        int $cmid,
        int $courseid,
        int $questionsperpage,
        activity_payload $payload
    ): void {
        global $DB;

        // Core's quiz_add_quiz_question() reads ->questionsperpage itself to
        // decide whether a new slot starts a new page; nothing here needs
        // that decided any more carefully than the quiz's own setting already
        // says, since slot-level page placement is not carried - see
        // export_children().
        $quizdata = (object) [
            'id' => $instanceid,
            'course' => $courseid,
            'cmid' => $cmid,
            'questionsperpage' => $questionsperpage,
        ];
        $structure = null;

        foreach ($payload->children('slot') as $child) {
            $maxmark = (float) activity_payload::child_field($child, 'maxmark', '0');

            if (activity_payload::child_field($child, 'kind') === 'fixed') {
                $questionid = $this->mapped_id('question', activity_payload::child_int($child, 'questionref', 0));

                if ($questionid === 0) {
                    // Every question a fixed slot points at was in the
                    // payload and was walked by sync_question_bank_questions()
                    // - the only reason it would not have a mapped id is an
                    // unsupported type, which that pass already counted via
                    // $unsupportedcount. Counting it again here under a
                    // different note would describe the same one missing
                    // question twice.
                    continue;
                }

                \quiz_add_quiz_question($questionid, $quizdata, 0, $maxmark);

                continue;
            }

            $categoryid = $this->mapped_id('category', activity_payload::child_int($child, 'categoryref', 0));

            if ($categoryid === 0) {
                $this->unresolvedslotcount++;

                continue;
            }

            // Built once, only if a random slot is actually encountered.
            $structure ??= \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($instanceid));

            $structure->add_random_questions(0, 1, $this->random_slot_filter_condition($child, $categoryid));

            // Core's add_random_questions() hardcodes maxmark to 1; this
            // quiz's own slot weighting is fixed up immediately after, on
            // the slot that call just created (nothing else can be
            // inserting into this brand new quiz at the same time).
            $newslotid = (int) $DB->get_field_sql(
                'SELECT MAX(id) FROM {quiz_slots} WHERE quizid = ?',
                [$instanceid]
            );

            if ($newslotid > 0) {
                $DB->set_field('quiz_slots', 'maxmark', $maxmark, ['id' => $newslotid]);
            }
        }
    }

    /**
     * DESTINATION SIDE. Rebuild a random slot's filter condition, resolving
     * the tag names it travelled with back into ids on this site - creating
     * a tag of that name if this site does not already have one, the same
     * way core resolves a legacy filter's tag names when migrating it.
     *
     * @param array $child the 'slot' child, kind 'random'
     * @param int $categoryid the local category already resolved by the caller
     * @return array
     */
    protected function random_slot_filter_condition(array $child, int $categoryid): array {
        $filtercondition = [
            'filter' => [
                'category' => [
                    'jointype' => activity_payload::child_int(
                        $child,
                        'catjointype',
                        \qbank_managecategories\category_condition::JOINTYPE_DEFAULT
                    ),
                    'values' => [$categoryid],
                    'filteroptions' => [
                        'includesubcategories' => (bool) activity_payload::child_int($child, 'includesubcategories', 0),
                    ],
                ],
            ],
        ];

        $decoded = json_decode(activity_payload::child_field($child, 'tagnames', '[]'), true);
        $tagnames = array_values(array_filter(array_map(
            'trim',
            is_array($decoded) ? array_filter($decoded, 'is_string') : []
        )));

        if ($tagnames !== []) {
            $collectionid = \core_tag_area::get_collection('core_question', 'question');
            $tagids = array_map(
                static fn(\core_tag_tag $tag): int => (int) $tag->id,
                \core_tag_tag::create_if_missing($collectionid, $tagnames)
            );

            $filtercondition['filter']['qtagids'] = [
                'jointype' => activity_payload::child_int(
                    $child,
                    'tagjointype',
                    \qbank_tagquestion\tag_condition::JOINTYPE_DEFAULT
                ),
                'values' => array_values($tagids),
            ];
        }

        return $filtercondition;
    }

    /**
     * Take the stored review bitmasks apart into the checkboxes the quiz wants.
     *
     * Each review column holds a bitmask of when that thing is shown: during the
     * attempt, immediately after, later while the quiz is open, and after it
     * closes. quiz_process_options() ignores the columns and rebuilds them from
     * one checkbox per moment, named for instance "marksimmediately", so the
     * bitmask has to be taken apart again on the way in. Handing over the stored
     * columns instead would silently produce a quiz that reviews nothing.
     *
     * @param activity_payload $payload what the source site sent
     * @return array<string, int> form field name => 1 for the boxes that are ticked
     */
    protected static function review_checkboxes(activity_payload $payload): array {
        $moments = [
            'during' => display_options::DURING,
            'immediately' => display_options::IMMEDIATELY_AFTER,
            'open' => display_options::LATER_WHILE_OPEN,
            'closed' => display_options::AFTER_CLOSE,
        ];

        $checkboxes = [];

        foreach (self::REVIEW_FIELDS as $field) {
            $stored = $payload->setting_int('review' . $field, 0);

            foreach ($moments as $when => $bit) {
                if ($stored & $bit) {
                    $checkboxes[$field . $when] = 1;
                }
            }
        }

        return $checkboxes;
    }

    /**
     * Keep a question behaviour to one this site actually has installed.
     *
     * @param string $behaviour what the source site sent
     * @return string
     */
    protected static function clean_behaviour(string $behaviour): string {
        $installed = array_keys(\question_engine::get_behaviour_options(''));

        return in_array($behaviour, $installed, true) ? $behaviour : 'deferredfeedback';
    }

    /**
     * Keep a setting to one of the values this site recognises.
     *
     * @param string $value what the source site sent
     * @param string[] $allowed
     * @param string $default used when the value is not one of them
     * @return string
     */
    protected static function clean_choice(string $value, array $allowed, string $default): string {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Say what came across with the quiz, or - the now rare case - that
     * nothing did.
     *
     * $unsupportedcount (an unsupported question type) and
     * $unresolvedslotcount (a whole slot that could not be classified or
     * whose reference never arrived) are deliberately not both counted for
     * the same fixed slot - see sync_quiz_slots(). A random slot's
     * unresolved category still counts here, since that is not something
     * $unsupportedcount already describes.
     *
     * @param activity_payload $payload what the source site sent
     * @return array<string|array{0:string,1:mixed}>
     */
    public function notes(activity_payload $payload): array {
        if ($payload->children('slot') === []) {
            return ['syncquiznoquestions'];
        }

        $notes = ['syncqbanknoattempts'];

        if ($this->questionsyncfailed) {
            // Whatever else notes() would otherwise say is unreliable if
            // the rebuild broke partway through, so this replaces rather
            // than joins the rest.
            $notes[] = 'syncquizquestionsyncfailed';

            return $notes;
        }

        if ($this->unsupportedcount > 0) {
            $notes[] = ['syncqbankunsupportedcount', $this->unsupportedcount];
        }

        if ($this->unresolvedslotcount > 0) {
            $notes[] = ['syncquizunresolvedslotcount', $this->unresolvedslotcount];
        }

        return $notes;
    }

    /**
     * The quiz settings that are carried, and what to assume without them.
     *
     * These are all whole numbers, which is why they can be carried in a loop.
     * The ones that are words - the overdue handling, the navigation method, the
     * question behaviour - are each checked against what this site has.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'timeopen' => 0,
            'timeclose' => 0,
            'timelimit' => 0,
            'graceperiod' => 0,
            'canredoquestions' => 0,
            'attempts' => 0,
            'attemptonlast' => 0,
            'grademethod' => 1,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'questionsperpage' => 0,
            'shuffleanswers' => 0,
            'delay1' => 0,
            'delay2' => 0,
            'showuserpicture' => 0,
            'showblocks' => 0,
            'completionattemptsexhausted' => 0,
            'completionminattempts' => 0,
            'allowofflineattempts' => 0,
        ];
    }
}
