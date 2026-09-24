# Setting up the source site

> Part of the Course Sync documentation set:
> [INSTALL.md](INSTALL.md) (administrators) ·
> **REMOTE_SETUP.md** (source site) ·
> [TEACHER_GUIDE.md](TEACHER_GUIDE.md) (teachers) ·
> [DEVELOPER.md](DEVELOPER.md) · [SECURITY.md](SECURITY.md)

Course Sync pulls activities from a course on one Moodle site (the **source**)
into a course on another (the **destination**). Moodle core has no general
service for handing activity content between sites, so Course Sync brings its
own. That service runs on the source site, which means **the plugin has to be
installed on both sites**.

Everything on this page happens on the **source** site. Course Sync never
changes settings on another site for you — granting one site that much control
over another is a far bigger trust decision than a sync needs, so these steps
are deliberately manual.

The setup wizard in the block shows the same steps. This page exists for when
the screens do not look quite like the wizard describes, or when the person
doing the source-site work is not the person using the block.

## What you need

- Administrator access on the source site.
- Course Sync installed on the source site (the same plugin, any matching version).
- An account on the source site to act as the sync user. A dedicated account is
  better than a person's own login: it makes the access easy to audit and easy
  to revoke.

## 1. Enable web services

*Site administration > Advanced features*

Tick **Enable web services** and save.

## 2. Enable the REST protocol

*Site administration > Server > Web services > Manage protocols*

Enable **REST protocol**. Course Sync uses REST only; the other protocols can
stay off.

## 3. Enable the Course Sync service

*Site administration > Server > Web services > External services*

A service named **Course Sync** is already listed — the plugin defines it, so
there is no need to build one by hand. Edit it and tick **Enabled**.

Leave **Authorised users only** ticked. It limits the service to the accounts
you name in the next step.

Leave **Can download files** switched **off**. Course Sync does not use Moodle's
general file-serving endpoint: it carries files through its own function, which
will only serve a file belonging to an activity it is syncing. Turning the
setting on would let the token fetch anything its account can reach.

The service contains these functions:

| Function | What it does |
| --- | --- |
| `block_coursesync_ping` | Confirms the site is reachable and the token works. Returns the site name, the Moodle release and the installed plugin version. Nothing else. |
| `block_coursesync_get_course` | Resolves a course id or shortname, so the destination can confirm a mapping before saving it. Returns the id, shortname, full name, visibility and activity count. |
| `block_coursesync_get_modified_activities` | Lists activities in a course changed after a given time: course module id, type, name, ID number and modification time. Metadata only — no activity content. |
| `block_coursesync_get_activity` | Returns everything needed to rebuild one activity, plus a list of any files it holds. |
| `block_coursesync_get_activity_file` | Serves one chunk of one file belonging to a synced activity. |

## 4. Give the sync account permission

The sync account needs three capabilities on the source site:

| Capability | Where | Why |
| --- | --- | --- |
| `webservice/rest:use` | Site level | Moodle's permission to call anything over REST. No role grants it by default, so it has to be added deliberately. |
| `block/coursesync:sync` | **Only** the category or course to copy from | Course Sync's own permission, checked by every function in the service against the course asked about |
| `moodle/course:view` | Same place as `block/coursesync:sync` | Lets the account read a course it is not enrolled in. Moodle's own `require_login()` check runs for every course-scoped call, and a service account is never a course participant. |

Those three are the whole list, whatever kinds of activity the course holds. In
particular the account does **not** need `mod/assign:view`, `mod/quiz:view`,
`mod/wiki:viewpage`, `mod/qbank:view`, `moodle/question:viewall`, or any other
per-activity permission: what authorises reading an activity is
`block/coursesync:sync` on its course. `SECURITY.md` explains why it is
checked there and what follows from that.

**Where you grant it is what the token can read.** An account holding
`block/coursesync:sync` and `moodle/course:view` on a course can read
everything in that course, including activities hidden from students and quiz
answers. Granted at site level, that is every course on this site. Grant them in
the category (or course) that destination is meant to copy from, and use a
separate account and token for each destination that should see something
different.

The tidiest way is two dedicated roles:

1. *Site administration > Users > Permissions > Define roles > Add a new role*,
   named something like `Course Sync REST`, assignable in **System** context,
   with `webservice/rest:use` set to **Allow**. Assign it to the sync account
   at *Assign system roles*.
2. A second role, `Course Sync reader`, assignable in **Category** and
   **Course** context, with `block/coursesync:sync` and `moodle/course:view`
   set to **Allow**. Assign it to the sync account in the category holding
   the source courses (*category > More > Assign roles*), or in one course.

## 5. Authorise the account on the service

*Site administration > Server > Web services > External services >
Course Sync > Authorised users*

Add the sync account to the authorised list.

Moodle may warn that the user is missing `block/coursesync:sync` or
`moodle/course:view` - it only looks at site level. With the reader role
assigned in a category, that warning is expected. A warning about
`webservice/rest:use` is not: go back to step 4.

## 6. Create the token

*Site administration > Server > Web services > Manage tokens > Create token*

- **User**: the sync account
- **Service**: Course Sync
- **Valid until**: set an expiry if your policy wants one, but remember the
  connection will stop working on that date

Copy the token. Moodle shows it in the token list afterwards, but treat it like
a password: anyone holding it can call the service as that account.

## 7. Paste it into the destination site

Back on the destination site, open the course, then the Course Sync block, and
choose **Set up the connection** (or **Manage the connection**). Enter the
source site's address, paste the token, and the wizard will test it.

The token is encrypted before it is stored, using Moodle's own secret storage
(`\core\encryption`, libsodium). The encryption key lives outside the database,
in the site's secret data directory. Two consequences worth knowing:

- The stored token is not readable from a database dump alone.
- If the destination site's database is restored somewhere without that key
  file, the token cannot be decrypted and has to be pasted in again. The block
  says so rather than failing silently.

## If the test fails

| What the block says | Usually means |
| --- | --- |
| The other site could not be reached | Wrong address, site down, or a firewall between the two sites |
| Nothing that looks like a Moodle site answered | The URL points somewhere else, or has an extra path on the end |
| The other site's security certificate could not be verified | The certificate is self-signed, expired, or issued for a different address |
| The other site did not accept the security token | Token mistyped, expired, or deleted on the source site |
| The token was accepted, but the Course Sync service is not switched on | Step 3 was missed, or the token belongs to a different service |
| The account the token belongs to is not allowed to use Course Sync | Step 4 or 5 was missed |
| The other site does not have Course Sync installed | Install the plugin on the source site |
| No course matches that id or shortname | Typo, or the sync account has no sync permission in that course |
| The sync account is not allowed to see that course | `moodle/course:view` is missing from the role |
| This site is not allowed to make connections to that address | The destination site's own outgoing-request rules blocked it — see below |

### Outgoing request rules on the destination site

Moodle filters the requests its own server makes, to stop a site being used to
reach things it should not. The relevant settings are at
*Site administration > General > Security > HTTP security*:

- **cURL blocked hosts** — must not cover the source site's address
- **cURL allowed ports** — must include the source site's port

The defaults allow ports 80 and 443 only. A source site on a non-standard port
needs that port adding, or the connection is refused before it leaves the
building.
