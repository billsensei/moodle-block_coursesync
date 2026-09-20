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
    and five read-only functions             in block_coursesync_connection

  classes/external/*                       classes/syncer.php
    ping                                     asks what changed
    get_course                               fetches each payload
    get_modified_activities                  hands it to a handler
    get_activity                             writes the run to history
    get_activity_file

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
reference can point forward as well as backward. Four handlers do this:

| Handler | What refers to what |
| --- | --- |
| `feedback_handler` | A question that only appears depending on another question's answer |
| `data_handler` | Which field the entries are sorted by |
| `workshop_handler` | A rubric level belongs to a criterion |
| `lesson_handler` | The page chain, and where every answer jumps to |

Resolve an unknown reference to a safe value rather than passing the number
through. It will match some unrelated local record if you do.

The trap in `assign_handler` is worth knowing before you meet it somewhere else:
its subplugin settings could not be restored by handing mod_assign the form it
expects, because a subplugin's form field names and its stored setting names are
unrelated — `assignsubmission_file`'s `assignsubmission_file_maxfiles` field is
stored as `maxfilesubmissions`. Only the subplugin knows its own mapping, so the
rows are carried as stored and written back directly, which is what mod_assign's
own restore does.

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

### 7. If it cannot bring everything across

Override `notes()` to return language string keys. The run reports them against
that activity and the history keeps them. An activity that quietly arrives
incomplete is worse than one that says what is missing — `quiz_handler` uses this
to state on every quiz that its questions did not come with it.

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
the same change detection a sync starts with, then sorts what comes back into
four groups using the same question a run asks - does anything in this course
carry that activity's identity:

| Group | Shown as | Why |
| --- | --- | --- |
| `new` | A ticked checkbox | Not here yet and this plugin handles it |
| `present` | A count | Pulled here by an earlier run |
| `collisions` | A warning, named | Something carries the identity that this plugin did not put there |
| `unsupported` | Named, not selectable | No handler for the type |

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

The cost is that a conflict resolved by hand is not re-offered by an ordinary
sync. That is what the **Check everything again** option (`since = 0`) is for.

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

`remote_client`, `file_sync` and `syncer` all accept an injected
`\core\http_client`, which is how a whole run can be driven from a test with
mocked responses.

**The Behat web server needs worker processes.** Because the site calls itself,
a single-threaded `php -S` deadlocks: the request being served is the one that
has to answer the nested call. Start it with

```bash
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8001 -t /path/to/moodle/public
```

**After changing `$plugin->version`, re-initialise both test sites** — PHPUnit
and Behat each refuse to run against a site built for a different version.

## Known limitations

- Files embedded in text fields are not transferred; `@@PLUGINFILE@@` links
  arrive broken and the sync says so.
- Forum discussions, wiki pages, glossary entries, feedback responses, database
  entries, workshop submissions, choice answers and lesson attempts are not
  synced. Those activities carry what a teacher set up, not what anyone did.
- A database's custom CSS and JavaScript are refused on purpose; see
  `SECURITY.md`.
- A lesson's password is not carried, and `usepassword` is turned off with it.
- A lesson's `dependency` and `activitylink` name another activity by a local id,
  so the copy has neither.
- An H5P activity is refused outright if its package did not arrive, rather than
  created as something that cannot be opened. It is the only handler that
  overrides `check_payload()` to insist on a file.
- **Quiz questions are not synced.** They live in the question bank, which is
  per-site, and a quiz holds references into it. Copying those references would
  point them at whatever held the same ids on the other site. Question banks are
  their own piece of work: they are shared between activities, they have
  categories and versions, and moving them safely is a larger job than moving an
  activity.
- An assignment's grading scale, and a forum's, are matched by name. A scale that
  does not exist on the destination means the copy is created ungraded, and the
  run says so.
- A quiz's password and subnet restriction are not carried.
- An activity deleted on the source is not detected as deleted — it simply stops
  appearing. A real sync will eventually need to decide what to do about that.
- Hidden activities on the source are reported to the destination; the capability
  check is the gate, not per-activity visibility.
- Updating an activity already copied is not implemented. It is flagged instead.
