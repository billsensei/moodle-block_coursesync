# Course Sync block (block_coursesync)

Pull newly-added activities from a course on a different Moodle site into a
course on this site.

**Status: complete (phase 8 of 8).** All five v1 activity types sync end to end —
Page, URL, Label, Resource (including its uploaded files) and Forum (activity
settings only). Anything already in the destination course is flagged for review
rather than overwritten or duplicated, every run is written to a sync history the
teacher can read, and the plugin has been through a security hardening pass.

## How it fits together

Moodle core has no general service for handing activity content between sites,
so Course Sync brings its own. That means the plugin is installed on **both**
sites and behaves differently at each end:

| Role | What it does |
| --- | --- |
| **Source** | Exposes the Course Sync external service. Needs web services enabled and a token issued to a sync account. |
| **Destination** | Holds the block, stores the source's address, token and course mapping, and makes the calls. |

The service contains five functions, all read-only:

| Function | Returns |
| --- | --- |
| `block_coursesync_ping` | Site name, Moodle release, installed plugin version |
| `block_coursesync_get_course` | A course resolved from an id or shortname, with its activity count |
| `block_coursesync_get_modified_activities` | Activity metadata for changes after a given time: course module id, type, name, ID number, modification time |
| `block_coursesync_get_activity` | Everything needed to rebuild one activity: the common envelope, type-specific settings, and a list of its files |
| `block_coursesync_get_activity_file` | One chunk of one file belonging to an activity, base64 encoded |

The same code runs at both ends; which role a site plays depends only on how it
is configured.

## Documentation

| For | Read |
| --- | --- |
| Administrators installing it | [docs/INSTALL.md](docs/INSTALL.md) |
| Administrators setting up the source site | [docs/REMOTE_SETUP.md](docs/REMOTE_SETUP.md) |
| Teachers using it | [docs/TEACHER_GUIDE.md](docs/TEACHER_GUIDE.md) |
| Developers extending it | [docs/DEVELOPER.md](docs/DEVELOPER.md) |
| Reviewing the security posture | [docs/SECURITY.md](docs/SECURITY.md) |
| Running a first pilot | [docs/PILOT_CHECKLIST.md](docs/PILOT_CHECKLIST.md) |

## Requirements

* Moodle 5.1 (2025100600 or later)
* PHP 8.2+
* HTTPS on the source site. The destination refuses to store a plain `http://`
  address, so a token can never be sent unencrypted.

## Installation

Copy this directory to `public/blocks/coursesync` in your Moodle 5.1 docroot on
both sites, then run the upgrade on each:

```bash
php public/admin/cli/upgrade.php --non-interactive
```

## Setting up a connection

On the destination site, add the block to a course and choose **Set up the
connection**. The wizard has three steps: the source site's address, the manual
steps to perform on the source site, and a connection test.

The source-site steps are also written out in [docs/REMOTE_SETUP.md](docs/REMOTE_SETUP.md),
for when the person doing that work is not the person using the block.

Course Sync never changes settings on the other site for you. Granting one site
that much control over another is a much larger trust decision than a sync
needs, so those steps are deliberately manual.

## Capabilities

| Capability | Context | Default |
| --- | --- | --- |
| `block/coursesync:addinstance` | Block | editingteacher, manager |
| `block/coursesync:sync` | Course | editingteacher, manager |

`block/coursesync:sync` does two jobs. On the destination it gates the setup
wizard, the change preview and, later, running a sync. On the source it is what
the token's account must hold for the service to answer — at system level, or in
a single course if you want to limit which courses can be pulled from.

The sync account on the source also needs `webservice/rest:use` and
`moodle/course:view`. The second is easy to miss: Moodle's `require_login()`
runs for every course-scoped call, and a service account is never enrolled in
the courses it reads.

## How the token is stored

The token is encrypted with `\core\encryption` (libsodium `crypto_secretbox`),
Moodle's supported secret storage. The key lives outside the database, in the
site's secret data directory, created automatically with restricted permissions.

Two things follow from that:

* A database dump on its own does not reveal the token.
* A database restored somewhere without that key file cannot decrypt it. The
  block reports this plainly and asks for the token again rather than failing
  in a way nobody can interpret.

Only the last four characters are kept in the clear, so an administrator can
tell which token is stored without being able to reconstruct it.

## Database

| Table | Purpose |
| --- | --- |
| `block_coursesync_connection` | One row per configured block instance: the remote address, the encrypted token, the mapped remote course, the result of the last connection test, and when the block last synced. |
| `block_coursesync_run` | One row per sync run: when, who started it, what it copied, what it flagged, and the overall outcome. |

Because the run table records who started each sync, this plugin holds personal
data and implements a full privacy provider. It was a `null_provider` up to phase
5, when it genuinely stored none.

`lastsync` is null until a real sync happens. Phase 3 reads it to decide what to
ask for, but never writes it: marking activities as seen before anything has
been copied would hide them from the first genuine run.

## Adding an activity type

Activity types plug in through one class. To add support for, say, mod_book:

1. Create `classes/local/handler/book_handler.php` extending `activity_handler`.
2. Implement `get_modname()`, `export_settings()` and `create_from_remote_data()`.
3. If the type keeps files, also implement `get_file_areas()`.
4. Add the class to `handler_registry::HANDLERS`.

Nothing else changes. The external function, the syncer, the block UI and the
language strings are all type-agnostic — they ask the registry what is supported
rather than naming types themselves. `page_handler` is the worked example, and
`activity_handler` carries the full contract in its docblock.

The payload that travels between sites is a fixed envelope (name, intro, section,
visibility) plus a flat map of type-specific settings. That is what lets one
external function carry any activity type without its return structure changing
when a type is added.

### Supported types

| Type | Notes |
| --- | --- |
| Page | Content, description and display options |
| URL | Address, display options and variable substitutions |
| Label | Text only; Moodle derives the name from it |
| Resource | Settings plus the uploaded files themselves |
| Forum | Activity settings only — no discussions or posts |

### Files

A handler that declares a file area in `get_file_areas()` gets its files carried
across without writing any transfer code. Declaring an area is also what
authorises reading it: the source refuses to serve a file that is not in an area
its own handler declared, so the file function cannot be used to read anything
else.

Files travel a chunk at a time through `block_coursesync_get_activity_file`,
rather than through Moodle's `webservice/pluginfile.php`. That endpoint would
serve anything the token's user can reach, and would need file downloading
enabled for the whole service; this keeps the service's permissions narrow and
avoids holding a whole file in memory at either end.

Every file is checked against the SHA1 the source reported before it is stored.
A transfer that does not match is refused and the activity is removed rather
than left with missing or corrupted content.

### Forum grading and scales

`forum.scale` is site-local: a positive number is a maximum point score and means
the same anywhere, but a negative number is minus the id of a row in that site's
scale table. The source sends the scale's **name** alongside the value, and the
destination matches by name. If no scale of that name exists locally the forum is
created ungraded and the sync says so, rather than attaching whatever scale
happens to hold that id.

## What a sync does

**Sync now** asks the source what has changed since `lastsync`, then for each
activity:

| Situation | What happens |
| --- | --- |
| No handler for that type | Skipped, and reported as skipped |
| Something already carries this identity | **Flagged for review.** Never overwritten, never duplicated |
| New, with a handler | Full payload fetched and the activity rebuilt locally |

### Conflicts

A conflict is anything the destination course already holds under the identity a
remote activity would claim. Course Sync never resolves one by itself: it leaves
the local activity exactly as it is, creates nothing, and records what it found.

There are two kinds, told apart by looking in the sync history:

| Kind | Means |
| --- | --- |
| Changed on the other site | An earlier run copied this here, and it has since changed at the source |
| Already in this course | Something carries that identity which Course Sync did not put there |

Conflicts do **not** hold the last synced marker back. Holding it would re-flag
every previously copied activity on the next run. The consequence is that once a
conflict has been dealt with by hand, the ordinary sync will not offer that
activity again — use **Check everything again** on the sync page, which ignores
the marker and looks at the whole course.

### Sync history

Every run is written to `block_coursesync_run`, including runs that did nothing
and runs that could not start, so the history answers "was this tried?" as well
as "what did it do?". **View sync history** on the block shows them newest
first, each expandable to what was copied, what was flagged, and what else was
considered.

A synced activity is stamped with an ID number of the form `coursesync-<remote
course module id>`, which is how the next run recognises it. That is a generated
marker rather than the source's own ID number: source activities usually have
none, and one that does could collide with something already in the destination.

`lastsync` moves forward **only if nothing failed**. A partial failure leaves it
where it was, so the activities that did not make it are tried again next time
rather than being silently skipped forever.

### Not copied yet

Embedded files are not transferred. A page whose content refers to
`@@PLUGINFILE@@` is still created, but the sync says plainly that those links
will not resolve.

## Change detection

`course_modules` has no modification time of its own, only `added`, so the time
comes from each activity's own table. Those are read one activity type at a
time rather than one activity at a time. An activity type whose table has no
`timemodified` column — some third-party ones do not — falls back to when the
activity was added to the course.

"Since X" is exclusive: an activity whose modification time is exactly X is not
reported. That keeps a repeated call from returning the same items forever once
`lastsync` starts being written.

## Development

```bash
# Static analysis and unit tests
moodle-plugin-ci phplint ./
moodle-plugin-ci codechecker ./
moodle-plugin-ci phpmd ./
moodle-plugin-ci phpunit -m /path/to/moodle ./
moodle-plugin-ci behat  -m /path/to/moodle ./
```

The cross-site call itself cannot be unit tested honestly — TLS verification and
Moodle's outgoing-request rules only mean something against a real server — so
`remote_client` accepts an injected HTTP client for tests, and the real path is
exercised between two live sites.

## License

GNU GPL v3 or later.
