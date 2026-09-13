# Course Sync: setting up the remote (source) site

This block connects two Moodle sites: a **destination** site (where you add the
Course Sync block, configure it, and pull activities into a course) and a
**source** site (where the activities currently live).

Moodle core has no built-in web service for this, so block_coursesync defines its
own, all bundled into one ready-made "Course Sync" external service that appears
automatically once this plugin is installed on the source site:

- `block_coursesync_ping` (Phase 2) - just proves the connection works.
- `block_coursesync_check_course` (Phase 3) - resolves a course ID or shortname
  and confirms the token can access it, for course mapping.
- `block_coursesync_get_modified_activities` (Phase 3) - lists a course's
  activities modified after a given time. Metadata only (course module id,
  activity type, name, idnumber, when it was last modified) - no content.
- `block_coursesync_get_activity_content` (Phase 4) - returns the full
  settings/content payload needed to recreate one activity, for activity
  types this plugin has an exporter for: **Page, URL, Label, Resource, and
  Forum** (settings only) as of Phase 5, plus **Assignment** (settings only)
  and **H5P** as of Phase 9. For Resource and H5P, this includes the
  underlying file(s), base64-encoded in the same payload.

Do this once, on the **source** site, as a site administrator:

1. Log in to the REMOTE (source) site as a site administrator.
2. Go to **Site administration ▸ General ▸ Web services ▸ Overview**, and enable
   web services if they are not already enabled.
3. Go to **Site administration ▸ Plugins ▸ Web services ▸ Manage protocols**, and
   enable the **REST** protocol.
4. Go to **Site administration ▸ Plugins ▸ Web services ▸ External services**.
   Find **"Course Sync"** in the list - it appears automatically once this plugin
   is installed there, you don't need to create it - click **Edit**, tick
   **Enabled**, and Save.
5. Still on that service's page, click **Authorised users** and add the account
   that will act as the sync account. This can be a dedicated account created for
   this purpose, or an existing admin/teacher account.
6. Make sure that account's role grants the **"Trigger a manual course sync"**
   (`block/coursesync:sync`) capability. For example:
   - **Site administration ▸ Users ▸ Define roles**: allow `block/coursesync:sync`
     on an existing role, or create a new one for this.
   - **Site administration ▸ Users ▸ Permissions ▸ Assign system roles**: assign
     that role to the sync account.

   This has to be a **system-level** role assignment. The ping function has no
   particular course to check the capability against, so it checks at system
   context - a course- or block-level assignment alone won't be picked up.
7. Go to **Site administration ▸ Plugins ▸ Web services ▸ Manage tokens**, and
   create a new token for that account and the **Course Sync** service.
8. Copy the generated token.
9. On the **destination** site, add or edit a Course Sync block, and paste this
   site's full HTTPS address and the token into the fields there.

## How "Sync now" works

Clicking **Sync now**:

1. Lists activities modified since `lastsync` (never, the first time).
2. Of those, pulls full content for the ones whose type is currently
   supported - **Page, URL, Label, File (Resource), and Forum** (settings
   only - see `classes/local/activity_handler_registry.php`). Other types
   are still detected and listed in the preview, but are not pulled.
3. For each one, first checks whether this course already has an activity
   with the idnumber the pulled item would get. If so, it is **flagged as a
   conflict and left alone** - never overwritten or duplicated, whether the
   existing activity came from an earlier sync or a teacher's own work.
4. Otherwise, recreates it in this block's course, using the same internal
   functions Moodle's own "Add an activity" form uses for that type, and
   assigns it an idnumber derived from the remote one, so a later sync
   recognises it as already-synced instead of duplicating it.
5. Advances `lastsync` up to, but not past, the earliest activity this run
   *couldn't* create - a type Sync now doesn't support yet, or one whose
   pull/create genuinely failed. That item (and anything modified after it)
   still has a `timemodified` after the new `lastsync`, so it's retried on
   the next Sync now rather than silently skipped forever. A flagged
   conflict is **not** a blocker like that - it's recorded once and left
   for manual review, not retried every run. If nothing this run needed
   retrying, `lastsync` advances all the way to when the run started.
6. Every attempt - whatever the outcome, including one that fails before
   listing anything - is recorded as one row in the sync history, reachable
   from the block's "View sync history" link. See below for how to read it.

## Sync history

The block's "View sync history" link (`history.php`) lists every past Sync
now run for that block instance, most recent first, each expandable
(a plain HTML `<details>` disclosure - no JavaScript needed) to show what
happened: activities created, activities flagged as conflicts (and which
existing local activity each one matched), activities that failed, and who
triggered the run. Runs are stored in `block_coursesync_synclog` - see
`classes/local/sync_history.php`.

## Notes on individual v1 types

- **Resource files**: exported base64-encoded inside the same JSON payload as
  the rest of the activity's settings. That's simple and works well for the
  file sizes this has been tested with, but it is **not** a real "any file
  size" story - base64 costs ~33% size overhead on top of whatever POST size
  and memory limits apply to the whole web service call. A large file may
  fail outright rather than sync incorrectly (the failure is reported by
  Sync now, not silent), but there's no chunking or streaming.
- **Forum**: activity-level settings only - no discussions or posts are
  synced, and none are planned to be. A **"news" (Announcements) forum is
  refused, not created** - every course already has its own, auto-created
  when the course itself was created, and syncing a remote one would leave
  the destination with two. This is treated as a permanent, structural
  carve-out (the sync keeps reporting it as unable to handle, same as an
  unsupported type would), not a bug to fix later.

## Security (Phase 7)

- **HTTPS is required** for the remote site URL, and the URL is rejected if
  it resolves to a loopback, link-local, or private network address
  (server-side request forgery hardening) - see
  `classes/local/url_safety.php`. The "development/testing connection"
  checkbox in the block's settings lifts both restrictions, for connecting
  to a local/private test site - never enable it for a real,
  internet-facing pilot.
- The token is encrypted at rest (`\core\encryption`, sodium-backed) and is
  never displayed again in the settings form once saved. Reviewed this
  phase for any path that could log it, echo it, or leak it in an error
  message - found none; see `classes/local/remote_client.php`'s docblock.
- Every non-text field pulled from the remote site (activity names, intros,
  page/label content, forum posts) is sanitised on the destination
  (`classes/local/sanitizer.php`) before it is stored, regardless of how
  much the source side is trusted. Text this plugin itself displays that
  originates from the remote site (site name, course name, error messages)
  is escaped at output time too - see `content_renderer.php`'s docblock.

## What this plugin does *not* do (v1)

- No automated changes are made to the remote site's configuration. The
  one-time setup above must still be done by hand (or scripted separately)
  on the source site.
- Only the five v1 types above are pulled. Other types are still detected
  (and listed in the preview) but skipped by Sync now until a later phase
  adds their `activity_handler`/`activity_exporter` pair.
- A flagged conflict is recorded and left alone - there's no UI to resolve
  one from within the block (rename/remove the existing local activity, or
  otherwise decide what to do about it, is a manual step outside the block
  for now).
