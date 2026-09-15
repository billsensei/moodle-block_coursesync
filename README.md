# Course Sync (`block_coursesync`)

A Moodle block that lets a teacher pull activities from a course on
**another** Moodle site into a course on **this** site, without recreating
them by hand.

Add the block to a destination course, point it at a source site and course,
and click **Sync now**: it lists what's new or changed since the last sync,
pulls full content for the activity types it supports, and recreates them
locally — flagging anything that would collide with an existing activity as
a conflict rather than touching it. Nothing is ever written back to the
source site.

## Important Information

**This was created with the help of Claude Code! Also, this is a project that is in the early stages and this is unstable code that should only be used in a sandbox environment. Use at your own risk!**

> **Status:** v0.11.0 (Phase 11), maturity `BETA`. Requires Moodle **5.2**
> (`2026042000`) or later.

## What it does

- **Change detection** — lists activities modified on the mapped remote
  course since the last successful sync (or all of them, the first time).
- **Selective pull** — recreates activities of the types it currently
  supports; other types are detected and shown in the preview but skipped
  until a handler is added for them.
- **Conflict-safe** — an activity that would collide with one already in the
  destination course (matched by idnumber) is flagged as a conflict and left
  alone, never overwritten or duplicated. A same-type-same-name match
  against any existing activity is treated as already present and silently
  excluded.
- **Sync history** — every run (success, partial, or precondition failure)
  is recorded and viewable from the block's "View sync history" link.
- **One plugin, two roles** — the same code is installed on every site.
  A site acts as **source** the moment another site's block calls its web
  services; it acts as **destination** wherever a teacher has added and
  configured a block instance. The same site can be both at once.

### Supported activity types

Page, URL, Label, File (Resource), Forum (settings only — no discussions or
posts, and the built-in "Announcements" forum is never pulled), Assignment
(settings only — submissions, grades, and feedback never leave the source
site), H5P (including its content package), Glossary (including its
approved entries, attachments, aliases, and categories — an entry still
awaiting moderation on the source isn't pulled), Quiz (including its
questions — both fixed questions and "Add > a random question" slots pulled
from a question-bank category — Multiple choice, True/False, Short answer,
Numerical, Essay, Matching, Description, and Cloze/multianswer; other
question types are detected but not yet pulled — see
[DEVELOPER_NOTES.md](DEVELOPER_NOTES.md)), Wiki (every page's current
content — no revision history — from the course-wide wiki and any group
wikis, including every image/audio/video/other file attached to those
pages; an individual student's own personal wiki pages are never pulled,
the same submissions-stay-on-the-source cut as Assignment — see
[DEVELOPER_NOTES.md](DEVELOPER_NOTES.md)), Choice/Feedback (the
choice's options / the feedback's questions themselves, always; who
answered what never leaves the source site — an optional per-block-instance
setting adds an anonymised aggregate response summary, total counts and
per-option/question tallies only, as a read-only note on the synced
activity, never as real responses — see
[DEVELOPER_NOTES.md](DEVELOPER_NOTES.md)), and Book (every chapter,
including hidden ones, in order, with any embedded image/other file — no
chapter tags).

## How it works, briefly

Everything that crosses the network goes through four web service
functions, defined in `db/services.php`, bundled into one "Course Sync"
external service:

| Function | Purpose |
|---|---|
| `block_coursesync_ping` | Proves the connection + token work; returns the site name. |
| `block_coursesync_check_course` | Resolves a course ID/shortname on the source and confirms the token can access it. |
| `block_coursesync_get_modified_activities` | Lists a course's activities modified after a given time — metadata only, no content. |
| `block_coursesync_get_activity_content` | Returns one activity's full settings/content payload. |

`classes/local/remote_client.php` is the only destination-side code that
speaks the wire protocol. Everything after the data is pulled — conflict
detection, activity creation, sync history, the block's UI — runs entirely
on the destination site; the source site is never written to.

For the full architecture, the source/destination service boundary, and how
to add support for a new activity type, see
**[DEVELOPER_NOTES.md](DEVELOPER_NOTES.md)**.

## Documentation

| Guide | Audience |
|---|---|
| **[INSTALL.md](INSTALL.md)** | Site administrator — installing the plugin on both sites. |
| **[REMOTE_SETUP.md](REMOTE_SETUP.md)** | Site administrator — one-time setup of the *source* site's web services. |
| **[TEACHER_GUIDE.md](TEACHER_GUIDE.md)** | Teacher — adding the block, connecting it, running a sync. |
| **[DEVELOPER_NOTES.md](DEVELOPER_NOTES.md)** | Developer — architecture, extension points, testing. |

## Requirements

- Moodle **5.2** or later, on both the source and destination site (they
  don't need to match exactly, just both be 5.2+).
- **HTTPS** on the source site — the destination block refuses a plain-HTTP
  or private/loopback remote URL by default (SSRF hardening; see
  `classes/local/url_safety.php`). A "development/testing connection"
  checkbox lifts this for local test setups only.
- Outbound HTTPS from the destination site's web server to the source site.

## Installation

```bash
cd /path/to/moodle/public/blocks
git clone <this repository's URL> coursesync
```

then trigger the upgrade (`php admin/cli/upgrade.php --non-interactive`, or
visit *Site administration* in a browser). See **[INSTALL.md](INSTALL.md)**
for the ZIP-upload alternative and the two capabilities
(`block/coursesync:addinstance`, `block/coursesync:sync`) this plugin
defines.

## Security

- Remote URLs must be HTTPS and are rejected if they resolve to a
  loopback/link-local/private address, unless explicitly overridden for a
  dev/testing connection.
- The access token is encrypted at rest (`\core\encryption`) and is never
  redisplayed once saved.
- Every remote-sourced field is sanitised before storage, and any
  remote-sourced text this plugin displays is escaped at output time.

See **[DEVELOPER_NOTES.md](DEVELOPER_NOTES.md)**'s "Security posture"
section for details.

## Testing

- **PHPUnit** (`tests/`): change detection, all activity handlers, conflict
  detection, lastsync advancement, token encryption, history rendering.
- **Behat** (`tests/behat/`): one end-to-end scenario covering the full
  teacher-facing flow.
- `moodle-plugin-ci parallel` is the full local gate (phpdoc, phplint,
  phpcpd, phpmd, Moodle codechecker, plugin validation, mustache lint,
  grunt, phpunit, behat) and should be run against a Moodle 5.2 checkout
  before every release.

## What it deliberately doesn't do (v1)

- No automated configuration of the remote site — the one-time source setup
  in REMOTE_SETUP.md is manual.
- Only the registered activity types are pulled; others are detected but
  skipped, not treated as failures.
- A flagged conflict has no in-block resolution UI — a teacher resolves it
  manually (rename/remove the local activity) and syncs again.

## License

GNU GPL v3 or later, consistent with Moodle core — see the license header in
each source file.
