# Installing Course Sync

> Part of the Course Sync documentation set:
> **INSTALL.md** · [REMOTE_SETUP.md](REMOTE_SETUP.md) · [TEACHER_GUIDE.md](TEACHER_GUIDE.md) · [DEVELOPER.md](DEVELOPER.md) · [SECURITY.md](SECURITY.md)


For administrators. Covers installing the plugin on both sites and getting them
talking to each other.

Course Sync copies activities from a course on one Moodle site into a course on
another. Moodle has no built-in service for handing activity content between
sites, so Course Sync brings its own — which is why **the plugin goes on both
sites**, not just the one doing the pulling.

| Role | What it does | Who runs it |
| --- | --- | --- |
| **Source** | Offers its course content through a web service | An administrator sets it up once |
| **Destination** | Holds the block and pulls the content in | A teacher uses it per course |

The same code does both jobs. Which role a site plays depends only on how it is
configured.

## Before you start

- Moodle 5.1 (2025100600) or later on both sites, and PHP 8.2 or later.
- **The source site must be reachable over HTTPS with a valid certificate.**
  Course Sync refuses plain `http`, because the access token would travel in
  clear text.
- Administrator access to both sites.
- An account on the source site to act as the sync service account. A dedicated
  account is better than a person's login: it makes the access easy to audit and
  easy to revoke.

## 1. Install the plugin on both sites

Copy the plugin directory to `public/blocks/coursesync` in each site's Moodle
directory, then run the upgrade:

```bash
php public/admin/cli/upgrade.php --non-interactive
```

Or install the ZIP through *Site administration → Plugins → Install plugins* on
each site.

Confirm on both: *Site administration → Plugins → Blocks → Manage blocks* should
list **Course Sync**.

## 2. Set up the source site

Full detail, with the screens named, is in
[REMOTE_SETUP.md](REMOTE_SETUP.md). In brief:

1. *Advanced features* → tick **Enable web services**.
2. *Server → Web services → Manage protocols* → enable **REST protocol**.
3. *Server → Web services → External services* → the **Course Sync** service is
   already listed because the plugin defines it. Edit it and tick **Enabled**.
   Leave **Authorised users only** ticked and **Can download files** unticked.
4. Give the sync account `webservice/rest:use` at **System** level, and
   `block/coursesync:sync` + `moodle/course:view` **only in the category or
   course** it should copy from (two small roles; see REMOTE_SETUP.md).
5. Add the account to the service's **Authorised users**.
6. *Manage tokens* → create a token for that account and the Course Sync
   service. Copy it.

### Why three capabilities

`block/coursesync:sync` is the plugin's own permission. `webservice/rest:use` is
Moodle's permission to call anything over REST, and **no role grants it by
default**. `moodle/course:view` lets the account read a course it is not enrolled
in — Moodle runs its usual access check on every course-scoped call, and a
service account is never a course participant. Missing the third is the most
common setup mistake.

### Restricting which courses can be pulled from

Granting `block/coursesync:sync` and `moodle/course:view` at system level lets
the token read any course on the site, hidden activities and quiz answers
included. Assign them in a category or in specific courses instead. The
destination is refused anywhere the account does not hold the permission, and
the connection test only needs the account to hold it somewhere.

## 3. Set up the destination site

Installing the plugin, then setting up each course's connection, is the
administrator's (or a manager's) job: the setup wizard needs
`block/coursesync:configure`, which only managers hold by default, because the
connection decides what this site may read on the other one. Once a course is
connected, its teachers sync it themselves — hand them
[TEACHER_GUIDE.md](TEACHER_GUIDE.md).

### Outgoing request settings

Moodle filters the requests its own server makes. If the source site is on a
non-standard port, that port must be allowed on the **destination**:

*Site administration → General → Security → HTTP security*

- **cURL allowed ports** — must include the source site's port. The default
  allows 443 and 80 only.
- **cURL blocked hosts** — must not cover the source site's address.

If these are wrong the destination reports *"This site is not allowed to make
connections to that address"*.

## 4. Check it works

On the destination, add the block to a course, run the setup wizard, and use
**Test the connection**. A working connection reports the source site's name and
Moodle version.

If it does not, the message says which of these it is:

| Message | Fix |
| --- | --- |
| The other site could not be reached | Address wrong, site down, or a firewall between them |
| Nothing that looks like a Moodle site answered | The URL has an extra path on the end, or points elsewhere |
| The security certificate could not be verified | Certificate is self-signed, expired, or issued for a different address |
| The security token was not accepted | Token mistyped, expired, or deleted on the source |
| The Course Sync service is not switched on | Step 2.3 was missed, or the token is for a different service |
| The account is not allowed to use Course Sync | Step 2.4 or 2.5 was missed |
| The account is not allowed to see that course | `moodle/course:view` is missing from the role |
| Course Sync is not installed on the other site | Step 1 was only done on one site |
| This site is not allowed to connect to that address | See the outgoing request settings above |

## Private networks and testing

Course Sync refuses to connect to loopback, link-local or private addresses, so
that the server cannot be talked into making requests into its own network.

Test servers legitimately sit on private networks. For those, and **only** those:

```php
// config.php on the destination site
$CFG->block_coursesync_allowprivateurls = true;
```

This relaxes the address rules and nothing else — plain `http`, other schemes and
credentials embedded in the URL are still refused.

It is a `config.php` flag rather than an administration setting on purpose:
turning off a protection against the server reaching its own network should need
access to the server. **Do not set it on a production site.**

## What the plugin stores

| Table | Holds |
| --- | --- |
| `block_coursesync_connection` | One row per configured block: the source address, the encrypted token, the mapped course, and the result of the last test |
| `block_coursesync_run` | One row per sync run: when, who, what was copied, what was flagged |

The token is encrypted with Moodle's own secret storage (`\core\encryption`,
libsodium). The key lives outside the database, in the site's secret data
directory.

**Two consequences worth planning for.** A database dump alone does not reveal
the token. And a database restored somewhere without that key file cannot decrypt
it — the block says so plainly and asks for the token again, but a restore drill
should copy the key file or expect to re-enter tokens.

The key is created with restrictive permissions by whichever account first uses
encryption. If a CLI script running as `root` creates it, the web server user
cannot read it and every sync fails with *"The saved token could not be read"*.
Check the ownership of `<dataroot>/secret/key/` if that happens.

Because the run history records who started each sync, the plugin holds personal
data and implements a full privacy provider. It appears in data requests and
data deletion as normal.

## Upgrading

Standard Moodle upgrade. Upgrade **both** sites, ideally together: the two sides
exchange a version number and the destination reports a mismatch it cannot work
with.

## Uninstalling

Uninstalling from the destination removes the connection records and the run
history. Activities already copied into courses stay where they are — they are
ordinary Moodle activities and are not deleted.

On the source, remember to delete the web service token as well, at
*Server → Web services → Manage tokens*. Uninstalling the plugin does not revoke
a token that was issued for it.
