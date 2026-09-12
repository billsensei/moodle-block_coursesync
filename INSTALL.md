# Course Sync: admin install guide

This guide is for a **site administrator** installing block_coursesync on
Moodle 5.2 sites. A pilot needs **two separate sites**: a **source** site
(where the activities currently live) and a **destination** site (where a
teacher adds the block and pulls them in). Both roles need the plugin code
installed; each then needs its own, different one-time setup.

If you're only installing the code and someone else will do the Course Sync
setup, you can stop after "1. Install the plugin code" below and hand off.

## Requirements

- Moodle **5.2** or later (`$plugin->requires` in `version.php` targets the
  502 branch baseline). Both the source and destination sites need to be on
  a compatible version - block_coursesync doesn't need to run the exact same
  Moodle release on both sides, only both need to be 5.2+.
- **HTTPS** on the source site. The destination site's Course Sync block
  refuses to connect to a plain-HTTP remote URL by default - see
  "A note on HTTPS" below.
- The source site's web services need to be reachable from the destination
  site's web server (outbound HTTPS, not blocked by a firewall between the
  two).

## 1. Install the plugin code

Do this on **both** the source and destination site. Moodle 5.x uses a split
docroot - the installed code goes under the `public/` directory even though
older guides (and some third-party instructions) say `blocks/coursesync`.

**Option A - from a Git checkout (recommended for a pilot you can update easily):**

```bash
cd /path/to/moodle/public/blocks
git clone <this repository's URL> coursesync
```

**Option B - ZIP upload through the UI**, if you don't have shell access to
the server:

1. Package this plugin's directory as a ZIP (the ZIP's top level should be
   the plugin's files directly, e.g. `version.php`, `block_coursesync.php`,
   `classes/`, ... - not wrapped in an extra folder).
2. Log in as a site administrator and go to **Site administration ▸ Plugins
   ▸ Install plugins**.
3. Upload the ZIP and follow the prompts.

**Then, either way**, trigger the install/upgrade:

- If you used Option A (or otherwise have shell access): visit **Site
  administration** in a browser, or run
  `php admin/cli/upgrade.php --non-interactive` from the Moodle root. Moodle
  detects the new plugin and creates its database tables
  (`block_coursesync_synclog` - see `db/install.xml`) automatically.
- If you used Option B, the install completes as part of that same flow.

Confirm it installed cleanly: **Site administration ▸ Plugins ▸ Plugins
overview** should list "Course Sync" with no problem/incompatible/missing
markers.

## 2. Set up the source site

The source site needs its web services enabled and a token generated for a
sync account. This is a one-time, per-source-site setup, done by a site
administrator there. Full step-by-step instructions - what to click, in
order - are in **[REMOTE_SETUP.md](REMOTE_SETUP.md)**, and are also shown
directly inside the block's own settings form (the "Connect to a remote
site" wizard), so a teacher setting things up doesn't need this file open at
the same time. In short:

1. Enable web services and the REST protocol.
2. Enable the "Course Sync" external service (it appears automatically once
   the plugin is installed - nothing to create).
3. Authorise a sync account for that service.
4. Make sure that account's role grants `block/coursesync:sync` at
   **system** level (not just course- or block-level - see
   REMOTE_SETUP.md for why).
5. Generate a token for that account and service, and copy it - a teacher
   on the destination site will need it.

## 3. Set up the destination site

Nothing extra to configure at the admin level here beyond the plugin being
installed (step 1). The two capabilities this plugin defines
(`block/coursesync:addinstance`, `block/coursesync:sync` - see
`db/access.php`) both default to **Teacher (editing)** and **Manager**, so a
teacher can add the block to their own course and use it without further
admin action. If your site's roles have been customised away from Moodle's
defaults, confirm those two capabilities are still granted to whichever role
your teachers actually have.

Once that's confirmed, hand the source site's URL and the token from step 2
to the teacher - see **[TEACHER_GUIDE.md](TEACHER_GUIDE.md)** for what they
do with it.

## A note on HTTPS

The destination site's block validates the remote URL: it must be
**HTTPS**, and must **not** resolve to a loopback, link-local, or private
network address (SSRF hardening - see `classes/local/url_safety.php`). Both
restrictions can be lifted by ticking "This is a development/testing
connection (allow HTTP)" in the block's settings, but that checkbox is for
local/private test environments only - **never enable it for a real pilot
between two real sites.**

## Verifying the install

- **Plugins overview** (mentioned above) shows no problems on either site.
- On the destination site, a teacher (or you, testing as one) can add a
  "Course Sync" block to a course - see TEACHER_GUIDE.md for the rest of the
  flow.
- This plugin's own automated test suite
  (`vendor/bin/phpunit`/`vendor/bin/behat`, or `moodle-plugin-ci parallel`
  if you have that tool) can be run against a staging copy before a
  production install, but isn't something a live production site normally
  runs itself.

## Upgrading later

Standard Moodle plugin upgrade: replace the code under
`public/blocks/coursesync` with the new version, then run
`php admin/cli/upgrade.php` (or visit Site administration in a browser) on
each site. Schema changes, if any, are handled by `db/upgrade.php`
automatically - no manual database work is needed.
