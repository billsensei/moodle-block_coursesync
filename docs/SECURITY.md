# Security notes

> Part of the Course Sync documentation set:
> [INSTALL.md](INSTALL.md) · [REMOTE_SETUP.md](REMOTE_SETUP.md) · [TEACHER_GUIDE.md](TEACHER_GUIDE.md) · [DEVELOPER.md](DEVELOPER.md) · **SECURITY.md**


Written during the phase 7 hardening pass. This records what is protected, how,
and which decisions were deliberate — so a later change does not quietly undo
one of them.

## Endpoint audit

Every entry point the plugin adds, and what guards it.

### Pages

| Page | Login | Capability | Sesskey | Changes state? |
| --- | --- | --- | --- | --- |
| `setup.php` (view) | yes | `block/coursesync:configure` | — | no |
| `setup.php` (form submissions) | yes | `block/coursesync:configure` | moodleform | yes |
| `setup.php?test=1` | yes | `block/coursesync:configure` | `require_sesskey()` | yes |
| `preview.php` | yes | `block/coursesync:sync` | — | no |
| `sync.php` (the list of what could be copied) | yes | `block/coursesync:sync` | — | no |
| `sync.php?confirm=1` (the chosen activities) | yes | `block/coursesync:sync` | `require_sesskey()` | yes |
| `history.php` | yes | `block/coursesync:sync` | — | no |
| Block configuration form | yes | `moodle/block:edit` (Moodle); its connection fields are shown **and saved** only with `block/coursesync:configure` | moodleform | yes |

`block/coursesync:configure` (manager only by default, `RISK_CONFIG`) is
separate from `:sync` because the token usually reaches more than one course
on the source: choosing the site, token and course is choosing what this site
may read there. `:sync` (`RISK_XSS | RISK_DATALOSS`) runs syncs, and what a
sync may create is further held to the syncing person's own permissions for
each type - `course_allowed_module()` (enabled + `mod/<type>:addinstance`),
`moodle/course:manageactivities`, and `moodle/question:add` for types that
bring questions (`activity_handler::check_permission()`, final).

Only one run per block at a time: `syncer::run()` holds a `\core\lock` lock
and refuses a second run outright (`errorsyncinprogress`).

Every page also confirms that the block instance it was given actually belongs to
the course it was given, so an instance id from another course cannot be passed
in.

The list page makes an outbound request to the other site but writes nothing, so
it carries no sesskey; the form on it does. The chosen activity ids arrive as
`cmids[]` and are filtered against what the source reported for the mapped
course, so a selection can only narrow a run and never widen it. An id that was
not in that answer names nothing, and one that was - but was not offered, because
it is already here or of an unhandled type - still goes through the same
already-here and unsupported checks it always did.

Read-only pages do not carry a sesskey, which follows Moodle's convention for
report pages. `preview.php` is the one to think about, because viewing it makes
an outbound request to the other site — but it writes nothing, and the request it
makes is a read the caller is already entitled to perform.

**`confirm_sesskey()` returns a boolean; it does not throw.** Guarding an action
with `if (... && confirm_sesskey())` means a bad key silently does nothing.
Phase 7 replaced one such guard in `setup.php` with `require_sesskey()`.

### Web service functions

All five check `block/coursesync:sync` in the relevant context and call
`validate_context()`. Sesskey does not apply: these are token-authenticated.

| Function | Capability checked in |
| --- | --- |
| `block_coursesync_ping` | system context, or any one course (so the role can be assigned in a category) |
| `block_coursesync_get_course` | the course's context |
| `block_coursesync_get_modified_activities` | the course's context |
| `block_coursesync_get_activity` | the course's context |
| `block_coursesync_get_activity_file` | the course's context |

The capability is checked **before** `validate_context()` so a missing sync
permission is reported as that, rather than as the "course not accessible" that
`require_login()` raises.

`get_activity_file` additionally refuses any file area the activity's own handler
has not declared (`activity_handler::file_areas()`: the description's `intro`,
which every type has, plus `get_file_areas()`). Without that it would be a way to
read any file in any activity. An area may name a component other than the
activity's own - a workshop grading strategy's `workshopform_*` - and the
optional `component` parameter is checked against the same list, so it widens
nothing: it only reaches areas a handler already declared.

The destination applies the same rule the other way. `file_sync` stores a file
only if its own handler declares that component and area, so a source cannot
place a file in an area of its choosing by listing it.

#### Why the course context, and not the activity's

`get_activity` and `get_activity_file` validate the **course** context, not the
context of the activity being read. That is deliberate, and it was a change: they
originally validated the activity's own context.

`validate_context()` on a module context calls `require_login($course, false,
$cm)`, which also requires the account to be allowed to *view that particular
activity*. The capability for that differs by activity type. An unenrolled sync
account holding only `moodle/course:view` can read a page, a book or a folder,
but not an assignment, a quiz or a wiki. The effect was that
`get_modified_activities` offered all ten types and `get_activity` then refused
three of them with a message about course enrolment that had nothing to do with
the real cause — and the list of extra capabilities to grant would have grown
with every type added.

What authorises these calls is `block/coursesync:sync` on the course. That is a
capability an administrator grants to a purpose-made account, on a service that
ships disabled and with `restrictedusers => 1`. The account is being trusted to
copy the course's activities elsewhere; being able to read them is that same
trust, not a wider one.

What this does mean:

- An account with `block/coursesync:sync` on a course can read every activity in
  it, including ones hidden from students or restricted by access conditions.
  Do not grant this capability to a role that should not see the whole course.
- It does **not** widen anything else. The capability is still required, the
  service is still restricted to named users, `get_activity_file` still refuses
  undeclared file areas, and the module context is still what files are read
  from, so nothing outside the named activity is reachable.

`get_activity_test::test_a_sync_account_can_read_every_supported_type()` builds
an account with exactly the two documented capabilities and reads one of every
supported type; `test_an_account_without_the_sync_permission_is_refused()` is the
other half.

`qbank_handler` (a question bank's categories and questions) follows the same
rule rather than adding to it: no new capability was introduced for it,
because the core calls it makes to read and write question data do no
capability enforcement of their own — those checks belong to the question
bank's editing UI, which this handler never goes through. See
`DEVELOPER.md`'s "Security" section.

`quiz_handler`'s random-slot import is the one deliberate exception, and it
is unrelated to and does not widen anything documented above — that account
is never the one checked here. Building a random slot calls core's own
`mod_quiz\structure::add_random_questions()`, which itself requires
`moodle/question:useall` on the System Bank's context. That capability is
evaluated against the *destination* teacher's own session, exactly as it
would be if that teacher added a random question to the quiz by hand — the
`editingteacher` archetype already holds it by default at the course
context. Fixed-question slots add no check at all, same reasoning as
`qbank_handler` above. See `DEVELOPER.md`'s "Security" section.

## Outgoing requests

A destination site makes server-side requests to whatever address is stored, so
an address an attacker chooses is an attacker asking this server to make requests
for them.

Three layers:

1. **When the address is saved** — `remote_url::validate()` refuses non-HTTP(S)
   schemes, plain `http`, credentials in the URL, and any host that resolves into
   a loopback, link-local, private or carrier-grade-NAT range.
2. **Immediately before every request** — `remote_url::check_and_pin()`
   resolves and checks again, inside `remote_client::call()`, and returns a
   `CURLOPT_RESOLVE` entry so curl connects to **the address just checked**
   instead of resolving the name a second time (DNS rebinding). This is the
   layer that matters: a host can resolve differently after it was saved, and
   no save-time check can prevent that.

The ranges refused include IPv6 spellings of IPv4 addresses - IPv4-mapped
(`::ffff:a.b.c.d`, handled by Moodle's matcher), IPv4-compatible (`::/96`),
NAT64 (`64:ff9b::/96`, `64:ff9b:1::/48`) and 6to4 (`2002::/16`) - plus
multicast, reserved and broadcast. Documentation ranges are not refused.

**Redirects are never followed** (`allow_redirects => false`). The endpoint
is a fixed path; following a 307/308 would re-send the token to an unchecked
address, possibly over plain http. A 3xx is reported as `errorredirected`.
3. **Moodle's own** — `\core\http_client` runs `curl_security_helper` on every
   request, governed by the site's *cURL blocked hosts* and *cURL allowed ports*
   settings.

A host that does not resolve **is accepted at save time**. Refusing would make
saving a setting depend on DNS being up, and would buy nothing, because layer 2
checks again when it counts.

### The development override

```php
// config.php
$CFG->block_coursesync_allowprivateurls = true;
```

Relaxes the private-address rules in layers 1 and 2, and nothing else — `http`,
bad schemes and embedded credentials are still refused.

It is a config.php flag rather than an administration setting on purpose:
switching off a protection against the server being used to reach its own network
should need access to the server, not a checkbox that comes with any compromised
administrator account.

**Do not set it on a production site.**

## The token

| Question | Answer |
| --- | --- |
| At rest | Encrypted with `\core\encryption` (libsodium), key outside the database |
| In the UI | Entered into a `password` element, never set as form data, never rendered back. Only the last four characters are kept in the clear, as a hint |
| In logs | Never logged. `remote_client::redact()` strips anything token-shaped from transport error messages before they reach `debugging()` |
| In backtraces | Moodle's `format_backtrace()` prints `line N of file: call to Class::method()` with no arguments, so a token passed as a parameter does not appear. The plugin never calls `getTraceAsString()` |
| In transit | POST body over HTTPS with certificate verification; never in a URL |

## Remote content is untrusted

Everything from the other site is treated as hostile input, whoever owns that
site today.

`activity_payload::from_response()` is the single place a payload is built, and
cleans every field there so nothing downstream has to remember to:

| Field | Cleaned as |
| --- | --- |
| `name`, `idnumber` | `PARAM_TEXT` |
| `modname` | `PARAM_PLUGIN`, then matched against the handler registry |
| `cmid`, `sectionnum`, `timemodified` | `PARAM_INT`, floored at zero |
| `intro`, and any HTML setting | `clean_text()` for the declared format |
| `introformat` | restricted to the formats this site implements |
| file `filename` / `filepath` / `filearea` | `PARAM_FILE` / `PARAM_PATH` / `PARAM_AREA`; a file that does not survive cleaning is dropped |
| setting names | `PARAM_ALPHANUMEXT` |

Handlers use the cleaned accessors for anything that reaches a sensitive place:

- `setting_url()` for `mod_url`'s external address — `PARAM_URL` rejects
  `javascript:` and `data:`, which would otherwise be script running on this site
  chosen by the other one. A URL that does not survive is refused rather than
  stored.
- `setting_html()` for `mod_page`'s body and `mod_assign`'s extra instructions.
- `forum_handler::clean_type()` keeps the forum type to one this site implements,
  and `wiki_handler`, `quiz_handler` and `assign_handler` do the same for wiki
  mode and format, quiz navigation, overdue handling and question behaviour, and
  the assignment reopen method. Anything unrecognised falls back to a safe
  default rather than reaching the database.
- `wiki_handler::clean_first_page_title()` uses `PARAM_TEXT`: the title becomes a
  page title on this site.

### Content that is code, not content

Two fields are refused outright rather than cleaned, because there is no cleaning
that makes them safe.

**mod_data's `csstemplate` and `jstemplate`.** These are served by `css.php` and
`js.php` verbatim, as `text/css` and `application/javascript`, and mod_data loads
them into every page of the activity. A block of script is not markup with script
in it that can be stripped out - it *is* script. Accepting them from another site
would hand that site the ability to run code in a reader's browser on this one,
which is the single thing the rest of this document is about preventing. They are
dropped, and `syncdatacodetemplates` tells the teacher so.

**mod_lesson's password.** Not refused for injection reasons but for the same
reason a quiz's is not carried: it is a shared secret of the other site, and not
sending it keeps it out of requests, logs and responses on both. The difference
from the quiz is that a lesson with `usepassword` on and an empty password lets
anybody through, so the setting is turned off with it and
`synclessonnopassword` says so.

`more_handlers_test::test_data_code_templates_are_refused()` and
`test_lesson_password_is_not_carried()` pin both.

### Cleaning depends on what a field is for

`mod_feedback`'s `presentation` column is the case that shows why a single rule
does not work. What it holds depends on the question type:

- For a **label** it is a block of HTML written in an editor, and mod_feedback
  renders it with `'noclean' => true`. Nothing downstream will clean it, so it
  must be cleaned here.
- For a **multiple choice** it is the options packed into one string, separated
  by `|` after a marker such as `r>>>>>`. Running that through `clean_text()`
  turns the marker into `r&gt;&gt;...` and the question stops working.

So the label's is cleaned as HTML and the rest are put through `PARAM_NOTAGS`,
which removes markup without touching the separators; their contents reach the
page through `format_string()`, which escapes. Both halves are pinned by tests,
because getting either one wrong is silent: the first is a hole, the second is a
broken question.

The same reasoning applies to `mod_data`'s field parameters, which hold things
like a menu's options one per line. Those use `PARAM_NOTAGS` too.

### Child records

An activity that is not a single row — a book with chapters, an assignment with
its submission and feedback plugin settings — carries those as child records.
They are cleaned in `activity_payload::clean_children()` the same way settings
are: the record type and every field name through `PARAM_ALPHANUMEXT`, values
kept raw for the handler to interpret. Handlers then clean what they read:
`book_handler` puts chapter titles through `PARAM_TEXT` and chapter content
through `clean_html()`.

Several handlers write child records into their module's tables directly, which
is what those modules' own restore steps do. Each filters first: a feedback
question's type must be one mod_feedback has, a database field's type must be an
installed `datafield` plugin, a workshop's grading strategy must be an installed
`workshopform` plugin, and a lesson page's type must be one mod_lesson can
display. A record naming something this site does not have is dropped rather than
stored where nothing would read it back.

`assign_handler::restore_plugin_config()` is the one that writes remote values
into a table directly, which is what mod_assign's own restore does and is needed
because a subplugin's form field names and its stored setting names are unrelated.
It is filtered three ways before anything is written: the subtype must be one of
the two an assignment has, the plugin name must survive `PARAM_PLUGIN`, and the
plugin must actually be installed here. A row for a plugin this site does not
have is dropped rather than left in the table to come alive later.

### File areas addressed by a child record

`mod_book` stores each chapter's files under that chapter's id, so it cannot name
its item ids in advance. It declares `['filearea' => 'chapter', 'anyitemid' =>
true]`, which tells `get_activity_file` to authorise by area name alone.

This is the one place the file rule is looser, so it is worth being precise about
what it does and does not allow. It allows any item id **within that one declared
area of that one activity**, which is exactly the set of files that belong to the
book's chapters. It does not allow any other area of the same activity, and it
changes nothing for handlers that name an item id — both are pinned by
`book_files_test`. On the way in, `book_handler::map_file_itemid()` translates the
source's chapter id into the one created here, and a file whose chapter did not
arrive is dropped rather than filed against whichever local chapter happens to
hold that number.

`mod_lesson` does the same for three areas at once - `page_contents` keyed by
page, `page_answers` and `page_responses` keyed by answer - alongside `mediafile`,
which does name its item id. Its `map_file_itemid()` therefore dispatches on the
area, and returns null for any area it does not recognise rather than guessing.

### References between records

An activity that is more than one row usually has those rows pointing at each
other by id, and an id means nothing on another site. Four of these handlers have
to translate such references: a feedback question's dependency on another
question, a database's sort field, a rubric level's criterion, and a lesson's
page chain and answer jumps.

They all use the same mechanism on `activity_handler` - `remember_id()`,
`local_id()`, `mapped_id()` - and the same shape: create the records first,
remembering each pairing, then fix the references, because a reference can point
forward as easily as backward.

This is correctness rather than security, with one exception worth stating: a
reference that failed to translate must not be left pointing at a raw number from
the other site, because that number will quietly match some unrelated local
record. Every one of these resolves an unknown reference to a safe value -
nothing, or the next page - rather than passing it through. A lesson jump is the
sharpest case, since a wrong one sends a student somewhere the teacher did not
intend.

Values from `ping`, `get_course` and `get_modified_activities` are cleaned in
`remote_client` where they arrive.

**Output escaping:** core's notification template renders its message with
`{{{ message }}}`, which is **not** escaped. Any message built from remote data
must escape it — `ping_result::get_message()` and `course_result::get_message()`
do. Table cells use `s()`.

## Known limitations

- Embedded files in text fields are transferred only from declared areas; a
  link to anything else arrives broken, and the sync names the file.
- Hidden activities on the source are reported to the destination, and are read
  and copied. The capability check is the gate, not per-activity visibility — see
  "Why the course context, and not the activity's" above. The copy keeps the
  source's visibility, so a hidden activity arrives hidden.
- A quiz's password and IP restriction are deliberately never exported, so they
  do not exist in a request, a log or a response on either site. The copy is
  created without them and the teacher sets their own.
- Updating a copy can **delete** an activity with no capability beyond
  `block/coursesync:sync`, the same gate that lets a teacher create one. It is
  bounded three ways: only a copy this plugin itself made (the sync history
  must say so), only when nobody has data in it (its own privacy provider plus
  completion), and only when a teacher ticked it. Deletion goes through
  `course_delete_module()`, so the recycle bin keeps it where that is enabled.
  Since audit phase 26 a sync also needs `moodle/course:manageactivities` and
  the type's `addinstance`, so a role without them can no longer replace
  synced activities. A group override (assign/lesson/quiz) counts as data, so
  an activity with one is kept and the update arrives as a new edition.
- An External tool copy is only linked to a tool this site's administrator
  set up (matched by Moodle's own launch matcher), and that tool's privacy
  settings override what the activity asked to send. So a source cannot
  direct this site's students' details to a service of its choosing. Tool
  keys, secrets and service salts are never exported. `lti_add_instance()`
  may request the tool address to check for a cartridge; by then the address
  has matched an approved tool's domain.
- A BigBlueButton copy gets fresh meeting credentials from this site; the
  source's meeting id and moderator, viewer and guest passwords are never
  exported, and participant rules naming a source user are dropped.
- A SCORM or IMS package from the source is unpacked on this site with
  Moodle's own zip packer, exactly as an uploaded package is, and served by
  the module's own pluginfile rules. Its content is as trusted as a package a
  teacher uploads; nothing about it is cleaned, as nothing is for an upload.
- A source site is trusted not to send an enormous file. `file_sync::MAX_CHUNKS`
  caps a single file at 1 GB. Pieces are streamed to a temporary file, not
  held in memory, and the source reads each piece with a seek rather than
  loading the whole file.
- A question's own files (its text, feedback, answers) do not go through
  `file_sync` at all — `qbank_handler` relies on `qformat_xml` inlining them
  as base64 inside each question's own XML, so `file_sync::MAX_CHUNKS`'s cap
  does not apply to them. A question bank with unusually large embedded
  images produces one correspondingly large `get_activity` response instead
  of many small file transfers.
