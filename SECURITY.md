# Security review

A review of `block_coursesync`'s own attack surface, carried out in Phase 5
against the code as it stood at the end of Phase 4. It covers what this plugin
adds to a Moodle site, not Moodle itself.

Each finding says what was done about it. "Fixed" means the code changed in
Phase 5; "already correct" means the review confirmed the behaviour and added
test coverage but changed nothing; "known limitation" means the risk is real,
understood, and accepted for the reasons given.

The three things that make this plugin worth reviewing on its own:

- a **teacher-entered URL that the server then requests** (SSRF),
- a **long-lived credential for another site** stored per block instance,
- **content from a different, only partially trusted Moodle** being restored
  into a course here.

---

## 1. Server-side request forgery

The remote site URL is typed in by whoever configures the block, and the server
makes outbound HTTP requests to it. Everything below is enforced by
`\block_coursesync\local\url_validator` and applied to requests through
`\block_coursesync\local\curl_security_helper`.

### 1.1 Every request path is guarded, not just the config-save check — already correct

There are four places the server reaches out: the two AJAX checks
(`test_connection`, `validate_course`), the server-side re-check on save
(`block_coursesync::verify_connection`), the web service calls a sync makes,
and the `.mbz` download. All of them go through `remote_client`, whose
constructor validates the base URL, and every individual request is then made
by `\block_coursesync\local\http\curl_transport`, which attaches a **fresh**
`curl_security_helper` each time. The helper re-parses, re-checks and
re-resolves on each call, so validation is never a one-off at save time.

Phase 5 moved this code out of `remote_client` into `curl_transport` so it can
be substituted in tests. The guards themselves did not change.

### 1.2 The check-then-connect window is closed by pinning — already correct

A name that resolves to a public address when checked and a private one a
moment later would defeat a validate-then-request design. `curl_security_helper`
returns the addresses that passed validation from `get_resolve_info()`, and
Moodle's own `\curl` feeds those into `CURLOPT_RESOLVE` before connecting, so
the connection can only go to an address this plugin approved.

Worth noting because it is easy to assume otherwise: core's own helper returns
an empty pin list when `$CFG->curlsecurityblockedhosts` is unset — which is the
default — so on a stock site there would be **no** pinning at all. The
subclass supplies it unconditionally.

### 1.3 Redirects are not followed — already correct

`CURLOPT_FOLLOWLOCATION => 0` and `CURLOPT_MAXREDIRS => 0`, so a remote site
cannot bounce a request to an address that was never validated. Moodle's `\curl`
handles redirects itself rather than in libcurl and re-runs the blocklist check
on each hop, so even if following were turned on the helper would still see
every target; but it is off, and a 3xx is simply reported as an unexpected
status.

### 1.4 What is blocked

`https` only; no embedded credentials; no query string or fragment on the site
base URL; and every resolved address must be outside the loopback, private,
link-local and reserved ranges, plus CGNAT (`100.64.0.0/10`), IETF protocol
assignments (`192.0.0.0/24`), the three TEST-NET ranges and the benchmarking
range (`198.18.0.0/15`). The site's own `curlsecurityblockedhosts` and
`curlsecurityallowedport` still apply on top, via the parent class.

### 1.5 Known limitation: only A records are resolved

`gethostbynamel()` returns IPv4 addresses only, matching core's helper. A host
that publishes only AAAA records fails to resolve and is refused outright
rather than being requested unchecked, so this is fail-closed; but it does mean
the plugin cannot talk to an IPv6-only remote site.

### 1.6 Known limitation: `block_coursesync_allowinsecureremotes`

Setting `$CFG->block_coursesync_allowinsecureremotes = true` in `config.php`
allows plain `http` and disables the address-range checks entirely, so the
block can be pointed at anything the server can reach. It exists so the plugin
can be tested against throwaway sites on a private Docker network. It is
config-file-only — not exposed in any admin UI — and documented in the README
as never to be set in production.

### 1.7 Known limitation: a capability holder can still probe public addresses

With every private range blocked, someone holding `block/coursesync:trigger`
can still make the server issue requests to arbitrary *public* addresses and
observe coarse success/failure and timing. That is inherent to the feature.
The actions are capability-gated and sesskey-protected, and there is no rate
limit on them.

---

## 2. Token handling

### 2.1 Encrypted at rest — already correct

The token is encrypted with `\core\encryption` (libsodium, site key under
`$CFG->dataroot/secret/`) before it reaches `block_instances.configdata`, which
is otherwise plain base64-serialised data visible to anyone with database or
site-backup access. `token_store::decrypt()` fails closed: an undecryptable
value yields an empty string and a developer-level debugging message rather
than an exception that might surface the ciphertext.

### 2.2 Never rendered back — already correct

The form's token field is an empty `password` element even when a token is
stored; a separate static line just says whether one is held. No renderable,
template or external function returns the token or its ciphertext. Leaving the
field blank keeps what is stored — subject to 2.4.

### 2.3 Fixed: the token could reach the debug log

`remote_client::log_failure()` passed cURL error strings and remote error
bodies straight into `debugging()`. The `.mbz` download URL carries the token
in its query string, and both a cURL error and a Moodle JSON error payload can
quote the URL that was requested — so a failed download could print the token
on screen to an administrator running in developer mode, and into the server
error log.

Fixed by adding `remote_client::redact()`, which masks any `token=` or
`wstoken=` value in text on its way to `debugging()`.

### 2.4 Fixed: the stored token could be sent to a host of the caller's choosing

`block_helper::effective_token()` fell back to the stored token whenever the
submitted token field was blank — **without regard to which URL it was about to
be sent to**. Two ways to exploit that, both needing
`block/coursesync:addinstance` + `block/coursesync:trigger` on the block:

- call `block_coursesync_test_connection` with the block's id, a `remoteurl`
  pointing at a server the caller controls, and an empty `token`; the server
  posts the stored token to that server;
- or simply edit the block, change the URL, leave the token field blank, and
  save — `verify_connection()` then does the same thing.

This matters because the person who can do it is not necessarily the person who
pasted the token in. A course can have several editing teachers; one of them
configures the connection with a token their department was issued, and another
can read it back out this way. It is a privilege escalation from "can use this
connection" to "knows the credential behind it".

Fixed by scoping the fallback to the site the token belongs to:
`effective_token()` now takes the target URL and returns the stored token only
when that URL normalises to the stored `remoteurl`. `instance_config_save()`
discards the stored ciphertext when the connection is repointed without a new
token, and `edit_form::validation()` asks for the new site's token up front so
the failure is a clear form error rather than a silent one. Covered by
`tests/local/block_helper_test.php`.

### 2.5 Known limitation: the token travels in the download URL

`webservice/pluginfile.php` accepts its token only as a `token` query
parameter, so the `.mbz` download necessarily carries the credential in the
URL. It will appear in the **remote** site's web server access log, and in any
intercepting proxy's. This is how Moodle's own mobile app fetches files and
there is no header-based alternative to use. HTTPS keeps it off the wire in
clear, and 2.3 keeps it out of this site's logs; the remote administrator's
access log is outside this plugin's control.

### 2.6 Known limitation: nothing rotates or expires the token

The token lives until the remote administrator revokes it. The plugin has no
way to create or roll one — Moodle deliberately exposes no web service for
that — so revocation is a manual, remote-side operation.

---

## 3. Access control

Every entry point added in Phases 2–6 was checked. Nothing relies on the
block's visibility, on the block being on the page, or on a page-level check
alone.

| Entry point | Check |
|---|---|
| `block_coursesync::get_content()` | `:viewhistory` or `:trigger`, else renders nothing |
| `edit_form` (`check_access_for_dynamic_submission`) | `:addinstance` **and** `:trigger` in the block context |
| `block_coursesync_test_connection` (AJAX) | `validate_context`, `require_sesskey`, `:addinstance` + `:trigger` |
| `block_coursesync_validate_course` (AJAX) | `validate_context`, `require_sesskey`, `:addinstance` + `:trigger` |
| `block_coursesync_check_updates` (AJAX) | `validate_context`, `require_sesskey`, `:trigger` |
| `block_coursesync_sync_status` (AJAX) | `validate_context`, `:viewhistory` |
| `sync.php` (sync and cancel) | `require_sesskey`, `require_login($course)`, `:trigger` |
| `history.php` | `require_login($course)`, `:viewhistory` |
| `conflicts.php` (view) | `require_login($course)`, `:trigger` |
| `conflicts.php` (three actions) | as above, plus `require_sesskey` per action |
| `engine::prepare_context()` | `:trigger`, `moodle/restore:restoreactivity`, `moodle/restore:restoretargetimport`, as the triggering user |
| `list_activities` (WS) | `validate_context(course)`, `moodle/backup:backupactivity` at course **and** per module |
| `backup_activity` (WS) | `validate_context(course)`, `moodle/backup:backupactivity` + `moodle/backup:downloadfile` on the module |
| `block_coursesync_pluginfile()` | `moodle/backup:backupactivity` + `moodle/backup:downloadfile` on the module |

Two notes on the deliberate choices there:

- The web service functions `validate_context()` against the **course**, not
  the module. Validating a module context routes through
  `require_login($course, ..., $cm)`, which refuses anyone who cannot view the
  activity as a participant — and the least-privilege service account this
  integration is meant to use holds no `mod_*:view` capabilities at all.
  Authorisation is instead the backup capabilities, checked per module. Hidden
  activities additionally require `moodle/course:viewhiddenactivities`.
- Resolving a conflict takes `:trigger`, not `:viewhistory`, because it changes
  course content. Where taking the remote version would delete an activity
  this block did not create, `conflicts.php` requires a separate confirmed
  POST before calling the resolver at all, and the activity to be replaced is
  identified again at that point rather than trusted from what an earlier run
  recorded.
- Checking what is available takes `:trigger`, not `:viewhistory`, although it
  changes nothing here: it makes this server send a request to another site
  with the stored token, which is the same power Sync now carries.
- The activities ticked in that list arrive as remote course module ids and
  are treated as a filter, never as an instruction. A run re-reads the remote
  course and plans it afresh; the ids only narrow that plan, so an id that was
  tampered with, or that named something the plan would not have touched,
  selects nothing. Every capability check a full run makes still runs.

### 3.1 Fixed: the conflict resolver trusted its caller

`resolver::resolve()` had no capability check of its own; `conflicts.php`
checked `:trigger` before calling it. That was not exploitable — the page was
the only caller — but it put the whole decision in the entry point, and
`pull_remote` goes on to restore content. `resolve()` now calls
`require_capability('block/coursesync:trigger', ..., $userid)` itself before
dispatching to any action. Covered by `tests/local/sync/resolver_test.php`.

### 3.2 The file area is re-authorised on serve — already correct

`block_coursesync_pluginfile()` repeats both backup capabilities rather than
assuming that whoever reaches the file area went through `backup_activity`
first. A file area is addressable independently of the function that filled it,
and the filename contains a `uniqid()` but should not be treated as a secret.

### 3.3 CSRF

Every state-changing action is a POST carrying a sesskey: `sync.php` calls
`require_sesskey()` before anything else, each of the three conflict actions
calls it, and the AJAX functions are reached through `lib/ajax/service.php`,
which enforces sesskey for every `ajax => true` function — with
`require_sesskey()` called explicitly in `test_connection` and
`validate_course` as well, since those two make outbound requests.
`sync_status` is a read.

`sync.php`'s `returnurl` is `PARAM_LOCALURL`, so it cannot be used as an open
redirect.

---

## 4. Handling data from the remote site

The remote Moodle is treated as **only partially trusted**: authenticated, and
chosen by a local teacher, but not assumed to be well-behaved.

### 4.1 Strings — already correct

Activity names, id numbers, the site name and the course full name all arrive
from the remote and are stored and later displayed. Every template renders them
with `{{ }}`, which HTML-escapes; there is not a single `{{{ }}}` in the
plugin. The web service return structures declare `PARAM_TEXT` for names, and
the provider side applies `\core_external\util::format_string()` before
returning them. Remote error detail that reaches a notification passes through
`clean_text()` in `\core\output\notification`.

### 4.2 Errors are not echoed back — already correct

`remote_client` never puts a remote error body into the exception it throws.
Remote `errorcode` values are mapped to a small fixed set of local language
strings, with a generic fallback; the raw body goes only to `debugging()` (and
now redacted, see 2.3). So a hostile remote cannot choose the text this site
displays.

### 4.3 Injection

All database access is through the `$DB` API with placeholders. The only
concatenated SQL is a static `get_records_select()` fragment in
`backup_packager::cleanup()`, whose values are bound.

### 4.4 Known limitation: a hostile remote can serve a hostile backup

The `.mbz` is unpacked and handed to Moodle's restore subsystem. That is the
same trust boundary as an administrator uploading a backup file by hand, and a
malicious backup is a known Moodle-wide risk class, not something this plugin
can neutralise. What the plugin does do is require the triggering user to hold
`moodle/restore:restoreactivity` and `moodle/restore:restoretargetimport` in
the target course, so the restore is never more privileged than the person who
asked for it, and run the restore as that user rather than as an
administrator.

The practical guidance, which belongs in any deployment note: connect a course
only to a site you trust about as much as your own.

---

## 5. Backup and restore

### 5.1 Restore cannot target a course the user may not edit — already correct

`engine::prepare_context()` requires, **as the triggering user** and in the
target course's context, `block/coursesync:trigger`,
`moodle/restore:restoreactivity` and `moodle/restore:restoretargetimport`. The
`restore_controller` is constructed with that user's id, not with an
administrator's, so the restore itself runs under their permissions. The ad-hoc
task carries the user id (`set_userid()`), so queuing a run does not launder it
into a more privileged context.

The target course is derived from the block instance's own context, never from
a request parameter, so there is no course id to tamper with.

### 5.2 Temporary files are not web-accessible — already correct

The downloaded `.mbz` goes to `make_request_directory()`, under
`$CFG->tempdir`, which is outside the web root and removed at the end of the
request. The unpacked backup goes to `make_backup_temp_directory()`, likewise
under `$CFG->dataroot`. The packaged backup on the provider side lives in
Moodle's file storage (`filedir`, content-addressed, not web-addressable) and
is reachable only through `block_coursesync_pluginfile()` with the checks in
3.2.

### 5.3 Fixed: a failed unpack left its temp directory behind

`restorer::pull()` used to take the temp directory name as the *return value*
of `extract()`, so if `extract()` threw — a corrupt or hostile archive being
exactly when it would — the name was never assigned and the `finally` that
cleans up never ran, leaving a partially-unpacked directory in
`$CFG->dataroot/temp/backup` indefinitely. Fixed by naming the directory before
anything is written into it and wrapping both the extract and the restore in
the `try`, so cleanup is reached on every path.

`$CFG->keeptempdirectoriesonbackup` is still honoured, so an administrator
debugging a restore keeps the behaviour they expect.

### 5.4 Uncollected backups expire — already correct

A packaged `.mbz` that is never downloaded (the puller died between the two
calls) is removed by the `cleanup_backups` scheduled task after six hours.

---

## Summary

**Fixed in Phase 5**

| # | Finding |
|---|---|
| 2.3 | Token could be written to the debug log and server error log via cURL/remote error text quoting the download URL |
| 2.4 | Stored token was sent to whatever URL the caller supplied, letting a second teacher read a credential they were never given |
| 3.1 | `resolver::resolve()` had no capability check of its own and trusted `conflicts.php` to have done it |
| 5.3 | A failed unpack left its backup temp directory behind for good |

**Confirmed correct, coverage added**

SSRF validation on every request path (1.1), DNS pinning via `CURLOPT_RESOLVE`
including on stock sites where core's helper supplies none (1.2), redirects not
followed (1.3), token encrypted at rest and never rendered back (2.1, 2.2),
capability checks on all thirteen entry points (3), sesskey on every
state-changing action (3.3), remote strings escaped everywhere (4.1), remote
error text never echoed (4.2), restore bounded by the triggering user's
capabilities (5.1), temp files outside the web root (5.2).

**Known limitations**

| # | Limitation |
|---|---|
| 1.5 | IPv4 (A records) only, so an IPv6-only remote is refused |
| 1.6 | `block_coursesync_allowinsecureremotes` disables the URL rules entirely — development only |
| 1.7 | A capability holder can still make the server probe public addresses; no rate limit |
| 2.5 | The token is in the download URL query string, so it reaches the remote site's access log |
| 2.6 | No token rotation or expiry; revocation is manual and remote-side |
| 4.4 | A hostile remote can serve a hostile backup; the trust boundary is the same as a hand-uploaded `.mbz` |
