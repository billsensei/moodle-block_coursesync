# Developer notes

> Part of the Course Sync documentation set:
> [INSTALL.md](INSTALL.md) · [REMOTE_SETUP.md](REMOTE_SETUP.md) · [TEACHER_GUIDE.md](TEACHER_GUIDE.md) · **DEVELOPER.md** · [SECURITY.md](SECURITY.md)


Architecture, the decisions behind it, and how to add an activity type.

## The shape of the thing

Moodle has no general service for handing activity content between sites. Core's
web services can list a course's contents, but there is nothing that exports an
activity in a form another site can rebuild. So Course Sync defines its own
service, and that single fact drives the whole design:

**The plugin is installed on both sites and behaves differently at each end.**

```
  SOURCE SITE                              DESTINATION SITE
  ───────────                              ────────────────
  db/services.php                          block instance
    defines the "Course Sync" service        holds URL + token + course mapping
    and seven read-only functions            in block_coursesync_connection

  classes/external/*                       classes/syncer.php
    ping                                     asks what changed
    get_course                               fetches each payload
    get_modified_activities                  hands it to a handler
    get_activity                             writes the run to history
    get_activity_file
    get_grades               ◄────────►    classes/grade_pull.php
                                             pulls grades for copies
    get_quiz_attempts        ◄────────►    classes/attempt_pull.php
                                             (called by grade_pull first)

  classes/local/handler/*                  classes/local/handler/*
    export_settings()          ◄────────►    create_from_remote_data()
    get_file_areas()                         get_file_areas()
```

The same handler class runs at both ends, doing a different half of the job. That
is the core idea: everything an activity type needs lives in one file, and
neither the service nor the syncer ever names an activity type.

### Direction of travel

Only the destination initiates. The source never calls out and never knows a
destination exists beyond a token being used. That keeps the trust relationship
one-way and means a source site needs no outbound network access.

### Why a custom service rather than core's

`core_course_get_contents` returns a rendered view of a course, not the settings
needed to rebuild an activity. An earlier attempt at this project tried to build
on those core functions; the schema it left behind is why the phase 2 brief ruled
the approach out. Course Sync's functions return what
`{modname}_add_instance()` actually needs.

## The payload contract

One external function serves every activity type, because the payload is a fixed
envelope plus a bag of type-specific settings:

```php
[
    'cmid'         => int,     // on the source site
    'modname'      => string,
    'name'         => string,
    'idnumber'     => string,
    'sectionnum'   => int,
    'visible'      => bool,
    'intro'        => string,  // HTML
    'introformat'  => int,
    'timemodified' => int,
    'settings'     => [['name' => string, 'value' => string], ...],
    'children'     => [[
        'type'      => string,   // 'chapter', 'pluginconfig', ...
        'sortorder' => int,
        'fields'    => [['name' => string, 'value' => string], ...],
    ], ...],
    'files'        => [[...file metadata...], ...],
]
```

Settings are flat strings in transit, and so are child records. That is what lets
a new activity type be added without the external function's return structure
changing — which would otherwise be a breaking change between site versions.

`children` is the escape hatch for an activity that is not a single row: a book's
chapters, an assignment's subplugin settings. It was added when those types were,
and adding it was preferable to letting each type invent its own encoding inside a
setting value, because a structure the payload knows about is a structure the
payload can clean.

`activity_payload::from_response()` is the **single** place a payload is built
from a response, and it cleans every field there. Nothing downstream repeats that
work, and nothing downstream should assume it needs to.

## Adding an activity type

The worked example is `page_handler`, the first one written. The contract is in
`activity_handler`'s docblock. `book_handler` and `assign_handler` are the ones
to read when an activity is more than a single row.

To add `mod_choice`:

### 1. Write the handler

`classes/local/handler/choice_handler.php`:

```php
namespace block_coursesync\local\handler;

use block_coursesync\activity_payload;

class choice_handler extends activity_handler {

    public static function get_modname(): string {
        return 'choice';
    }

    /** SOURCE SIDE: what a choice needs beyond the common envelope. */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        return [
            'allowupdate'   => (string) $instance->allowupdate,
            'showresults'   => (string) $instance->showresults,
            'display'       => (string) $instance->display,
        ];
    }

    /** DESTINATION SIDE: build it locally. */
    public function create_from_remote_data(
        \stdClass $course,
        activity_payload $payload,
        string $idnumber
    ): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/choice/lib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);
        $data->allowupdate = $payload->setting_int('allowupdate', 0);
        $data->showresults = $payload->setting_int('showresults', 0);
        $data->display     = $payload->setting_int('display', 0);

        $instanceid = \choice_add_instance($data);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);
            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }
}
```

**Read the module's `*_add_instance()` before writing the import half.** Many do
not take the activity as it is stored, and the mismatch is always silent:

- `quiz_add_instance()` runs its argument through `quiz_process_options()`, which
  expects what the settings *form* submits: it renames `quizpassword` to
  `password`, and it ignores the eight stored review columns entirely, rebuilding
  them from four checkboxes each. Handing it the stored columns produces a quiz
  that reviews nothing and reports no error.
  `quiz_handler::review_checkboxes()` is what that costs.
- `lesson_add_instance()` calls `lesson_process_pre_save()`, which *builds* the
  serialised `conditions` column out of `timespent`, `completed` and
  `gradebetterthan` and then removes them. So the export has to take that column
  apart and the import has to hand over the three pieces.
- `choice_add_instance()` reads its options from two parallel arrays, `option`
  and `limit`, sharing an index - the shape the form posts.
- `feedback_add_instance()` and `workshop_add_instance()` read editor fields
  (`page_after_submit_editor`, `instructauthorseditor` and two more) **without
  checking they are there**. Pass them, with `itemid` 0 to mean "no draft area".
- `workshop_grade_item_update()` reads `gradecategory` and `gradinggradecategory`
  the same way.
- `glossary_add_instance()` *throws* on a display format it does not have rather
  than falling back, so check before calling it.

A useful habit: run the handler once and read the PHP warnings. Every one of
these was found that way, as an "Undefined property" from inside the module.

### 2. Register it

Add `choice_handler::class` to `handler_registry::HANDLERS`. That is the only
place an activity type is named.

### 3. If it has files

Declare the areas:

```php
public function get_file_areas(): array {
    return [['filearea' => 'content', 'itemid' => 0]];
}
```

The syncer then copies them after the activity exists, and `get_activity_file`
will serve them. **Declaring an area is also what authorises reading it** — the
source refuses any area a handler has not declared, which is what stops the file
function being a general file reader. Do not widen it casually.

Note the ordering constraint: a file area is addressed by the module's context
id, and that context does not exist until the course module does. Files are
therefore always written *after* `create_from_remote_data()` returns. If the
activity has to do something once they have landed — `mod_resource` marks which
file it opens — override `post_files()`.

### 4. If it is more than one row

An activity whose content lives in a second table carries it as **child records**:
a flat list, each with a type, a sort order and a map of fields. Override
`export_children()` on the source side and read them back with
`$payload->children('chapter')`. `activity_payload` cleans the record types and
field names on arrival; the handler cleans the values, because only it knows
whether a field is a number, a title or a block of HTML.

`book_handler` is the example for content, `assign_handler` for settings.

**If those records refer to each other**, use the id map on `activity_handler`:
`remember_id()` while creating, then `mapped_id()` to translate. The shape is
always the same - create everything first, then fix the references, because a
reference can point forward as well as backward. Several handlers do this:

| Handler | What refers to what |
| --- | --- |
| `feedback_handler` | A question that only appears depending on another question's answer |
| `data_handler` | Which field the entries are sorted by |
| `workshop_handler` | A rubric level belongs to a criterion |
| `lesson_handler` | The page chain, and where every answer jumps to |
| `qbank_handler` | A category can belong to another category, and a question belongs to a category |
| `quiz_handler` | A slot points at a question or a category - question_bank_sync_trait, shared with qbank_handler, is where the referencing actually happens |

Resolve an unknown reference to a safe value rather than passing the number
through. It will match some unrelated local record if you do.

The trap in `assign_handler` is worth knowing before you meet it somewhere else:
its subplugin settings could not be restored by handing mod_assign the form it
expects, because a subplugin's form field names and its stored setting names are
unrelated — `assignsubmission_file`'s `assignsubmission_file_maxfiles` field is
stored as `maxfilesubmissions`. Only the subplugin knows its own mapping, so the
rows are carried as stored and written back directly, which is what mod_assign's
own restore does.

`qbank_handler` bends the "flat map of fields" rule on purpose for its
`'question'` children: rather than flattening each question type's own
columns (seventeen types means seventeen different, unrelated shapes), one field
holds the whole question as a `qformat_xml`-serialised fragment - Moodle's
own question export format, reused rather than re-derived. `'category'`
children stay flat, because a category's own fields are the same regardless
of type.

### 5. If a field is code rather than content

Refuse it. `mod_data`'s `csstemplate` and `jstemplate` are served verbatim as CSS
and JavaScript and loaded into every page of the activity; there is no cleaning
that makes a block of script safe. `data_handler` drops both and reports
`syncdatacodetemplates`. `SECURITY.md` has the reasoning.

While you are there, check what the field is actually *for* before choosing a
cleaner. `mod_feedback`'s `presentation` is HTML for a label - rendered with
`'noclean' => true`, so it must be cleaned here - and a `|`-separated option list
for a multiple choice, which `clean_text()` would corrupt. One column, two
cleaners, both necessary.

### 6. If its files hang off those records

`mod_book` keeps each chapter's images under that chapter's id, which is a
different number on each site. Declare the area without naming an item id:

```php
public function get_file_areas(): array {
    return [['filearea' => 'chapter', 'anyitemid' => true]];
}
```

Then implement `map_file_itemid()` to turn the source's id into the local one,
returning `null` for a file with nowhere to go so it is left behind rather than
filed against whatever local record holds that number.

`anyitemid` widens what the source will serve from that area, so use it only when
the ids genuinely cannot be known in advance, and read the note in `SECURITY.md`
on exactly what it does and does not allow.

`qbank_handler` is the one handler that declares no file areas at all -
`get_file_areas()` returns `[]`, the inherited default. A question's own
files (its text, feedback, answers) never go through `file_sync`: the
`qformat_xml` fragment in each `'question'` child already carries them,
base64-encoded, because that is what Moodle's own question export format
does. Piping the same bytes through a second transfer mechanism would be
strictly more code for nothing, so this handler does not.

### 7. If it cannot bring everything across

Override `notes()` to return language string keys. The run reports them against
that activity and the history keeps them. An activity that quietly arrives
incomplete is worse than one that says what is missing — `quiz_handler` uses this
to state on every quiz that its questions did not come with it.

An entry can also be a `[key, $a]` pair when the string needs a parameter -
`qbank_handler` reports how many questions were of a type it could not
rebuild this way. Render either shape with `sync_result::describe_note()`
rather than calling `get_string()` directly; both `sync.php` and
`history.php` already do.

### 8. Write the tests

`handlers_test.php` and `new_handlers_test.php` have the pattern: export a real
activity through the source-side code, rebuild it through the destination-side
code in a second course, and compare. Testing one side alone misses the failure
that matters, which is an export and an import that disagree — every settings bug
found while writing the newer handlers was a field the export had simply
forgotten, which only a round trip shows.

Add a Behat scenario too if the type has anything a teacher would notice.
`new_types_sync.feature` goes through the real web service between two courses,
and that is what caught the permission problem described in `SECURITY.md`, which
no unit test would have: calling the external function directly does not go
through the sync account.

### Five things that will bite you

1. **`require_once($CFG->dirroot . '/course/lib.php')`.** `add_course_module()`
   and `course_add_cm_to_section()` live there, and Moodle does not load it at
   bootstrap — it arrives as a side effect of `get_fast_modinfo()` and little
   else. `create_course_module()` and `finish_creation()` already do this; a
   handler that calls those functions itself must too.

2. **Use `make_instance_data()`.** It sets `cmidnumber`, which several modules
   read when creating a grade item. Leaving it out produces a warning from inside
   the other module, not from here.

3. **`FORMAT_HTML` is the string `'1'`**, not an integer. `===` against a
   `FORMAT_*` constant will surprise you.

4. **Clean anything that reaches a sensitive place.** `setting_url()` for
   anything rendered as a link, `setting_html()` for anything rendered as
   markup. A `javascript:` address from a source site is script running on the
   destination.

5. **Site-local ids do not travel.** Scale ids, role ids, category ids mean
   different things on different sites. `forum_handler` shows the pattern: send
   the scale's *name* alongside its id, match by name at the other end, and fall
   back honestly when there is no match.

## Choosing what to sync

`sync.php` asks `syncer::list_candidates()` before it offers anything. That runs
the same change detection a sync starts with - always with `since = 0`, the
whole of the other course, since v1.16.0 - then sorts what comes back into
groups using the same question a run asks - does anything in this course
carry that activity's identity:

| Group | Shown as | Why |
| --- | --- | --- |
| `new` | "Ready to copy" table, ticked, enabled checkbox | Not here yet and this plugin handles it |
| `changed` | "Changed since it was copied" table, unticked | Pulled here earlier, and the source changed it since - see "Updating a copy" |
| `present` | "Already on this course" table, unticked, status says replace or "(copy)" (`recopy_adds_copy()`) | Pulled here by an earlier run, unchanged since |
| `collisions` | "Already on this course" table, unticked, status "Needs review", plus a named warning above that table | Something carries the identity that this plugin did not put there |
| `unsupported` | Not shown anywhere on this page | No handler for the type - nothing a teacher can do about it here |

Each group is its own table, sorted by remote cmid, under its own heading -
`new` first and ticked, since that is the actual decision this page exists
for; the others after it, never pre-ticked. "Select all" only reaches `new`
and `changed` rows (`data-coursesync-bulk`, see `amd/src/choose.js`).

Ticking an already-present row copies it again (`handle_update(..., $recopy =
true)`): if `copy_update::has_people_data()` finds nothing - the module's
privacy userlist, completion, gradebook grades, qbank use - the fresh copy
replaces it exactly as an update would; otherwise the old copy keeps its
people's work, loses its identity, and a fresh tracked copy named
"Name (copy)" (then "(copy 2)", ...) goes after it. A ticked collision is
never touched: `syncer::copy_beside()` adds an **untracked** "(copy)" after
it (no idnumber), so the collision stays flagged. `unsupported` is computed by `list_candidates()` but
deliberately never rendered: a type nothing here handles is not a choice a
teacher can make on this page, so naming it would be noise, not help.

`list_candidates()` writes nothing, so the page can be reloaded freely.

The chosen ids come back as `cmids[]` and are passed to `syncer::run()` as
`$only`. A selection can only ever **narrow** a run: `run()` walks what the
source reported and skips anything not in the chosen set, so an id that was
never offered names nothing. That is the whole validation, and it is enough,
because the set being filtered is the source's own answer for the mapped course.

### Deselection and the last synced marker

Leaving something out has to hold `lastsync`, or "not this one" would quietly
mean "not ever" - the marker would move past it and it would never be offered
again.

That rule needs care, because it is easy to hold the marker for something that
was never a choice at all. Two cases must **not** count as deselected:

- a type nothing handles, which can never be ticked;
- something already in this course, which is never offered.

Both were bugs during development, and the second is the worse one: it holds the
marker permanently, because every later run re-detects the same already-present
activities and would keep finding them "deselected". `syncer::run()` therefore
decides between `syncskippedtype`, `syncskippedpresent` and
`syncskippeddeselected`, and only the last of those reaches
`sync_result::has_deselected()`.

## Identity and conflicts

A copied activity is stamped `idnumber = coursesync-<remote course module id>`.
That marker is the entire identity scheme — there is no mapping table.

Before creating anything, `syncer::find_existing()` looks for that marker in the
destination course. If it finds one, the activity is **flagged**, never
overwritten and never duplicated.

`history::was_pulled_here()` then decides which kind of conflict it is by looking
for a past run that recorded creating that exact remote/local pair.

### Updating a copy

`syncer::list_candidates()` puts an activity in `changed` rather than `present`
when the source's `timemodified` is later than the start of the run that pulled
the local copy (`history::pulled_at()`, read from the same `pulled` JSON as
`was_pulled_here()`). Changed activities are offered unticked. Only a run given
an explicit choice (`$only !== null`) ever updates; a run without one keeps the
old behaviour and flags `conflictchangedupstream`.

`syncer::handle_update()` never edits the existing copy. It creates a fresh one
through the ordinary `create_copy()` path with an **empty** idnumber, so a
failure leaves the old copy exactly as it was, and only then:

- **Nobody has data in the old copy** (`copy_update::has_people_data()`): the
  fresh copy takes the old one's section, position, visibility, availability,
  completion settings and grade categories; other activities' availability and
  course completion criteria are repointed to it; it takes the identity; and
  the old copy is deleted **last**, so nothing after the delete can fail and
  leave neither.
- **Somebody has**: the old copy's idnumber is cleared (course module and grade
  item) and nothing else about it changes; the fresh copy goes straight after
  it, named with `synceditionname`, and takes the identity.

"Somebody has data" asks the activity's own privacy provider
(`core_userlist_provider::get_users_in_context()`), so it needs no code per
type; completion states are checked separately because they belong to the
course module, and a Question bank also counts as used when it holds questions
added locally or referenced by a quiz. History records the outcome as
`updated`, stored among `pulled` so the fresh copy is recognised as this
plugin's from then on.

A quiz's questions are reused by idnumber when it is replaced, so an edit to a
question on the source (a new version of the same bank entry) is not picked up,
and editing a question does not change the quiz's own `timemodified` either.

### What the candidate list changed about conflicts

Conflicts used to be how a teacher learned an activity was already here: the run
created nothing and reported it afterwards. Now the list filters those out
before anything is offered, so in ordinary use a conflict no longer happens -
the situation is prevented rather than reported.

The conflict path is still there and still matters. It catches a race between
listing and submitting, and it is what `handle_one()` falls back on when `run()`
is called without a selection. What would have been silently lost is the
`conflictlocalactivity` case - something carrying the identity that this plugin
did not put there - which is why `list_candidates()` separates `collisions` from
`present` and the page warns about them.

### Why conflicts do not hold `lastsync`

This is the subtlest decision in the plugin and it is easy to get backwards.

`sync_result::is_clean()` ignores conflicts, so the marker still moves forward
after a run that flagged things. If it did not, every activity copied by an
earlier run would be detected again on the next run, find its own copy present,
and be flagged too — one conflict cascading into flagging the whole course, every
run, forever.

Failures *do* hold the marker, so a genuine failure is retried.

The marker no longer filters the sync page: since v1.16.0 `sync.php` always
asks with `since = 0`, so a conflict resolved by hand is offered again on the
very next visit. (The **Check everything again** link that used to do this is
gone - a page filtered by the marker came back empty once everything had been
copied, and said "There is nothing in the other course to copy".)

### Deleting a synced activity, so it can be synced back

`find_existing()` also excludes a `course_modules` row flagged
`deletioninprogress = 1`. Moodle's standard "Delete" action in the course
editor is asynchronous by default
(`course_delete_module($cmid, true)`, called from
`stateactions::cm_delete()`): it only sets that flag and moves the module out
of its section, then queues an adhoc task to do the real deletion -
`quiz_delete_instance()`, `question_delete_activity()`, and the rest -
whenever cron next processes it. The teacher already sees the activity gone
from the course at that point, so `find_existing()` treats it as gone too,
rather than making "delete it, then sync it back" depend on the site's cron
schedule. The next visit to the sync page correctly re-offers it.

This means, briefly, two `course_modules` rows can carry the same idnumber
at once: the flagged one, still waiting on its adhoc task, and the freshly
synced one. That is harmless - `course_modules.idnumber` has no database
uniqueness constraint, only an application-level one enforced by the course
edit form (see `idnumber-course` in `install.xml`, deliberately
`UNIQUE="false"`) - and for `quiz_handler`/`qbank_handler` specifically it is
also why the flagged module's own question bank content is never at risk:
a quiz's questions live in the shared System Bank, untouched by its own
module being flagged for deletion, and `question_bank_sync_trait`'s
idnumber-reuse logic finds and reuses that same content on the resync
rather than duplicating it - proven together in
`quiz_handler_test::test_deleting_and_resyncing_a_quiz_reuses_its_bank_content()`.
A qbank's own categories and questions live in its own module context, so a
second sync before cron catches up simply creates its own independent copy
under a fresh context, with no collision either way.

## Change detection

`course_modules` has no modification time of its own — only `added`. The time
comes from each activity's own table, read one module type at a time rather than
one activity at a time. A third-party type whose table has no `timemodified`
falls back to `added`, which means **edits to such an activity will not be
detected**, only its creation.

"Since X" is **exclusive** (`timemodified > since`). With `>=`, every run would
re-report the same items forever once `lastsync` started being written. There is
a test pinning this; if an activity ever looks skipped, fix the timestamp being
stored rather than this comparison.

## Grade sync

Added in v1.18.0 (LEARNFROMME phases 34-39). The only part of the plugin that
moves people's data, so it is switched off on both sides by default; the gates
are listed in [SECURITY.md](SECURITY.md#students-grades).

**Source:** `external\get_grades` takes a course and a list of cmids and returns
each grade item (itemnumber, type, range, scale items, hidden) with each graded
student's grade by **username**. Students come from core's
`get_gradable_users($courseid, null, true)` — graded roles, active enrolment. It
runs `grade_regrade_final_grades()` first if the course needs it: the iterator
behind `get_gradable_users()` throws `gradesneedregrading` otherwise, and final
grades would be stale anyway.

**Destination:** `grade_pull::preview()` / `run()`, returning a
`grade_pull_result` (one entry per student per grade item: ADD, UPDATE, SAME,
CONFLICT or SKIPPED with a reason). `grades.php` is a thin page over it.

- Copies are found by the idnumber `coursesync-<remote cmid>`, the same
  identity the syncer uses. Only those cmids are sent.
- Grade items are paired by `itemnumber` (workshop has two).
- A different range is converted linearly; a scale grade only lands on a scale
  with the same items; a different grade type is refused.
- Writes go through `grade_item::update_final_grade()`, which marks a mod
  item's grade **overridden**. That is the point: the activity here has no
  attempts for these students, and `quiz_update_grades($quiz, $userid, true)`
  would null a plain grade. `test_a_quiz_regrade_does_not_wipe_a_pulled_grade`
  pins it.
- `block_coursesync_grade` records each grade a pull wrote (value + SHA-1 of the
  feedback). "Ours and untouched" = both still match → UPDATE; anything else
  with a grade is CONFLICT.
- `run()` takes the **same lock** as `syncer::run()` (`run-<blockinstanceid>`),
  so grades are never written into an activity a sync is replacing.
- History: `block_coursesync_run.kind` is `activities` or `grades`.
  **`history::pulled_at()` must filter `kind = activities`** — a grade run lists
  activities too, and taking one for "the run that copied this" would break
  change detection. A grade run's `pulled` holds per-activity counts only.

## Quiz attempts

Added in v1.19.0 (LEARNFROMME phases 40-44), marks only.

**Source:** `external\get_quiz_attempts` returns, per quiz, its outline (from
`qbank_helper::get_question_structure()`: slot, page, maxmark, qtype or
`random`) and each finished, non-preview attempt by an active graded student,
with each slot's mark and **question state** from
`question_engine_data_mapper::load_questions_usage_latest_steps()`. The state
matters: a null mark is either `needsgrading` (the attempt has no total) or
`gaveup` (counts as nothing). `formatversion` is 1; responses are the obvious
version 2, and would need the per-qtype id remapping core only does in restore
(`restore_qtype_*::recode_response()`).

**Destination:** `attempt_pull::work()`, called by `grade_pull::pull()` first,
inside its lock. It refuses a quiz whose outline differs, skips attempts not
graded yet, and builds each attempt directly:
`quiz_create_attempt` → `quiz_start_new_attempt` →
`quiz_attempt_save_started(…, $timestart)` → `finish_all_questions($timefinish)`
→ `manual_grade()` per slot → the row set finished with
`gradednotificationsenttime` → `recompute_final_grade()` + completion.
**Never `process_submit()`** - it notifies teachers - and never leave
`gradednotificationsenttime` null, or a scheduled task emails the student.

Then `grade_pull` skips overrides for students with attempts in a quiz listed
in `$result->attemptquizzes`, and **releases** an earlier pull's untouched
override (`set_overridden(false)`, which refreshes from the quiz). Results
carry `kind` (`grade` / `attempt`); `grades.php` and the history show them in
separate sections.

Tests share `tests/local/quiz_on_the_source.php`: a four-slot quiz with a
random slot on a source course, copied by the real handler, and helpers to
attempt and hand-mark it.

## Security

The full audit is in [SECURITY.md](SECURITY.md). The parts that constrain how you
write code here:

- **Remote data is hostile input.** Cleaned once in
  `activity_payload::from_response()` and in `remote_client` where values arrive.
- **Core's notification template renders `{{{ message }}}` unescaped.** Anything
  built from remote data and passed to `$OUTPUT->notification()` must be escaped.
- **Outgoing addresses are checked twice** — at save time and again immediately
  before every request, in `remote_client::call()`. The second is the one that
  matters, because a hostname can resolve differently later.
- **`confirm_sesskey()` returns a bool and does not throw.** Use
  `require_sesskey()`.
- **Only `block/coursesync:sync`, at the course context, is ever checked.**
  No handler checks a module-specific capability, on purpose - see
  `SECURITY.md` for why. `qbank_handler` follows this too: the core calls it
  makes (`get_qtype()->save_question_options()` and friends) do no
  capability enforcement of their own, since those checks live in the
  question bank's editing UI, which this handler never goes through. Do not
  add one without re-reading that reasoning first.
- **`quiz_handler`'s random-slot import is the one exception**, and it is not
  this plugin's own check: building a random slot calls core's own
  `mod_quiz\structure::add_random_questions()`, which itself calls
  `require_capability('moodle/question:useall', $catcontext)` against the
  System Bank's own context. That runs against the *destination* teacher's
  own session - the same check that would run if they added a random
  question by hand - not a wider grant for the source-side service account.
  The `editingteacher` archetype holds it by default at the course context,
  which covers the System Bank since it lives inside that same course, so
  this is not a new administrative step for the normal case. Fixed-question
  slots need no capability at all, same reasoning as `qbank_handler` -
  `quiz_add_quiz_question()` enforces nothing itself.

## Testing

```bash
moodle-plugin-ci phpunit -m /path/to/moodle ./
moodle-plugin-ci behat  -m /path/to/moodle ./
```

The Behat suite includes a full teacher-facing flow which points the site at
**itself** as the source — web services enabled, a token issued, the block
connected to `$CFG->wwwroot`. That keeps the test deterministic and free of a
second server. The one step it cannot drive is entering the remote address in the
wizard, because that field requires `https` and the Behat site is served over
plain http; a step definition records the address instead, and the token, test,
mapping, sync and history are all driven through the interface.

`remote_client`, `file_sync`, `syncer` and `grade_pull` all accept an injected
`\core\http_client`, which is how a whole run can be driven from a test with
mocked responses. `tests/local/source_on_this_site.php` goes one better: its
client answers by running this site's own external functions, so a test can
copy an activity and pull its grades end to end.

`tests/behat/grade_pull.feature` grades the source course in the Background,
**before** anything is copied: core's `grade grades` generator finds a grade
item by name, which is ambiguous once a copy exists on the same site. For a
grade on the copy there is a step of our own,
`"user" has a grade of "55" in the synced "Name" in course "DEST"`, and both
switches are turned on with `Course Sync grade sharing|pulling is switched on`.

**The Behat web server needs worker processes.** Because the site calls itself,
a single-threaded `php -S` deadlocks: the request being served is the one that
has to answer the nested call. Start it with

```bash
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8001 -t /path/to/moodle/public
```

**After changing `$plugin->version`, re-initialise both test sites** — PHPUnit
and Behat each refuse to run against a site built for a different version.

## Known limitations

- Files embedded in text fields travel only when they are in a declared file
  area. Every type gets its description's area (`intro`) from
  `activity_handler::file_areas()` without declaring it, and the handler
  declares the rest (`get_file_areas()`): a page's `content`, a book's
  `chapter`, a lesson's pages, a feedback's `item`, a workshop's instructions
  and - under each grading strategy's own component, `workshopform_*` - its
  criteria's `description`. `local_file_itemid()` files `intro` under item 0
  before any handler's `map_file_itemid()` is asked, because several of those
  only know their own child records' areas. On the destination, a file is
  stored only if `declares_file_area()` agrees on its component and area,
  whatever the source listed. A link whose file does not arrive is named in
  `syncfilesmissing` (`activity_payload::missing_files()`), matched by path
  and name; runs before this recorded the unnamed `syncfilewarning`.
- A quiz's overall feedback (`quiz_feedback`) is carried as
  `overallfeedback` children, one per band, and recreated row for row with its
  exact `mingrade`/`maxgrade` rather than through `quiz_add_instance()`'s form
  shape, which would re-derive boundaries from percentages. Images in a band
  are in `mod_quiz`/`feedback` under the band's id, mapped to the new band.
- Forum discussions, wiki pages, glossary entries, feedback responses, database
  entries, workshop submissions, choice answers and lesson attempts are not
  synced. Those activities carry what a teacher set up, not what anyone did.
- A database's custom CSS and JavaScript are refused on purpose; see
  `SECURITY.md`.
- A lesson's password is not carried, and `usepassword` is turned off with it.
- A lesson's `dependency` and `activitylink` name another activity by a local id,
  so the copy has neither.
- An H5P, SCORM or IMS activity is refused outright if its package did not
  arrive, rather than created as something that cannot be opened. They test
  for the package's own area (`activity_payload::has_file_in()`), not
  `has_files()`, which a description image alone would now satisfy.
- **Subsections and placement.** A subsection owns a delegated section,
  numbered after the ordinary ones. `get_activity` reports an activity inside
  one by the ordinary section its subsection is in (`sectionnum`) plus the
  subsection's cmid (`subsectioncmid`, optional in the return structure).
  Handlers place through `activity_handler::target_section()`: inside this
  course's copy of that subsection if there is one, else
  `resolve_section()`, which now only ever returns an ordinary section
  (`component IS NULL`) - before this, clamping to the highest section number
  could drop an activity into an unrelated subsection. `syncer::run()` handles
  subsections before anything else. A changed subsection is never replaced -
  `subsection_delete_instance()` force-deletes everything inside - so
  `subsection_handler::updates_in_place()` is true and `update_in_place()`
  renames it through `formatactions::cm()->rename()`, whose hook renames its
  section too. Renaming either way on the source moves the subsection's
  `timemodified` (`sectiondelegatemodule::preprocess_section_name()`), so
  change detection needs nothing special.
- **External tools (`lti_handler`) are only linked to a tool already set up
  here.** `check_destination()` - a handler hook asked before anything is
  created, for refusals that depend on the course - runs Moodle's own launch
  matcher, `lti_get_tool_by_url_match()` (configured tools, site or this
  course, by domain), on the activity's `toolurl` or else its source tool's
  `baseurl`. No match refuses with `errorltinotool`, and `failure_notes()` - a
  second new hook, for what a fixed failure message cannot say - names the
  tool. `lti_add_instance()` then applies `lti_force_type_config_settings()`,
  so this site's tool decides what is sent about people. Keys, secrets and
  `servicesalt` never travel; an activity with its own key/secret and no tool
  (`typeid` 0) is refused (`errorltiownsecret`). Test note: the `mod_lti`
  generator's site tools are pending with no domain, unlike any saved through
  the admin screens, so tests pass `state` and `lti_toolurl`.
- **BigBlueButton (`bigbluebuttonbn_handler`) is set up afresh.**
  `bigbluebuttonbn_add_instance()` generates the meeting id and moderator,
  viewer and guest credentials; none of the source's travel. Participant rules
  travel with role ids turned into shortnames and back; `user` rules and
  unknown roles are dropped and counted. `voicebridge` is reset to 0. Refused
  (`errorbbbnotenabled`) where the module is not enabled.
- **SCORM and IMS packages are unpacked and parsed here, then checked to
  open** (LEARNFROMME P11.2). Only the zip travels, never the unpacked
  `content` area or the parsed tables. Their `post_files()` runs what
  `scorm_add_instance()` / `imscp_add_instance()` run for an upload -
  `scorm_parse($scorm, true)`, or `extract_to_storage()` plus
  `imscp_parse_structure()` - because the package only exists once the
  context does. A SCORM that parsed as `ERROR` or has no launch SCO, or an IMS
  package with no table of contents or whose first page is missing, throws
  `errorpackagenotdeployed`, and `syncer::create_copy()` removes it. Only
  `local` SCORMs have a package; `localsync` is copied as `local` (noted),
  `external`/`aiccurl` are refused (`errorscormnotuploaded`). An IMS source
  may keep old revisions in `backup`; only the current one is kept, as
  revision 1. The popup `options` string travels as separate `popup_*`
  settings so `scorm_option2text()` rebuilds it in this site's format.
- **A synced Question bank, and a synced quiz's questions, cover all
  seventeen of Moodle's standard question types** — multiple choice,
  true/false, short answer, matching, essay, numerical, multianswer (cloze),
  drag and drop into text (`ddwtos`), onto image (`ddimageortext`) and
  markers (`ddmarker`), select missing words (`gapselect`), `ordering`,
  random short-answer matching (`randomsamatch`), `description`, and the
  three calculated types (`calculated`, `calculatedsimple`,
  `calculatedmulti`). Only a third-party type is ever left out
  (`question_bank_sync_trait::SUPPORTED_QTYPES`, shared by both
  `qbank_handler` and `quiz_handler`). Multianswer's embedded sub-questions
  need no special handling here — `qformat_xml` resolves them from the
  parent's own `questiontext` before this handler's `save_question()` ever
  sees it, and `qtype_multianswer::save_question_options()` saves them
  itself, inside the same one call this handler already makes for every
  other type. The two image-based drag and drop types need nothing special
  either: their `export_to_xml()` inlines the background image and any
  image drag items as base64, `import_from_xml()` turns them back into
  draft areas, and `save_question_options()` files them under the new
  question. `randomsamatch` carries only its settings (`choose`, `subcats`):
  it draws short-answer questions from its own category at attempt time, so
  it works once those arrive, which they do because every question in an
  exported category travels. With `subcats` on, a quiz sync brings only the
  subcategories the quiz otherwise references. `gapselect` renumbers its
  `[[n]]` placeholders round any empty choice when saved, on both sites, so
  compare a copy with the saved source, not with form data. The calculated
  types' dataset definitions and values are only loaded when a question has
  `export_process` set, which `question_bank::load_question_data()` never
  does - so `question_bank_sync_trait::with_export_data()` sets it on a copy
  of the cached question and adds them, as Moodle's own export does. The
  destination needs nothing extra: `qformat_xml`'s reader sets
  `import_process` on every question it parses, which is what makes their
  `save_question_options()` save the datasets. A shared dataset stays shared,
  created in the new category by the first question and found there by name
  by the rest (`qtype_calculated::import_datasets()`). Core's generator makes
  `calculated` and `calculatedmulti` questions with no dataset values, so a
  test must add some before either original can even be attempted. A question of
  any other type is left out and counted, not
  attempted; see `notes()` on either handler. Only the current ready
  version of each question is copied — no drafts, no hidden versions, no
  history.
- **A quiz's questions land in the destination course's shared System Bank**
  (`\core_question\local\bank\question_bank_helper::TYPE_SYSTEM`), not a
  dedicated activity — the same place Moodle itself puts a question added
  straight to a quiz with no bank picked. Two quizzes sharing a question, or
  a quiz and a `qbank_handler` sync, reuse the same local question rather
  than duplicating it; see `question_bank_sync_trait`'s idnumber-reuse logic.
- A fixed slot whose question is unsupported is left out of the quiz - it is
  never left as a broken reference - and counted once, under the shared
  trait's `syncqbankunsupportedcount`. A random slot whose category never
  arrived at all is a separate case, counted under its own
  `syncquizunresolvedslotcount`; see `quiz_handler::notes()`. The two are
  deliberately never both counted for the same slot.
- A random slot's whole source category (and its whole subtree, when the
  slot includes subcategories) is exported, not a sample — the point of a
  random slot is drawing from the full pool at attempt time, and exporting
  only enough to look plausible would silently narrow that pool.
- A quiz's per-slot page number is not carried; every synced slot is
  appended in the source's original order, relying on the destination's own
  `questionsperpage` setting (which is carried) to reproduce equivalent
  paging.
- An assignment's grading scale, and a forum's, are matched by name. A scale that
  does not exist on the destination means the copy is created ungraded, and the
  run says so.
- A quiz's password and subnet restriction are not carried.
- An activity deleted on the source is not detected as deleted — it simply stops
  appearing. A real sync will eventually need to decide what to do about that.
- Hidden activities on the source are reported to the destination; the capability
  check is the gate, not per-activity visibility.
- Updating an activity already copied is never done in place — no handler has
  an update path. It is a fresh copy that either replaces the old one or sits
  beside it as a new edition; see "Updating a copy" above.
