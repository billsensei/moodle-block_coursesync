# Pilot checklist

> Part of the Course Sync documentation set:
> [INSTALL.md](INSTALL.md) · [REMOTE_SETUP.md](REMOTE_SETUP.md) · [TEACHER_GUIDE.md](TEACHER_GUIDE.md) · [DEVELOPER.md](DEVELOPER.md) · [SECURITY.md](SECURITY.md)

A short list for the first run between two real client sites. Everything here is
covered in more detail in the guides above; this is the order to do it in and the
things worth checking before teachers touch it.

## Before the pilot

**On both sites**

- [ ] Moodle 5.1 or later, PHP 8.2 or later
- [ ] Plugin installed and the upgrade run
- [ ] Both sites on the **same plugin version** — the destination reports a
      mismatch it cannot work with

**On the source site**

- [ ] Web services enabled, REST protocol enabled
- [ ] Course Sync service enabled, **Authorised users only** ticked,
      **Can download files** left off
- [ ] A dedicated sync account, not a person's login
- [ ] That account holds `block/coursesync:sync`, `webservice/rest:use` and
      `moodle/course:view`
- [ ] Token created and handed over by a route that is not email in plain text
- [ ] Decide the scope: system-level permission means any course can be pulled
      from. Assign in specific courses to limit it

**On the destination site**

- [ ] Reachable over HTTPS with a certificate the destination will accept
- [ ] The source's port is in **cURL allowed ports** if it is not 443
- [ ] The source's address is not covered by **cURL blocked hosts**
- [ ] `$CFG->block_coursesync_allowprivateurls` is **not** set unless the source
      genuinely sits on a private network
- [ ] `<dataroot>/secret/key/` is readable by the web server user — see below

## The encryption key

The token is encrypted with a key created on first use, owned by whichever
account created it. If a CLI script running as `root` gets there first, the web
server cannot read it and every sync fails with *"The saved token could not be
read"*.

- [ ] Check `ls -la <dataroot>/secret/key/`
- [ ] Include that directory in backups, or accept that tokens must be re-entered
      after a restore

## First run

- [ ] Add the block to one real course, not a scratch one — the point is to see
      it behave against real content
- [ ] Run the wizard and confirm the test reports the source site's name
- [ ] Use **See changes** before the first **Check now**
- [ ] Check the results table: copied, flagged, skipped, failed
- [ ] Open the synced activities and compare them with the source

## What to tell teachers up front

- Every activity type in standard Moodle is copied - twenty-three: Page, URL,
  Label, File, Folder, Book, Forum, Wiki, Assignment, Quiz, Choice, Glossary,
  Feedback, Database, Workshop, Lesson, H5P, Question bank, SCORM package, IMS
  content package, External tool, BigBlueButton and Subsection. A SCORM that is
  only a link to a package elsewhere is refused with a message. An activity
  type added to Moodle by a plugin is not offered; check for any before the
  pilot
- **Before the pilot, set up on the destination any external tools the pilot
  courses use**, with the same addresses. An External tool activity is only
  ever linked to a tool this site's administrator has set up; without one it is
  refused, naming the tool, and comes across on the next sync once it is added
- **BigBlueButton must be enabled on the destination** (it is off in a new
  Moodle). Copied rooms get their own meeting and passwords; recordings stay on
  the source
- Forum, Wiki and Assignment **settings** come across; discussions, wiki pages
  and student submissions do not
- A copied **Quiz brings its questions with it** — fixed slots and random
  (draw-from-category) slots both come across, landing in this course's
  question bank alongside anything already there. A question used by more
  than one quiz, or also synced via a separate Question bank activity, is
  only ever added once
- Both **Quiz** and **Question bank** carry every standard Moodle question
  type, calculated ones included, and only each question's current version.
  A question of a type added by a plugin (a third-party question type) is
  named in the results, not silently dropped. If the pilot site uses any,
  check them before the pilot. Tell pilot teachers this before they open a copied quiz, not
  after
- A copied **Database has no custom CSS or JavaScript**, and a copied **Lesson
  has no password**. Both are deliberate and both are reported on the results
  page; see `SECURITY.md`
- Images embedded in text come across - descriptions, pages, text and media
  areas, book chapters, lesson pages, feedback labels, workshop instructions and
  criteria. Any link whose file could not come is named on the results page
- Nothing is overwritten unless a teacher ticks it. A copy changed on the
  source is offered for updating, unticked; it is replaced only if nobody has
  anything in it, and otherwise added beside the old one as a "(New edition)".
  Tell pilot teachers a replaced copy loses any edits they made to it
- Nothing happens automatically — a sync only runs when someone asks for it

## Known limitations to set expectations on

| Limitation | Effect on a pilot |
| --- | --- |
| Updates are a fresh copy, never an in-place edit | A copy with people's work in it gets a "(New edition)" beside it rather than an update |
| Quiz question edits are not picked up | Editing a question on the source does not mark the quiz as changed, and a replaced quiz reuses the questions already here |
| Deletions are not detected | An activity removed from the source simply stops being offered |
| Hidden activities are reported | Teachers see the names of hidden activities on the source |
| Third-party activity types with no `timemodified` | Edits to them are not detected, only their creation |

## After the pilot

- [ ] Read the sync history with the teacher — it is the record of what happened
- [ ] Note which activity types were skipped most; that is the next priority list
- [ ] Ask quiz users whether an empty quiz with correct settings is useful to
      them, or whether question banks need solving first
- [ ] Revoke the token if the pilot is paused
      (*Server → Web services → Manage tokens* on the source)
