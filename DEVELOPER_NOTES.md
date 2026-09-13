# Course Sync: developer notes

Architecture summary and extension guide for anyone maintaining or building
on block_coursesync (v0.10, Phase 10). This complements the docblocks
in the code itself, which is where the authoritative, up-to-date detail
lives - this file is a map, not a duplicate.

## The big picture: one plugin, two roles

block_coursesync is installed **identically** on every site - there is no
separate "source" build and "destination" build. Which role a given
installation plays is entirely determined by how it's used:

- A site is acting as **source** the moment another site's Course Sync
  block successfully calls one of its web services (below). Nothing on the
  source side needs to know or care that it's "the source" - it's just
  answering read-only, capability-checked web service calls like any other
  Moodle external function.
- A site is acting as **destination** wherever a teacher has added a Course
  Sync block instance and pointed it at a source site.

The same site can be a source for one pilot and a destination for another
at the same time - there's nothing that prevents it. This symmetry (decided
in Phase 2, when the web services were first designed) is why there's a
single `remote_client` class and a single set of external functions, rather
than mirrored "source plugin" / "destination plugin" code paths.

### The service boundary (Phase 2)

Everything that crosses the network goes through four web service
functions, defined in `db/services.php` and implemented in
`classes/external/`:

| Function | Purpose |
|---|---|
| `block_coursesync_ping` | Proves the connection + token work; returns the site name. |
| `block_coursesync_check_course` | Resolves a course ID/shortname on the source and confirms the token can access it. |
| `block_coursesync_get_modified_activities` | Lists a course's activities modified after a given time - **metadata only** (cmid, modname, name, idnumber, timemodified, section), no content. Backed by `classes/local/activity_lookup.php`. |
| `block_coursesync_get_activity_content` | Returns one activity's full settings/content payload, built by that type's `activity_exporter`. |

All four are bundled into one external service ("Course Sync") that every
installation of this plugin defines - see `db/services.php`. A site acting
as source needs that service enabled and a token issued to a sync account,
per `REMOTE_SETUP.md`; a site acting as destination doesn't need to touch
its web services configuration at all, since it's the one making the calls,
not answering them.

`classes/local/remote_client.php` is the **only** code on the destination
side that speaks the wire protocol (Moodle's REST web service format). It
normalises every possible outcome (network failure, bad token, wrong URL,
remote-side error, success) into one consistent result shape, so nothing
above it (`block_coursesync.php`, `sync_runner`) needs to know anything
about HTTP.

### Everything else is destination-side

Once `get_modified_activities` and `get_activity_content` have returned
their data, everything from there on - conflict detection, activity
creation, sync history, the block's own UI - runs entirely on the
destination site and touches only its own database. The source site is
never written to, and never even told that a sync happened.

## Where things live

```
block_coursesync.php              Block lifecycle: config save (incl. token
                                   encryption + connection test), sync_now()
                                   orchestration, footer links.
edit_form.php                     Settings form: wizard, connection fields,
                                   course mapping fields.
sync.php / history.php            Thin controllers: sesskey + capability
                                   checks, then delegate to the block class.

classes/local/
  remote_client.php                Talks to a remote site's web services.
  sync_runner.php                  One sync: list -> pull -> create/conflict
                                    -> compute new lastsync. No side effects
                                    outside the destination course itself -
                                    doesn't touch block config or history.
                                    Places each new activity in the
                                    destination section with the same
                                    relative number (course_sections.section)
                                    it had on the source, creating that
                                    section on the destination first if
                                    needed - no name-based matching for
                                    section placement, so this only lines up
                                    when both courses share the same section
                                    structure. Before pulling, skips anything
                                    already effectively in the destination:
                                    an idnumber collision is flagged as a
                                    conflict (process_activities()), while a
                                    same-type-same-name match against any
                                    existing activity - regardless of how it
                                    got there - is excluded silently.
  activity_handler.php             Destination-side interface: recreate one
  activity_handler_registry.php    activity type locally. Registry maps
                                    modname => handler class - the only file
                                    that needs editing to teach Sync now a
                                    new type (see below).
  activity_exporter.php            Source-side interface: build one activity
  activity_exporter_registry.php   type's payload. Same registry pattern.
  <modname>_activity_handler.php   One pair per supported type: page, url,
  <modname>_activity_exporter.php  label, resource, forum (Phase 5); assign,
                                    h5pactivity (Phase 9); quiz (Phase 10 -
                                    see "Quiz and its questions" below).
  activity_lookup.php              Change detection (Phase 3): activities
                                    modified after a given time, per course.
  question_handler.php             The same activity_exporter/
  question_handler_registry.php    activity_handler/registry pattern, one
  question_exporter.php            level down: one question in a quiz.
  question_exporter_registry.php   quiz_activity_exporter/handler are the
  <qtype>_question_handler.php     only callers - see "Quiz and its
  <qtype>_question_exporter.php    questions" below. One pair per supported
                                    qtype: multichoice, truefalse,
                                    shortanswer, numerical, essay, match,
                                    description (Phase 10).
  question_file_helper.php         Embedded-file handling shared by every
                                    question_exporter/question_handler pair -
                                    the question-bank equivalent of
                                    sanitizer.php being shared by every
                                    activity_handler.
  sanitizer.php                    Cleans remote-sourced content before it's
                                    stored (Phase 7) - see its docblock for
                                    which PARAM_* is used for what.
  sync_history.php                 Persists/reads block_coursesync_synclog
                                    rows.
  content_renderer.php             Turns config/result state into the
                                    escaped text shown in the block itself.
  history_renderer.php             Turns sync_history rows into the
                                    history.php page's HTML.
  url_safety.php                   SSRF check: rejects loopback/link-
                                    local/private-range remote URLs unless
                                    the dev/testing checkbox is set.
  course_lookup.php                Resolves a course ID/shortname on the
                                    *local* site - used by check_course.php.

classes/external/                 The four web service functions - thin:
                                   validate_parameters/context, check
                                   capability, delegate to a local/ class.

classes/privacy/provider.php      GDPR: what this plugin stores and why.

db/services.php                   Web service + external function defs.
db/access.php                     The two capabilities (addinstance, sync).
db/install.xml / db/upgrade.php   block_coursesync_synclog schema.

tests/                            PHPUnit (Phase 8) + one Behat scenario
                                   covering the full teacher-facing flow.
```

## Adding a new activity type handler (v2+)

This is the extension point Phase 4 established and every type since has
followed: page/url/label/resource/forum (Phase 5), assign/h5pactivity
(Phase 9 - see those classes for a worked example of a type with its own
sub-plugin config (`assign`) and one with file content (`h5pactivity`)), then
quiz (Phase 10 - see "Quiz and its questions" below; it's the odd one out,
since its own payload embeds a second, separate exporter/handler/registry
pattern for questions). To add support for activity type `<modname>`:

### 1. Write the exporter (source side)

`classes/local/<modname>_activity_exporter.php`, implementing
`activity_exporter`:

```php
class quiz_activity_exporter implements activity_exporter {
    public function export(\cm_info $cm): array {
        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        // Return whatever fields the matching handler will need. This array
        // travels as an opaque JSON blob - nothing else in the plugin reads
        // its shape, so it's entirely up to this type.
        return [
            'name' => $quiz->name,
            'intro' => $quiz->intro,
            'introformat' => $quiz->introformat,
            // ...
        ];
    }
}
```

Register it in `activity_exporter_registry.php`'s `$exporters` map.

### 2. Write the handler (destination side)

`classes/local/<modname>_activity_handler.php`, implementing
`activity_handler`. Follow `page_activity_handler`'s shape:

1. Build the `course_modules` row yourself via `add_course_module()`
   (`instance` starts as a `0` placeholder).
2. Call that type's own `<modname>_add_instance()` to create the instance
   row, with `->coursemodule` already set to the new cm id.
3. **Check whether `<modname>_add_instance()` sets `course_modules.instance`
   for you.** `page` and `resource` do; `url`, `label`, and `forum` don't
   and need an explicit
   `$DB->set_field('course_modules', 'instance', $id, ['id' => $cmid])`
   afterwards. There is no documented contract for this - it's each
   module's own implementation choice, and you have to check its `lib.php`.
   Getting it wrong doesn't throw or fail the sync: it silently leaves an
   invisible, broken course module (`instance = 0`) that Sync now still
   reports as "created". This bit Phase 5's `url_activity_handler` on the
   first attempt - caught only by checking the destination course directly
   after a live sync, not by the sync's own success/failure counts. **Write
   a PHPUnit test that asserts on the real created course_modules/instance
   row**, not just on the returned cmid, so this class of bug fails loudly.
4. `course_add_cm_to_section($courseid, $cmid, $sectionnum, null, '<modname>')`.
5. `rebuild_course_cache($courseid, true)`.
6. Run every remote-sourced field through `sanitizer.php` before it touches
   the database - see that file's docblock for which method to use for
   which kind of field (plain text vs. HTML vs. a format constant vs. a
   filename).

```php
class quiz_activity_handler implements activity_handler {
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);

        $newcm = new \stdClass();
        $newcm->course = $courseid;
        $newcm->module = $moduleid;
        $newcm->instance = 0;
        $newcm->section = 0;
        $newcm->idnumber = $idnumber;
        $newcm->visible = 1;
        // ... the rest of the standard course_modules fields, same as page_activity_handler.

        $cmid = add_course_module($newcm);

        $quizdata = new \stdClass();
        $quizdata->course = $courseid;
        $quizdata->coursemodule = $cmid;
        $quizdata->name = sanitizer::text($data['name'] ?? '');
        // ... map the rest of $data through sanitizer, matching whatever
        // quiz_add_instance() reads unconditionally - check its body.

        quiz_add_instance($quizdata);
        // If quiz_add_instance() doesn't set course_modules.instance itself,
        // set it explicitly here - see step 3 above.

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'quiz');
        rebuild_course_cache($courseid, true);

        return $cmid;
    }
}
```

Register it in `activity_handler_registry.php`'s `$handlers` map.

### 3. Nothing else needs to change

`sync_runner`, `block_coursesync_get_activity_content`,
`block_coursesync_get_modified_activities`, and the block's own UI all go
through the two registries - none of them reference a specific type. Once
both classes are registered, `activity_handler_registry::is_supported()`
starts returning `true` for the new modname, and Sync now picks it up on
its own: activities of that type stop appearing in "not yet supported" and
start being pulled.

### 4. Test it

Follow `tests/local/activity_handlers_test.php`'s pattern: create a real
activity of the type via `$this->getDataGenerator()->create_module(...)`,
export it, feed the payload to the new handler against a fresh course, and
assert on the real resulting `course_modules`/type-table rows - not just
that `create_from_remote_data()` returned an int. Include a case with
`<script>` or similarly dangerous content in a text field to confirm
`sanitizer` is actually being used, not just present in the file.

## Quiz and its questions (Phase 10)

Quiz is the one activity type whose payload embeds a second, independent
exporter/handler/registry pattern: `quiz_activity_exporter` doesn't just
export the quiz's own settings, it also exports every slot, and for a slot
with a fixed question reference, nests that question's own payload from
`question_exporter_registry` inside it. `quiz_activity_handler` does the
mirror image: create the quiz, then for each slot, hand its nested payload
to `question_handler_registry` to create the actual question before wiring
it into the quiz. See `question_exporter.php`/`question_handler.php`'s own
docblocks for the pattern in detail - it's deliberately the same shape as
`activity_exporter`/`activity_handler`, just one level further in.

**Which question types are supported.** v1 covers multichoice, true/false,
short answer, numerical, essay, matching, and description - the types most
real quizzes actually use. A slot using any other qtype (calculated/
calculatedsimple/calculatedmulti, multianswer/Cloze, the drag-and-drop
family - ddwtos/ddmarker/ddimageortext, gapselect, random, ordering,
randomsamatch, ...) is *detected* (it appears in the quiz's own "not yet
supported" slots) but not pulled - the same "unsupported type" treatment an
activity gets, one level down. These weren't skipped for lack of a plan:
each has its own schema and at least one substantially harder problem than
the v1 set -

- **calculated / calculatedsimple / calculatedmulti**: answers are formulas
  over wildcards (`{x}`), with dataset items that can be *shared across
  multiple questions in the same category* (`question_datasets` /
  `question_dataset_definitions` - a "shared" dataset isn't owned by any one
  question). Exporting one question correctly means deciding what happens
  to a dataset other questions on the source still reference.
- **multianswer (Cloze)**: the "outer" question's questiontext embeds
  `{1:SHORTANSWER:...}`-style markup, and each embedded answer is itself a
  *separate, real row* in `question` (qtype shortanswer/numerical/
  multichoice under the hood), linked via `question_multianswer.sequence` -
  exporting one means recursively exporting several, then re-creating them
  in the right order so the outer question's own save step can re-link them.
- **the drag-and-drop family and gapselect**: coordinates/drop-zones
  (`qtype_ddmarker_drops`, `qtype_ddimageortext_drops`, ...) and, for
  ddimageortext specifically, a background image file with its own
  per-drop-zone geometry - more moving pieces than a wrong answer/feedback
  pair, and harder to unit-test the geometry math is even right.
- **random / randomsamatch**: never resolve to one fixed question at all
  (see quiz_activity_exporter's docblock for how a random *slot* is
  reported) - there is no single "the question" to export.

Adding one of these follows the exact same two-file-plus-registry pattern as
the v1 set (below) - it's the *content* of `export()`/`create()` that's
harder here, not the shape.

**Adding a new question type**, following `multichoice_question_exporter`/
`multichoice_question_handler`'s worked example:

1. **Exporter**: `classes/local/<qtype>_question_exporter.php`, implementing
   `question_exporter`. Read that qtype's own table(s) directly (see
   `lib/db/install.xml` for the shared `question`/`question_answers`/
   `question_hints` schema, and `question/type/<qtype>/db/install.xml` for
   that type's own tables) - the same "direct $DB queries, no question
   engine" style every exporter in this plugin already uses. Use
   `question_file_helper::export_files()` for any field with its own file
   area (check that qtype's `move_files()` override to find every
   component/filearea/itemid it moves - that's the authoritative list of
   which fields carry files).
2. **Handler**: `classes/local/<qtype>_question_handler.php`, implementing
   `question_handler`. Build a `$question` stdClass with only `->qtype` set,
   and a `$form` stdClass shaped exactly like that qtype's own
   `edit_<qtype>_form.php` would submit - **read that qtype's
   `questiontype.php::save_question_options()` (and `extra_question_fields()`)
   line by line** to know which `$form` properties it reads and in what
   shape; there is no other documentation of this contract. Two file
   mechanisms exist depending on which field:
   - `questiontext`/`generalfeedback` (handled by `save_question()` itself,
     not the qtype's own code): must use
     `question_file_helper::store_in_draft_area()` for `['itemid' => ...]` -
     this is the ONLY mechanism `save_question()` understands for these two
     fields.
   - every other rich field (an answer's text/feedback, combined feedback, a
     match subquestion, essay's graderinfo, ...): use
     `question_file_helper::richfield()` / `to_import_files()` for
     `['files' => ...]` instead - simpler, no draft area needed, and it's
     what `import_or_save_files()` (called from inside that qtype's own
     `save_question_options()`) supports directly.
   Call `\question_bank::get_qtype('<qtype>')->save_question($question, $form)`
   and return `(int) $result->id`. **Read the qtype's `save_question_options()`
   for any hard failure path before trusting a round-trip of valid source
   data is safe**: most validation failures throw a catchable
   `moodle_exception` (fine - sync_runner's own try/catch turns that into a
   "failed" entry), but at least one qtype (`match`, on a subquestion with
   text but a blank answer) calls the legacy `notice()` helper instead, which
   `exit()`s the whole PHP process rather than throwing - see
   `match_question_handler`'s docblock for how that's defused by filtering
   the input before it ever reaches that code.
3. **Register both** in `question_exporter_registry`'s and
   `question_handler_registry`'s maps. Nothing else needs to change -
   `quiz_activity_exporter`/`quiz_activity_handler` only ever go through
   these registries, never a specific qtype.
4. **Test it**, following `tests/local/question_handlers_test.php`'s
   pattern: build a payload by hand (or export a real question created via
   `$this->getDataGenerator()->get_plugin_generator('core_question')`), feed
   it to the handler against a fresh category, and assert on the real
   resulting `question`/qtype-table rows.

**Category placement.** Every pulled question is created in the *new* quiz's
own module-context default category (`question_get_default_category()`, the
exact category a teacher's first manually-added question would land in) -
never a shared course-level bank - so a sync never collides with anything
already in the destination course's other question banks.

**Quiz settings scope cuts** (see `quiz_activity_handler`'s docblock for the
mechanics): quiz feedback boundaries (`quiz_feedback` - the "well done" /
"please revise" messages for a grade range) aren't synced; access-restriction
sub-plugins beyond the plain password/subnet/browsersecurity columns already
on the `quiz` row (Safe Exam Browser, IP restriction lists, ...) aren't
either. Both are settings-only cuts, same spirit as assign's "only the two
most common submission sub-plugins" scope.

## Testing (Phase 8, extended Phase 9-10)

- **PHPUnit** (`tests/`): change detection (`activity_lookup_test.php`),
  all eight non-quiz handlers (`activity_handlers_test.php`), quiz itself and
  every v1 question type (`quiz_activity_test.php`, `question_handlers_test.php`),
  conflict-flagging, same-name-and-type exclusion, and lastsync advancement
  (`sync_conflict_test.php`, against `tests/fixtures/stub_remote_client.php`
  - a `remote_client` subclass returning canned data instead of a real HTTP
  call), the token encryption round-trip (`token_encryption_test.php`), and
  `history_renderer_test.php`.
- **Behat** (`tests/behat/sync_activities.feature`): one scenario driving
  the full teacher-facing flow end to end. A single Behat run only ever
  drives one site, so this points the block at the **same** site running
  the test (two different courses stand in for source/destination) -
  `tests/behat/behat_block_coursesync.php` supplies the two custom steps
  that make that self-reference practical (filling in the site's own
  address, and creating a working token directly rather than through a
  second site's web services UI). See that file's docblock before changing
  or extending it.
- `moodle-plugin-ci parallel` (phpdoc, phplint, phpcpd, phpmd, the Moodle
  codechecker/phpcs standard, plugin validation, mustache lint, grunt,
  phpunit, behat) is the full local gate - run it against a Moodle 5.2
  checkout with `moodle-plugin-ci install ... && moodle-plugin-ci parallel`
  before every release.

## Security posture (Phase 7 - still current)

- Remote URLs must be HTTPS and are rejected if they resolve to a
  loopback/link-local/private address, unless the dev/testing checkbox is
  set (`url_safety.php`) - defence in depth, since `edit_form.php`'s
  `validation()` is the primary block but doesn't run for every possible
  caller (this plugin's own tests call `instance_config_save()` directly,
  for instance) - see `block_coursesync.php::test_connection()`'s docblock.
- The token is encrypted at rest with `\core\encryption` and is never
  redisplayed once saved.
- Every remote-sourced field is sanitised on the destination before
  storage (`sanitizer.php`), and any remote-sourced text this plugin
  itself displays (site name, course name, error messages) is escaped at
  output time (`content_renderer.php`, `history_renderer.php`) -
  `\core\notification::add()` (used by `redirect()`) does **not**
  auto-escape, which is easy to miss. This applies equally to a quiz's
  question payloads (Phase 10) - every question_handler runs its own
  remote-sourced text through `sanitizer` before it reaches
  `\question_bank::...->save_question()`, the same principle as every
  activity_handler.

## What v1 deliberately doesn't do

See `REMOTE_SETUP.md`'s "What this plugin does *not* do" section - still
accurate as of Phase 10. In short: no automated remote-site configuration,
only the registered activity types are pulled (others are detected
but skipped, not treated as failures), and a flagged conflict has no
in-block resolution UI - a teacher resolves it manually and syncs again.
Assignment sync is settings-only: student submissions, grades, and
feedback never leave the source site, only the assignment's own
configuration (see `assign_activity_exporter`'s docblock). Quiz sync pulls
questions themselves (not just the quiz's own settings), but only for the
question types listed in "Quiz and its questions" above - see that section
for exactly what's cut and why.
