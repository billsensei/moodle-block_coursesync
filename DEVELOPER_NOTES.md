# Course Sync: developer notes

Architecture summary and extension guide for anyone maintaining or building
on block_coursesync (v0.8, end of Phase 8). This complements the docblocks
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
  <modname>_activity_handler.php   One pair per supported type (Phase 5):
  <modname>_activity_exporter.php  page, url, label, resource, forum.
  activity_lookup.php              Change detection (Phase 3): activities
                                    modified after a given time, per course.
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

This is the extension point Phase 4 established and every v1 type
(page/url/label/resource/forum, Phase 5) follows. To add support for
activity type `<modname>` (e.g. `quiz`):

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

## Testing (Phase 8)

- **PHPUnit** (`tests/`): change detection (`activity_lookup_test.php`),
  all five v1 handlers (`activity_handlers_test.php`), conflict-flagging,
  same-name-and-type exclusion, and lastsync advancement
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
  auto-escape, which is easy to miss.

## What v1 deliberately doesn't do

See `REMOTE_SETUP.md`'s "What this plugin does *not* do" section - still
accurate as of Phase 8. In short: no automated remote-site configuration,
only the five registered activity types are pulled (others are detected but
skipped, not treated as failures), and a flagged conflict has no in-block
resolution UI - a teacher resolves it manually and syncs again.
