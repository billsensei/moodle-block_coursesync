# LEARNFROMME.md

Running log of errors hit, how they were fixed, and traps to watch for when
testing `block_coursesync`.

Started: 2026-09-20 (Phase 1 of 8).
Entries so far:
- **Phase 1** — plugin scaffold, moodle-plugin-ci baseline, rebuild of the Docker
  test environment.
- **Phase 2** — cross-site connection: TLS on the test sites, encrypted token
  storage, custom external service, live ping between two servers.
- **Phase 3** — course mapping and change detection: two more external
  functions, a preview of what has changed, and a phase 2 bug found by writing
  the first test of the block's own configuration form.
- **Phase 4** — the full pull-and-rebuild pipeline for mod_page, the
  `activity_handler` extension pattern, and the first real Sync now button.
- **Phase 5** — handlers for the remaining v1 types (URL, Label, Resource with
  its files, Forum), and chunked file transfer with integrity checking.
- **Phase 6** — conflict flagging, a persistent sync history, and the real
  privacy provider that storing a user id made necessary.
- **Phase 7** — security hardening: SSRF protection at two layers, an XSS hole
  closed, an endpoint audit, and remote content treated as hostile.
- **Phase 8** — test coverage completed, three documentation deliverables, and a
  full-flow Behat test that found a bug phase 7 had introduced.
- **Phases 9-12** — more activity types, H5P, choosing what to sync.
- **Phase 13** — question banks, quiz questions, Cloze, repeatable sync
  (reconstructed from notes on 2026-09-23).
- **Phases 14-17** — every core question type.
- **Phase 15** — updating changed activities (replace or new edition).
- **Phases 18-19** — embedded files in text; quiz overall feedback.
- **Phases 20-21** — SCORM, IMS, LTI, BigBlueButton.
- **Phase 22** — subsections, and a placement bug that predated them.
- **Phase 23** — sync page always lists everything; already-synced activities
  can be copied again (replace, or "(copy)" when people have work in it).
- **Phases 24-33** — the full code review and security audit of 2026-09-24,
  one phase per finding: a stored XSS, a manager-only setup permission and
  category-scoped tokens, per-type permission checks, streamed file transfer,
  a run lock, replace keeping local set-up, crash-proof runs, the block form,
  outgoing-request hardening, and tidy-up.

---

## Part 1 — Errors found and fixed

### 1.1 Environment / tooling

**`moodle-plugin-ci` had no dependencies installed**
`../moodle-plugin-ci/vendor/` did not exist, so no CI command could run.
*Fix:* `composer install --no-interaction --no-progress` in `moodle-plugin-ci/`.

**`/tmp` is a 1.9 GB tmpfs — too small for a Moodle install**
A CI Moodle checkout plus `node_modules` and dataroot is ~1.4 GB and would have
filled it.
*Fix:* built the CI environment under `/home/vagrant/cs-pro/ci/` (44 GB free on
`/`) instead of the session scratchpad.

**`moodle-plugin-ci install` failed: `database "csci" already exists`**
I created the Postgres database by hand first. The `install` command creates it
itself and aborts if it is already there.
*Fix:* `DROP DATABASE csci`, delete the half-written `ci/moodle` and
`ci/moodledata`, re-run `install`. **Never pre-create the database.**

**`The "-m" option does not exist.`**
Not every moodle-plugin-ci subcommand takes `-m`. Standalone commands —
`phplint`, `codechecker`/`phpcs`, `phpmd`, `savepoints` — reject it. Only the
ones needing a bootstrapped Moodle accept it: `validate`, `phpdoc`, `phpunit`,
`behat`, `mustache`, `grunt`.

### 1.2 Coding standards and tests

**`PSR12.Classes.OpeningBraceSpace.Found` — 3 files**
A blank line sat between the class opening brace and the first member in
`block_coursesync.php`, `classes/privacy/provider.php` and the test class.
*Fix:* `moodle-plugin-ci codefixer ./`, which fixed all three automatically.

**`moodle.PHPUnit.TestCaseCovers.Missing` — 2 warnings**
`test_capabilities_are_defined()` and `test_sync_capability_defaults()` had no
coverage metadata.
*Fix:* marked both `#[CoversNothing]` — they exercise `db/access.php` data, not a
class method. **Note the trap:** this sniff only fired when `codechecker` was run
from inside the Moodle tree (`ci/moodle/public/blocks/coursesync`). Run from the
standalone source directory it reported a clean pass. Always run `codechecker`
from the in-Moodle path before believing it.

**PHPUnit reported 6 deprecations while still passing**
`OK, but there were issues! ... PHPUnit Deprecations: 6` — PHPUnit 11.5 deprecates
metadata in doc-comments (`@covers`, `@coversDefaultClass`, `@coversNothing`);
it is removed in PHPUnit 12.
*Fix:* converted to attributes — `use PHPUnit\Framework\Attributes\CoversClass;`
with `#[CoversClass(coursesync_block::class)]` on the class and `#[CoversNothing]`
on the two data tests. Matches what Moodle 5.1 core now does, e.g.
`public/blocks/recentlyaccesseditems/tests/helper_test.php`.

**Test bootstrap used `$CFG->dirroot`-relative requires**
`require_once($CFG->dirroot . '/blocks/coursesync/block_coursesync.php')` is
fragile under the Moodle 5.1 `public/` docroot split.
*Fix:* `__DIR__`-relative requires (`__DIR__ . '/../../moodleblock.class.php'`),
which is what core block tests do.

### 1.3 Behat

**Every scenario failed: `http://localhost:8000 is not available`**
An orphaned `php -S localhost:8000` (pid 244729, from a previous session,
serving the long-deleted `/home/vagrant/dev/moodle/ci-scratch/moodle/public`) held
`[::1]:8000`. moodle-plugin-ci's own web server could not bind and died silently
because its output is disabled — so the only symptom was the "not available"
message, three times over as it auto-reran.
*Fix, without killing the user's process:* set
`$CFG->behat_wwwroot = 'http://127.0.0.1:8001'` in the CI `config.php`, re-run
`php admin/tool/behat/cli/init.php` (the base URL is baked into the generated
`behat.yml`, so editing config alone is not enough), then start the web server
and Selenium by hand.

**`docker: Conflict. The container name "/selenium" is already in use`**
Even without `--start-servers`, the behat command tried to start Selenium.
Cause: `moodle-plugin-ci install` writes `MOODLE_START_BEHAT_SERVERS=YES` into
`moodle-plugin-ci/.env`, and `BehatCommand` honours it before it looks at the
flag.
*Fix:* prefix the invocation with `MOODLE_START_BEHAT_SERVERS=NO`. Shell
environment wins because the Dotenv loader does not override existing vars.

### 1.4 Docker test environment

**`docker exec` failed: `current working directory is outside of container mount
namespace root -- possible container breakout detected`**
Alarming message, mundane cause: the containers' bind-mount **sources** had been
deleted. `/home/vagrant/cs-dev/` (the compose project, the shared Moodle
checkout, the cleanroom tree and all three `config-*.php`) no longer existed —
it looks as if it was renamed to `cs-pro` while the stack was running. It is not
about the shell's own working directory; `cd /` does not help. The DB and
Selenium containers, which have no host bind mounts, exec'd fine.

**All three sites returned HTTP 403** — same root cause: Apache's docroot
`/var/www/html/public` resolved into a dangling mount.

*Fix:* reconstructed `env/docker-compose.yml` and `config-{a,b,c}.php` from
`docker inspect` of the orphaned containers, then `docker compose down` +
`up -d`. The nine named volumes were preserved, so every site came back with its
data. `cleanroom` had to be re-cloned from `../moodle` at the same commit.

**`Cannot downgrade block_coursesync from 2026092100 to 2026092000`**
All three sites still had a *later* `block_coursesync` (v2026092100, four
capabilities, three tables) plus a companion `local_coursesync_provider`
(v2026092101) registered from an earlier attempt whose code is gone.
*Fix (user's choice — purge, not version-bump):*
`admin/cli/uninstall_plugins.php --plugins=block_coursesync,local_coursesync_provider --run`
on A, B and C. Pre-purge dumps are in `backups/site-{a,b,c}-20260920.sql.gz`.

**The uninstaller left an orphaned block instance on site C**
`mdl_block_instances` id 6 survived on the XSITE course after the plugin was
deregistered from `mdl_block`.
*Fix:* `blocks_delete_instance()` via a CLI one-liner — **not** raw SQL, so
`block_positions` and the block context are cleaned up too.

**The uninstaller does not touch test-site table prefixes**
Site A kept `phpu_block_coursesync_*` and `bht_block_coursesync_*` after the
live-site uninstall, because `admin/cli/uninstall_plugins.php` only operates on
`$CFG->prefix`.
*Fix:* dropped them explicitly. Remember this whenever a plugin is uninstalled
from a site that has PHPUnit/Behat initialised.

### 1.5 Mistakes in my own throwaway scripts (logged so they are not repeated)

**`Trying to reference an unknown block region side-pre`**
`$page->blocks->add_block()` on a bare `moodle_page` throws unless the region is
registered first. Needs `$page->blocks->add_region('side-pre');` and
`$page->blocks->load_blocks();` before the call.

**`column "version" does not exist`** — `mdl_block` has no `version` column in
Moodle 5.1. Plugin versions live in `mdl_config_plugins` as
`plugin='block_coursesync', name='version'`.

**A `Monitor` until-loop never exited** — `until ! pgrep -f "moodle-plugin-ci
install --moodle"` matched *its own* command line, so the condition was never
true. Use a PID, a marker file, or `pgrep -f` with a pattern that cannot match
the watcher.

**Piping a command into `tail` hides its exit code** — `cmd | tail -50; echo $?`
reports `tail`'s status, so failures looked like successes. Use
`set -o pipefail` and `${PIPESTATUS[0]}`.

---

## Part 2 — Be cautious of these when testing

### 2.1 The two test servers are not independent

**Sites A and B share one Moodle checkout** (`../moodle`) and **both mount the
same plugin directory** (`../moodle-block_coursesync`). Consequences:

- A host edit is live on A *and* B simultaneously. There is no copy step, and no
  isolation — a syntax error takes down both sites at once.
- You **cannot** test plugin version X on A and version Y on B. If a phase needs
  a requester running one build against a provider running another, the compose
  file has to change first (give B its own checkout, or its own plugin mount).
- Site C (`../cleanroom`) is deliberately plugin-free. Keep it that way; it is
  the control for "what a site without the plugin sees".

### 2.2 Version numbers are a one-way ratchet

Sites A and B now hold `block_coursesync = 2026092000`. Moodle refuses any lower
number with `cannotdowngrade` — that is exactly how this session got blocked.

- Always **increase** `$plugin->version` when the schema or capabilities change.
- If you ever need to go backwards (e.g. reverting to an earlier phase), you must
  uninstall first, or restore from `backups/`.
- Editing `version.php` without bumping means `admin/cli/upgrade.php` silently
  does nothing — a real trap when `db/access.php` changed and you wonder why the
  new capability never appeared.

### 2.3 Capability context level

`block/coursesync:sync` is declared at **`CONTEXT_COURSE` (50)**, not
`CONTEXT_BLOCK` (80). This was a deliberate Phase 1 decision.

- If a later phase moves it, Moodle does **not** relocate an existing capability
  — it needs an explicit upgrade step, and role overrides made at the old level
  will not follow.
- The purged legacy plugin had `:sync` at context 80. Any old notes, screenshots
  or role exports from that build will disagree with the current site state.

### 2.4 The placeholder string contains a real em dash

`Course Sync — not yet configured` uses U+2014 (verified: bytes `e2 80 94`), in
the lang file, the PHPUnit test and the Behat feature.

- Copy-pasting through an editor or terminal that normalises punctuation will
  turn it into `-` or `--` and the assertion will fail with a diff that looks
  identical to the eye.
- If a string comparison fails for no visible reason, check the bytes first.

### 2.5 PHPUnit invocation traps

- Running `vendor/bin/phpunit path/to/test.php` **without** `-c <moodle>/phpunit.xml`
  dies with `Class "advanced_testcase" not found`. Always pass the config.
- Running the full config reports **~4150** deprecations from core. Scope with
  `--testsuite block_coursesync_testsuite` to see only this plugin's 0.
- `--display-deprecations` does **not** show PHPUnit *runner* deprecations. The
  flag you want is `--display-phpunit-deprecations`.
- `OK, but there were issues!` is not a pass in spirit. Investigate before
  calling a baseline clean.

### 2.6 The CI install is a separate copy — keep it in sync

`/home/vagrant/cs-pro/ci/moodle/public/blocks/coursesync` is a **copy**, not the
bind mount the Docker sites use. After every source edit:

```bash
rsync -a --delete --exclude '.git' \
  /home/vagrant/cs-pro/moodle-block_coursesync/ \
  /home/vagrant/cs-pro/ci/moodle/public/blocks/coursesync/
```

Forgetting this means CI happily tests the previous version and reports green.

### 2.7 Behat needs a manual setup dance

On the CI install, until pid 244729 is gone:

```bash
# 1. web server on the non-conflicting port
php -S 127.0.0.1:8001 -t /home/vagrant/cs-pro/ci/moodle/public &
# 2. selenium on the host network
docker run -d --rm --name=selenium --network=host --shm-size=2g \
  -v /home/vagrant/cs-pro/ci/moodle:/home/vagrant/cs-pro/ci/moodle \
  selenium/standalone-firefox:4
# 3. run, overriding the .env that forces server startup
cd /home/vagrant/cs-pro/ci/moodle/public/blocks/coursesync
MOODLE_START_BEHAT_SERVERS=NO \
  ../../../../../moodle-plugin-ci/bin/moodle-plugin-ci behat \
  -m /home/vagrant/cs-pro/ci/moodle --profile=firefox ./
```

Stop both afterwards. If `behat_wwwroot` is ever changed again, re-run
`admin/tool/behat/cli/init.php` — editing `config.php` alone is not enough.

Site A has its own Behat setup (`bht_` prefix, `behat_wwwroot = http://web-a`,
the `selenium` compose service). It has **not** been initialised since the
rebuild; `init.php` there will create `bht_` tables from scratch.

### 2.8 Destructive operations to avoid

- **Never `docker compose down -v`** in `env/`. The `-v` destroys all nine named
  volumes: every site's database, moodledata, phpunitdata and behatdata. Plain
  `down` is safe and was used for the rebuild.
- `coursesync-ci-db` (port 5433) is **not** part of the compose project — it has
  no compose labels and `down` will not touch it. It backs the `csci` CI
  database. Do not delete it expecting the sites to be unaffected, or vice versa.
- The `coursesync-shared` network was created by hand, has no labels and nothing
  attached. Leave it unless a later phase genuinely needs it.
- `backups/site-{a,b,c}-20260920.sql.gz` are point-in-time, taken **before** the
  legacy purge. Restoring one brings the old v2026092100 plugin registrations
  back with it.

### 2.9 Things still unresolved

- **Stale `php -S` (pid 244729)** is still running, serving a deleted directory
  and holding `[::1]:8000`. Harmless in itself, but it is why the Behat
  workaround exists. Killing it lets `--start-servers` work normally.
- **The environment does not match its description.** These are Docker/Apache
  containers, not native LEMP hosts with SSH. Worth reconciling before anyone
  writes deployment notes against the wrong assumption.
- **The purged schema is a hint, not a spec.** The old build's
  `block_coursesync_connection` columns (`remoteurl`, `token`, `tokenhint`,
  `remotecourseid`, `remotecoursename`, `remoterelease`, `providerversion`,
  `setupstage`, `status`) plus `_map` and `_run` tables and a whole
  `local_coursesync_provider` plugin show where phases 2–8 were heading. The code
  is gone, so treat it as a sketch to re-derive, not a design to restore.

---

# Phase 2 — cross-site connection

## P2.1 Errors found and fixed

### Every cross-site call was a redirect, not a call

`https://web-b/webservice/rest/server.php` from site A returned
`303 -> http://localhost:8081/...`. Moodle compares the request's host and port
against `$CFG->wwwroot` in `lib/setuplib.php:707` and redirects on any mismatch.
With `wwwroot = http://localhost:8081`, "localhost" inside container A is A
itself, so the two sites could never talk.

*Fix:* give both sites a `wwwroot` on the VM's static host-only IP,
`192.168.56.10`, which resolves the same from the host, from the containers and
from the desktop. Ports were re-bound from `127.0.0.1` to that address.

### Apache must listen on the same port the host publishes

Publishing `8443:443` looks natural and produces an endless redirect. Moodle
builds `$rurl['port']` from `SERVER_PORT`, which would be 443 inside the
container while `$CFG->wwwroot` says 8443 — a permanent mismatch.

*Fix:* `env/tls/10-coursesync-ssl.sh` makes Apache listen on `SITE_HTTPS_PORT`
and compose publishes it 1:1 (`8443:8443`, `8444:8444`, `8445:8445`).

### Moodle blocked its own outgoing call

`\core\http_client` runs `curl_security_helper` on every request. Two site
settings stopped the ping before it left the building:

- `curlsecurityallowedport` defaults to `443,80`. Our source site is on 8444.
- Site B additionally had `curlsecurityblockedhosts` covering `192.168.0.0/16`,
  which is the host-only network.

*Fix:* add 8444 to `curlsecurityallowedport` **on the calling site**. This is a
site configuration change, not a code weakening — the SSRF protection stays
fully active, and the `errorblocked` path was verified for real before the port
was allowed.

### `accesslib_clear_all_caches_for_unit_testing()` throws outside tests

`Coding error detected ... You must not call clear_all_caches outside of unit
tests`. Use `reload_all_capabilities()` (or `purge_caches()`) in CLI scripts.

### `webservice/rest:use` has no default archetypes

`webservice/rest/db/access.php` declares it with an **empty** `archetypes`
array, so no role grants it. A sync account needs both
`block/coursesync:sync` and `webservice/rest:use` explicitly, or Moodle refuses
the call. This was missing from the first draft of the wizard text and the docs.

### `get_content()` assumed `$this->page->course` exists

Phase 1's test caught it: `Attempt to read property "course" on null`, then
`dml_missing_record_exception` from `context_course::instance(null)`. A block can
be instantiated without a page.

*Fix:* `get_course_id()` / `get_course_context()` fall back to the block
instance's own context, and `get_content()` degrades to the placeholder when
neither is available.

### Guzzle options on the client behaved differently from options on the request

`HTTP_ERRORS => false` was set when constructing `http_client`, so the injected
mock client in tests did not have it and threw on a 404 — a 404 was classified
`errorunreachable` instead of `errornotmoodle`. The test only disagreed with
production because the setup differed.

*Fix:* move `timeout`, `connect_timeout`, `http_errors` and `verify` onto the
`post()` call, so they hold however the client was built.

### PHPUnit: "Unexpected debugging() call detected"

Any `debugging()` a test triggers must be acknowledged with
`$this->assertDebuggingCalled()`, or PHPUnit reports a notice and the run ends
with `OK, but there were issues!`.

### phpmd cyclomatic complexity

`remote_url::validate()` reached 11 and `remote_client::classify_moodle_error()`
reached 14 against a threshold of 10. Fixed by extracting
`has_required_parts()` / `has_valid_port()`, and by replacing a long `switch`
with a lookup array — which reads better anyway.

### `defined('MOODLE_INTERNAL') || die();` is wrong in `db/upgrade.php`

`moodle.Files.MoodleInternal.MoodleInternalNotNeeded` — a file that only defines
functions has no side effects to guard. No core block has it there either.

### The wizard never advanced past step 1

The forms were built as
`new remote_url_form($pageurl, null, 'post', '', null, true, [...])`. The 7th
parameter of `moodleform::__construct()` is **`$ajaxformdata`**, not
`$customdata` (which is the 2nd). Passing custom data there makes the form treat
it as submitted AJAX data and `get_data()` never returns.

*Fix:* `new remote_url_form($pageurl)` and populate via `set_data()`. Two forms
on one page are fine — Moodle distinguishes them by the hidden `_qf__<formname>`
field.

### `passwordunmask` was the wrong element

It is built for admin settings and depends on JavaScript. Replaced with a plain
`password` element, which works in a non-JS Behat run and is the right shape for
a write-only token anyway.

### CI Moodle has no live site

`php admin/cli/upgrade.php` in `ci/moodle` fails with *"Config table does not
contain the version"* — `moodle-plugin-ci install` only builds the PHPUnit and
Behat sites. After adding `db/install.xml`, re-run
`php admin/tool/phpunit/cli/init.php` so the test database picks up the new
table.

## P2.2 Be cautious of these when testing

### The site URLs have changed

| Site | Old | New |
| --- | --- | --- |
| A (destination) | http://localhost:8080 | **https://192.168.56.10:8443** |
| B (source) | http://localhost:8081 | **https://192.168.56.10:8444** |
| C (clean room) | http://localhost:8082 | **https://192.168.56.10:8445** |

Your browser will warn about the certificate until you import
`env/tls/ca.crt` into its trust store. The certificate covers
`IP:192.168.56.10`, `IP:127.0.0.1`, `DNS:localhost` and `DNS:web-a/b/c`, but
reaching a site by any name other than the one in its `wwwroot` still redirects.

**The certificate expires 2028-12-23.** Re-issue it from `env/tls/ca.key` before
then, or every cross-site call starts failing with `errorcertificate`.

### Port allowances are one-directional

Only site A has 8444 in `curlsecurityallowedport`. If you ever test **B calling
A** you must, on site B:

1. add `8443` to `curlsecurityallowedport`, and
2. clear `192.168.0.0/16` from `curlsecurityblockedhosts`, which currently
   covers the host-only network.

Forgetting either produces `errorblocked`, which reads like a bug in the plugin
but is Moodle protecting the site correctly.

### Changing the remote URL deliberately destroys the token

`connection::set_url()` wipes the token, the hint and the test result whenever
the normalised URL differs. A token from one site is meaningless to another, and
silently keeping it would produce a baffling failure later. Re-saving the *same*
URL (even with a different trailing slash) keeps it.

### The token cannot survive a database-only restore

The encryption key lives in the site's secret data directory, not the database.
Restore the database somewhere else and `connection::get_token()` returns null
and the block says so. That is intended behaviour, not a bug — but it means a
restore drill must copy the key file or plan to re-enter tokens.

### Two capabilities, two contexts

`block/coursesync:sync` is declared at `CONTEXT_COURSE` for role overrides, but
the source site checks it at **system** context for the token's account.
`has_capability()` does not enforce the declared context level — that only drives
the role-definition UI — so this works, and there is a test pinning the
behaviour. Do not "fix" the context level without reading that test.

### The ping says nothing about courses

`block_coursesync_ping` returns site name, release and plugin version only. If a
later phase needs more, add a *new* function rather than widening this one: it is
the function the token is scoped to, and it is called before trust is
established.

## P2.3 Things needing your attention

### A leftover admin token on site B (security) — RESOLVED 2026-09-20

Site B carried a hand-made external service from the abandoned earlier build:

| | |
| --- | --- |
| Service | `CourseSync Probe` (`coursesync_probe`), id 2 |
| Enabled | yes |
| Authorised users only | **no** — any user with `webservice/rest:use` could call it |
| File download / upload | **both enabled** |
| Functions | `core_course_get_contents`, `core_course_get_courses`, `core_course_get_course_module`, `core_course_check_updates`, `core_question_search_shared_banks`, `core_webservice_get_site_info` |
| Token | id 1, bound to **`admin`**, created 2026-09-20 00:59, **never expired** |

A standing administrator credential on an unrestricted service with file access,
unused by anything in phase 2.

**Deleted** at the user's instruction via `webservice::delete_service()`, which
removes the authorised users, the service functions, the tokens and the service
row together — rather than by raw SQL, which would have left orphans. A dump was
taken first: `backups/site-b-20260920-before-probe-delete.sql.gz`.

Verified afterwards: no orphaned rows in `external_services_functions` or
`external_services_users`, the phase 2 ping still succeeds, and an audit of all
three sites now shows only

- `moodle_mobile_app` (core, disabled) on each site
- `block_coursesync` — enabled and restricted on B (the source), installed but
  disabled on A (the destination, which does not need to expose it)
- exactly one token anywhere: `coursesync_service` on B's Course Sync service

**Worth noting:** that service's function list shows the earlier build tried to
move activity content using *core* web services. The phase 2 architecture note
rules that approach out, and this is evidence of why the question came up.

### Accounts and roles created during testing

On site B, following `docs/REMOTE_SETUP.md`:

- user `coursesync_service` (a service account, manual auth)
- role `coursesyncservice`, assigned at system level, granting
  `block/coursesync:sync` and `webservice/rest:use`
- a permanent token for that account on the `Course Sync` service

The token's plaintext was written to site B's moodledata during setup so the
destination could be configured; **that file has been deleted**. The only copy
now is encrypted in site A's database and hashed in site B's token table.

---

# Phase 3 — course mapping and change detection

## P3.1 Errors found and fixed

### Saving the block's own config form silently did nothing (a phase 2 bug)

`instance_config_save()` checked `$data->config_remoteurl`. Moodle strips the
`config_` prefix in `block_manager::save_block_data()`
(`lib/blocklib.php:1971-1977`) before calling it, so the field arrives as
`$data->remoteurl` and the branch was never entered. The URL fell through to
`parent::instance_config_save()` and was written into `configdata` instead of
the connection table.

It went unnoticed in phase 2 because every test drove the wizard or called
`connection::set_url()` directly — nothing exercised the block's **Configure**
form. Fixed, and `test_instance_config_save_stores_the_remote_url()` now pins
both halves: the URL reaches the table, and nothing is mirrored into
`configdata`.

**The lesson:** a form field and the method that saves it are two different
things. Test the save path, not just the validator.

### `validate_context()` on a course context demands enrolment

Both new functions failed with `require_login_exception: Course or activity not
accessible. (Not enrolled)`. `external_api::validate_context()` ends with
`require_login($course, ...)` (`lib/external/classes/external_api.php:520`), and
a sync service account is never enrolled in the courses it reads.

This was not just a test artefact — the live service account would have hit it
too. `ping` was unaffected because it validates the **system** context.

*Fix, in two parts:*

1. The sync account also needs **`moodle/course:view`** ("View courses without
   participation"). This is the same capability core requires of its own
   cross-course functions — `core_course_get_courses` checks it per course.
2. Check `block/coursesync:sync` **before** `validate_context()`, so a missing
   sync permission is reported as exactly that instead of the much more
   confusing "course not accessible".

`require_login_exception` carries errorcode `requireloginerror`, now mapped to
its own message naming `moodle/course:view`.

### Language strings were double-quoted

The generated strings came out as
`$string['coursemapped'] = "Mapped to {$a->fullname} ...";`. In a double-quoted
PHP string, `{$a->fullname}` interpolates **at parse time** against an undefined
`$a`. Moodle lang strings must be single-quoted so the placeholder survives to
`get_string()`. Six strings were affected.

### phpcs and phpmd, again

- `PSR12.Classes.OpeningBraceSpace.Found` — still the habit of a blank line after
  `{`. `codefixer` handles it; run it before believing anything.
- Multi-line call formatting in the tests — also auto-fixed.
- `moodle.Commenting.InlineComment.NotCapital` fires on a comment that starts
  with a function name, e.g. `// validate_context() calls ...`. Reword to start
  with a capitalised word.
- phpmd complains about `connection_test` having 17 public methods. Informational
  only; it exits 0.

## P3.2 Be cautious of these when testing

### The sync account now needs three capabilities

On the **source** site, at system level (or per course if you want to restrict
which courses can be pulled from):

| Capability | Why |
| --- | --- |
| `block/coursesync:sync` | The plugin's own gate, checked by every function |
| `webservice/rest:use` | No role grants it by default |
| `moodle/course:view` | `require_login()` runs for every course-scoped call and the account is never enrolled |

Site B's `coursesyncservice` role has all three. Miss the third and the
destination reports *"The sync account on the other site is not allowed to see
that course"* — which is accurate, but only if you know where to look.

### "Since" is exclusive, and that is deliberate

`get_modified_activities` returns activities with `timemodified > since`, not
`>=`. An activity modified in the same second as the last sync is **not**
reported.

The alternative is worse: with `>=`, every call would return the same items
forever once `lastsync` starts being written in phase 4. There is a test pinning
this (`test_boundary_is_exclusive`). If phase 4 ever sees an activity apparently
skipped, this is the first place to look — but changing it will reintroduce the
repeat-forever bug, so fix the timestamp being stored instead.

### Modification time does not come from `course_modules`

`course_modules` has `added` (creation) and no `timemodified`. The time comes
from each activity's own table, read one module type at a time. All 23 core
activity types have `timemodified`; a third-party type that does not falls back
to `added`, which means **edits to such an activity will not be detected** —
only its creation. Worth checking if you install an unusual activity type.

### `lastsync` is intentionally never written in phase 3

The preview reads `lastsync` to decide what to ask for and deliberately leaves
it alone. Writing it here would mark activities as synced when nothing had been
copied, hiding them from the first genuine run in phase 4. The live test asserts
it still reads "never" after a preview. **If you see `lastsync` set on a phase 3
connection, something is wrong.**

### Changing the remote URL now clears the course mapping too

Already true of the token; the course id, shortname, name and reference go with
it. A course id on one site means nothing on another, so keeping it would map
the block to whatever happened to share that id. Re-saving the *same* URL keeps
everything.

### Hidden activities are reported

`get_modified_activities` returns every activity in the course, including ones
hidden from students. The capability check is the gate, not per-activity
visibility. That is defensible — an administrator deliberately authorised this
account to sync the course — but it does mean a destination teacher sees the
names of hidden activities. Worth revisiting in the security phase.

### Test data added to site B, and removed again

The live test added two activities to the source site:

- `Phase 3 detection test` (assign, idnumber `PHASE3TEST`) in **REMOTE1** — the
  activity that proved change detection
- `Decoy in the wrong course` (page) in **CSB** — added to prove scoping, and it
  correctly never appeared on the destination

**Both were deleted on 2026-09-20** at the user's request, with
`course_delete_module($cmid, false)` so grades, files and completion data went
with them rather than leaving orphans. Each deletion was guarded by a check that
the module type, name and course matched what was expected, so a wrong cmid
would have been refused rather than silently removing something else. Dump kept
at `backups/site-b-20260920-before-testdata-delete.sql.gz`.

REMOTE1 is back to its original five activities (forum, page, label, url, qbank)
and CSB has none.

### Phase 3 does not detect deletions

Confirmed while cleaning up: after the test activity was deleted on the source,
the destination simply stopped listing it. There is no "removed" signal —
`get_modified_activities` reports what currently exists and has changed, so a
deletion is only visible as an absence.

That is fine for a preview, but a real sync needs to decide what to do about an
activity that disappears upstream. Worth settling deliberately in phase 4 or 5
rather than discovering it as a surprise.

---

# Phase 4 — pull and rebuild, for Page

## P4.1 Errors found and fixed

### `course/lib.php` is not loaded until something happens to load it

The live sync failed with:

```
Error: Call to undefined function block_coursesync\local\handler\add_course_module()
```

`add_course_module()`, `course_add_cm_to_section()` and friends live in
`course/lib.php`, which Moodle does **not** load during bootstrap. Measured
directly:

```
after bootstrap          : add_course_module=NO
after mod/page/lib.php   : add_course_module=NO
after get_course()       : add_course_module=NO
after get_fast_modinfo() : add_course_module=yes
```

So whether the function exists depends on what else the request happened to do
first. `get_fast_modinfo()` pulls it in; nothing else on that list does.

*Fix:* `require_once($CFG->dirroot . '/course/lib.php')` inside the handler
methods that use those functions, and call them as `\add_course_module()` etc.
so it is obvious they are global rather than namespaced.

**Worth noting honestly:** the *first* live sync created a page successfully with
the same code, and the second failed. I could not account for what loaded
`course/lib.php` on that first run. That inconsistency is the whole argument for
requiring the library explicitly rather than relying on ambient loading — a bug
that appears only sometimes is worse than one that always fails.

This is exactly the trap Momopda's own `block.md` guidance warns about under
"Required Library Includes". Worth re-reading before writing a handler.

### `FORMAT_HTML` is a string, not an integer

`lib/weblib.php` has `define('FORMAT_HTML', '1')` — the value is the **string**
`'1'`. `assertSame(FORMAT_HTML, $someint)` therefore fails with the unhelpful
*"Failed asserting that 1 is identical to '1'"*.

Only the test assertions were wrong here; the production code casts. But the same
trap will bite any `===` comparison against a FORMAT_* constant.

### `cm_info::sectionnum` is a string

`$cminfo->sectionnum` comes back as `'1'`, not `1`. Same class of failure as
above. Cast before comparing.

## P4.2 Be cautious of these when testing

### The ID number is the sync marker — do not edit it

A synced activity carries `idnumber = coursesync-<remote cmid>`. That is the only
record that it came from somewhere else. Change or clear it and the next sync
will create a **second** copy, because `syncer::find_existing()` will no longer
recognise it.

It is deliberately a generated marker rather than the source's own ID number:
source activities usually have none, and one that does could collide with
something already in the destination course.

### `lastsync` moves only on a clean run

One failure anywhere in the run holds `lastsync` where it was, so the failed
activities are retried next time. This was observed working for real: the run
that hit the `course/lib.php` bug reported `0 created, 0 skipped, 1 failed` and
left `lastsync` untouched.

The flip side is that a permanently failing activity will pin `lastsync` forever
and every run will re-report everything after it. Phase 6's conflict handling
needs an answer for that — probably a per-activity failure record rather than an
all-or-nothing marker.

### The exclusive boundary is easy to trip over in manual testing

A source activity touched **in the same second** as `lastsync` is not detected,
because the comparison is `timemodified > since`. This actually happened while
testing: a page touched immediately after a sync appeared not to be noticed.

It is correct behaviour, not a bug — with `>=` every run would re-report the same
items forever. When testing by hand, leave a clear gap or roll `lastsync` back
explicitly.

### Existing copies are skipped, not updated

If an activity already synced is changed on the source, the sync reports
*"Already copied into this course by an earlier sync"* and leaves the local copy
alone. Updating an existing copy is conflict territory and belongs to phase 6.
Do not read the skip as a failure.

### Embedded files are not copied

A page whose content references `@@PLUGINFILE@@` is still created, and the sync
says so explicitly. The links will not resolve until file transfer is
implemented. `activity_payload::references_files()` is what detects this, and it
checks the intro and every setting.

### Test data now on the live sites

- **Source (site B, REMOTE1):** `Week 2 Reading` (page, cmid 8) — added to prove
  the pipeline. Its `timemodified` was also poked several times during testing.
- **Destination (site A, CSA):** two synced pages, `Week 1 Notes`
  (`coursesync-2`) and `Week 2 Reading` (`coursesync-8`).

The destination copies are the proof that phase 4 works; deleting them means the
next sync recreates them, which is itself a reasonable re-test. `lastsync` on the
destination block is currently set, so a fresh full pull needs it cleared.

---

# Phase 5 — the rest of the v1 activity types

## P5.1 Decisions taken with the user

Three things were flagged before any code was written, because each had a
defensible answer that was not obviously right.

**File transfer.** Moodle's own convention is to fetch files through
`webservice/pluginfile.php` with the token. That endpoint requires
`downloadfiles = 1` on the service, and once enabled it serves **anything** the
token's user can reach through `file_pluginfile` — far wider than our three
narrow functions. Chosen instead: a dedicated chunked function,
`block_coursesync_get_activity_file`, which only serves files in a file area the
activity's own handler has declared. The service keeps `downloadfiles = 0`.

**Forum scales.** `forum.scale` is site-local: positive is a point score and
means the same anywhere, negative is minus the id of a row in that site's
`scale` table. Chosen: send the scale's name alongside the value and match by
name, falling back to an ungraded forum with a note in the sync result.

**Forum types.** The user chose to sync every forum type including `news`.
Note the consequence: Moodle auto-creates one news forum per course, so a
destination course that has (or later gets) its own will end up with two
Announcements forums.

## P5.2 Errors found and fixed

### `cmidnumber` is required by modules that create grade items

The first live five-type sync produced:

```
Warning: Undefined property: stdClass::$cmidnumber in mod/forum/lib.php on line 812
```

`forum_grade_item_update()` reads `$forum->cmidnumber` for the grade item's ID
number. Moodle's own `add_moduleinfo()` sets it; a handler building `$data` by
hand does not, and nothing complains until a warning surfaces inside another
module.

*Fix, deliberately not local to Forum:* a shared
`activity_handler::make_instance_data()` now builds the fields every module's
add-instance function expects — including `cmidnumber` — and all five handlers
start from it. That removes the repetition across handlers and stops the same
class of bug reaching phase 6's types.

### Phase 4 tests encoded "only Page is supported"

Four tests failed after the new handlers were registered:

- `add_created()` gained a notes array where it had a boolean
- `test_unsupported_type_is_refused` used **forum** as its unsupported example
- `test_registry` asserted `supported_modnames() === ['page']`

None were product bugs, but they are a reminder that a test asserting *what is
not supported yet* has a short shelf life. The replacements use `quiz` as the
stand-in for "no handler" and check membership rather than exact list equality
where the list is expected to grow.

## P5.3 Be cautious of these when testing

### Declaring a file area is what authorises reading it

`get_activity_file` refuses any area a handler has not declared in
`get_file_areas()`. There is a test proving that `intro` — a real Moodle file
area — cannot be read through it on a resource. If a future handler needs
another area, it must declare it; do not relax the check.

### File integrity is checked, and a failed file removes the activity

Every file is compared against the SHA1 the source reported. If it does not
match, or a chunk fails, the whole activity is deleted again rather than left
behind. This matters: an activity left in place with missing files would be
marked as synced by its ID number and **never repaired** by a later run.

### Verified live, not just in unit tests

The resource test on the live servers carried two files:

| File | Size | Chunks | Result |
| --- | --- | --- | --- |
| `/handbook.txt` | 20,481 B | 1 | sha1 identical |
| `/appendix/dataset.bin` | 1,510,500 B | 3 | sha1 identical |

That covers the multi-chunk path, a subdirectory file path, binary content, and
the sort order that tells mod_resource which file to open.

### Forum type "single" creates a post

A forum of type `single` makes an opening post out of the description when it is
created. That is how Moodle builds that forum type, not this plugin syncing
posts — but if you are checking that no discussion content crosses, expect to
see one there.

### Label names are derived, not copied

`label_add_instance()` generates the name from the text. The handler sends a
name but Moodle overwrites it. The round-trip test asserts the derived names
match rather than that the sent name survived.

### Test data now on the live sites

Source (site B, REMOTE1) gained a resource, `Course handbook` (cmid 9), with the
two files above. The destination (site A, CSA) holds one synced copy of each of
the five types. Both are the evidence phase 5 works.

---

# Phase 6 — conflicts and sync history

## P6.1 Things worth knowing about the design

### Recording who ran a sync made the plugin hold personal data

Up to phase 5 this plugin genuinely stored none, and declared
`null_provider` honestly. The history table records `userid`, so that
declaration became false and a full privacy provider was required —
`get_metadata`, `get_contexts_for_userid`, `get_users_in_context`,
`export_user_data`, and the three delete methods.

Nothing in the phase brief asked for this. It is not optional: a plugin that
stores user data behind a `null_provider` is misreporting itself to the site's
data requests. Core's own privacy suite (643 tests) passes against the new
provider.

The connection record — remote address and encrypted token — is deliberately
**not** reported as personal data. It is course configuration, not information
about a person.

### Conflicts must not hold the last synced marker

This was the sharpest design decision in the phase, and getting it backwards
would have been a slow-burning bug.

A conflict is a decision the run made and wrote down, not something that went
wrong, so `is_clean()` ignores conflicts and the marker still moves. Holding it
for a conflict would mean that on the **next** run every previously copied
activity is detected again, finds its own copy already present, and is flagged
as a conflict too. One unresolved conflict would cascade into flagging the whole
course, every run, forever.

The cost of moving the marker is that a conflict resolved by hand is never
re-offered by an ordinary sync. That is why the sync page has **Check everything
again**, which runs with `since = 0`. The live test walked exactly that path:
flagged → teacher deletes their local page → full re-check → copied.

### Two kinds of conflict, told apart by the history

| Reported as | Means |
| --- | --- |
| `conflictlocalactivity` | Something carries that identity which Course Sync did not put there |
| `conflictchangedupstream` | An earlier run copied it here, and it has since changed at the source |

The distinction comes from `history::was_pulled_here()`, which scans past runs
for a matching remote/local course module pair.

**A wrinkle worth expecting:** activities copied in phases 4 and 5, *before* the
history table existed, have no record. They are therefore reported as
`conflictlocalactivity` even though this plugin did create them. That is the
honest answer given the data — the history genuinely does not know — but it will
look wrong if you test with pre-phase-6 copies. Test with something copied since.

## P6.2 Be cautious of these when testing

### The safety property, and how it was proven

A local page was created by hand carrying `idnumber = coursesync-10`, the exact
identity the source's cmid 10 would claim. After the sync:

```
NOT OVERWRITTEN : name=My own hand-made page
                : content=THIS CONTENT MUST NOT BE OVERWRITTEN.
NOT DUPLICATED  : 1 activity carries idnumber coursesync-10
```

The changed-upstream case was proven the same way: the local copy still held
`Read the case study.` while the source had moved on to `Revised on the source
after it was copied.`

### Every run is recorded, including the boring ones

A run that pulled nothing still writes a history row, so the history answers
"was this ever tried?" as well as "what did it do?". Run #1 in the live test is
exactly that — `ok`, zero of everything.

### The idnumber is still the whole identity scheme

Everything in this phase rests on `idnumber = coursesync-<remote cmid>`. Edit or
clear it on a copied activity and the next sync will not recognise it, will not
flag it, and **will** create a second copy. That was true from phase 4 and
matters more now that conflict detection depends on it.

### Deleting the block deletes its history

`instance_delete()` now removes the run history alongside the connection. There
is no confirmation step beyond Moodle's own block deletion prompt.

## P6.3 Two bugs the web UI found that CLI testing had hidden

Everything up to this point was verified by running syncs through `docker exec
... php`, which runs as **root**. Driving the real pages over HTTPS as a logged-in
teacher found two things in the first attempt.

### The encryption key was unreadable by the web server

The sync page reported *"The saved token could not be read on this site."*

`core\encryption` creates its key as `0400`, owned by whoever creates it. My CLI
runs were root, so:

```
-r-------- 1 root root 32 /var/www/moodledata/secret/key/sodium.key
```

Apache serves as `www-data`, which cannot read that. Every sync through the web
UI failed at token decryption — for four phases — while every CLI run worked.

**This is a deployment trap, not a plugin bug.** The plugin behaved exactly as
designed: it reported the token as unreadable and told the user to paste it
again, which is the phase 2 behaviour for a missing key. Any site where an
administrator runs a CLI script as root before the web server first touches
encryption will hit the same thing.

*Fix in this environment:* `chown -R www-data:www-data /var/www/moodledata/secret`.
The existing ciphertext still decrypts, because the key itself did not change.

**The process lesson is the bigger one:** CLI verification is not a substitute
for driving the actual pages. Run at least one pass per phase through the real
UI as the real user.

### A silent no-op edit left conflicts showing as "Failed"

The conflict badge on the sync page rendered as **Failed** (red) rather than
**Flagged** (amber). The `'conflict'` arm had never been added: `codefixer` had
reformatted the `default =>` arm onto multiple lines between my reading the file
and editing it, so the string replacement matched nothing — and the script did
not assert that it had.

This is the same mistake as the phase 5 dynamic-property slip. **Every scripted
edit must assert its target was found.** A `str.replace()` that matches nothing
fails silently and looks like success.

## P6.4 Scope note

The phase brief said not to build automated test suites — those are phases 7 and
8. I kept the existing 141 tests passing and updated the three that encoded
phase-5 behaviour (`add_created()` signature, the `syncskippedexisting` reason
that conflicts replaced), but wrote **no new tests** for the conflict logic or
the history.

That leaves real gaps for phase 7 to close:

- `syncer` conflict branching — both kinds, and that neither overwrites
- `history::record()` / `get_runs()` / `was_pulled_here()`
- the privacy provider's export and all three delete paths
- the history UI, including the empty state

All of it was verified live instead, which is good evidence but not a regression
guard.

---

# Phase 7 — security hardening

## P7.1 Real problems found

### An XSS hole from a malicious source site

Core's notification template renders its message with `{{{ message }}}` — a
triple-stache, which is **not escaped**. The template's own docblock says "a
cleaned string (use `clean_text()`)".

`ping_result::get_message()` and `course_result::get_message()` built their
messages by interpolating the other site's name straight into a language string:

```php
get_string('teststatusok', 'block_coursesync', (object) [
    'sitename' => $this->sitename,   // straight from the other site
]);
```

A source site returning `sitename = <img src=x onerror=...>` would have run
script in the destination administrator's browser, on the destination's origin,
at exactly the moment they were testing a connection.

*Fixed in two places, on purpose:* the values are cleaned with `PARAM_TEXT` in
`remote_client` where they arrive, **and** escaped with `s()` where the message
is built. Either alone would do; both means a future call site that forgets one
is still covered.

**The general lesson:** `$OUTPUT->notification()` does not escape. Anything built
from data this site did not author must be escaped before it goes in.

### `javascript:` could have reached a rendered link

`mod_url` stores `externalurl` and renders it as a clickable link. The handler
was copying it across with a plain `(string)` cast, so a source site could have
sent `javascript:...` and had it rendered as a link on the destination.

Now `setting_url()` runs `PARAM_URL`, which rejects it, and a URL that does not
survive cleaning causes the activity to be refused rather than created with a
dangerous address.

### `confirm_sesskey()` returns a bool and does not throw

`setup.php` guarded the connection re-test with:

```php
if ($step === 3 && $test && confirm_sesskey()) {
```

A wrong key silently skipped the action and re-rendered the page unchanged. Not
exploitable, but it reads like a guard while behaving like a filter, and the user
gets no feedback. Replaced with `require_sesskey()` inside the branch.

**Worth checking anywhere you see `confirm_sesskey()` in a condition.**

### `address_in_subnet('0.0.0.0', '0.0.0.0/8')` is false in Moodle

Measured directly:

```
0.0.0.0    in 0.0.0.0/8    => NO
0.1.2.3    in 0.0.0.0/8    => yes
127.0.0.1  in 127.0.0.0/8  => yes
```

The all-zeros address is not matched by the range that contains it. The SSRF test
caught this, which is the whole argument for writing the attack cases rather than
assuming the helper does what its name suggests. Fixed with an explicit
`BLOCKED_ADDRESSES` list for `0.0.0.0`, `::` and `::1`.

### Configuring a block is not the same as being allowed to use its connection

The block configuration form validates the remote course by calling the other
site with the stored token. That was gated only by Moodle's `moodle/block:edit`.
Now it also requires `block/coursesync:sync` before making any outbound call on
the user's behalf.

## P7.2 A design decision that changed during the phase

My first implementation refused a hostname that did not resolve. The phase 2
tests failed immediately, because they used `moodle.example.edu`, which does not
exist.

That was the tests catching a real design mistake, not a stale expectation:

- Saving a configuration value would have depended on DNS being available.
- It bought nothing. A name that resolves safely when saved can resolve to
  `127.0.0.1` an hour later — classic DNS rebinding — and no save-time check can
  prevent that.

*Restructured into two layers:* `validate()` checks at save time and accepts a
name it cannot resolve; `check_before_request()` resolves and checks again inside
`remote_client::call()`, immediately before every outgoing request. The second is
the one that actually protects anything. Moodle's own `curl_security_helper`
inside `\core\http_client` is a third layer on top.

## P7.3 Be cautious of these when testing

### The test sites need the development override

The sites live on `192.168.56.10`, which is exactly what this phase now blocks.
`$CFG->block_coursesync_allowprivateurls = true;` has been added to all three
`config-*.php` files, with a comment saying what it is.

**Verified both ways on the live sites:** with the flag on, a sync runs normally;
with it off, the same call is refused with `[errorurlprivate]`.

It is a config.php flag rather than an admin setting deliberately. Switching off
a protection against the server being used to reach its own network should need
server access, not a checkbox that comes with any compromised admin account.

### New language strings need a cache purge

The first live check of the refusal printed `[[errorurlprivate]]` rather than the
message. The string was in the file; the site's string cache was stale.
`php admin/cli/purge_caches.php` after adding strings, or you will chase a
missing string that is not missing.

### Layer 2 costs a DNS lookup per request

`check_before_request()` resolves the host before every outgoing call. For an IP
literal it is free. For a hostname it is one lookup per web service call, which
during a sync means one per activity. If that ever shows up in a profile, cache
it for the life of the request — do not remove it.

### What the override does *not* relax

Only the private-address rules. Plain `http`, non-HTTP(S) schemes and credentials
embedded in the URL are still refused with the flag on. There is a test pinning
that (`test_override_does_not_relax_anything_else`).

## P7.4 Coverage added

The phase 6 note said conflict logic and history had no regression coverage.
Phase 7 added 27 tests, but they are the security cases the brief asked for —
SSRF ranges, schemes, the override, the request-time check — not the phase 6
gap. **That gap is still open** and belongs to phase 8:

- `syncer` conflict branching, both kinds
- `history::record()` / `get_runs()` / `was_pulled_here()`
- the privacy provider's export and delete paths
- the history UI

---

# Phase 8 — coverage and documentation

## P8.1 Bugs found by writing the tests

### The connection test never ran after pasting a token

The full-flow Behat scenario failed at **Save and test** with *"A required
parameter (sesskey) was missing"*, traced to `setup.php` line 157.

After the token form saves, the page redirects to the step that runs the
connection test:

```php
redirect(new moodle_url($pageurl, ['step' => 3, 'test' => 1]));   // no sesskey
```

Phase 7 changed that step's guard from `confirm_sesskey()` to
`require_sesskey()`. So:

- **Before phase 7:** the redirect arrived without a sesskey, `confirm_sesskey()`
  returned false, and the test was **silently skipped**. The user landed on step 3
  being told the connection had not been tested yet — and would press the button
  manually, which worked, so nobody noticed.
- **After phase 7:** the same redirect threw.

Phase 7 did not break this. It made a pre-existing bug visible, which is what
replacing a silent filter with a real guard is supposed to do. Fixed by carrying
the sesskey through the redirect.

**Neither unit tests nor live CLI testing could have found this** — it only
appears when a browser follows the redirect that a form submission issues.

### `normalise()` hardcoded `https://`

```php
$normalised = 'https://' . strtolower($parts['host']);
```

The scheme was assumed rather than kept, so any address normalised through this
came out as https whatever it was. Harmless in production, because `validate()`
requires https before this is ever reached — but it silently rewrote the Behat
fixture's address, and "silently sends the request somewhere else" is not a
property worth keeping. Now preserves the scheme, with a test.

## P8.2 Behat strips $CFG settings it does not recognise

The full-flow scenario refused its own loopback address even though
`$CFG->block_coursesync_allowprivateurls = true` was in `config.php` and
demonstrably visible from CLI.

`lib/behat/lib.php:231` deletes every `$CFG` key that is not on an allowlist or
prefixed `behat_`:

```php
foreach ($CFG as $key => $value) {
    if (!isset($allowed[$key]) && strpos($key, 'behat_') !== 0) {
        unset($CFG->{$key});
    }
}
```

The escape hatch is `$CFG->behat_extraallowedsettings`:

```php
$CFG->block_coursesync_allowprivateurls = true;
$CFG->behat_extraallowedsettings = ['block_coursesync_allowprivateurls'];
```

**Any plugin with a config.php flag needs this if Behat is to see it.** Added to
the CI install and all three live site configs.

Diagnosing it took a throwaway PHP file in the web root printing the flag, curled
over http, then deleted — worth remembering as a technique when CLI and web
disagree about configuration.

## P8.3 How the full-flow Behat test works

A teacher-facing end-to-end test needs a source site. Rather than depend on a
second server being up, **the Behat site is pointed at itself**: a step
definition enables web services, switches on the Course Sync service, issues a
token to an account holding the three required capabilities, and records the
site's own wwwroot as the remote address. Everything after that is a real web
service call over loopback.

One step is not driven through the interface: entering the remote address in
wizard step 1, because that field requires `https` and the Behat site is served
over plain http. The step definition records the address instead. Token, test,
course mapping, sync, results and history are all driven through the UI.

**If the Behat site ever gains https**, that step definition can be dropped and
the scenario can walk step 1 too.

## P8.4 `php -S` cannot serve a request that calls itself

The full-flow scenario timed out at **Save and test**:

```
cURL error 28: Operation timed out after 20002 milliseconds with 0 bytes
received for http://127.0.0.1:8001/webservice/rest/server.php
```

Not a plugin problem. `php -S` is **single-threaded**. Behat's request to
`setup.php` was still being served when that request made an HTTP call back to
the same server, so nothing was free to answer it — a self-deadlock that sits
there until the client's timeout.

Any self-referential test needs worker processes:

```bash
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8001 -t /path/to/moodle/public
```

Note also that `nohup ... &` from a tool-run shell did not survive; `setsid`
with stdin redirected from `/dev/null` did.

## P8.5 Three stale-state traps in one session

All three cost a full Behat cycle each, and all three look like code failures:

1. **A killed task had skipped its rsync.** A command shaped
   `rsync ... && behat ...` was stopped; the next run used the *previous*
   feature file. The failure pointed at a line number that did not match the
   source — which is the tell. Check the deployed copy, not the source.
2. **The Behat site was outdated after a version bump.** Bumping
   `$plugin->version` invalidates the test site: *"Your behat test site is
   outdated"*. Re-run `init.php` after any version change.
3. **The same applies to PHPUnit.** Site A reported *"initialised for different
   version"* and needed re-initialising before its suite would run.

## P8.6 Be cautious of these when testing

### Running the suite on the live servers needs Composer

The shared Moodle checkout had no `vendor/`, so PHPUnit could not run there.
Installed inside the container (`composer install` under PHP 8.2) rather than on
the host (PHP 8.4), so the resolved dependencies match the runtime the sites
actually use. `vendor/` is gitignored by Moodle, so the checkout stays clean.

### PHPUnit assertions against Moodle values

Three separate test failures this phase were `assertSame()` against values Moodle
returns as strings: context ids, `pulledcount`, `cm_info::sectionnum`. Cast, or
use `assertEquals`.

### `PARAM_TEXT` leaves the text inside a stripped tag

`clean_param('Notes <script>alert(1)</script>', PARAM_TEXT)` gives
`Notes alert(1)`. The tags are gone, which is the security property; the word
`alert` remaining is not a finding. Assert that no `<` survives, not that a
particular word is absent.

### The full-flow Behat scenario needs the wizard's step 3 -> 4 navigation

Wizard step 3 (the connection test) and step 4 (the course) are separate pages.
A scenario that presses **Save and test** lands on step 3 and must then
`I follow "Next"` before the course field exists. Easy to miss, because the
wizard reads as one continuous flow.

### The plugin now has 208 unit tests and 18 Behat scenarios

Phase 6's coverage gap is closed: conflict branching (both kinds, and that
neither overwrites nor duplicates), history storage and lookup, the privacy
provider's export and all three delete paths, and payload sanitisation all have
tests now.

---

# Phase 9 — five more activity types

Book, Folder, Assignment, Quiz and Wiki, then a full code review and security
audit. The plugin now syncs ten types and has 230 unit tests and 20 Behat
scenarios.

## P9.1 The bug that only a real HTTP round trip could find

**Assignments, quizzes and wikis were listed as available and then refused when
asked for.** The message shown was "The sync account on the other site is not
allowed to see that course" — which was true of nothing.

`block_coursesync_get_modified_activities` validates the **course** context.
`block_coursesync_get_activity` validated the **activity's** context.
`validate_context()` on a module context calls `require_login($course, false,
$cm)`, which additionally requires the account to be allowed to *view that
particular activity*. That capability differs by type: an unenrolled sync account
holding `moodle/course:view` can read a page, a book or a folder, but not an
assignment, a quiz or a wiki.

So the listing offered ten types and the fetch refused three, and the error
classifier mapped `requireloginerror` to a message about course enrolment that
pointed nowhere near the cause.

Fixed by validating the course context in `get_activity` and
`get_activity_file`, matching the listing. `block/coursesync:sync` on the course
is what authorises the read. See `SECURITY.md` for the reasoning and what it
means.

**Why no unit test caught it:** PHPUnit called `get_activity::execute()` directly
as the admin user. The permission problem only exists for the sync account going
through the web service, which is what the Behat scenario does. There is now a
unit test that builds an account with exactly the two documented capabilities and
reads one of every supported type — but the Behat scenario is what found it.

**Be cautious of this when testing:** a unit test that calls an external
function's `execute()` directly proves the function's logic and nothing about who
is allowed to call it. If a capability matters, set the user.

## P9.2 `assign_plugin_config` names are not form field names

The first version of `assign_handler` rebuilt the assignment's subplugin settings
into the field names mod_assign's settings form submits, on the assumption that
a row `{subtype: assignsubmission, plugin: file, name: maxfiles}` corresponds to
the field `assignsubmission_file_maxfiles`.

It does not. `assignsubmission_file::save_settings()` reads
`$data->assignsubmission_file_maxfiles` and stores it under the name
`maxfilesubmissions`. The form name and the stored name are unrelated, and only
the subplugin knows the mapping.

The symptom was a PHP warning — `Undefined property:
stdClass::$assignsubmission_file_maxsizebytes` — and settings silently reverting
to this site's defaults.

Fixed by carrying the rows as stored and writing them into `assign_plugin_config`
directly, which is exactly what `restore_assign_activity_structure_step` does.
`nosubmissions` then has to be recomputed, because `add_instance()` worked it out
from this site's defaults before the real rows landed.

**Ordering trap:** recomputing it goes through `mod_assign`, which reads the
course cache, so it has to happen **after** `finish_creation()` — the new
activity is not in that cache until its section is set and the cache rebuilt.
Doing it before gives `Invalid course module ID`.

## P9.3 `quiz_add_instance()` does not take a quiz

It runs its argument through `quiz_process_options()` first, which expects what
the settings *form* submits. Two consequences:

- The password must be passed as `quizpassword`. The function does
  `$quiz->password = $quiz->quizpassword;` with no `isset()` guard, so omitting
  it is a PHP warning and a null password.
- **The eight review columns are ignored.** `quiz_process_options()` overwrites
  every one of them by calling `quiz_review_option_form_to_db()`, which rebuilds
  each from four form checkboxes named `{field}{during|immediately|open|closed}`.

Handing it the stored review bitmasks produces a quiz that reviews nothing, with
no error anywhere. `quiz_handler::review_checkboxes()` takes them apart again.

**Read `*_add_instance()` before writing any import half.** Several modules
expect form-shaped data, not stored-shaped data, and the two differ in ways that
fail silently rather than loudly.

## P9.4 Settings the export forgot

Three separate bugs, all the same shape: a field read on import that was never
written on export. Every one was caught by a round-trip test and would have been
invisible to a test of either side alone.

- `quiz`: `navmethod`, `overduehandling` and `preferredbehaviour` were read via
  `$payload->setting()` and never exported. Every copied quiz silently got the
  defaults.
- `assign`: `activity` (the extra instructions) and `attemptreopenmethod` were
  missing for the same reason. `attemptreopenmethod` is a `char` column, so it
  also could not go through the whole-number loop the other fields use.
- `quiz`: `grade` was carried with `setting_int()`, but the column is
  `number(10,5)`. A maximum grade of 12.5 arrived as 12.

**Write the round trip first.** It is the only test that catches this class of
bug, and it catches all of it.

## P9.5 Both test generators insert book chapters at page one

`mod_book`'s PHPUnit generator and its Behat generator both default `pagenum` to
1 and shift every existing chapter down:

```sql
UPDATE {book_chapters} SET pagenum = pagenum + 1 WHERE bookid = ? AND pagenum >= ?
```

So chapters created in the order A, B, C end up in the order C, B, A. Two tests
failed on this and both times the sync was correct and the test was wrong.

**Always pass an explicit `pagenum`** when the order matters. It works as an
extra column in the Behat table too, even though it is not in the generator's
`required` list.

## P9.6 A file area that cannot name its item ids

Every type before this kept its files under one known item id, so the source
could authorise a read by naming the area *and* the id. A book cannot: each
chapter's images are stored under that chapter's id.

The mechanism added is `['filearea' => 'chapter', 'anyitemid' => true]`, which
authorises by area name alone, plus `map_file_itemid()` to translate the source's
chapter id into the one created here.

**This is the only place the file rule is looser, so it is worth testing
precisely.** `book_files_test` pins all three halves: every chapter's files are
found, another area of the same book is still refused, and a handler that names
an item id still only allows that one. A file whose chapter did not arrive
returns `null` and is left behind rather than filed against whichever local
chapter happens to hold that number.

## P9.7 A scripted edit destroyed the language file

Adding four strings to `lang/en/block_coursesync.php` was done by splitting the
file into lines, sorting the ones starting with `$string[`, and reassembling.
Two strings in that file span multiple lines. Their continuation lines were not
`$string[` lines, so they were treated as header material and hoisted to the top
of the file, which stopped parsing.

There was no git repository to restore from. The file was recovered from the
rsynced copy in the CI tree, which had not yet been updated.

**Never sort or reorder a PHP file by lines.** Split it into statements — here,
on `^\$string\[` — and reorder those. And a plugin that is not in version control
has exactly one safety net: the copy in the CI tree, which is only as fresh as
the last rsync.

## P9.8 Tests that asserted the old type list

Four existing tests used `quiz` as their example of "an activity type nothing
handles". Adding a quiz handler broke all four. They now use `glossary`.

**A negative test needs a subject that will stay negative.** Naming a type the
project intends to support eventually guarantees the test breaks on the day it is
supported — at which point it looks like a regression rather than a stale
fixture.

## P9.9 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| Selenium reachability | The compose project's Selenium exposes 4444 but does not publish it. Behat on the host cannot reach it. A throwaway `--network host` container fixes both directions at once, because the browser also has to reach the site at `127.0.0.1:8001` |
| Behat and PHPUnit after a version bump | Both refuse to run against a site built for a different version. Re-run `public/admin/tool/behat/cli/init.php` and `public/admin/tool/phpunit/cli/init.php`. The plugin version went to 2026092007 this phase |
| `behat_dump` | When a step fails, the rendered page is written to `<behat_dataroot>/../behat_dump/<timestamp>/`. Reading it answered in one step what three rounds of guessing had not: it showed the sync results table with three rows marked Failed and the exact message |
| A copied quiz looks broken | It has no questions, on purpose. Check the note on the results page before assuming the sync failed |
| An empty wiki | Same. Settings only; pages stay on the source |
| Grading scales | An assignment or forum graded on a scale the destination does not have arrives **ungraded**, and the run says so. Worth setting up deliberately if you want to see that path |
| `@block_coursesync` Behat tag | Runs all 20 scenarios in about 26 seconds. There is no reason to run less than the whole set |

---

# Phase 10 — six more activity types

Choice, Glossary, Feedback, Database, Workshop and Lesson, then another code
review and security audit. The plugin now syncs sixteen types and has 243 unit
tests and 22 Behat scenarios.

Four of these six are made of records that refer to each other by id, which is
what most of this phase was actually about.

## P10.1 A real XSS hole in my own new code

`mod_feedback`'s `feedback_item.presentation` was carried with `PARAM_RAW`,
which is not cleaning at all. For a **label** item that column holds a block of
HTML, and mod_feedback renders it like this:

```php
// mod/feedback/item/label/lib.php:196
$formatoptions = array('overflowdiv' => true, 'noclean' => true);
$output = format_text($output, FORMAT_HTML, $formatoptions);
```

`'noclean' => true`. Nothing downstream cleans it, so a source site could have
put script in a feedback label and had it run on the destination.

The fix is not simply "clean it", because the same column holds something else
entirely for other question types. A multiple choice packs its options into it
separated by `|` after a marker like `r>>>>>`, and:

```
clean_text('r>>>>>Yes|No', FORMAT_HTML)  =>  'r&gt;&gt;&gt;&gt;&gt;Yes|No'
```

which silently breaks the question. So: labels are cleaned as HTML, everything
else goes through `PARAM_NOTAGS`, which strips markup and leaves separators
alone. Both halves have tests, because both failures are silent.

**The lesson:** *what a column is called tells you nothing about what it holds.*
Before choosing a cleaner, find where the field is rendered and check for
`noclean`, `trusted`, or a raw `echo`. `grep -rn "'noclean' => true" mod/<name>/`
is a two-second check that would have found this immediately.

## P10.2 Two fields that cannot be cleaned at all

`mod_data` stores `csstemplate` and `jstemplate`, and serves them verbatim:

```php
// mod/data/js.php
header('Content-type: application/javascript; charset=utf-8');
echo $content;
```

loaded into every page of the activity by `$PAGE->requires->js()`. There is no
cleaning that makes a block of script safe, because it is script. Accepting it
from another site is handing that site code execution here.

Both are refused, and `syncdatacodetemplates` tells the teacher. A teacher who
wrote them can paste them across by hand, which is a decision a person makes
rather than one a sync makes for them.

**Be cautious of this when testing:** a synced database will look different from
the original if the original was styled. That is correct behaviour, not a bug.

## P10.3 The lesson password needed more than the quiz treatment

A quiz's password is simply not carried; the access rule only applies when the
password is non-empty, so the copy is unprotected and consistent.

A lesson is different: `usepassword` and `password` are separate columns, and a
lesson with `usepassword = 1` and an empty password **lets anybody straight in**.
Carrying the setting without the secret would have produced a lesson that looks
protected and is not — worse than one that is plainly open. So `usepassword` is
turned off with it and `synclessonnopassword` says so.

**The lesson:** when you decline to carry a secret, check what the *flag* that
depends on it does with a blank value.

## P10.4 Six modules, six different ideas of what add_instance() takes

Every one of these was found the same way — run it once, read the PHP warnings.

| Module | What it expects that is not what is stored |
| --- | --- |
| `choice` | Options as two parallel arrays, `option` and `limit`, sharing an index |
| `feedback` | `page_after_submit_editor['itemid']`, read **without** an isset check |
| `workshop` | Three editor arrays, all read without a check, plus `gradecategory` and `gradinggradecategory` in `workshop_grade_item_update()` |
| `glossary` | A display format it actually has — it **throws** rather than falling back |
| `lesson` | `timespent`, `completed` and `gradebetterthan`; `lesson_process_pre_save()` serialises them into `conditions` and unsets them |
| `data` | Nothing unusual, the one straightforward insert of the six |

The lesson one is the same shape as the quiz's review columns in P9.3: a column
that exists in the table is *built* by the save path from form fields that do
not. Export has to take it apart; import has to hand over the pieces.

## P10.5 References between records, and why they need two passes

Four of these six store records that point at each other by id:

| Handler | The reference |
| --- | --- |
| `feedback_handler` | A question shown only if another was answered a certain way |
| `data_handler` | `defaultsort` names a field |
| `workshop_handler` | A rubric level names its criterion |
| `lesson_handler` | `prevpageid`, `nextpageid`, and every answer's `jumpto` |

All of them now use one mechanism on `activity_handler` — `remember_id()`,
`local_id()`, `mapped_id()` — and `book_handler` was moved onto it too, replacing
its own chapter map.

**Two passes are not optional.** A feedback question can depend on one further
down the list; a lesson page can jump forward. Fixing references as you create
records only works if nothing ever points forward, which is not true of any of
these.

**Resolve an unknown reference to a safe value, never pass the number through.**
An id from the other site *will* match some unrelated local record. A lesson jump
is the sharpest case — a wrong one sends a student somewhere the teacher never
intended — so an unresolvable jump goes to the next page rather than to whatever
holds that number.

`lesson_answers.jumpto` has one more trap: only a **positive** value is a page
id. Zero and the negatives are mod_lesson's own constants (`LESSON_NEXTPAGE` is
-1, `LESSON_EOL` is -9, and so on) and mean the same on any site. Core's own
restore uses exactly this rule; it is worth copying rather than inventing.

## P10.6 mod_lesson's generator also inserts at the front

Same trap as `mod_book` in P9.5, in a second module:

```sql
-- the lesson generator, creating a page
UPDATE {lesson_pages} SET prevpageid = ... -- new page becomes the first
```

Pages created A then B come out as B then A. A test that assumed creation order
failed, and — as with the book — the sync was right and the test was wrong.

The fix was better than pinning an order: the test now compares the **shape** of
the two chains, written in page titles rather than ids.

```php
$this->assertSame($this->chain_by_title($source), $this->chain_by_title($copy));
```

**The lesson:** ids are exactly what a sync is supposed to change, so never
compare them across sites. Compare structure, expressed in something stable —
titles, names, or the constants. The same trick made the live verification
meaningful: the source/destination dump writes the feedback dependency as the
name of the question depended on, the sort order as the field's name, and each
lesson jump as `page:Welcome` or `constant:-9`.

## P10.7 My "unsupported type" tests went stale again

Four tests named `quiz` as an example of a type with no handler in phase 9, and
four named `glossary` in phase 10. Both times, adding the handler broke them, and
both times it looked like a regression rather than a stale fixture.

Fixed properly this time with `handler_registry::first_unsupported_modname()`,
which asks the question instead of answering it in advance, and fails loudly if
this plugin ever handles everything installed.

**The lesson from P9.8, restated because I did not act on it hard enough:** a
negative test must not name its subject. Derive it.

## P10.8 A scripted edit split a function from its docblock

Inserting a helper method before `detected()` in `syncer_conflict_test.php` put
it between that function and its docblock, leaving two stacked docblocks and one
undocumented function. The anchor matched, so the assertion passed and the edit
looked fine.

`codechecker` caught it: *"Missing docblock for function detected in testcase"*.

**The lesson:** asserting that a scripted edit found its anchor (P6's lesson) is
necessary but not sufficient — it says the edit landed *somewhere*, not that it
landed *correctly*. When inserting before a function, anchor on the start of its
docblock, not on the function signature.

## P10.9 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| Live sites reset | The compose rebuild emptied the connection table and the source course. Setting the source up again needs `enablewebservices`, `webserviceprotocols=rest`, the service enabled, the sync account authorised in `external_services_users`, **and** a token |
| `curlsecurityblockedhosts` / `curlsecurityallowedport` | Both reset with the site. B calling A on `192.168.56.10:8443` needs the blocklist cleared and 8443 in the allowed ports, **on the calling site**. Already noted in phase 7, and it bit again |
| A text column in a `WHERE` | `set_field('choice_options', ..., ['text' => 'Monday'])` throws *"Comparisons of text column conditions are not allowed"*. Look the row up by id first. My test bug, not the plugin's |
| Creating test data with `*_add_instance()` | It runs the same form-shaped transformations described in P10.4, so the fixture may not hold what you passed. The phase 9 quiz review columns came out as defaults for exactly this reason. Verify the **source** before concluding the sync is wrong |
| Selenium | Same as P9.9: the compose Selenium does not publish 4444. A throwaway `--network host` container fixes both directions |
| `@block_coursesync` Behat tag | 22 scenarios, about 37 seconds |

---

# Phase 11 — H5P

One type, and the easiest of the whole project: `mod_h5pactivity` is a single
settings row plus one file. No child records, no ids to translate, no
form-shaped save path. The plugin now syncs seventeen types, with 246 unit tests
and 22 Behat scenarios.

## P11.1 The only interesting decision: refuse an activity with no package

An H5P activity *is* its package. A copy without one exists, appears in the
course, opens to an error, and — because it carries the synced ID number — is
treated as already done by every later run. That is worse than not creating it.

`h5pactivity_handler` is therefore the first handler to override
`check_payload()`:

```php
return $payload->has_files() ? null : 'errornopackage';
```

The activity is refused before anything is created, reported as a failure rather
than a conflict, and — because the run then has a failure in it — `lastsync` does
not move, so it is tried again next time. That chain of consequences is the whole
reason the check is worth having.

**The general lesson:** ask what happens on the *next* run when a copy is
half-made. If the answer is "nothing, ever", refuse the copy instead.

## P11.2 Copying the file is not the same as proving it works

The source/destination dump matched byte for byte, including the package's
contenthash. That proves the transfer, and it proves nothing about whether the
activity is usable.

H5P content has to be *deployed*: `core_h5p` unpacks the package, installs the
libraries it contains, and registers the content. That happens on first view, in
`core_h5p\player`, not at upload. So the check that actually matters is running
what `mod_h5pactivity/view.php` runs:

```php
$player = new \core_h5p\player($fileurl->out(false), $config, true, 'mod_h5pactivity', true);
$messages = $player->get_messages();   // ->error and ->exception
echo $player->get_title();
```

On the destination this returned `Fill in the Blanks` with no errors, and
`h5p_libraries` went from empty to 12 rows — the libraries came out of the
copied package and installed themselves. That is the evidence the sync works.

**Be cautious of this when testing:** for any activity whose content is a
packaged file, a passing contenthash comparison is a transfer test, not a
functional one. Find the module's own "open it" path and run that too. The same
applies to SCORM and IMS if they are ever added.

## P11.3 A 716 KB package is a genuine multi-chunk transfer

`file_sync::CHUNK` is 524288 bytes, so the fixture
(`h5p/tests/fixtures/filltheblanks.h5p`, 716126 bytes) crosses in two chunks and
exercises reassembly and the SHA1 check for real. Earlier live tests used files
of a few dozen bytes and never touched that path.

**Worth keeping:** when testing file transfer, use something over 512 KB. Small
fixtures silently skip the only interesting code.

## P11.4 What add_instance() wanted, for once, was almost nothing

After phases 9 and 10, the habit is to expect a form-shaped surprise.
`h5pactivity_add_instance()` has exactly one: `h5pactivity_set_mainfile()` reads
`$data->packagefile` as a draft area id, and does nothing at all when it is
falsy. Setting it to 0 and writing the package into the real area afterwards is
the same pattern `resource_handler` and `folder_handler` already use.

`displayoptions` is stored as an integer bitmask and inserted **as it is** —
unlike the quiz's review columns, which are rebuilt from form checkboxes. Two
modules, two opposite conventions for the same kind of field. There is no rule
here; read the function.

## P11.5 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| H5P fixtures | `public/h5p/tests/fixtures/*.h5p` are real packages and are what the generator uses by default. `filltheblanks.h5p` is a good size for exercising chunked transfer |
| The H5P generator needs a user | `mod_h5pactivity` generator throws `H5P activity generator requires a current user` if `$USER` is not set. `setAdminUser()` first |
| Library installation | Deploying a package installs its libraries site-wide. On a shared destination this is a real side effect of syncing, though the same one any upload would have |
| A copied H5P that will not open | Check `h5p_libraries` on the destination and run the `player` check in P11.2. The package arriving intact does not mean it deployed |

---

# Phase 12 — choosing what to sync

The sync page no longer offers all-or-nothing. It lists what is in the other
course but **not** in this one, each row a ticked checkbox, and copies only what
is left ticked. 260 unit tests, 23 Behat scenarios.

## P12.1 The same bug twice, and the second one was permanent

Leaving something out has to hold `lastsync`. Otherwise the marker moves past the
activity the teacher declined and it is never offered again — "not this one"
would silently become "not ever".

That rule is easy to state and easy to get wrong, because plenty of things are
*not chosen* without being *a choice*. Both of these turned up in live testing,
neither in unit tests:

**First:** an activity of a type this plugin cannot handle was reported as
*"You chose not to copy this one. It will be offered again next time."* It was
never on the list. The deselection check simply ran before the
`handler_registry::supports()` check.

**Second, and much worse:** anything already in the course was counted as
deselected too. That one is permanent. Deselect once, the marker holds; every
later run re-detects the same already-present activities, finds them "not
chosen", and holds the marker again — **forever**, however diligently the teacher
ticks everything on offer. The live run showed `held=true` when the teacher had
chosen every single thing they were given.

The fix is to decide *why* something was not chosen:

| Reason | Key | Holds the marker? |
| --- | --- | --- |
| No handler for the type | `syncskippedtype` | no |
| Already in this course | `syncskippedpresent` | no |
| The teacher unticked it | `syncskippeddeselected` | **yes** |

**The lesson:** when a flag means "the user decided something", check that the
user could actually have decided it. Anything they were never shown must not
feed that flag — and if the unshowable thing recurs on every run, the effect is
not a one-off glitch but a permanent stall.

## P12.2 Filtering a list can throw away a warning

The request was "only list what is not in the destination". Taken literally that
also hides the case phase 6 existed to catch: something in this course carrying a
synced activity's identity that Course Sync did **not** put there.

Before, that surfaced as a `conflictlocalactivity` after a run. Filter it into
"already here" and the warning disappears — the teacher is told nothing, and two
activities quietly share an ID number.

So `sync_candidates` has four groups, not three, and `list_candidates()` asks
`history::was_pulled_here()` — the same question `handle_one()` asks — to tell
ordinary housekeeping from something worth a look.

**The lesson:** before filtering something out of a view, ask what the old view
was *for*. A filter that removes noise can remove a signal that happened to be
travelling with it.

## P12.3 Conflicts became mostly unreachable, on purpose

Worth writing down because it changes what the code means rather than what it
does. Conflicts used to be how a teacher discovered an activity was already
here. Now the list filters those out beforehand, so in normal use a conflict
never happens: the situation is prevented rather than reported.

The path stays as a safety net — a race between listing and submitting, and any
`run()` called without a selection — and `syncer_conflict_test` still covers it.
But the Behat scenario that exercised conflict display had to change, because the
UI no longer reaches it. It now asserts the better behaviour: a second pass says
*"Everything in the other course is already here"* and offers no form at all.

**Be cautious of this when testing:** if you are looking for the conflict
display, you will not find it by syncing twice any more.

## P12.4 A Behat step already existed for unchecking

I wrote `When I uncheck the Course Sync activity "X"` before checking. Moodle's
`behat_form_checkbox::set_value()` treats anything `empty()` as unchecked, so:

```gherkin
When I set the field "Unwanted bits" to ""
```

does it with no custom step definition, as long as the checkbox has a `<label
for="...">` — which it needs for accessibility anyway. The label can be in a
different table cell; `for` is what links them.

## P12.5 A stale `php -S` server fails as HTTP 403, not as a connection error

*Update, 2026-09-22: restarting only hid it. The real cause was a Behat step
trusting `$CFG` across Moodle's per-scenario database reset - see P13.6.*

Seven Behat scenarios failed with `block_coursesync_ping got HTTP 403` after a
run that had passed 23/23 minutes earlier, with no relevant code change between.

The web server was still running and still served `/` with a 200. What it had was
a stale cached config, so `webservice/rest/server.php` reported *"the web
services or the REST protocol are not enabled"* and answered 403.

Restarting the server and re-running `admin/tool/behat/cli/init.php` fixed it,
unchanged code passing 23/23 again — and then it happened a second time, on the
very next round, which pinned the trigger:

> **After every `rsync` of the plugin into the CI tree, re-run
> `public/admin/tool/behat/cli/init.php` and restart `php -S` before running
> Behat.**

Between rounds the site is left in whatever state the last Behat run's teardown
put it in, and a long-lived server keeps serving from a config cache that no
longer matches. The first request still returns 200 for `/`, so the server looks
healthy; only the web service call fails.

**Be cautious of this when testing:** a 403 from the self-sync is an environment
symptom, not a plugin bug. The `php -S` log names the real cause in one line —
*"the web services or the REST protocol are not enabled"* — so `tail` it before
chasing anything in the plugin. Both times, the identical code passed once the
server was restarted.

## P12.6 Lang strings need a cache purge without a version bump

A new string added after the version had already been bumped rendered on the
live site as `[[syncskippedpresent]]`, with a debugging message asking whether it
was missing from the lang file. It was not; the cache was stale.

`php admin/cli/purge_caches.php` fixed it. A version bump does this as part of
the upgrade, which is why this only bites when editing strings *after* bumping.

## P12.7 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| Live testing found both P12.1 bugs | Unit tests passed throughout. What exposed them was printing `lastsync before/after` on a real run and reading the reason text on each row. Assert on the *reason*, not just the count |
| The preview page still lists everything | `preview.php` answers "what changed over there", the sync page answers "what can I bring over". Two lists, two questions — do not expect them to match |
| Deselecting holds the marker | So the next run re-detects everything since the *old* marker, including what was just copied. The list stays clean because those are filtered out, but the run's item list will mention them as already here |
| `php -S` and Behat | See P12.5. Restart it between rounds of work rather than trusting a long-lived one |


---

# Phase 13 — question banks, quiz questions and repeatable sync (2026-09-22)

*Reconstructed on 2026-09-23 from project notes, not written at the time: this
log was not kept up during that session. Details are as recorded then.*

Question bank activities (`qbank`) and a quiz's questions - fixed and random
slots - are synced through a shared `question_bank_sync_trait`, carrying each
question as a `qformat_xml` fragment. Later that day: Cloze, repeatable sync,
and the grouped sync page. 268 unit tests, 25 Behat scenarios.

## P13.1 A real teacher's quizzes arrived empty because every question was Cloze

The quiz synced "successfully" with no questions. The summary banner the user
pasted twice said nothing useful; the per-row detail named the unsupported
type. Adding `multianswer` needed no special code - `qformat_xml` already
round-trips the sub-questions through the parent's text.

**Lesson:** when a user reports a sync problem, get the exact text of the
failing row, not the summary. And ask *which site* - the user also runs the
plugin on a real install outside Docker and CI.

## P13.2 A deleted copy could not be synced back until cron ran

Moodle's "Delete" is asynchronous: the course module sits with
`deletioninprogress = 1` until an adhoc task runs. `find_existing()` saw it
and called the re-offered activity a conflict. It now ignores such rows.

## P13.3 Six real syncs showed as "collisions" on site A

The Docker rebuild on 2026-09-20 kept course data but lost one
`block_coursesync_run` row, so `was_pulled_here()` could not vouch for six
genuine copies. Backfilled one history row in the same JSON shape.

Then a later test ran `delete_records('block_coursesync_run', [])` on the same
shared site and silently destroyed the backfill, bringing the bug back.

**Be cautious of this:** never wipe a shared test site's history table
wholesale; delete only the rows your own test created.

## P13.4 Unsupported types left off the choose page

The page now groups "Ready to copy" (ticked) above "Already on this course"
(disabled). Types with no handler are not shown at all.

## P13.5 First Behat run on the CI install, and a server that deadlocked

Until 2026-09-22 Behat had never been run on the CI install at
`/home/vagrant/cs-pro/ci/moodle` - only PHPUnit's tables existed. Setup, once:
`php public/admin/tool/behat/cli/init.php`. Then, every session:

- `PHP_CLI_SERVER_WORKERS=2 php -S 127.0.0.1:8001` from `public/`. **A
  single-worker server deadlocks**: these scenarios point the site at itself
  as the sync source, so a request such as "Save and test" calls back into
  the same server while it is still busy serving the first. Two workers is
  enough.
- Selenium with `--network=host`, so it can reach `127.0.0.1:8001`. The
  Docker compose project's `selenium` container is on another network and
  cannot be reused.
- `MOODLE_START_BEHAT_SERVERS=NO`: moodle-plugin-ci's own server start
  clashes with an orphaned `php -S localhost:8000` from an earlier session.
- Tear both down afterwards.

## P13.6 The real cause of the intermittent 403 (corrects P12.5)

P12.5 blamed a long-lived `php -S` with stale config, because restarting it
made the 403 go away. It came back roughly every other run. Instrumenting both
the Behat step and `webservice/rest/server.php` found the actual cause:

The step `this site is set up as a Course Sync source` only wrote
`webserviceprotocols` if `$CFG->webserviceprotocols` did not already contain
`rest`. `$CFG` lives in the single Behat CLI process that runs every scenario
in a feature. Moodle resets the **database** between scenarios, but not that
process's `$CFG`. So scenario N set it; the reset wiped it from the database;
scenario N+1 saw `rest` still in `$CFG`, skipped the write, and the web service
refused - `$CFG` said `rest` while the database row did not exist.

**Fix:** read the current value from `$DB`, not `$CFG`, before deciding.
Eleven back-to-back full runs passed, against about half failing before.

**Lesson:** never gate a write on `$CFG->x` inside a Behat step. `$CFG`
outlives the per-scenario reset. Read the database, or call `set_config()`
unconditionally.

## P13.7 A Behat assertion that the grouped sync page made wrong

`sync_now.feature` ended with `I should not see "Wanted notes"`. Once the sync
page listed already-synced activities (disabled, "Already synced"), the copied
activity legitimately still appears. Now it checks the row exists and its
checkbox is disabled. Any scenario asserting an already-synced activity is
*absent* from that page is testing the old behaviour.

## P13.8 The Docker sites' bind mounts went stale again

After heavy file churn elsewhere under `/home/vagrant/cs-pro` (an `npm ci` of
1164 packages, and `rsync`/`chown` on a sibling path), sites A and B stopped
seeing edits to the plugin, though `docker inspect` still showed the right
source path. An inode mismatch and a probe file confirmed it.

**Fix**, from `env/`: `docker compose up -d --force-recreate --no-deps web-a
web-b` - only the two web containers, so no data or database is touched. Then
`admin/cli/upgrade.php` and `admin/cli/purge_caches.php`.

**Be cautious of this:** if a live page stops reflecting a host edit, write a
probe file to the host plugin path and `docker exec cat` it before suspecting
the code. `docker inspect` shows the mount as configured at creation, not
whether it is live.

## P13.9 The CI install's routine

Plugin source of truth is `moodle-block_coursesync`; the CI copy under
`ci/moodle/public/blocks/coursesync` must be refreshed with
`rsync -a --delete --exclude .git` after every edit, and
`public/admin/tool/phpunit/cli/init.php` re-run after any `version.php` bump
(PHPUnit refuses to run otherwise: "initialised for different version").

---

# Phase 14 — drag-and-drop question types (2026-09-23)

`ddwtos`, `ddimageortext`, `ddmarker` added to `SUPPORTED_QTYPES`. No other
code: each type's own `export_to_xml()` inlines its background and drag images
as base64. 269 unit tests.

## P14.1 Proving a one-line change

The new round-trip test was run once with the list reverted, to prove it fails
without the change (it did: the three questions were counted as unsupported).
Worth doing for every "just add it to the list" change - a test that passes
either way proves nothing.

## P14.2 The CI database had stopped overnight

`pg_connect ... Connection refused` on port 5433. Not a plugin fault:
`docker start coursesync-ci-db`, then wait for `pg_isready`. Stop it again
after testing.

## P14.3 Why no code was needed, and what the test checks

`ddimageortext` and `ddmarker` keep images in their own file areas
(`bgimage`, `dragimage`), not in the question text. Their `export_to_xml()`
writes those files as base64 inside the question's XML, `import_from_xml()`
turns them back into draft areas, and `save_question_options()` files them
under the new question - so the shared question code needed nothing.

The round-trip test (`test_drag_and_drop_types_round_trip`) checks drag
items and drop zones row for row - positions and marker coordinates
included - the background images byte for byte (content hash), and a drag
item that is an image. The core fixture has only word drag items, so the test
attaches an image to one drag itself.

## P14.4 A stale comment found on the way

`qbank_handler.php` said "Six supported types" while the list held seven
(Cloze had been added without updating it). Now kept in step whenever the list
changes; the docs say the number too. Released as v1.7.0-beta.

---

# Phase 15 — updating changed activities (2026-09-23)

A copy whose source changed after the run that pulled it is offered, unticked,
as "Changed since it was copied". The user chose the rule: replace the copy if
nobody has data in it, otherwise keep it and add the fresh copy beside it as
"(New edition)". `copy_update` decides using each activity's own privacy
provider plus completion states. 277 unit tests, 27 Behat scenarios.

## P15.1 Deleting the old copy has to be the last step

The first draft deleted the old copy and then set the identity on the new one.
If that later step threw, the error path removed the new copy too - leaving
neither. Reordered: everything on the new copy first, `course_delete_module()`
of the old one last.

## P15.2 `moveto_module()` wants modinfo, not a database row

`Undefined property: stdClass::$modname` from `course/lib.php`. It reads
`modname`, `section` and `visibleold`, so pass
`get_fast_modinfo($courseid)->get_cm($id)` after `rebuild_course_cache()`.

## P15.3 A flaky Behat test that was really a clock tick

The update scenario passed once, then failed every time. Behat copied the page
and edited it within the same second, and "changed" is measured to the second
(strict `>`), so the edit did not count. A new step,
`the Course Sync runs in course "X" happened a minute ago`, makes it
deterministic. Real users never edit within the second of a sync.

## P15.4 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| `pkill -f "php -S ..."` | Matches the shell running the command too (exit 144). Use `pgrep -f "^php -S ..." \| xargs -r kill` |
| Text columns in WHERE clauses | `get_field(..., ['text' => 'Yes'])` fails on Postgres: "Comparisons of text column conditions are not allowed" |
| Expected debugging() calls | A test that provokes an HTTP 500 must call `assertDebuggingCalled()` |

## P15.5 The decision was the user's

Updating an existing copy was on the "deliberately not built" list. When asked
for it, the user was given three options: replace the copy only if nobody has
data in it; always replace, with a warning; or update in place (weeks of
per-type work). Their answer combined the first with a new idea: **replace if
there is no student work; otherwise keep the old copy and add the fresh one
beside it as "(New edition)"**.

## P15.6 How it decides and what it keeps

- **Changed** means the source's `timemodified` is later than the start of
  the run that pulled the copy (`history::pulled_at()`, from the same history
  rows `was_pulled_here()` reads).
- **Offered unticked**, because it changes something already in the course.
  Unticking holds the last-synced marker like any other deselection, so it is
  offered again. A run with no choice (`$only === null`) never updates - it
  still flags `conflictchangedupstream`.
- **Somebody's data** is read from the activity's own privacy provider
  (`core_userlist_provider::get_users_in_context()`), so it needs nothing per
  type; completion states are checked separately because they belong to the
  course module; a question bank also counts as used when it holds questions
  added locally or used by a quiz. **Judgment call, stated to the user:**
  completion progress counts as student work, so a page someone has
  completed gets a new edition, not a replacement. A teacher's own data
  (a quiz preview, an announcement) counts too.
- **A replacement keeps the course's own set-up:** section and position,
  visibility, access restrictions, completion settings, grade categories; and
  other activities' restrictions and course completion criteria are repointed
  to the new copy (`update_dependency_id_across_course()`). The old copy goes
  to the recycle bin where that is enabled.
- **A new edition** leaves the old copy untouched except for clearing its ID
  number (course module and grade item), so the new one takes the identity.
- The fresh copy is always created first with an **empty** ID number, so any
  failure leaves the old copy exactly as it was.

## P15.7 Limits recorded at the time

- Edits a teacher made to the copy itself are lost when it is replaced.
- Editing a quiz's questions on the source does not change the quiz's own
  `timemodified`, and a replaced quiz reuses the questions already copied.
- Replacing deletes an activity with only `block/coursesync:sync`; bounded to
  copies this plugin made, with nobody's data, that a teacher ticked.
  Recorded in `SECURITY.md`.

Tests: `syncer_update_test.php` (8), including a failed fetch leaving the copy
alone, plus a Behat scenario. Released as v1.8.0-beta.

---

# Phase 16 — select missing words, ordering, random matching, description

Four more `SUPPORTED_QTYPES`. 278 unit tests.

## P16.1 My test compared against the form, not what Moodle saved

`gapselect` renumbered `[[3]]` to `[[2]]` in the copy. So did the source:
saving compacts around an empty choice. Compare copies with the saved record,
never with generator input.

## P16.2 A random-draw assertion that passed by luck

Random short-answer matching draws 2 of 3 questions. When it drew Frog and Toad
there was only one answer, "Amphibian", and my assertion wanted two. It passed
the first time by chance and was caught a round later. Assert what holds for
every draw, and re-run anything random 10-15 times before trusting it.

## P16.3 `randomsamatch` has no test generator

Created as a short-answer question and turned into one directly in the database
(`qtype` plus a `qtype_randomsamatch_options` row).

## P16.4 The tests' "unsupported" example had to move

`description` was the tests' example of an unsupported question type (unit
tests and `qbank_sync.feature`). Once supported, the example became
`calculated` - which lasted one phase (see P17.4).

## P16.5 A limit of random short-answer matching

It stores only settings (`choose`, `subcats`) and draws short-answer questions
from its own category at attempt time. Every question in a synced category
travels, so that works - but with "include subcategories" on, a quiz sync only
brings subcategories the quiz references elsewhere, so the copy may have fewer
to draw from. Syncing the whole question bank does not have this gap. In the
teacher guide. Released as v1.9.0-beta.

---

# Phase 17 — calculated question types

All 17 core question types are now supported. 279 unit tests.

## P17.1 Datasets are only loaded for export when you ask for an export

`get_question_options()` for the calculated types only reads dataset
definitions and values when `$question->export_process` is set.
`question_bank::load_question_data()` never sets it, so the XML had formulas
and nothing to calculate them from. Fix: `with_export_data()` sets it on a
**clone** (the data is cached; mutating it would change it for every reader).

## P17.2 I added an import flag that was not needed - and proved it

I also set `import_process` on the destination. A test with it removed still
passed: `qformat_xml` already sets it in `defaultquestion()`. Removed. Checking
each half of a two-part fix separately is what found this.

## P17.3 Core's calculated fixtures cannot even be attempted

The generator's `calculated` and `calculatedmulti` questions have dataset
definitions but no values ("Cannot get the specified dataset"). The test adds
values before copying.

## P17.4 No core type left to be "unsupported"

Tests that used an unsupported question type now relabel a question as
`qtype = 'notinstalled'` (a Behat step does the same). A quiz refuses to add an
unknown type to a slot, so relabel after adding.

## P17.5 Shared datasets stay shared, and the test answers each copy

A dataset shared across a category (two questions using one set of `{a}` and
`{b}` values) is recreated once in the new category by the first question and
found by name by the rest (`qtype_calculated::import_datasets()`); the test
checks both copies point at the same definitions.

Each copy is also attempted: loaded with `question_bank::load_question()`,
started, answered with `get_correct_response()` and graded - it must score 1.0.
That is the proof the values arrived; a structural comparison alone would not
be. The dataset values are drawn at random, so the test was run ten times.
Released as v1.10.0-beta.

---

# Phase 18 — files embedded in text

Every activity's `intro` area is now transferred automatically
(`activity_handler::file_areas()`), plus page content, feedback label items,
and workshop criteria (under their `workshopform_*` component). The warning now
names only files that really did not arrive. 286 unit tests.

## P18.1 Book and Lesson would have dropped description images

Both override `map_file_itemid()` and return null for areas they do not know.
`local_file_itemid()` now settles `intro` as item 0 before asking the handler.

## P18.2 The receiving site must check areas too

Only the source checked declared areas. Now `file_sync` refuses to store a file
in a component/area its own handler does not declare, whatever the source
lists.

## P18.3 Old history rows would show a literal `{$a}`

Changing `syncfilewarning` to take a parameter would break every stored run. A
new key `syncfilesmissing` takes the list; the old string stays for old rows.

## P18.4 Negative checks

Each of three safeguards was removed in the CI copy and its test failed: the
intro area, the base-class intro mapping, the destination area check.

## P18.5 A new optional `component`, compatible both ways

A workshop criterion's images are filed under its grading strategy's
component (`workshopform_*`), not `mod_workshop`, and file transfer assumed
`mod_<type>` everywhere. File areas can now name a component, listed files
carry it, and `get_activity_file` takes an optional `component` parameter.
A newer destination only sends it for a file that has one, which an older
source never lists - so a newer destination still works with an older source.
Both sites need this version for the new files to arrive at all.

## P18.6 Testing the whole path on one site

`tests/local/source_on_this_site.php` began here (inside
`embedded_files_test`): a mock HTTP client that answers each file request by
running the real `get_activity_file` on the same test site. Export, listing,
refusal rules, transfer and filing all run together. Three existing tests had
used `intro` as their example of an undeclared area; they now use areas that
really are undeclared (another module's component, a student-work subplugin).

## P18.7 A gap found on the way

A quiz's overall feedback was not copied at all, images or not. Recorded as a
known limitation, then done as Phase 19. Released as v1.11.0-beta.

---

# Phase 19 — quiz overall feedback

`quiz_feedback` bands travel as children and are inserted directly, keeping
exact `mingrade`/`maxgrade`, instead of going through the form's percentage
boundaries. 288 unit tests.

## P19.1 Why the bands are inserted directly

`quiz_add_instance()` only creates feedback when given the edit form's shape:
a list of boundaries, which `quiz_process_options()` re-derives from
percentages and re-validates. Given none, it creates none. So the handler
exports each `quiz_feedback` row - text, format, `mingrade`, `maxgrade`, id -
and inserts them exactly as stored right after the quiz is created. The top
band's upper limit is one more than the quiz's grade, as Moodle stores it,
and the grade travels with the quiz, so that stays true.

They are created before the questions, which can fail partway without undoing
the quiz; feedback does not depend on them.

## P19.2 Images follow their band

A band's images are in `mod_quiz` / `feedback` under the band's own id. The
area is declared with any item id, and `map_file_itemid()` maps each file to
the band created here for it.

## P19.3 What the tests check

`test_overall_feedback_is_copied_band_for_band` builds three bands the way the
form does (70% and 40% of a 10-mark quiz) and checks every band's text and
range match exactly, that a score of 5/10 gets the same message on the copy,
and that a quiz with no feedback gets none.
`test_a_quiz_feedback_bands_image_follows_the_band` checks an image lands under
the new band. Both were confirmed to fail without the change. Released as
v1.12.0-beta.

---

# Phase 20 — SCORM and IMS content packages

Only the zip travels. `post_files()` unpacks and parses it with Moodle's own
calls, then checks it would open (P11.2): a SCORM that parsed with a launch SCO
whose file exists, an IMS package whose first page exists. 295 unit tests,
28 Behat scenarios.

## P20.1 `has_files()` stopped meaning "the package came"

Once description images travel, an H5P activity with an image but no package
passed the package check. All package checks now use
`has_file_in('package', 0)`.

## P20.2 An IMS table of contents can start with a heading

My first check read the first entry's `href`; the fixture opens on
`shared/launchpage.html` in a subfolder, and entries can be headings with no
page. Search depth first and resolve folders.

## P20.3 Behat link click "out of bounds of viewport"

The course page drawer pushed the link off-screen. `change window size to
"large"` and click `in the "region-main" "region"`.

## P20.4 Why these differ from H5P

H5P content is deployed when first opened (P11.2). SCORM and IMS are unpacked
and parsed **when the activity is created** - inside `scorm_add_instance()` /
`imscp_add_instance()`, from the package area. But this plugin writes files
after creation (the context must exist first). So `post_files()` re-runs what
creation would have: `scorm_parse($scorm, true)`, or `extract_to_storage()`
plus `imscp_parse_structure()`. Only the zip travels; the source's unpacked
`content` area and parsed tables never do.

## P20.5 What "it opens" means for each

- **SCORM:** the parse did not end in `version = 'ERROR'`, and the launch SCO
  (`scorm.launch`) exists - checked by the handler; the tests also check the
  launch SCO's file exists in the unpacked content, the path `player.php`
  resolves. SCORM 2004 and SCORM 1.2 fixtures both round-trip with the same
  SCO structure.
- **IMS:** a table of contents was parsed (`view.php` refuses to show a package
  without one) and its first page exists, searching depth first because an
  entry can be a heading, and allowing an absolute web address.
- Otherwise `errorpackagenotdeployed`, and `syncer::create_copy()` removes the
  half-made activity. A test swaps in a package with no manifest to prove it.

## P20.6 SCORM types and settings

- Only an uploaded (`local`) package can be copied. `external` and `aiccurl`
  are links to packages elsewhere, with nothing to send: refused with
  `errorscormnotuploaded`, telling the teacher to add it with the same link.
- `localsync` (kept in sync with a web address) does have its latest package
  here, so it is copied as `local`, with a note that it will no longer update.
- The popup window's `options` string travels as separate `popup_*` settings,
  so `scorm_option2text()` rebuilds it in this site's own format.
- Choice settings (grading method, table of contents, navigation...) are
  checked against this site's SCORM lists. The SCORM constants are defined in
  `mod/scorm/locallib.php`, which a test must load before using them.

## P20.7 IMS keeps only the current package

`mod_imscp` keeps each upload under its revision number in `backup`, and a
source with "keep old packages" has several. `map_file_itemid()` keeps only
the current revision's, as revision 1 here; older ones are never fetched (a
test checks the fake source was asked for one file only).

## P20.8 Tests

`package_handlers_test.php` (7) and `package_sync.feature`, which syncs both
types and opens each copy in a browser: the SCORM player launches
(`#scorm_object`), the IMS table of contents shows. Removing the unpack step
made all four copy-and-open checks fail. The shared test trait
`source_on_this_site` was extracted from `embedded_files_test` here.

**Security note** (in `SECURITY.md`): a package from the source is unpacked
with Moodle's own zip packer and served by the module's own rules - exactly as
an uploaded one; its content is as trusted as a teacher's upload. Released as
v1.13.0-beta.

---

# Phase 21 — LTI and BigBlueButton

User-confirmed design: an external tool is only linked to a tool already set up
on the destination (Moodle's own `lti_get_tool_by_url_match()`), otherwise
refused naming the tool; secrets never travel. BigBlueButton rooms are set up
afresh; role participant rules kept by shortname, per-user rules dropped.
302 unit tests, 29 Behat scenarios.

## P21.1 Core's LTI generator makes tools no admin could

`create_tool_types()` leaves tools pending with no `tooldomain`, so nothing
matched. Real tools saved through the admin screens are configured and have a
domain. Tests pass `state => 1` and `lti_toolurl`; the Behat tool table needs
the same columns. The handler was not loosened to fit the fixture.

## P21.2 An expected refusal must not take the error path

Refusing inside `create_from_remote_data()` logged a developer `debugging()`
message, which Behat fails on. It was also semantically wrong: a missing tool is
expected. New hook `check_destination(course, payload)` runs before anything is
created; `failure_notes()` lets the refusal name the tool.

## P21.3 Two design decisions were the user's, not mine

Both were asked before any code was written, because each changes what
happens to real students' data:

- **LTI - which tool the copy uses.** Chosen: only a tool this site's
  administrator already set up, matched by address; otherwise refuse and
  name the tool. Rejected: creating it hidden with no tool, and copying the
  source's tool address as-is (student names or emails could go to a
  service this site never approved).
- **BigBlueButton - the participant list.** Chosen: keep "everyone" and role
  rules, drop rules naming individual people. Rejected: ignoring the list
  and using this site's default.

What was *not* asked, because it is not a choice: secrets never travel,
recordings stay on the source, each copy gets its own meeting.

## P21.4 LTI: this site's tool decides what is shared about people

The activity's own "send name / send email" choices are carried, but
`lti_add_instance()` runs `lti_force_type_config_settings()`, which overrides
them with whatever this site's matched tool enforces. A test has the source
allow both and the destination's tool forbid both; the copy sends neither.

An activity set up with its own key and secret and no site tool (`typeid` 0)
is refused (`errorltiownsecret`): without the secret, which never travels, it
could not launch. The generator gives every LTI activity a sample key and
secret, which usefully tests that none appear anywhere in the export.

**Security note:** outside tests, `lti_add_instance()` may request the tool
address to see whether it is a cartridge. By then the address has matched an
approved tool's domain, so that request can only go somewhere this site's
administrator already trusts - the same as a teacher adding the activity.

## P21.5 BigBlueButton: a fresh room, and what could not come

- `bigbluebuttonbn_add_instance()` generates the meeting id and the moderator,
  viewer and guest credentials itself. None of the source's are exported - a
  test checks each value is absent from the export - and two courses must
  never share a live room.
- Recordings live on the source's BigBlueButton server against the source's
  meeting, so they cannot come.
- A participant rule stores a *role id*, which is local to each site. The
  export turns it into the role's shortname and the destination turns it
  back; a rule naming a person, or a role this site lacks, is dropped and
  counted in the results.
- The dial-in number (`voicebridge`) belongs to one room on one server, so the
  copy gets none, with a note if the source had one.
- The preloaded presentation travels as a file (`presentation` area) and the
  room is pointed at it in `post_files()`.
- BigBlueButton is off in a new Moodle until an administrator enables it,
  which includes accepting its data-processing terms. On such a site the room
  is refused (`errorbbbnotenabled`) and comes across once it is enabled.
- Not tested: opening a copied room, which needs a BigBlueButton server.

## P21.6 Failures that name something

A failed item could only carry a fixed message. `sync_result::add_failed()`
now takes notes, and handlers supply them through `failure_notes()` - which is
how "the tool it needs: Quiz engine (https://...)" reaches the results page.

## P21.7 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| BigBlueButton in tests | Disabled by default: `\core\plugininfo\mod::enable_plugin('bigbluebuttonbn', 1)`, or Behat `I enable "bigbluebuttonbn" "mod" plugin` |
| A missing tool on one test site | Use a *course* tool in the source course; the destination course cannot see it |
| Refused LTI stays "failed" | By design - it holds the marker and is retried once the admin adds the tool |

Released as v1.14.0-beta, with `lti_bbb_handlers_test.php` (7) and
`lti_bbb_sync.feature`, which checks the results page: a tool with a match is
created, one without is refused naming the tool, and the room notes appear.

---

# Phase 22 — subsections, and where activities land

`mod_subsection` supported: every activity module in standard Moodle now has a
handler (23). Activities inside a subsection land inside its copy. 307 unit
tests (one deliberately skipped), 30 Behat scenarios.

## P22.1 A placement bug that already existed

A subsection's delegated section is numbered after the ordinary ones, and the
source reported an activity inside one by that number. The destination clamped
to its own highest section number - which, in a course with subsections, is a
delegated section. So an activity could land inside an unrelated subsection.
Now the source reports the subsection's ordinary section plus
`subsectioncmid`, and `resolve_section()` only ever returns ordinary sections.

## P22.2 Replacing a subsection would have deleted everything inside it

`subsection_delete_instance()` deletes its section with
`forcedeleteifnotempty = true`. The update feature (Phase 15) replaces a copy
when nobody has data in it - and a subsection itself never holds anyone's data.
So a renamed subsection would have been "replaced", taking every activity
inside with it. Subsections are updated in place instead
(`updates_in_place()` / `update_in_place()`), through Moodle's own rename.

**Lesson:** a generic rule ("replace if nobody has data in it") must be checked
against every type that *contains* other things, not just the activity itself.

## P22.3 A rule I added on a wrong premise, found by instrumenting

I assumed renaming a subsection's section left the subsection's `timemodified`
alone, so change detection would miss it, and added a rule to report the
section's time instead. The negative check showed the rule made no difference.
Instrumenting the test showed why: `preprocess_section_name()` renames the
module too, moving its `timemodified`. The rule was removed (as in P17.2).

**Lesson:** when a negative check still passes, the test is not testing what
you think - find out why before trusting either the code or the test.

## P22.4 Every test's "unsupported type" is gone

`first_unsupported_modname()` now returns null on a standard site. Tests that
only need a *name* use `?? 'thirdpartymodule'`. The source-side refusal test
needs an installed module without a handler, so it now skips with a reason
rather than passing vacuously. `handlers_test` instead asserts that every
module in `core_plugin_manager::standard_plugins_list('mod')` has a handler, so a
new Moodle module will fail loudly.

## P22.5 Two stale caches made broken code look fine

Both showed up while checking that the new Behat scenario fails without
subsection placement:

- **OPcache.** `php -S` runs with `opcache.revalidate_freq=2`. Editing a file
  and running Behat within two seconds served the old code: the negative
  check "passed". Wait a few seconds after editing, or restart the server.
- **Behat's gherkin cache**, `/tmp/behat_gherkin_cache`. It treats a cached
  feature as fresh unless the file is newer. `rsync -a` preserves timestamps,
  so restoring a file gave it an *older* mtime and Behat kept running my
  temporary debugging step. `rm -rf /tmp/behat_gherkin_cache` fixed it.

## P22.6 The "activity" selector, as core's own tests use

The first version checked `I should see "X" in the "Subsection" "section"`,
and it passed with placement switched off. That run was also hit by the stale
code cache (P22.5), so it is not proven that the selector itself was at fault.
It was changed anyway to what mod_subsection's own tests use -
`in the "Subsection" "activity"`, whose element holds the subsection's
contents and nothing else - and that version was then confirmed, with the
caches out of the way, to fail without placement and pass with it.

## P22.7 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| `get_fast_modinfo($id, 0, true)` | The third argument only resets the cache and returns null. Reset, then call again |
| A subsection's name | Is its section's name (`subsection_get_coursemodule_info()`). Renaming the `subsection` row directly changes nothing visible |
| Inline `python3 -c "..."` in bash | `$variables` inside get expanded by the shell, so a negative check can silently change nothing. Use a quoted heredoc and assert the pattern was found |
| `&&` chains | A failing `grep` check early in a chain silently skips everything after it - including writing the test file |

## P22.8 How it behaves, as built

- The source reports an activity inside a subsection by the ordinary section
  its subsection is in (`sectionnum`) plus the subsection's cmid
  (`subsectioncmid`, optional in the web service return - an older
  destination just ignores it and places the activity in that ordinary
  section, which is still better than before).
- Every handler places through `activity_handler::target_section()`: inside
  this course's copy of that subsection if there is one, otherwise the
  ordinary section, with the note `syncsubsectionmissing`. The question bank
  handler stays in section 0 on purpose - question banks are not shown on the
  course page.
- `syncer::run()` handles subsections before anything else, so ticking a
  subsection and its contents in one sync works whatever order they changed
  in. A test makes the page the older change to prove it.
- A changed subsection shows on the sync page as "Changed - its name will be
  updated here" and is renamed through `formatactions::cm()->rename()`; its
  section follows through mod_subsection's own hook.

## P22.9 Tests

`subsection_test.php` (5) runs whole `syncer::run()` calls between two
courses on one site - the shared trait was extended to answer
`get_modified_activities` and `get_activity` as well as file requests.
`subsection_sync.feature` checks on the course page that the page sits inside
the copied subsection; with placement switched off it fails, as confirmed once
the two stale caches (P22.5) were out of the way. Negative checks also showed
the subsection-first ordering and the ordinary-section-only rule are each
pinned by a test. Released as v1.15.0-beta.

---

# Phase 23 — copying again, and "There is nothing in the other course to copy"

2026-09-24, v1.16.0-beta (2026092400).

## P23.1 The bug: "Check now" said the other course was empty

`sync.php` asked the source only for activities modified since `lastsync`.
Once everything had been copied and nothing had changed, that list was
empty, and the page printed `syncnothingatall` - "There is nothing in the
other course to copy" - which is simply untrue. The teacher had to find
**Check everything again** (`since = 0`) to see anything. Behat never caught
it because `full_flow.feature`'s "run twice" scenario *followed that link*
before asserting, i.e. the test encoded the workaround.

Fix: `sync.php` always calls `list_candidates(..., true)` and
`run(..., true, ...)`. The `full` parameter and the **Check everything
again** link are gone (old bookmarks with `full=1` still work - the param is
just ignored). `lastsync` still moves after a clean run and still shows on
the block; it no longer filters the page. Cost: detection returns cmid +
timemodified for the whole course, cheap. `syncconfirmsince` now reads
"Last synced X." rather than "Only activities changed since X will be
considered", which is no longer true.

## P23.2 Already-here rows are selectable

User-confirmed design:

| Row | Ticked means |
| --- | --- |
| Already synced, nobody has data in the copy | Fresh copy **replaces** it in place (same path as an update: `handle_update(..., $recopy = true)`), detail `syncrecopiedreplaced` |
| Already synced, people have work/grades | Old copy left alone, identity cleared; fresh tracked copy after it named "Name (copy)", then "(copy 2)"...; outcome `created`, detail `syncrecopiedcopy` |
| Needs review (collision) | Local activity never touched, **not even its idnumber**; `syncer::copy_beside()` adds an *untracked* "(copy)" after it, detail `syncrecopiedbeside`; the collision stays flagged |
| Changed, people have data | Unchanged: still "(New edition)" - user chose to keep the two names distinct |

Nothing already-here is ever pre-ticked, and "Select all" only reaches rows
carrying `data-coursesync-bulk` (new + changed) - one click must never
duplicate or replace a whole course. "Select none" clears everything.
`sync_candidates::offered_cmids()` now includes present and collisions, so
the server-side "only what was offered" guard still holds.

`add_created()` gained an optional `$detailkey` - a re-copy beside people's
work is a creation, not an update, and the summary counts it that way.

## P23.3 "Has grades" was not fully covered before

`copy_update::has_people_data()` relied on the module's privacy provider +
completion. A grade entered or overridden **in the gradebook** lives in
`grade_grades` only; mod_assign's provider does not report it. Added a
`grade_grades` check (finalgrade/rawgrade not null, or overridden).
Verified necessary: with the new check disabled,
`test_a_gradebook_grade_counts_as_peoples_data` fails. This also tightens
the existing replace-vs-new-edition decision for changed activities.

## P23.4 Why the collision copy is untracked

Giving the new copy the identity would mean clearing the teacher's own
activity's idnumber (two course_modules rows with the same idnumber make
`find_existing()`'s `get_field` warn about multiple records). The user chose
"the local one is never replaced or changed", so the copy carries no
idnumber: later syncs won't update it, and the collision is still listed for
review. That trade-off is stated in the result text.

## P23.5 Traps hit

- **`pkill -f "php -S 127.0.0.1:8001"` kills the Bash tool's own shell**
  (exit 144): the pattern matches the shell's command line, which contains
  the same string. Use `ps -eo pid,args | grep "[p]hp -S ..."` and kill the
  pids, or run pkill in a call with nothing after it.
- **Behat named groups bind to parameters by name.** Shortening the regex
  groups (`username` -> `user`, to fit phpcs's 132-char line limit) means
  renaming the PHP parameters too.
- **`coursesync-ci-db` container was stopped** at session start - PHPUnit
  bootstrap fails with `pg_connect ... 5433 Connection refused`.
  `docker start coursesync-ci-db`.
- The CI tree's main site isn't installed (only `phpu_`/`bht_` prefixes), so
  `admin/cli/upgrade.php` says "Config table does not contain the version" -
  harmless; re-run the phpunit and behat `init.php` after a version bump.
- `grunt amd` must run from inside the plugin dir in the CI tree
  (`ci/moodle/public/blocks/coursesync`), then copy `amd/build/*` back to the
  source repo, or the next rsync --delete reverts it.

## P23.6 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| Any Behat asserting an already-synced checkbox "should be disabled" | Now enabled and unticked. Assert `matches value ""` |
| A Behat scenario that follows "Check everything again" | The link no longer exists |
| `offered_cmids()` in tests | Now includes present and collision cmids |
| New-edition vs "(copy)" names | Changed + data = "(New edition)"; unchanged re-copy + data = "(copy)" |
| `name_as_copy()` numbering | Looks at every cm name in the course except the copy itself, via `get_fast_modinfo()` |

## P23.7 Tests

313 unit tests (1 skipped): 6 new in `syncer_update_test` (replace, "(copy)",
numbering, collision copy-beside, unticked present doesn't hold lastsync,
gradebook grade), 4 existing assertions updated for `offered_cmids()`.
32 Behat scenarios: `sync_choose_page.feature` gained the replace and
"(copy)" scenarios plus a Select all check, and a new step
`"user" has completed the synced "X" in course "Y"`. `full_flow.feature`'s run-twice
scenario now asserts the fixed message directly. phpcs clean; phpdoc shows
only the 16 existing errors in untouched files.


---

# Phase 24 — stored XSS through "files that could not be brought across" (audit #1)

From the full code review and security audit of 2026-09-24. Phases 24-33
each fix one finding from that audit, in its order.

## P24.1 The hole

`activity_payload::missing_files()` scans the **raw** settings and child
fields (not only the cleaned intro) for `@@PLUGINFILE@@/...` links and
`rawurldecode()`s the path. `%3Cimg%20src%3Dx%20onerror%3Dalert%281%29%3E`
therefore decodes to live markup - the regex's `[^"'\s<>?#)]` guard runs
*before* decoding, so it stops nothing. The name went into
`['syncfilesmissing', $names]`, `sync_result::describe_note()` passed it to
`get_string()` unescaped, and `sync.php` / `history.php` print the note as
HTML. Stored in the run history, so every later viewer (admins included) ran
it. Anyone who can edit a page on the *source* site could plant it.

## P24.2 Fix, in two places

- `describe_note()` now `s()`-escapes a scalar parameter itself. Escaping at
  render time is the one that matters: history rows already written keep
  whatever they were recorded with.
- `missing_files()` holds each decoded name to `PARAM_FILE`, and drops a name
  that cleans to nothing.

The LTI note (`syncltitoolneeded`) goes through the same function, so a URL
with `&` now shows as `&amp;` in source, which renders correctly.

## P24.3 Traps hit

- The regex exclusion list looked like it made this safe. Any guard applied
  before a decode step guards nothing - check what the value is *after* the
  last transformation.
- `get_string()` never escapes `$a`. Every parameterised string whose `$a`
  can hold remote text needs escaping by whoever renders it.

## P24.4 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| A test asserting a note's exact text with `&`, `<`, `"` in the parameter | Now escaped |
| Old history rows with a hostile file name | Render as text now; nothing to migrate |
| A missing file whose name is only illegal characters | No longer listed at all |

## P24.5 Tests

New `tests/sync_result_test.php` (4): the parameter is escaped, counts are
unchanged, plain keys unchanged, and a percent-encoded markup file name
comes out of `missing_files()` without `<` or `>`.

# Phase 25 — a manager-only setup permission, and source tokens scoped to a category (audit #2)

## P25.1 The problem

Two halves that together let any destination teacher read any course on the
source site:

- **Source side:** `block_coursesync_ping` required `block/coursesync:sync`
  in the **system** context, so the documented setup gave the sync account
  the role site-wide (plus `moodle/course:view`). The docs even said "assign
  in specific courses to limit it" - which could never have worked, because
  ping would then refuse. The token therefore read every course on the source:
  hidden activities, quiz questions with their answers.
- **Destination side:** the setup wizard needed only `block/coursesync:sync`,
  which editing teachers hold. Step 4 (map a course) keeps the stored token
  when only the course changes, so a teacher could remap to any source course
  by id or shortname and pull it.

## P25.2 Fix

- New capability **`block/coursesync:configure`** (course level,
  `RISK_CONFIG`, **manager only** by default). `setup.php` requires it instead
  of `:sync`. The block, `sync.php` and `preview.php` only show the setup link
  to holders of it; a syncing teacher without it sees "A manager has to set up
  the connection" (`notconfiguredaskmanager` / `errornotmappedaskmanager`).
- **Ping** now passes if the account holds `:sync` at system level **or in any
  course** (`get_user_capability_course(..., limit 1)`). Every other function
  already checked the course asked about, so the role can now be assigned in a
  category and the token reaches only that category.
- `remotestep5`, `REMOTE_SETUP.md` (now two roles: `Course Sync REST` at system,
  `Course Sync reader` in the category), `INSTALL.md`, `PILOT_CHECKLIST.md`,
  `SECURITY.md`, `README.md` and `TEACHER_GUIDE.md` updated.
- Version 2026092401, release v1.17.0-beta.

## P25.3 Behaviour change to tell people about

An editing teacher can no longer run the setup wizard by default. On an
existing site, courses already connected keep working (syncing still needs
only `:sync`); new connections need a manager, or an override granting
`block/coursesync:configure` to editingteacher. The block edit form's own
URL/course fields are dealt with in Phase 31 (audit #8).

## P25.4 Traps hit

- Moodle's "Authorised users" screen warns if the user lacks a service's
  capabilities **at system level only**. With the reader role in a category,
  that warning is now expected; the docs say so, or admins will "fix" it by
  granting site-wide.
- A string containing "set up the connection" makes `I should not see "Set up
  the connection"` ambiguous. Assert on the **link** instead:
  `"Set up the connection" "link" should not exist in the ...`.

## P25.5 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| Behat that runs the wizard as `teacher1` | Needs a `permission overrides` row granting `block/coursesync:configure` (added to setup_wizard, course_mapping, full_flow) |
| Docker site A | teacher accounts can no longer open setup.php; use admin, or add the override |
| Upgrading | capability appears only after the version bump + upgrade.php |

## P25.6 Tests

`test_configure_capability_defaults` (teacher no, manager yes, RISK_CONFIG),
`test_capability_held_only_in_a_category_is_enough` (ping). Behat scenario
"A teacher without the setup permission is told to ask a manager". 319 unit
tests pass.

# Phase 26 — a sync is held to the syncing person's own permissions (audit #3)

## P26.1 The problem

Nothing on the destination asked whether the person running the sync may add
the activity being created. `activity_handler::create_course_module()` read
`modules` without looking at whether the type was enabled, and no code
checked `mod/<type>:addinstance`, `moodle/course:manageactivities` or (for
quiz/qbank questions) `moodle/question:add`. `block/coursesync:sync` was
therefore "create any type, even one the admin switched off" - and
`db/access.php` gave it no risk flags.

## P26.2 Fix

- `activity_handler::check_permission($course)` - **final**, so no handler
  can skip it. Uses core's `course_allowed_module()` (enabled plugin +
  `mod/<type>:addinstance`, the same test the activity chooser uses) and then
  every capability in `required_capabilities()` - default
  `moodle/course:manageactivities`; `question_bank_sync_trait` overrides it to
  add `moodle/question:add` (quiz and qbank).
- `syncer::create_copy()` asks it first (before `check_payload()` /
  `check_destination()`), and so does the update-in-place path. A refusal is
  a failure with `errormodulenotallowed`, so it is offered again once the
  permission is granted - same model as the LTI "no tool" refusal.
- `block/coursesync:sync` now declares `RISK_XSS | RISK_DATALOSS` (content
  from elsewhere; replacing a changed copy deletes the old one).

## P26.3 Traps hit

- A trait method **does** override a method inherited from the parent class
  (precedence: class > trait > parent), which is what lets
  `question_bank_sync_trait::required_capabilities()` replace the base one
  without touching quiz_handler or qbank_handler.
- `course_allowed_module()` lives in `course/lib.php` - require it first,
  same trap as `add_course_module()` in Phase 4.
- A Python insert "before the method" landed between a docblock and its
  function. Check where the docblock *starts*.

## P26.4 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| Tests that run a sync as a custom/limited role | Now fail with `errormodulenotallowed` unless the role can add the type |
| Disabling a module on site A | Its activities now fail to sync instead of appearing |
| A sync run from CLI/cron in future | `$USER` must be someone with the permissions - there is no bypass |

## P26.5 Tests

In `syncer_selection_test`: a teacher with `mod/page:addinstance` prohibited
is refused and nothing is created; a disabled type is refused; an ordinary
editing teacher still syncs. 322 unit tests pass. Version 2026092402.

# Phase 27 — large files no longer read whole, or held whole (audit #4)

## P27.1 The problem

- **Source:** `get_activity_file` did `substr($file->get_content(), $offset,
  $length)` - the *whole* file read into memory for every 512 KB piece. A
  200 MB SCORM package = 400 calls x 200 MB read: quadratic I/O and a memory
  limit hit per request.
- **Destination:** `file_sync::fetch()` concatenated every piece into one PHP
  string (up to `MAX_CHUNKS` x 512 KB = 1 GB), hashed it, then
  `create_file_from_string()` - the whole file in memory at least twice.

## P27.2 Fix

- Source: `get_activity_file::read_part()` opens
  `$file->get_content_file_handle()` and uses
  `stream_get_contents($handle, $length, $offset)` - reads only the piece.
  Falls back to the old substr only if a file system's stream cannot seek
  (returns false).
- Destination: `fetch()` now writes each piece to a file in
  `make_request_directory()` (`fetch_into()`), checks `sha1_file()` against
  the source's content hash, and `copy_files()` stores it with
  `create_file_from_pathname()` then unlinks it. Return value changed from
  "the content" to "a temp path"; `fetch()` is protected, so no caller
  outside the class was affected.

## P27.3 Traps hit

- `make_request_directory()` makes a fresh directory each call and removes
  it at the end of the request, so a failed transfer leaves nothing behind
  even when an exception skips the explicit unlink.
- `fwrite()` can write short; checked and treated as a failed transfer.

## P27.4 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| Memory measurements | Peak memory now ~one piece (plus base64/JSON overhead), not the file size |
| Alternative file systems (S3 etc.) | If their streams can't seek, the fallback reads the whole file - correct, just slow |
| The 1 GB cap | Still `MAX_CHUNKS`; a larger file fails with `errorfiletoobig` |

## P27.5 Tests

New `tests/external/get_activity_file_test.php`: a random file of
2 x MAX_CHUNK + 12345 bytes read in pieces reassembles to the same SHA1, and
reading at the end returns 0 bytes with eof. Existing `file_sync_test`
(reassembly, corruption, stalls, truncation, replacement) passes unchanged
against the disk-based path. 323 unit tests.

# Phase 28 — one sync per block at a time (audit #5)

## P28.1 The problem

Nothing stopped two runs of the same block overlapping: a double click on
"Copy the chosen activities", or two teachers at once. Both runs asked
`find_existing()` before either had created anything, both created the
activity, and the course ended up with two course modules carrying the same
`coursesync-<cmid>` identity - after which `find_existing()`'s `get_field()`
warns about multiple records and picks one arbitrarily.

## P28.2 Fix

- `syncer::run()` takes `\core\lock` lock `block_coursesync / run-<blockinstanceid>`
  with **timeout 0** and a one-hour lifetime (`LOCK_LIFETIME`), and releases it
  in `finally`. The body moved unchanged into `run_locked()`.
- A second run is **refused, not queued**, with `errorsyncinprogress`, and
  recorded in the history as a failed run. Waiting would be wrong: by the
  time the first finished, what the second was asked to copy would be here,
  and the second would then *replace* the copies just made.
- `amd/src/choose.js` also disables the submit button on submit and ignores a
  second submit, so the common case (double click) never reaches the lock -
  otherwise the browser would show the second request's "already running"
  page instead of the first one's results.

## P28.3 Traps hit

- **Postgres advisory locks are re-entrant within one DB session.** The
  default lock factory on Postgres is `postgres_lock_factory`, and PHPUnit is
  one session, so a test that takes the lock and then calls `run()` gets the
  lock *again* and the run goes ahead. The test sets
  `$CFG->lock_factory = '\core\lock\file_lock_factory'` (flock on a fresh
  file handle does conflict in-process). Between real requests (different
  sessions) the default factory works as intended.
- `grunt amd` again: run it inside `ci/moodle/public/blocks/coursesync`, copy
  `amd/build/*` back to the source repo.

## P28.4 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| A run that crashes hard (fatal, killed process) | The lock expires after an hour at most; DB-session locks go with the session sooner |
| Behat pressing the sync button twice quickly | Second press does nothing now |
| Any future CLI/cron sync | Must go through `run()` to get the lock |

## P28.5 Tests

`test_a_run_already_in_progress_refuses_a_second`: with the lock held, the
run fails with `errorsyncinprogress` and creates nothing; after release the
same run creates the activity. 324 unit tests.

# Phase 29 — replacing a copy keeps this course's set-up, and never deletes group overrides (audit #6)

## P29.1 The problem

`copy_update::take_local_setup()` carried position, visibility, availability,
completion and grade category from the old copy to its replacement - then
`course_delete_module()` deleted the old copy. Lost silently with it:

- group mode and grouping, indent, "show description", `downloadcontent`,
  forced language, AI settings (all course_modules columns);
- permission overrides and hand-made role assignments on the module context;
- **group overrides** (assign/lesson/quiz extensions for a group). Not
  anyone's personal data, so no privacy provider reports them, so
  `has_people_data()` said "nothing here" and the replace path deleted them.

## P29.2 Fix

- `LOCAL_SETUP_FIELDS` lists every course_modules column that is the
  course's choice; `take_local_setup()` copies each one the old record has
  (`property_exists()` guard, for columns newer than some sites).
- `take_permissions()` copies `role_capabilities` rows from the old module
  context (`assign_capability(..., overwrite true)`) and manual
  (`component = ''`) `role_assignments`. Component-managed assignments are
  left to their component.
- `has_group_overrides()` (`OVERRIDE_TABLES`: assign_overrides.assignid,
  lesson_overrides.lessonid, quiz_overrides.quiz) makes a group override
  count as people's data, so the old copy is **kept** and the update arrives
  as a new edition. Copying overrides across was rejected: each module keeps
  calendar events and cached dates in step with them, and getting that wrong
  would be worse than keeping the old copy.
- `TEACHER_GUIDE.md` lists what is kept and that a group's dates count.

## P29.3 Traps hit

- The override tables do not share a column name for the instance
  (`assignid`, `lessonid`, but plain `quiz`). Checked against each
  `install.xml` rather than guessed.
- An inserted helper again landed between a docblock and its function; moved
  it above the docblock's start.

## P29.4 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| A quiz/assign/lesson with a group override, ticked as changed | Now "new edition", not "replace" - the sync page label changes too |
| Role overrides on the replacement | Present on the new context; the old context goes with the old module |
| `take_local_setup()` in the new-edition path | Also copies permissions now - the new edition behaves like the old one |

## P29.5 Tests

`test_a_replacement_keeps_groups_and_permission_overrides` (groupmode,
grouping, indent, a prohibit override survive a replace) and
`test_a_group_override_counts_as_peoples_data`. 326 unit tests.

# Phase 30 — one activity's unforeseen error no longer ends the run (audit #7)

## P30.1 The problem

`syncer::update_in_place()` called `$handler->update_in_place()` with no
try/catch - the only creation path without one. An exception there (a
subsection renamed to more than 1333 characters makes core's
`cmactions::rename()` throw `maximumchars`) escaped `syncer::run()`: the
teacher got an error page, the remaining activities were never tried, and
`finish()` never ran, so **no history row** recorded the attempt.

## P30.2 Fix

- The in-place call is caught like every other path: `debugging()` +
  `add_failed(..., 'errorupdatefailed')`.
- Belt and braces: each activity in `run_locked()`'s loop is now wrapped in
  try/catch; anything unforeseen fails *that activity* with
  `errorcreatefailed`, the loop carries on, and the run is recorded with the
  marker held (a failure makes the result not clean).

## P30.3 Traps hit

- Core's `cmactions::rename()` returns false for an empty name but *throws*
  for one over 1333 characters, and `activity_payload` has no length limit on
  names - a quick way to force the failure in a test.
- The expected `debugging()` call has to be claimed with
  `assertDebuggingCalled()`, or PHPUnit reports a notice.

## P30.4 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| A bug in a handler | Now shows as a failed row (`errorcreatefailed`), not a stack trace - check the debug log for the cause |
| `continue` inside the new try block | Still continues the foreach; nothing else changed in the loop body |

## P30.5 Tests

`subsection_test::test_an_in_place_update_that_throws_fails_only_that_activity`:
the run returns a failed item, a history row is written, the marker is held
and the copy is untouched. 327 unit tests.

# Phase 31 — the block's own settings form follows the setup permission (audit #8)

## P31.1 The problem

The block edit form (`edit_form.php`) has its own "Remote site URL" and
"Remote course" fields. `block_coursesync::instance_config_save()` stored the
URL for anyone who could edit the block (`moodle/block:edit`), and cleared the
course mapping on an empty field, with no Course Sync permission at all. A
URL change wipes the token and mapping, and someone who can then talk a
syncing user into pasting a token gets it sent to their site. (The form did
check `:sync` - but only before *resolving* a course, not before saving.)

## P31.2 Fix

- `instance_config_save()` ignores `remoteurl` / `remotecourse` unless the
  user holds `block/coursesync:configure` in the course - checked at save time,
  so a hand-crafted POST gets nothing either.
- `edit_form.php` shows the two fields (and runs their validation) only for
  holders of `:configure`; anyone else editing the block sees
  `notconfiguredaskmanager` in their place. `can_configure()` asks in the
  course context of the page.
- The old `:sync` check in `validate_remote_course()` became redundant and was
  removed with its string `errornosyncpermission`.

## P31.3 Traps hit

- Hiding fields is not a permission check: `instance_config_save()` receives
  whatever `get_data()` returns, so the check has to live there too.

## P31.4 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| Behat that edits the block as teacher1 | Needs the `:configure` override (course_mapping.feature has it) |
| A teacher moving/renaming the block | Still works; the connection is untouched |

## P31.5 Tests

`test_instance_config_save_needs_the_configure_permission`: an editing
teacher saving an https URL through the block creates no connection. 328
unit tests.

# Phase 32 — outgoing requests: more ranges, no redirects, the checked address pinned (audit #9)

## P32.1 The gaps

- `BLOCKED_RANGES` missed IPv6 spellings of IPv4 addresses that Moodle's
  matcher does not unwrap: IPv4-compatible `::127.0.0.1`, NAT64
  `64:ff9b::a9fe:a9fe` (= 169.254.169.254 on a network with a NAT64 gateway),
  6to4 `2002:7f00:1::`; plus multicast/reserved/broadcast. (IPv4-*mapped*
  `::ffff:127.0.0.1` was already refused - Moodle's `address_in_subnet()`
  handles that one; verified before changing anything.)
- **DNS rebinding:** `check_before_request()` resolved and checked the name,
  then Guzzle/curl resolved it *again* to connect.
- **Redirects** were followed (Guzzle default). A 307/308 keeps the POST body,
  so the token would be re-sent to wherever the source pointed, http included.

## P32.2 Fix

- Added `224.0.0.0/4`, `240.0.0.0/4` (covers 255.255.255.255), `::/96`,
  `64:ff9b::/96`, `64:ff9b:1::/48`, `2002::/16`, `100::/64`, `ff00::/8`.
- `remote_url::check_and_pin()` resolves once, checks every address, and
  returns `host:port:address` for `CURLOPT_RESOLVE`; `remote_client::call()`
  passes it as the request's `curl` option. `check_before_request()` is kept
  as a thin wrapper. No pin when the host is already an IP, when it did not
  resolve, or when the private-address override is on.
- `RequestOptions::ALLOW_REDIRECTS => false`; any 3xx is `errorredirected`
  with a message telling the admin to enter the address it ends up at.
- `SECURITY.md` updated (also for phases 25, 26, 28, 29).

## P32.3 Traps hit

- **Blocking the documentation ranges broke a test on purpose:**
  `test_public_addresses_are_still_accepted` uses `203.0.113.10` (RFC 5737)
  as its stand-in public address. Documentation ranges are unrouted and
  harmless, so they stay allowed.
- Guzzle is not in `vendor/` in this tree - it is `public/lib/guzzlehttp`,
  loaded by Moodle's class loader. A standalone script needs `config.php`
  with `ABORT_AFTER_CONFIG` + `core_component::classloader`.
- **curl shares its DNS cache across requests on a reused handle.** Proving the
  pin by requesting `pinned-name.invalid` pinned and then unpinned in one
  process makes the unpinned one "succeed" off the cached pin. Run the
  unpinned request first. (Harmless in the plugin: the only entry ever
  cached is the address that was just checked.)
- Guzzle merges request options over client config non-recursively, so a
  per-request `curl` array would replace a client-level one. Core's
  `http_client` sets none today; worth re-checking on a core upgrade.

## P32.4 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| A source behind a redirect (www., http->https, a moved path) | Now fails with `errorredirected` instead of silently working or not |
| Sites behind an outbound HTTP proxy | The proxy resolves the name, so the pin has no effect there (no harm) |
| The Docker test sites | Use the private override, so no pin is set - the pin is only exercised with public names |

## P32.5 Tests

Seven new blocked-address cases (IPv4-compatible, NAT64, 6to4, multicast,
broadcast, reserved, v6 multicast); `test_check_and_pin`;
`test_a_redirect_is_not_followed` (asserts the request carried
`allow_redirects = false` and the 307 maps to `errorredirected`). Pin
behaviour proven with a real curl against a local `php -S`. 337 unit tests.

# Phase 33 — the informational findings, and code tidy-up (audit #10 + code quality)

## P33.1 Information the source gave away

- **Ping returned `$CFG->release`** ("5.1.7+ (Build: 20260916)") - exactly
  which point release, so exactly which security fixes the source lacks, to
  anyone holding a token. Now `ping::major_release()` sends "5.1", which is
  all the connection-test message ever needed.
- **`get_course` told "exists" from "not allowed":** a missing course was
  `errorcoursenotfound`, a course the account could not sync was
  `required_capability_exception`. With tokens now scoped to a category
  (Phase 25) that answered "is there a course with this shortname?" for every
  course on the site. A course the account may not sync is now reported as
  not found; the destination's `errorcoursenotfound` text already names both
  causes, and a token with no sync permission anywhere is still told so by
  ping. The id-based functions (`get_activity` etc.) were left as they are:
  they are only ever called with ids from the mapped course's own listing.

## P33.2 Code quality

- Three garbled docblocks (`/**` pasted onto the end of the summary line,
  then the summary repeated): `activity_handler` x2, `sync_result` x1.
- Stale phase comments that said the opposite of what the code now does:
  the block class ("Nothing is synced yet"), `preview.php` and
  `connection::get_last_sync()` ("lastsync is never written"), `ping`
  ("the only external function"), `activity`, `sync_result`.
- `public` added to every class constant: phpcs `moodle-extra` is now
  **0 errors, 0 warnings** (was 18 warnings).
- Left for the UX phase, deliberately: `preview.php` lists what changed since
  the last sync while `sync.php` lists everything. That is a design question,
  not a defect.

## P33.3 Traps hit

- phpcs's inline-comment rule rejects a comment starting with a function
  name in lower case (`// validate_context() ...`). Reword so it starts with
  a capital.
- A regex to repair the garbled docblocks had to match the repeated summary
  line exactly; checked afterwards with `grep '[^ ]    /\*\*$'`.

## P33.4 Be cautious of these when testing

| Thing | What to watch |
| --- | --- |
| Setup step 3 "Connected to X, running Moodle Y" | Y is now "5.1", not the full release |
| Mapping a course the sync account cannot read | Now "not found (or cannot see it)", not a permission message |

## P33.5 Tests, for the whole audit (phases 24-33)

- PHPUnit: **338** tests pass (1 skipped, as before) - up from 313 at the
  start of the audit.
- Behat: **33/33** scenarios pass. Nine more feature files needed the
  `block/coursesync:configure` permission-override row in their Background,
  because they open the setup page as `teacher1`; the first run after
  Phase 25 had 14 failures, all `nopermissions` at `setup.php:50`.
- phpcs clean; `php -l` clean on every file. Version 2026092403,
  release v1.17.0-beta.


---

# Phase 52 — release preflight: privacy declaration for usernames sent, lang order

Ran `moodle-release-preflight` and `moodle-definition-of-done` against
v1.20.0-beta (build 2026092505). Nothing blocked a tag; two things were fixed.

## P52.1 What was wrong

- **Privacy metadata described only one direction.** The `othersite` external
  location says what leaves the site *when it is the source*. When it is the
  *destination*, `remote_client::call_for_students()` sends the students'
  usernames to the source (`usernames`, one per line) - and nothing declared
  that. `docs/INSTALL.md` already said the provider declares it, so the docs
  were ahead of the code.
- **`lang/en/block_coursesync.php` was not sorted** (`errorlti*` keys sat after
  `errornogradepullpermission`). phpcs did not complain: the sorted-keys sniff
  only runs with `--runtime-set moodleBranch <n>`.

## P52.2 Fix

- `classes/privacy/provider.php`: second external location `sourcesite`
  (field `username`), with `privacy:metadata:sourcesite` and
  `privacy:metadata:sourcesite:username`.
- Lang file sorted by key. Content unchanged: sorted-lines diff before/after
  is empty. 342 keys, 0 out of order.
- `tests/privacy/provider_test.php`: `sourcesite` added to
  `test_metadata_is_declared`; new `test_usernames_sent_to_the_source_are_declared`
  and `test_every_metadata_string_exists`.

## P52.3 Traps hit

- **Which key is "the misplaced one" is not knowable from one pair.** The first
  report was `errorsyncinprogress > errorltinotool`; moving `errorsyncinprogress`
  just exposed `errornogradepullpermission > errorltinotool`, then
  `... > errorltiownsecret`. A whole group had been appended out of place.
  Moving keys one at a time is a loop; sort the whole file.
- **The lang file has multi-line strings** (`remotecourse_help`, `remoteurl_help`).
  A first attempt that assumed one line per string failed its own assertion
  (good) before touching the file. Sort *statements* (group continuation lines
  under the `$string[` line that starts them), and assert the multiset of
  statements is unchanged.
- **`moodle-plugin-ci phpcs/phplint/savepoints` take no `-m`** - passing it
  exits 1 with "The `-m` option does not exist", which looks like a plugin
  failure. `phpdoc`, `mustache`, `grunt` do take it.
- **`phpdoc` looked vacuous but is not - my test input was wrong.** The
  vendored `local_moodlecheck` (v1.3.2) registers only 5 rules (inline phpdoc
  tags, `functionarguments`, ...); the rest moved to moodle-cs. So an
  undocumented method gets no moodlecheck complaint by design - `phpcs` flags
  it (`moodle.Commenting.MissingDocblock.Function`). A docblock whose `@param`
  does not match the signature makes `moodle-plugin-ci phpdoc` print
  `Line 13: ... incomplete parameters list (error)` and exit 1; on the real
  plugin it lists the checked files with no `Line` rows and exits 0 - a true
  pass. (Earlier guess - "no `mdl_` tables" - was wrong; moodlecheck needs only
  a reachable database.) Remaining trap: with the CI database container
  stopped, `phpdoc` printed `dbconnectionfailed` and **still exited 0**, so
  always read the log, not the exit code.
  To prove it non-vacuous, use a signature/docblock mismatch, not a missing
  docblock.
- `moodle-plugin-ci phpmd` exits 0 with violations. (I first recorded this as
  "2 violations": I had read only the first two file blocks of a 42-file report.
  The real count, from Phase 57, is 85 in plugin code and 44 in tests.)

## P52.4 Tests

- New tests fail on the old provider (2 failures: `test_metadata_is_declared`,
  `test_usernames_sent_to_the_source_are_declared`) and pass on the new one.
- PHPUnit: **449** tests, 2151 assertions, 1 skipped (was 447 / 2114).
- phplint, phpcs `--max-warnings 0`, savepoints: clean. phpcs proven to fail on
  a deliberately bad file in a scratch copy.
- Behat: **40/40 scenarios, 866/866 steps** pass (1m35s, all 17 feature files).
  The first run did not run at all: "Your behat test site is outdated" (exit 1)
  - the plugin version had moved since the Behat site was built. Fix:
  `php public/admin/tool/behat/cli/init.php` (rebuilds only the `behat_` site),
  then rerun. An exit-1 with zero scenarios is a stale test site, not a plugin
  failure. Was 33/33 at v1.17.0; the count has grown with v1.18-v1.20.
- `coursesync-ci-db` was stopped when the session began; started for the runs
  (with `php -S 127.0.0.1:8001`, 2 workers, and host-network Selenium for
  Behat) and all three torn down again.

## P52.5 Not fixed (reported)

- No course-reset hook, no `db/events.php`: grade/attempt tracking rows survive
  a course reset and a user deletion. Attempt pull already skips a locally
  deleted attempt (`attemptskipdeletedhere`); not otherwise tested.
- No `.github` workflow and no changelog file.
- LEARNFROMME.md had no entries after Phase 33, although the plugin is at
  v1.20.0 - the work for v1.18-v1.20 is not logged here.


---

# Phase 53 — course reset and user deletion forget what pulls remembered

Finding 3 of the Phase 52 preflight. Build 2026100401 (release string left at
v1.20.0-beta: a release is a separate decision).

## P53.1 What was wrong

A pull remembers what it wrote: `block_coursesync_grade` (which grades),
`block_coursesync_attempt` (which quiz attempts). Core's course reset and user
deletion remove the grades and attempts but know nothing of that memory.

- **After a reset with quiz attempts removed, the attempts never came back.**
  `attempt_pull` found the tracking row, found no local attempt, and answered
  `attemptskipdeletedhere` ("somebody's decision, not a gap"). A real bug, not
  just stale rows. (Grades were harmless: no local grade means ADD.)
- Rows kept referring to deleted users and removed grade items.

## P53.2 Fix

- `db/events.php` + `classes/local/observer.php`:
  - `course_reset_ended`: `reset_quiz_attempts` forgets the course's attempt
    rows; `reset_gradebook_grades` or `reset_gradebook_items` forgets its grade
    rows. Only what was really reset, so an attempt deleted by hand with no
    reset is still respected.
  - `user_deleted`: forgets that user's grade and attempt rows in every course.
  - Run rows are kept: `history.php` already shows "unknown user"; the privacy
    provider still erases them on request.
- Docs: TEACHER_GUIDE, SECURITY ("deleted here is never brought back" now has
  the reset exception, plus a what-is-kept bullet), DEVELOPER (new subsection).

## P53.3 Traps hit

- **Core calls `<mod>_reset_userdata()` for activity modules only.** A block
  has no reset callbacks; `course_reset_ended` carries `other['reset_options']`
  and is the way in. Read `lib/moodlelib.php` `reset_course_userdata()` before
  assuming a hook exists.
- **A test helper named `count()` is a fatal error** in a PHPUnit 11 TestCase
  (`Cannot override final method TestCase::count()`). `php -l` passes it; only
  running it shows. Named it `rows()`.
- **Fixture rows hit the unique keys** `(quizid, remoteattemptid)` and
  `(gradeitemid, userid)`. Give each fake row its own ids.
- **A course with no grade items makes `grade_course_reset()` warn**
  (`foreach() argument must be ... false given`, `gradelib.php:1629`). Fixture
  artefact: create a graded activity in each test course.
- **I copied only 14 of the 15 header lines** into three new files, so phpcs
  failed `BoilerplateComment.CommentEndedTooSoon`. Diff a new file's first 15
  lines against an existing file's.
- **`PHPUnit Deprecations: 4144`** appears in every run now. It is core's own
  test classes (`core\advanced_test`, ...) using doc-comment metadata, not this
  plugin. Do not chase it.
- **A version bump makes the Behat site stale again** (exit 1, zero scenarios,
  "test site is outdated"): rerun `behat/cli/init.php` before Behat.

## P53.4 Tests

- `tests/local/observer_test.php` drives core's real `reset_course_userdata()`
  and `delete_user()`: reset quiz attempts, reset gradebook grades, reset
  gradebook items, an unrelated reset (forgets nothing), user deletion (other
  user, other course and run history untouched).
- `attempt_pull_test::test_an_attempt_removed_by_a_course_reset_comes_back`:
  pull, reset, attempt gone, pull again, attempt back.
- **Proven able to fail:** with `db/events.php` removed from the CI copy, 5 of
  the 6 new tests fail (the sixth, "unrelated reset", is a negative case and
  passes either way); with it, all pass.
- PHPUnit: **455** tests, 2171 assertions, 1 skipped (was 449 / 2151).
- phplint, phpcs `--max-warnings 0`, savepoints, validate, phpdoc (0 `Line`
  rows, database up): clean.
- Behat: **40/40** scenarios, 866/866 steps, after rebuilding the Behat site.
- Started `coursesync-ci-db`, `php -S 127.0.0.1:8001` and Selenium; all torn
  down again.

## P53.5 Not done

- (Phase 52/35 listed "unenrolling a student leaves their rows" as a gap.
  That was wrong - see Phase 54: leaving them is correct.)
- No `.github` workflow, no changelog file; LEARNFROMME has no v1.18-v1.20
  entries.


---

# Phase 54 — unenrolling a student: investigated, deliberately not handled

Asked to handle unenrolment after Phase 53. The investigation found there is
nothing to fix, and that "fixing" it would add bugs.

## P54.1 What core does on unenrol

- `grade_user_unenrol()` (lib/gradelib.php) deletes the student's grades into
  grade history; re-enrolling restores them (`recovergradesdefault`, default on).
- Their quiz attempts are not touched.

So `block_coursesync_grade` / `block_coursesync_attempt` rows still describe
real, recoverable data. Deleting them on `user_enrolment_deleted`:
- re-enrol, pull -> every attempt imported **a second time**;
- re-enrol (grade recovered), source changes -> grade no longer known as ours,
  so the pull says CONFLICT instead of UPDATE.

Unlike a reset or a user deletion (Phase 53), the data the rows point at is
not gone for good.

## P54.2 What was done

No plugin code change. Tests pin the behaviour, and a DEVELOPER.md paragraph
says why unenrolment is not observed:
- `grade_pull_test::test_a_pulled_grade_survives_unenrolling_and_reenrolling`
- `attempt_pull_test::test_an_unenrolled_students_attempts_are_not_imported_twice`

Both drive the real manual-enrol `unenrol_user()` / `enrol_user()`.

## P54.3 Traps hit

- **Mutation-tested, and the first mutation run proved nothing.** The tests
  passed on current code, and the mutant (a `user_enrolment_deleted` observer
  deleting the rows) printed *no result at all*: the CI database was still
  "starting up" after `docker start` (a fixed `sleep 6` was not enough this
  time) and my `grep` filtered the bootstrap failure away. Wait with
  `until docker exec coursesync-ci-db pg_isready -U postgres; do sleep 1; done`
  and look at the raw output when a filtered run prints nothing.
- Re-run result: current code passes; the mutant fails both (attempt count 2,
  expected 1; recovered grade reported as a conflict).
- The CI copy was edited for the mutation and restored; verified with
  `diff -r` against the source.
- A silent "no output" from a filtered test run is not a pass.

## P54.4 Tests

- PHPUnit: **457** tests, 2180 assertions, 1 skipped (was 455 / 2171).
- phplint, phpcs `--max-warnings 0`, savepoints, phpdoc (0 `Line` rows, DB up):
  clean. Behat not re-run: no runtime code changed since the Phase 53 run
  (40/40); only tests and docs.
- `coursesync-ci-db` started for the runs and stopped again.


---

# Phase 55 — GitHub Actions workflow (`.github/workflows/ci.yml`)

Started from `moodle-plugin-ci`'s `gha.dist.yml` (diffed against upstream `main`
first: identical). Changed only the matrix, the database images, the Behat web
server and the artifact name; everything else is the template.

## P55.1 Matrix, and how each axis was checked

The plugin supports Moodle 5.1 only (`$plugin->supported = [501, 501]`).

| Axis | Value | Source |
| --- | --- | --- |
| Branch | `MOODLE_501_STABLE` | `version.php` |
| PHP | 8.2 and 8.4 | 5.1 `public/admin/environment.xml` (min 8.2); core `push.yml` on 5.1 (MySQL job "lowest" 8.2, PostgreSQL job "highest" 8.4) |
| Database | `pgsql`, `mariadb`, crossed | |
| Images | `postgres:15`, `mariadb:10.11` | the 5.1 minimums in `environment.xml` (mysql is 8.4, not used) |

4 jobs, all keys present in each (no `include:` trap; checked by expanding the
matrix). Read from the local Moodle clone, not memory.

Left out, deliberately: an experimental `main` (5.3) row. Its requirements could
not be read from the checkout (no 5.3 block found), and an unverifiable pair is
worse than none.

## P55.2 Behat: two workers

`moodle-plugin-ci behat` starts `php -S localhost:8000` as a plain child
process (`BehatCommand::startServerProcesses`), which inherits the environment.
This plugin's Behat scenarios use the site as its own sync source, so one
request makes a second request back to it, and a single-worker built-in server
deadlocks (see the CI-environment notes). The Behat step sets
`PHP_CLI_SERVER_WORKERS: 2`. Untested against real CI.

## P55.3 Rehearsal - a real one, and it found three defects

Ran the workflow's steps exactly as written, from a fresh `composer create-project`
and a fresh `moodle-plugin-ci install` (nvm 0.40.8 installed into ~/.nvm with
`PROFILE=/dev/null`, so no shell profile was touched), against PostgreSQL 15.19
and MariaDB 10.11.19 in Docker, with `moodle-plugin-ci behat --profile chrome
--scss-deprecations` starting its own server on `localhost:8000`. PHP was the
local 8.4 only (8.2 not covered).

**Found by the rehearsal and fixed, none of which any local gate had caught:**

1. **`install.xml`: `remoteurl` CHAR NOT NULL with `DEFAULT=""`.** XMLDB refuses
   an empty-string default on a NOT NULL char column: a *fresh* install prints
   "XMLDB has detected one CHAR NOT NULL column (remoteurl)..." and PHPUnit
   initialisation treats the debugging as fatal, so **the CI install step would
   have failed on its very first run**. My local CI Moodle had been *upgraded*,
   never freshly installed, so it never showed. Fixed in `db/install.xml`, and in
   the old `upgrade.php` step that created the column. **No upgrade step and no
   version bump**: a fresh install ends up with the same column either way
   (PostgreSQL: `DEFAULT ''` is added by the generator; XMLDB had already dropped
   the declared default in memory), checked in the database. I first wrote an
   upgrade step with `change_field_default`, then reverted it once the column
   showed no difference.
2. **Behat: "address not permitted".** The SSRF guard refuses a loopback site
   address unless `$CFG->block_coursesync_allowprivateurls` is set in
   `config.php`. My local CI config has it (and `behat_extraallowedsettings`);
   the config `moodle-plugin-ci install` generates does not, so 23 of 40
   scenarios failed. The guard was right. New workflow step "Allow the Behat site
   to sync from itself" inserts both lines before `require_once(.../setup.php)`,
   placed *after* PHPUnit (the SSRF unit tests must run without the flag), and
   `grep -q`s that the edit landed. Tested: it edits the generated config, the
   edited file parses, and it exits 1 on a config without the anchor line.
3. **`.sr-only` (Bootstrap 4) in `sync.php` and `grades.php`**, found by the
   template's `--scss-deprecations`, which my local Behat runs never used. Core
   5.1 uses `visually-hidden`; `.sr-only` survives only in a bs4-compat shim.
   Replaced in all 3 places. 4 scenarios had failed on the Sync page.

**Result, both rows:** install, phplint, phpmd (85 violations in plugin code, non-fatal - see Phase 57),
phpcs `--max-warnings 0`, phpdoc, validate, savepoints, mustache (no templates),
grunt (eslint + rollup + gherkinlint), `phpunit --fail-on-warning` (457 tests,
2180 assertions, 1 skipped) all exit 0, and **Behat 40/40 scenarios, 866/866
steps with no reruns**. Zero XMLDB notices. The other steps were read, not
trusted: 117 files linted, no `dbconnectionfailed`, real grunt tasks.

Not covered: PHP 8.2; a real GitHub runner (ubuntu-24.04, x86-64 - this machine
is arm64); the `main` row, which was left out on purpose.

## P55.4 Traps hit

- **`moodle-plugin-ci grunt <path>` rewrites the directory you give it.** It
  mirrors the plugin to /tmp, deletes `amd/build`, runs grunt in the *Moodle
  copy*, then mirrors the backup back with `override => true`. Pointed at the
  real repo, the restore failed (`Failed to copy ... .git/objects/...
  Permission denied`: git's read-only objects) and left **`amd/build` deleted**
  from the working tree. Recovered from the /tmp backup (`diff -r` against it
  empty, `git diff -- amd` empty, `git fsck` clean). Never pass the real repo
  path. CI is not exposed to this: the installer copies the plugin into the
  Moodle tree, `.env` records that copy as `PLUGIN_DIR`, and a bare
  `moodle-plugin-ci grunt` runs against it (no `.git`).
  Same for any positional plugin path that has a `.git` (a shallow clone fails
  identically).
- **"Unable to find local grunt" was my path, not a missing install.** Run
  from outside the Moodle tree, grunt could not find `node_modules`. The bare
  command, against the installed copy, found it.
- A `sleep 6` after `docker start coursesync-ci-db` is not a readiness check;
  use `pg_isready` (Phase 54).
- No PyYAML here: validate YAML with `symfony/yaml` from
  `moodle-plugin-ci/vendor`.
- **A fresh install is a different test from an upgrade.** Every earlier local
  run used a Moodle that had been upgraded step by step; only a fresh
  `moodle-plugin-ci install` runs `install.xml` through XMLDB's checks.
- **The scratchpad is a 1.9 GB tmpfs.** The first rehearsal's Moodle tree
  (clone + node_modules + three test sites) filled it: `git clone` failed with
  "No space left on device" and looked like a plugin failure. Heavy
  rehearsals go on the real disk (here `~/ci-rehearsal`, deleted afterwards).
- **The local machine has no `mysql` client**, which runners have;
  `moodle-plugin-ci install --db-type mariadb` died with `exec: mysql: not
  found`. A three-line shim script that `docker exec`s the client inside the
  container, put first on PATH, was enough; no system package installed.
- **A stale monitor replays.** A `tail -F` on a path that is deleted and
  recreated re-emits the old file's lines as new events; do not read an old
  "install failed" as a new failure.
- **`moodle-plugin-ci behat` reruns failed scenarios twice** before reporting,
  so a Behat log holds three summaries. Read the first for what really failed.

## P55.4b The first real GitHub run (2026-10-04, run #1, commit 27259ba)

Pushed by the user. **All four jobs passed** (PHP 8.2 and 8.4 x PostgreSQL 15 and
MariaDB 10.11, 6.8 to 8.7 minutes each): every step succeeded, including the
rows the local rehearsal could not cover - **PHP 8.2** and a real x86 runner with
the Chrome Behat profile - and the "Allow the Behat site to sync from itself"
step. The two skipped steps (Behat faildump upload, cancelled-job marker) are the
failure-only ones. Caveat: "PHP Mess Detector" is `continue-on-error`, so its
success does not mean zero violations (85 in plugin code, 44 in tests; Phase 57).
Checked read-only through the public Actions API
(`/repos/<owner>/<repo>/actions/runs/<id>/jobs`); the `gh` CLI is not installed.

## P55.5 Not done

- (Changelog written in Phase 56.)
- Nothing pushed: `.github/` is untracked in the plugin repo.


---

# Phase 56 — `CHANGES.md`, and a numbering collision in this log

## P56.1 The changelog

New `CHANGES.md` at the plugin root (the name the Moodle plugin-release guidance
uses), newest first, Keep-a-Changelog sections. Reconstructed from git commit
dates and version bumps, the phase titles in this log, and the docs diffs of each
release commit; where git cannot say which release held what (v1.3.0-v1.4.1, the
first builds) it says so and points at phases 1-12. This session's work is under
**Unreleased (build 2026100401)**: the release string is still `v1.20.0-beta`,
because choosing the number is a release decision, not a changelog one.

Spot-checked against the commits before finishing: capabilities and
`get_grades` arrive in v1.18.0 (1794062), `get_quiz_attempts` and
`block_coursesync_attempt` in v1.19.0 (7f458f2), LTI/BigBlueButton/subsection
handlers in the v1.15.0 commit (308be42), SCORM/IMS in v1.13.0 (e2d8db0). Two
things were wrong and were corrected after cross-checking my own memory notes
(written at the time, so trusted over inference from git):
- **v1.14.0 existed** (LTI and BigBlueButton) but was never committed on its own;
  its content is in the v1.15.0 commit. I had written "there is no v1.14.0" and
  credited LTI/BBB to v1.15.0. Now: v1.14.0 = LTI + BBB, v1.15.0 = subsections.
- v1.16.0's "nothing to copy" message is not "nothing new": the sync page
  started always listing everything (a filter by `lastsync` had produced the
  message once all was copied), and already-copied activities became tickable.

## P56.2 This log has a hole, and I collided with it

Code comments and docs refer to phases 34-39 (grade sync), 35, 37, 40-44 (quiz
attempts), 41, 49-51 and 50 (matching existing activities) - the whole of
v1.18.0-v1.20.0 - but **this file has no entries between Phase 33 and mine**. I
had numbered this session's entries Phases 34-37, which collide with those
references (`db/upgrade.php` says "Phase 37: grade pulls..." and "Phase 41:
quiz attempts"). Renumbered to **52-55** (`P34.x` -> `P52.x`, and so on, with
the cross-references; phases 1-33 checked byte-identical before and after).
The highest phase referenced anywhere is 51, so 52 is the first free number; if
a phase 52+ exists outside this repo, expect another collision.

The missing entries (phases 34-51) are not reconstructed here: the changelog's
v1.18-v1.20 entries come from commits and docs, not from the phase log.

## P56.3 Traps hit

- **Phase numbers are an identifier, and other files cite them.** Grep the repo
  for `Phase N` before numbering a new one, not just the headings of this file.
- **Git history is not the release history.** A build (v1.14.0) can exist and be
  committed inside another release's commit, so "which version has X" cannot be
  read off version.php diffs alone; cross-check with notes written at the time.
- A changelog entry per release can look authoritative while resting on commit
  messages like "Bug fixes". Say what is inferred (v1.19.1's "bug fixes" line is
  generic on purpose).


---

# Phase 57 — full review pass: capability, privacy, string, security audits

Asked to run all the code-review and security checks again. Used the moodle-dev
skills (as the memory note says), inline, on the committed code (HEAD 27259ba,
working tree clean, identical to origin/main). Report only: **no file was
changed** except this log. GitHub run #1 for the same commit is green on all 4
jobs, which is where PHPUnit (457) and Behat (40/40) evidence comes from; they were
not re-run locally this time.

## P57.1 Results

- **Capabilities: clean.** 5 declared, all used (`sync` 20 places, `configure` 8,
  `exportgrades`, `pullgrades`, `addinstance` by the block framework). Nothing
  used-but-undeclared. One dynamic check (`activity_handler::check_permission`)
  checks core's own `mod/*:addinstance` capabilities on the destination course.
  Risk bitmasks right: `configure` RISK_CONFIG, `sync` XSS|DATALOSS,
  `exportgrades`/`pullgrades` PERSONAL. All 5 lang strings exist.
- **Privacy: green, with a yellow.** Provider has all 7 methods and 36 lang
  strings, none missing. Every user-identifying column (`userid`, x3 tables) is
  declared, exported and deleted on all three paths. Yellow: declared but not
  exported - `run.kind`, `grade.feedbackhash`, `grade.remotecmid`,
  `attempt.remotecmid`, `attempt.remoteattemptid` (bookkeeping, not personal);
  exported but not declared - `run.pulledcount`, `run.conflictcount`. Low.
  (Fixed in Phase 58.)
  The connection table holds no user data (a site token and mapping).
- **Strings: clean.** 148 literal keys used, all defined; 14 `moodle_exception`
  keys, all defined; the 32 dynamic `get_string` sites resolve (every key-like
  literal is defined; the only 3 hits were an array key, a web-service field and a
  DB column). No hard-coded user-facing text in PHP; no templates; `choose.js`
  holds no user-visible literals. Lang file sorted (0 of 342 out of order).
- **Security: no findings.**
  - Entry pages (5): `require_login` -> `require_capability` -> block-instance
    lookup bound to the course, in that order; `require_sesskey()` precedes every
    write; `preview`/`history` only read.
  - No `$_GET/$_POST/$_REQUEST/$_SERVER`, `eval`, raw curl, `file_get_contents` on a
    variable, `md5`, `print_error` or shell calls. `sha1` is a content fingerprint.
    4 `unserialize` calls, all `allowed_classes => false`. `fopen` path is
    `make_request_directory() . '/transfer'`.
  - SQL: 17 raw calls inspected, all placeholders; one (`gradebook.php:109`)
    interpolates fragments that come from core's `get_in_or_equal` /
    `get_enrolled_sql`.
  - Output: 7 unescaped-looking tag contents read; all ints, `get_string()`, or core
    grade formatting. Names `s()`, activity names `format_string()`, the remote site
    name `s()`.
  - Outbound: one choke point (`remote_client::call`): `check_and_pin`,
    `CURLOPT_RESOLVE` pinning, `ALLOW_REDIRECTS => false`, `VERIFY => true`,
    timeouts, token in the POST body only.
  - Secrets: token via `core\encryption`, only the last 4 characters kept; no
    token in any `debugging`/log/exception text.
  - External functions (7): all validate parameters/context, all registered in
    the service and its bundle; service is `restrictedusers` and disabled by
    default; `ping` and `get_course` check the capability with `has_capability()`
    (not `require_capability()`, which is why a grep for the latter shows 0), and
    `get_course` reports a refused course as "not found".
- **Preflight classes:** backup/restore N/A (no `backup/`; a restore needs the
  token re-entered, documented in INSTALL/PILOT_CHECKLIST), uninstall N/A (the
  plugin writes only its own tables and config), reset and user deletion handled
  (Phase 53), tests present (54 files), no `error_log`/TODO/placeholder holders.
- **Static gates now:** phplint, phpcs `--max-warnings 0`, savepoints exit 0.
- **phpmd: 85 violations in plugin code** (50 complexity/NPath/length, 16 unused
  parameters, 10 coupling/class size, 5 boolean flags, 4 too many parameters), 44
  in tests. Biggest: `activity_handler` 18 (the unused parameters are hook
  signatures subclasses override), `syncer` 12 (`run_locked` is 189 lines),
  `grade_pull` 9, `remote_client` 6, `quiz_handler` 6, `attempt_pull` 6. Non-fatal
  in the workflow (`continue-on-error`); maintainability, not correctness.

## P57.2 Not seen

Dependabot alerts, code scanning and secret scanning on GitHub: the fine-grained
token lacks the permissions, so the answers are "unknown", not "none". Check the
repo's Security tab, or add the read permissions to the token.

## P57.3 Traps hit

- **My own grep was the bug, three times**: a lang-key check with shell-quoted
  `$string[...]` printed 0 for strings that exist; a `require_capability`-only grep
  said `ping`/`get_course` check nothing (they use `has_capability`); an
  `allow_redirects` string grep missed `RequestOptions::ALLOW_REDIRECTS`. Every one
  was caught by reading the code. A zero from a grep is a question, not a result.
- **A truncated report read as the whole report** (phpmd: 2 violations vs 85).
  Count and categorise the full output before characterising it.


---

# Phase 58 — the privacy declarations and the export now match

The yellow from Phase 57 (P57.1): the metadata and the export had drifted apart.

## P58.1 Fix

- `classes/privacy/provider.php`: the run table now **declares** `pulledcount` and
  `conflictcount` (already exported), and its export includes `kind` (declared,
  not exported). The grade export adds `remoteactivity` (`remotecmid`) and
  `feedbackfingerprint` (`feedbackhash`); the attempt export adds `remotequiz`
  (`remotecmid`) and `remoteattempt` (`remoteattemptid`). All were declared but
  left out of the export; none is personal data in itself, but "declared" should
  mean "exported", and a person reading their own export should see all of it.
- Two new lang strings, `privacy:metadata:run:pulledcount` and `...:conflictcount`,
  inserted at their sorted positions (344 keys, 0 out of order).
- No schema change, so no version bump and no upgrade step; the release string is
  unchanged. `CHANGES.md` (Unreleased, Privacy) records it.

## P58.2 The guard test

`provider_test::test_the_export_matches_what_is_declared` maps, per table, each
declared field to the key that carries it in the export, then asserts the declared
fields equal the mapping's keys and the exported keys equal its values. Adding a
declaration or an export key on one side only now fails. `userid` is excluded: it
is who the export is for, not a field in it. **Proven able to fail:** against the
old provider from `HEAD` it is the one failing test (1 of 16); against the new one
all 16 pass.

## P58.3 Traps hit

- phpcs `LineLength.TooLong` (133 vs the 132 limit) on one assertion I wrote. The
  full PHPUnit run happened *before* that wrap and the gates after; the wrap
  changed formatting only, `php -l` and phpcs clean afterwards.
- A mechanical "declared vs exported" comparison needs the mapping: the export uses
  friendly keys (`gradeitem`, `timechangedonothersite`, `quiz`, `attempt`), so
  comparing column names to keys flags false drift. Keep the map in the test and
  make a missing mapping fail.

## P58.4 Tests

PHPUnit **458** tests, 2191 assertions, 1 skipped (was 457 / 2180). phplint,
phpcs `--max-warnings 0`, savepoints, validate and phpdoc (0 `Line` rows, database
up) all clean. Behat not re-run: no runtime code path changed, only the export
and metadata. `coursesync-ci-db` started and stopped again. Nothing committed.


---

# Phase 59 — splitting the seven long methods (phpmd)

Asked to refactor the long methods phpmd reports. **Scope, stated up front:** the
seven methods over phpmd's 100-line limit in plugin code. Left alone on purpose:
the upgrade function in `db/upgrade.php` (170 lines; upgrade steps are one linear
function by convention and splitting released steps adds risk for nothing), the
long test methods, and the class-level complexity findings. Pure extraction: no
logic change, public signatures unchanged, no test edited.

## P59.1 What was done

| Method | Lines | Extracted into |
| --- | --- | --- |
| `syncer::run_locked` | 189 | `sync_activity()` (the per-activity decisions), `open_connection()`, `subsections_first()` |
| `grade_pull::pull` | 133 | `bring_attempts()`, `usernames_in()`, `local_grade_items()`, `skip_items_not_on_source()` |
| `grade_pull::pull_item` | 120 | `existing_grades()`, `release_pulled_override()`, `classify_grade()` |
| `attempt_pull::pull_quiz` | 118 | `quiz_has_attempts_to_bring()`, `write_attempt()` |
| `quiz_handler::export_children` | 108 | `fixed_slot_child()`, `random_slot_child()` |
| `quiz_handler::create_from_remote_data` | 108 | `build_quiz_data()` |
| `remote_client::call` | 104 | `interpret_response()` |

phpmd, plugin code: **85 -> 74 violations**; long-method findings 8 -> 1 (the
excluded upgrade function). Cyclomatic complexity and NPath fell with them
(`pull_item` 31 -> 14 and 629,040 -> 868; `pull_quiz` 16 -> 13 and 3,900 -> 868;
`run_locked` 24 -> gone). One finding is genuinely new: `sync_activity()` has
complexity 15 / NPath 360 - the complexity moved out of `run_locked`, it was not
created. Not chased: the remaining complexity findings are a separate piece of
work.

## P59.2 Evidence

- **The block suite is unchanged**: 458 tests, **2191 assertions**, 1 skipped
  before and after - identical counts. No test file was touched.
- phplint, phpcs `--max-warnings 0`, savepoints, validate, phpdoc (0 `Line`
  rows, database up), lang order: all clean.
- **Behat 40/40 scenarios, 866/866 steps** locally (syncer drives nearly every
  browser flow, so unit tests alone were not enough).
- Each method was verified on its own before the next: sync, targeted tests,
  phpcs, and phpmd for that file with a before/after diff.

## P59.3 Traps hit

- **phpmd counts physical lines**, signature to closing brace, comments and blanks
  included (`pull_quiz` 178..295 = 118). So moving code *and its comments* is what
  shortens a method. `pull()` landed on exactly 100 and was still flagged: the
  limit is "100 or more". A second extraction (`skip_items_not_on_source`) fixed it.
- **The 10-parameter limit is real**: a naive extraction of `run_locked`'s loop body
  needed 10 parameters, which phpmd flags too. `sync_activity()` takes 9 by
  deriving `$blockinstanceid` from `$record->blockinstanceid` (the record is
  fetched `WHERE blockinstanceid = :param`, so they are identical by construction;
  commented in the method).
- **`attempt_pull` relies on late static binding**: `write_attempt()` still calls
  `static::create_attempt()`, and a test subclass overrides
  `next_attempt_number()` deeper in the chain. `self::` forwards the called class,
  so it still resolves; the clash test (`attemptskipbusy`) covers it and passed.
- **Unused `global $DB`**: after the grade loading moved out of `pull_item`, its
  `global $DB` would have been a new phpmd finding; removed.
- **A loose `--filter` runs core's tests too.** `quiz|qbank|...` matched 1,646
  tests, and two core tests failed (`mod_qbank restore_test`, `quiz_grading
  provider_test`). They pass alone, and the unchanged `HEAD` run still failed
  `quiz_grading`; the qbank restore test failed once and passed on rerun. Both
  are core, unrelated. Filter on the plugin's namespace (`block_coursesync`) for
  the block's own signal.
- **Counted wrongly before**: I earlier reported "two phpmd violations" from the
  first two blocks of a 42-file report (Phase 57). The baseline for this phase was
  counted from the full output: 85 in code, 44 in tests.
- My first `verify.sh` failed because `$S` was not defined inside the script
  (log paths became `/gates/...`): a script is not the shell that wrote it.

## P59.4 State

Nothing committed. `coursesync-ci-db`, the 8001 server and Selenium were started
for the runs and stopped again. A full local rehearsal against the GitHub workflow
was not repeated: the push will run it on 4 jobs.

# Phases 60-64 — importing assignment submissions (v1.21.0-beta)

Designed in `moodle-block_coursesync/docs/DESIGN_ASSIGN_SUBMISSIONS.md` (all six
open questions answered with the recommendation: latest attempt only, no drafts,
no team assignments, a separate pull button, grades left for later, course
`maxbytes` plus a per-run total). Built in one sitting, logged here by the five
phases the design named. Version 2026100501, release v1.21.0-beta.

## P60 Source side (switches, permissions, `get_submissions`, `get_submission_file`)

- `allowsubmissionexport` / `allowsubmissionpull`, capabilities
  `exportsubmissions` (no archetypes, `RISK_PERSONAL`) and `pullsubmissions`
  (editing teacher, manager). `local\submissions` holds what both ends share:
  the latest-submitted rule, the two file areas, the enabled plugins and the
  content fingerprint.
- `get_submission_file` is a new function, **not** an extension of
  `get_activity_file`: that one authorises "an area the handler declared", a
  promise about an activity, and a submission file belongs to one student.
- 32 new tests (18 + 14). Mutation checks on ten gates; nine failed the tests
  as they should. **The tenth (team gate in the file function) did not**: my team
  test used an assignment with no submission, so the call was refused for an
  unrelated reason. Fixed by giving the test a real file in an ordinary
  submission and flipping `teamsubmission` afterwards; mutation now caught.

## P61-P62 Destination: deciding, writing, files

- `submission_pull` (decides) and `local\submission_writer` (writes, in one
  transaction; files fetched to temp files first). Ledger table
  `block_coursesync_submission`, upgrade step 2026100501.
- **`(object) $by + [...]` is a TypeError**: the cast binds tighter than `+`, so
  it adds an array to an object. Wrote `(object) ($by + [...])`. Found by the
  first destination test run (16 of 38 failed on it).
- **A foreach variable shadowed a parameter**: adding `$kind` to
  `history::record_grade_pull()` collided with its own `foreach (... as $kind)`,
  so every grade pull was recorded as kind `submission`. Two existing tests caught
  it; renamed the parameter `$runkind`.
- **`get_max_upload_file_size()` was the wrong limit.** It folds in PHP's
  `upload_max_filesize`/`post_max_size`, which are about a browser's request. In
  the test run it silently capped files, and on a real site it would have refused
  files the source allowed. Now the site's and the course's `maxbytes` only.
- **`clean_param(PARAM_PATH)` mends rather than refuses**: `/../../` became `/`,
  and my "refuse unsafe paths" test failed because the answer was accepted.
  `submissions_result` now refuses any path, name or hash that cleaning changes.
- **`classify()` order mattered**: first version flagged a copy edited here on
  every pull even when the source had not changed. An unchanged source is now
  SAME before the "touched here" check.
- **`make_request_directory()` makes a new directory each call**, so holding
  several fetched files at once cannot collide on the name `transfer`.

## P63 UI and history

- `submissions.php` is a copy of the grades page's shape; the block offers
  **Pull submissions** only where `check_allowed()` passes. History gets
  `KIND_SUBMISSIONS` (`kind` is char(20); `submissions` fits) and a table.
- **My lang-string script destroyed multi-line strings.** I appended 58 strings and
  re-sorted by treating every `$string[` line as one string; three existing
  strings span lines, and their continuation lines ended up at the bottom of the
  file (parse error). The working tree had no other uncommitted changes, so I
  rebuilt from `git show HEAD:` with a regex that matches whole entries, and
  re-used the 58 new lines: `git diff --stat` 58 insertions, 0 deletions.
  *Rule: parse the real structure before rewriting a file; check `git status`
  first so a restore cannot lose someone's work.*

## P64 Privacy, reset, docs, tests

- Privacy provider (`TABLES` constant), `local\observer` (reset of
  `reset_assign_submissions`, user deletion), five docs and the design doc.
- Privacy export test maps each declared field to its export key, as before.
- **Fixtures vs the assign generator**: the generator's default
  `submissiondrafts` is 1, so a saved submission is a `draft`; fixtures set 0.
  And the online text plugin needs its editor in the data even for a
  files-only submission, or it raises deprecations and an error.
- **The fake source ran as whoever was pulling**, so a limited user's pull failed
  on the source's `exportsubmissions` check, which is not what happens between
  two sites. The test source now runs as admin and restores the puller.
- **Postgres advisory locks are re-entrant in one session**: the in-progress test
  switches to the file lock factory, as the grade pull test does.
- **Core gives `mod/assign:editothersubmission` to no role.** Behat's editing
  teacher could not see **Pull submissions** until the Background granted it.
  This is a real property of the design (documented in INSTALL, TEACHER_GUIDE,
  PILOT_CHECKLIST), not a test artefact.
- Behat step context: `$this->getDataGenerator()` does not exist there; use
  `testing_util::get_data_generator()`.
- `pkill -f "php -S ..."` killed my own shell (the pattern is in its own command
  line, exit 144). Use `pkill -f "[p]hp -S ..."`.
- `admin/cli/upgrade.php` says "Config table does not contain the version" on the
  CI install: it only has PHPUnit and Behat tables. `phpunit/cli/init.php` does
  the upgrade that matters, after the DB container has had time to start.

## P60-64 Evidence

- PHPUnit **531** tests, **2491** assertions, 1 skipped (was 458 / 2191): +73.
  New files: `get_submissions_test` (18), `get_submission_file_test` (14),
  `submission_pull_test` (38), plus privacy and observer tests.
- Mutation checks, ten gates: first run caught 9; after the fix all 10. Gates:
  existing work overwritable, SHA-1 check, export switch, export capability,
  student gate on the file function, drafts served, team gate on the file
  function, "deleted is not brought back", "changed here is kept", pull switch.
- Behat **43/43** scenarios, **958/958** steps (was 40 / 866); new feature
  `assignment_submission_pull.feature` (3 scenarios). First run failed three
  steps: the generator call and the capability above.
- phplint, phpcs `--max-warnings 0`, phpdoc (exit 0, no `Line` rows), validate,
  savepoints: clean. First phpcs run had 6 line-length warnings, fixed.
- phpmd (informational, exit 0): `submission_writer` clean after splitting
  `write()` (103 lines) into `save_row`/`save_files`/`save_record`;
  `pull_submission` went from 11 parameters to 5 with a per-run object. Left:
  `submission_pull` class complexity 57 (limit 50), coupling 16, `pull()`
  cyclomatic 12 / NPath 896 (the same shape as `grade_pull::pull`); the new
  test classes' method counts; `remote_client::get_submission_file` with 10
  parameters (as `get_activity_file` has 11).

## P60-64 State, and not done

- **Nothing committed.** Servers and Selenium started for Behat were stopped;
  `coursesync-ci-db` stopped.
- **Not run on the Docker sites A/B/C** (two real sites over HTTPS): everything
  above was on the CI site, whose "other site" is itself. A real two-site check
  is still worth doing before a pilot.
- Not done on purpose: marks/feedback (`assign_grades`), earlier attempts,
  drafts, team assignments, other submission plugins, submission statements.

## Phase 65 - Submission sync tried on the Docker sites (2026-10-10)

First real two-site run of v1.21.0-beta (2026100501): destination A
(`https://192.168.56.10:8443`, CLAUDEFRESH, block 10) pulling from source B
(`:8444`, REMOTE1) over HTTPS. Sites had been stopped for two weeks.

- **Start-up:** `docker compose up -d db-a db-b db-c`, then
  `up -d --force-recreate --no-deps web-a web-b` (the stale bind-mount trap),
  then `web-c`. `upgrade.php` on A and B registered the three new settings.
  Site C has no plugin and was only checked for HTTP 200.
- **Seed, B:** students student1-3 enrolled in REMOTE1; assignment `Pilot essay`
  (online text + file, not team); student1 submitted text + 5021-byte file,
  student2 text only, student3 a draft. `allowsubmissionexport` on;
  `exportsubmissions` + `exportgrades` granted to the `coursesyncservice` role.
- **Seed, A:** same three usernames enrolled; `teacher_csa` enrolled as editing
  teacher; `allowsubmissionpull` on; `mod/assign:editothersubmission` granted to
  `editingteacher` (core gives it to no role). Assignment copied by `syncer::run`.
- **Results:** preview = 2 add (draft not offered); run = 2 add, file arrived
  with the same SHA-1 as on B; second run = 2 same, no duplicates, ledger 2 rows;
  edit on B = update; edit on both = conflict (`subreasonchanged`), the local
  text kept; pull switch off = `errorsubmissionpulloff`; source switch off =
  `errorsubmissionexportoff`.
- **Traps:** `add_moduleinfo` needs `$mi->module` (module id) as well as
  `modulename`, else a NOT NULL violation on `course_modules.module`. CLI scripts
  that touch the token key must run as `www-data` (`docker exec -u www-data`);
  the first `syncer::run` ran as root, and the key stayed `www-data`-owned only
  because it already existed.
- **Not done:** the browser page `submissions.php` was not driven (no known
  teacher password); the engine calls are the same ones it makes. Marks/feedback,
  earlier attempts, drafts and team assignments remain out of scope.

## Phases 66-70 - Assignment marks and feedback sync (v1.22.0-beta, build 2026101001), 2026-10-10

Asked for as "next is marks and feedback sync". Two decisions asked of the user
first, both answered "recommended": **marks sync takes over from grade sync** for
assignments, and **mark + written comment** is the scope. Design:
`docs/DESIGN_ASSIGN_MARKS.md`.

**Why it exists.** Grade sync writes a gradebook override; the assignment's grader
screen does not see overrides, so after the submissions import it showed work as
ungraded beside a gradebook grade. This writes the real `assign_grades` row.

**What was built** (same shape as submissions, own switches `allowmarksexport` /
`allowmarkspull`, caps `exportmarks` (no role) / `pullmarks`, ledger
`block_coursesync_mark`, page `marks.php`, history kind `marks`):
- Source: `local\marks` (shared: which grade row counts, comment, fingerprint,
  scaling), `external\get_marks`, `external\get_mark_file` (separate from the
  submission file function: its gate is "a mark `get_marks` would describe").
- Destination: `marks_result`, `remote_client::get_marks/get_mark_file`,
  `marks_pull` (classify), `local\mark_writer`.
- Written through `assign::update_grade()` so the gradebook is pushed; the person
  pulling is the grader (a teacher on the other site is not a user here).
- Takes over from grade sync: after the grade is in, a grade-sync override that is
  still as written (`grade_pull::untouched()`, now public with `release()`) is
  removed; `grade_pull` skips students with a mark ledger row (`gradeskipmarks`).

**Traps found and how**
- `assign::get_user_grade($uid, true)` also creates a `new` submission row when
  there is none - that is what marking does, and the submissions pull already
  reuses such a row, so it was kept.
- `assign::grading_disabled()` is true for any *overridden* gradebook grade, so it
  cannot be the lock check (it would block the very grades we replace): the lock
  is read from `grade_item`/`grade_grade` directly.
- `update_grade()` only pushes to the gradebook for the latest attempt; the grade
  goes to the student's latest local attempt.
- The release of the grade-sync override must come **after** `update_grade()`:
  `set_overridden(false)` refreshes from the assignment, which must already hold it.
- `assign::add_attempt()` is protected; the test reopens an attempt by rows.
- The lang file's multi-line strings have blank lines inside them: a script that
  re-sorts the file must keep blank lines within a string (two were lost and caught
  by the diff showing deletions). Placeholders must be `{$a->x}`, not `{\$a->x}`.
- A Behat step regex over 132 characters is a phpcs warning; `.*?` groups shorten it.
- Moodle 5.x assignment page has a **Submissions** tab, not "View all submissions".
- `pkill -f "php -S ..."` inside a command that contains that text kills the
  shell itself (exit 144); use `pgrep -f "[p]hp -S ..." | xargs kill`.

**Evidence**
- PHPUnit **568** tests, **2703** assertions, 1 skipped (was 531 / 2491): +37
  (`external/get_marks_test` 14, `marks_pull_test` 22, privacy +1, observer extended).
- Behat **46/46** scenarios, **1052/1052** steps (was 43 / 958): new feature
  `assignment_marks_pull.feature` (3 scenarios).
- Mutation checks, 20 gates: first run caught 18 of 20 real ones (one "survivor" was
  an equivalent mutation); the other two were real gaps, closed by
  `test_no_files_where_comments_are_off` and
  `test_a_comment_only_mark_here_is_in_the_way`, then caught. Gates: changed-here
  kept, existing not overwritten, removed not brought back, workflow release gate,
  student gate on the file function, comments-off gate on the file function, both
  switches, both permissions, gradebook lock, override release only if untouched,
  grade-sync skip, scaling, comments-off-locally skip, placeholder rows, team reason,
  grader recorded, comment-only mark counts as a mark.
- phplint, phpcs `--max-warnings 0` (first run: 9 long-line warnings + 1 blank-line
  error in new code, fixed), phpdoc (0 `Line` rows), validate, savepoints: clean.
- **Real two-site run on the Docker sites** (A pulling from B, over HTTPS): grade
  sync first wrote overrides for student1/2; marks preview = 2 add + 2 "override
  removed"; run wrote `assign_grades` 85/62 with the comments, the 3020-byte comment
  file arrived with B's SHA-1, gradebook finalgrade 85/62 with `overridden = 0` and
  the grade-sync ledger empty; second run = 2 same; grade sync afterwards =
  `gradeskipmarks` for both; B edit = update (85 -> 90 and the comment), B edit + A
  edit = conflict, A's text and 66 kept; source switch off = `errormarksexportoff`;
  pull switch off = `errormarkspulloff`. Containers stopped afterwards.

**State, and not done**
- Committed? See git log; **not pushed** unless the user says so.
- Not done on purpose: other feedback plugins and feedback files, rubric detail,
  earlier attempts, team assignments, scales, per-assignment selection.
- The `marks.php` page was driven by Behat (CI site), not by a browser on the Docker
  sites; the Docker run used the engine from the CLI as `teacher_csa`.
- phpmd not run this time; `marks_pull` is smaller than `submission_pull`.
- The release zip was not rebuilt.

## Phase 71 - Choose which assignments a submissions or marks pull covers (v1.23.0-beta, build 2026101002), 2026-10-10

Recommended by me and chosen by the user ("do the assignment selection"). A pull used to
cover every copied assignment in one go; marks pulls write grades, so a teacher needs to
be able to hold one back.

**What was built.** `preview()`/`run()` of `submission_pull` and `marks_pull` take an
optional `?array $only` of source cmids; `pull()` narrows the copies with
`array_intersect_key()` before asking the source (so the source is asked only about the
ticked ones). The preview pages put the table inside the form with a tick box per
assignment (ticked where something would be written); the button is **Pull the ticked
assignments**; ticking nothing says so and runs nothing (so no history row). A selection
can only narrow: an unknown id matches nothing. No schema change, version bumped only.

**Traps**
- `marks.php` called `marks_pull::local_copies()`, which does not exist; the helper lives
  in `submission_pull` (both pulls copy only assignments).
- Behat's field locator did not find a checkbox that only had `aria-label`; a real
  `<label for>` (visually hidden) is found. Moodle has no `I uncheck` step: use
  `I set the field "..." to ""`. An undefined step makes behat stop and wait for
  interactive input with stdin attached: run it with `< /dev/null` and `timeout`.
- Making the button label count-free meant editing the old scenarios (`Pull the
  submissions (1)` etc.). A second assignment in the Background changes the expected
  counts in the existing submission scenario (Sam now has two submissions).
- `pgrep -f "[v]endor/bin/behat"` in a command that also contains `vendor/bin/behat`
  kills the shell (exit 144) - separate the kill from the run.

**Evidence.** PHPUnit **574** tests, **2733** assertions, 1 skipped (was 568 / 2703);
Behat **48/48** scenarios, **1121/1121** steps (was 46 / 1052); phplint, phpcs
`--max-warnings 0`, phpdoc (0 `Line` rows), validate, savepoints clean. Mutation checks
(4, all caught): narrowing removed in each engine, empty-selection-means-everything,
`run()` ignoring `only`.

**Not done.** Selection on grade sync (it covers every activity type and has its own
page); a combined "pull submissions then marks" action; not tried by hand on the Docker
sites (the engine is covered by the tests that drive the real source functions, and the
page by Behat). Committed? See git log. The v1.22.0 zip does not contain this; no new zip
was built.

## Phase 72 - Clean question XML from the other site (v1.23.1-beta)

**Trigger:** an automated review said `sync_question_bank_questions()` stored
the question XML fragment unchecked. SECURITY.md says every remote field is
cleaned in `activity_payload`; `child_field('xml')` returns the raw string, so
questions were the one path around that rule.

**Fix:** `local/question_cleaner` walks the question `qformat_xml` returns and
cleans every text that has a format (arrays with text+format, and strings next
to a `<name>format` sibling) with `activity_payload::clean_html()`; the name
goes through PARAM_TEXT. Called once, just before `save_question()`.

**Proof:** a round-trip test plants `<script>` and `onerror` in question text,
general feedback, option feedback, answers and a hint. With the call removed
the script is stored verbatim (mutation check), so the gap was real, not
theoretical. 575 PHPUnit tests, all plugin-ci gates clean.

**Traps:** the CI Postgres container (`coursesync-ci-db`) was stopped, so
PHPUnit failed with "Connection refused" until `docker start`. The table is
`qtype_multichoice_options.questionid`, not `question`. Questions already
copied are not rewritten. Cleaning cannot be done in `from_response`: the XML
is only parseable by the importer, so cleaning has to follow the parse.

## Phase 73 - Assignment selection tried on the Docker sites (v1.23.1-beta), 2026-10-10

Closes the "not tried by hand" item of Phase 71. Destination A (block 10, CLAUDEFRESH),
source B (REMOTE1), both on v1.23.1-beta (build 2026101003); `up -d db-a db-b web-a web-b`
found the bind mounts live this time (no force-recreate needed), `upgrade.php` was a no-op.

- **Seed, B:** second assignment `Pilot essay 2` (cmid 40, online text) with a submission
  by student2. `add_moduleinfo` also needs `requireallteammemberssubmit`, `blindmarking`
  and the other NOT NULL assign columns when no form supplies them; the failed attempt
  rolled back cleanly.
- **Results (engine calls, as `teacher_csa`):** `syncer::run(..., only=[40])` copied only
  the new assignment (A cmid 67). Submission preview `only=[37]` = 2 entries (same,
  conflict), `only=[40]` = 1 (same -> add on the real run), `only=[]` of an unknown id
  = 0, no selection = 3. `run(only=[40])` added exactly one ledger row (remotecmid 40) and
  left 37's rows and the kept local text alone. Marks preview narrows the same way
  (`[37]` = 2, `[40]` = 0 because nothing is marked there).
- **Traps:** the result object's rows are `->entries`, not `->items`; the ledger column is
  `remotecmid`, not `remoteassignid`. Mail from the seed script logs noreply warnings
  (harmless on the test sites).
- **Not done:** the browser page's tick boxes (covered by Behat 48/48, not driven by hand);
  marks `run()` with a selection (only the preview was run, to avoid altering the
  student2 conflict). The v1.23.1 zip was checked against `git archive HEAD`: identical,
  so no rebuild. Origin/main was already level with HEAD.

## Phase 74 - Bad marks from the other site (v1.23.2-beta, build 2026101004), 2026-10-10

**Trigger:** the `moodle-reviewer` pass before release (H1, M1). Both checked against the
code before fixing; H1 was reproduced by a test failing with `DivisionByZeroError`.

**H1.** `marks::convert()` divides by the source's maximum; `marks_result` let that be 0
(`max(0.0, ...)`), and `1e999` is numeric, so it became INF. `DivisionByZeroError` is not a
`moodle_exception`, so nothing caught it: the page died, no history row. **Fix:** a
`grademax` that is not finite or is negative makes the whole answer `errorbadresponse`; a
mark that is not finite is refused the same way; a zero maximum with no reason skips the
assignment (`markreasonbadmax`, checked in `assignment_problem()`).

**M1.** After scaling, nothing checked the mark against this assignment's maximum. **Fix:**
below zero or above `grade` here (plus half the rounding step) is skipped,
`markreasonoutofrange`; comment-only marks are unaffected.

**Traps**
- A zero maximum is legitimate in a *parsed* answer (a source with no grade says why
  instead), so the parser rejects only non-finite or negative; the pull refuses to scale
  by zero. Rejecting zero in the parser would have failed whole answers over one assignment.
- JSON cannot carry INF, so the test sends the string `'1e999'`, which `is_numeric()` accepts.
- Lang strings were added, so the build was bumped (no schema change).

**Evidence.** PHPUnit **578** tests, 2798 assertions, 1 skipped (was 575); marks_pull_test
28/28. Mutation checks, all caught: guard removed (Error: the division by zero), range check
removed, finite checks removed (two tests). phplint, phpcs `--max-warnings 0`, phpdoc
(0 `Line` rows), validate, savepoints clean. Not tried on the Docker sites or by Behat.

**Not done (from the same review):** M2 files deleted before the database write on an update,
M3 remote online text and comments not cleaned, M4 two `latest` attempts after a reopen, M5
per-module capability check and idnumber targeting, L1-L7.
