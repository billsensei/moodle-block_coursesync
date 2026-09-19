# block_coursesync

A Moodle 5.1 block plugin that lets a teacher or manager pull new or updated
activities into the current course from a course on another Moodle site, over
the Moodle Web Services REST API.

## Status

**Phase 6 — checking before syncing.** The first five phases are in: Phases
1–4 built the feature set, and Phase 5 added the test suite, an offline seam
so no test needs a second live Moodle, a wider CI matrix, and a written
[security review](SECURITY.md). Phase 6 adds **Check now**, so a teacher can
see what a sync would bring in before starting one, and choose which of it to
take.

Still `MATURITY_ALPHA`: it has been exercised across two real sites by hand
and by the suite below, not in production.

## Build plan

1. **Phase 1 — Scaffolding** (done): plugin skeleton, capabilities,
   two-instance Moodle test environment, CI.
2. **Phase 2 — Configuration** (done): per-instance settings
   (`edit_form.php`) for the remote site connection — URL, encrypted Web
   Services token, source course — plus the guided setup instructions and
   the connection/course checks.
3. **Phase 3 — Sync engine** (done): custom external functions wrapping
   backup/restore, the pull ledger and audit log, change detection and
   conflict handling, driven by an ad-hoc task.
4. **Phase 4 — UI** (done): block content, the manual "sync now" trigger, the
   pull history, and conflict resolution.
5. **Phase 5 — Tests, CI and security** (done): PHPUnit and Behat
   coverage against a scripted remote, a wider CI matrix, and a security
   review written up in [SECURITY.md](SECURITY.md).
6. **Phase 6 — Check before syncing** (this phase): a "Check now" button
   listing the activities a run would bring in, each one selectable, so a
   sync is never a leap in the dark and never has to be all or nothing.

## Requirements

- Moodle 5.1+ (uses the `public/` web root introduced in MDL-83424)
- PHP 8.2+

## Capabilities

| Capability                     | Purpose                          | Default archetypes             |
|---------------------------------|-----------------------------------|---------------------------------|
| `block/coursesync:addinstance`  | Add the block to a course        | editingteacher, manager         |
| `block/coursesync:trigger`      | Trigger a course sync            | editingteacher, manager         |
| `block/coursesync:viewhistory`  | View sync history                | editingteacher, manager         |

Configuring a connection, and running the test/validate checks, requires
**both** `:addinstance` and `:trigger` in the block context. The form and
both AJAX actions enforce this independently.

## Connecting to a remote site

Moodle's REST protocol authenticates with a static token that an
administrator creates against an external service. Moodle deliberately
exposes no web service function for creating either — that would be a
privilege escalation path — so this plugin cannot provision anything on the
remote site. The block's configuration form instead carries a checklist to
hand to the remote site's administrator, and then verifies whatever domain
and token come back:

- **Test connection** calls `core_webservice_get_site_info` and reports the
  remote site's name and version, plus any required function the service is
  missing.
- **Validate course** calls `core_course_get_courses_by_field` and confirms
  the entered ID or shortname resolves to exactly one course, showing its
  full name.

Both also run server-side when the form is saved, so the stored
"last validated" timestamp always reflects a check this server made itself
rather than something the browser claimed.

### How the token is stored

The token is encrypted with `\core\encryption` before it is written to
`block_instances.configdata`, which is otherwise plain serialised data
visible to anyone with database or backup access. It is never sent back to
the browser: the form's token field stays empty once a token is saved, and
leaving it empty keeps the stored one.

A stored token stands in for a blank field only while the connection still
points at the site that issued it. Aim the block at a different URL and the
old token is discarded rather than sent there — otherwise a second editing
teacher could point the connection at a server of their own and read a
credential they were never given. See [SECURITY.md §2.4](SECURITY.md).

### Outbound request safety

The remote site URL is entered by a teacher and then requested by the
server, which makes it an SSRF surface. Every URL must be `https`, must not
embed credentials, and must resolve only to addresses outside the private,
loopback, link-local and reserved ranges. The addresses that passed
validation are pinned via `CURLOPT_RESOLVE` so the host cannot resolve to
something else between the check and the request, redirects are not
followed, and the site's own `curlsecurityblockedhosts` still applies on top.

For local testing against sites that are plain http on a private network,
set `$CFG->block_coursesync_allowinsecureremotes = true;` in `config.php`.
This lifts the scheme and address-range rules and must never be set in
production.

## How a sync works

The plugin is installed on both sites, so it defines its own web services
rather than working from generic core metadata. A run is a pull: the local
site asks, decides, and fetches. The remote is never asked to push, and the
service it exposes grants no write access.

1. `block_coursesync_list_activities` returns every activity in the shared
   course with a change signal (see below).
2. The planner compares that against the pull ledger and the current state of
   the local copies, and classifies each activity as new, updated, unchanged,
   conflict or skipped.
3. For new and safely-updated activities, `block_coursesync_backup_activity`
   runs a single-activity backup (`backup::TYPE_1ACTIVITY`, the mechanism
   behind "duplicate activity"), parks the `.mbz` in a file area, and returns
   where to fetch it. The local site downloads it through
   `webservice/pluginfile.php` and restores it with
   `backup::TARGET_CURRENT_ADDING`, then moves it into the section it occupies
   remotely (see below).

Each activity is attempted independently: a network error, a permission
problem on the remote, or a restore failure is logged against that one
activity and the run carries on. The ledger is written only after a pull
actually succeeds, so a failed run leaves the last known-good state intact.

Runs go through an ad-hoc task (`\block_coursesync\task\sync_course`) because
backup and restore of several activities is far too slow to hold a page open.
`engine::run()` is equally callable synchronously, which is what the tests do.

### Where a pulled activity lands

A restore of a single activity drops it wherever the backup's own section
data lands, which is rarely right for the course being pulled into. So every
pulled activity is moved into the section number it occupies on the remote
site: what was in week 7 there is in week 7 here.

A course being pulled into is often shorter than the one it pulls from, so
the sections up to that number are created — the same thing Moodle does when
restoring a course into a shorter one. Two limits apply. The site's own
`$CFG->maxsections` is respected: beyond it the activity is left where the
restore put it rather than growing a course past what the site allows. And
module types that Moodle never shows on the course page, such as the
question bank, stay in the general section, because moving those anywhere
else is an error.

An activity inside a subsection is a special case. A subsection owns a course
section of its own, delegated to that module, and its section number means
nothing to a course with no such subsection. The remote site therefore
reports such an activity against the ordinary section the subsection sits in,
so the copy lands in the right part of the course even though the subsection
itself is not recreated.

### Change signals

Moodle has no universal content hash across module types, so
`activity_signature` uses the module's own `timemodified` column where it has
one, and otherwise a sha1 of the module instance record with the columns that
move on their own removed. Which method produced a value is recorded next to
it. A signal is only ever compared with an earlier signal from the same site,
so it does not need to be stable across sites or versions.

Signals describe the module instance only. Hiding an activity or moving it
between sections deliberately does not count as a change, so ordinary tidying
up on either side does not raise conflicts.

### Conflicts

Nothing is overwritten when both sides have moved. On each run the block
compares the remote signal against the one recorded at the last pull, and the
local copy's signal against the one recorded immediately after that pull:

- changed remotely, local copy untouched → replaced with the remote version
- changed remotely, local copy also edited → **conflict**, nothing is touched
- changed only locally → left alone
- an incoming activity whose name or id number is already taken by an activity
  this block did not create → **conflict**, so a pull can never quietly shadow
  a teacher's own work

A conflict is recorded in both tables and leaves the ledger's last-good
reference points intact, so resolving it later does not lose the baseline.
Replacing an activity restores the new copy first and deletes the old one only
once that has succeeded; note that replacement means replacement, so any
grades or submissions attached to the old copy go with it.

### Tables

- `block_coursesync_pulls` — the live ledger, one row per (block instance,
  remote course module), holding where it landed locally, the signals seen at
  the last successful pull, and the current status.
- `block_coursesync_log` — append-only audit trail, one row per activity per
  run, grouped by run id. This is what Phase 4's history will read.

## Using it

The block shows the course it pulls from, how many activities are in step,
how many need review, and when the last run finished. Below that it offers
three things to do: **Check now**, **Sync now** and **View history**.

**Check now** (needs `block/coursesync:trigger`) asks the remote course what
it holds and lists what a sync would bring in — each activity marked *New*
when this course has no copy of it, or *Changed* when the remote copy has
moved on since it was pulled. The new ones come first, since they are usually
what a teacher is looking for; within each group the remote course's own
order is kept. It is a question, not an action: nothing is
transferred, nothing is written to the ledger or the history.

Every activity in that list has a checkbox, ticked to begin with, and the
list carries four buttons of its own:

| Button | What happens |
|---|---|
| Select all | Ticks every activity. |
| Select none | Clears every activity. |
| Sync now | Queues a run carrying over only the ticked activities. |
| Cancel | Drops the list and returns to the course page, having synced nothing. |

While that list is on show, the whole-course **Sync now** below it is hidden,
so there are never two buttons of that name meaning different things: the
list's own button is the one that acts, and it acts on what is ticked. With
nothing ticked, it reports that and starts no run rather than falling back to
syncing everything.

It reads the remote course and plans against it through exactly the same code
a run uses, so what it lists is what Sync now would carry out. Activities a
run would leave alone are left out: ones already in step, ones held back for
review (the conflicts alert covers those), and ones whose module type cannot
be backed up.

The answer is kept in a cache keyed by block instance, so the list survives a
page reload without every course page render calling out to another site.
Anything that changes what a run would do — a run itself, a resolved
conflict, or the connection being pointed somewhere else — throws it away
rather than showing something that is no longer true.

**Sync now** (the one beside Check now, for the whole course) is shown to
anyone with `block/coursesync:trigger`. It queues the
ad-hoc task rather than holding the page open, and the block then reports
"Sync in progress" and polls until the run finishes, at which point the page
refreshes itself. Nothing is lost if the browser is closed: the run carries
on, and the next page load shows the result.

**View history** (`history.php`, needs `block/coursesync:viewhistory`) lists
runs newest first, ten per page, each expanding to the activities that run
touched and what happened to them. Activities that still exist link to
themselves.

**Conflicts** (`conflicts.php`, needs `block/coursesync:trigger`, because
resolving one changes course content) lists what the engine held back, with
why it was held back and when each side last changed. Activities this course
has no copy of are listed first — one that never arrived because something
else was in its way — then the ones that are here already and have diverged.
Three choices per activity:

| Choice | What happens |
|---|---|
| Keep this course's version | Nothing is transferred. Both sides' current state becomes the new baseline, so later runs stop reporting it. |
| Take the remote version | The transfer the run held back is carried out: the remote copy replaces the local one. |
| Decide later | Nothing changes; it stays flagged. |

All three are sesskey-protected POSTs, re-check the capability, and write
their own entry to the audit log, so the history shows who decided what.

"Take the remote version" is offered for every conflict, including a name
collision, where the activity it replaces is one this block did not create.
That case is asked about first: the page says what will be deleted and takes
a confirmation before doing anything. Two details make it safe to offer at
all. The colliding activity is deliberately never recorded in the ledger — a
later run must not mistake someone else's work for a copy of the remote one —
so the activity in the way is looked for again, by the same rule, at the
moment the replacement happens; if it has since been renamed or removed,
nothing is deleted and the pull is simply a first pull. And the old activity
is only dropped once its replacement exists, so a transfer that fails part
way leaves the course exactly as it was.

## License

GPLv3 or later — see [LICENSE](LICENSE).

## Development environment

Two independent Moodle 5.1 instances (`site-a`, `site-b`), run via
[moodle-docker](https://github.com/moodlehq/moodle-docker), on a shared
Docker network so they can reach each other by hostname for cross-site
sync testing in later phases. Following this repo's own convention of
keeping the Moodle codebase out of the plugin repo (see AGENTS.md), the
docker setup and the two Moodle core checkouts live in sibling directories,
not inside this repo:

```
~/dev/moodle/
├── block_coursesync/   # this repo
├── docker/             # moodle-docker checkouts for site-a and site-b
└── moodle-core/        # Moodle 5.1 core checkouts for site-a and site-b
```

See [`../docker/README.md`](../docker/README.md) for how to bring the two
instances up/down.

## Tests

No test talks to a network, and no test stands up a second Moodle. The
outbound HTTP layer sits behind `\block_coursesync\local\http\transport`,
with three implementations chosen by `transport_factory`:

- `curl_transport` in production — and it is the only place the SSRF guards
  live, so a test can replace the network without replacing them;
- a `fake_transport` installed directly by PHPUnit
  (`transport_factory::set_for_testing()`, which refuses to run outside
  `PHPUNIT_TEST`);
- the same `fake_transport`, under Behat, scripted through plugin config,
  because the step that arranges the remote runs in a different process from
  the page under test.

DNS is substitutable the same way: `url_validator::set_resolver_for_testing()`
drives host resolution so the address rules can be tested against a name that
resolves publicly when checked and privately when requested — the rebinding
case that cannot be provoked with real DNS.

**PHPUnit** covers the diffing logic against constructed remote listings and
real ledger rows (including the two conflict paths — a local copy edited
independently, and a name or id number collision — as separate cases), token
encryption round-trips, token scoping to the site that issued it, the URL
rules, and the capability checks on both web service functions and all three
conflict actions.

**Behat** covers the Phase 4 flows against a scripted remote: the setup
prompt, a configured block, a student seeing nothing, the history, a conflict
being held back, and keeping the local version or deferring the decision.
Every scenario is non-JavaScript.

The two moodle-docker instances stay what they were: the manual,
exploratory cross-site environment, not a CI target.

## CI

Quality checks run via
[moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci). It's
installed via `composer create-project`, as a sibling of this repo
(`../moodle-plugin-ci`), not as a dev dependency inside this repo — its
bundled binaries (`phpcs`, `phpmd`, ...) locate themselves via paths
hardcoded relative to its own package directory, which only resolve when it
is the Composer project root, not when nested inside another project's
`vendor/`.

- **Locally**: `ci/run-local-ci.sh` (add `--with-behat` to also run Behat;
  add `--reinstall-ci` to refresh the sibling moodle-plugin-ci install).
  Behat is opt-in because `--start-servers` pulls a Selenium image even
  though every scenario here is non-JavaScript; the local run uses the
  default Firefox profile, whose image has arm64 builds, while GitHub
  Actions uses Chrome on amd64.
- **GitHub Actions**: `.github/workflows/ci.yml`, on every push and pull
  request. Six blocking jobs: PHP 8.2/8.3/8.4 × pgsql/mariadb against
  `MOODLE_501_STABLE`, each running phplint, phpcs, phpdoc, validate,
  savepoints, mustache, grunt, phpunit and behat. phpmd is advisory.

  There is a seventh, non-blocking job against `MOODLE_502_STABLE` as an
  early signal for the next release. There is deliberately **no** job for
  the previous stable release: `version.php` requires `2025100600`, so
  Moodle 5.0 refuses to install this plugin at all, and a green 5.0 job
  would mean nothing.

## This repository's agent prompts

This repository is based on the
[momopda](https://github.com/wilenius/momopda) prompt template for
AI-assisted Moodle plugin development. See [AGENTS.md](AGENTS.md) for the
guide a coding agent should follow when working on this plugin.
# moodle-block_coursesync
# moodle-block_coursesync
# moodle-block_coursesync
