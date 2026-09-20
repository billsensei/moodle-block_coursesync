# Course Sync: a guide for teachers

> Part of the Course Sync documentation set:
> [INSTALL.md](INSTALL.md) · [REMOTE_SETUP.md](REMOTE_SETUP.md) · **TEACHER_GUIDE.md** · [DEVELOPER.md](DEVELOPER.md) · [SECURITY.md](SECURITY.md)


Course Sync brings activities from a course on **another Moodle site** into your
course here. You choose when it runs; nothing happens on its own.

It is careful by design. It will never change or delete anything already in your
course. If it finds something in the way, it stops and tells you rather than
guessing.

## Before you start

You need three things from whoever administers the **other** site:

1. **Its web address** — starting `https://`, for example
   `https://moodle.partner.edu`
2. **A security token** — a line of 32 letters and numbers. Treat it like a
   password.
3. **Which course** to pull from — its short name, like `HIST101`, or the number
   from its web address.

If you do not have these, your site administrator can arrange them. There is an
installation guide written for them.

## Adding the block

1. Open your course.
2. Turn on **Edit mode** (top right).
3. **Add a block** → **Course Sync**.

The block appears saying *"Course Sync — not yet configured"*. That is expected.

## Setting up the connection

Choose **Set up the connection** in the block. The wizard has four steps and
shows you where you are.

### Step 1 — the other site

Enter the web address of the site you are pulling from. The front page address
only, with nothing after it.

It must start with `https://`. This is not fussiness: the token you are about to
use would otherwise travel across the network unprotected.

### Step 2 — the token

This step lists what has to be done on the **other** site — switching on its web
services and creating a token. You probably will not do this yourself; it is
there so you can send it to whoever will, or check it has been done.

When you have the token, paste it into the box and choose **Save and test**.

The token is encrypted before it is stored, and it is never shown again. Only the
last four characters are kept so you can tell which token is saved.

### Step 3 — the test

Course Sync contacts the other site and reports what happened.

Success looks like: *"Connected to Partner College, running Moodle 5.1."*

If not, the message says what to do. The common ones:

| What it says | What to do |
| --- | --- |
| The other site could not be reached | Check the address. It may be offline |
| Nothing that looks like a Moodle site answered | The address probably has something extra on the end |
| The security token was not accepted | Ask for a fresh token |
| The Course Sync service is not switched on | The other site's administrator has a step left to do |
| The account is not allowed to use Course Sync | Same — a permission is missing there |

### Step 4 — the course

Enter the short name or number of the course on the other site.

It is checked immediately, so a typo is caught straight away. When it works you
see *"Mapped to History 101 (HIST101) on the other site."*

That is setup finished. The block now shows what it is connected to.

## Running a sync

Choose **Sync now** in the block. You are asked to confirm, and told which kinds
of activity will be copied.

Course Sync currently copies seventeen kinds:

| Type | What comes across | What stays behind |
| --- | --- | --- |
| **Page** | Text and content | — |
| **URL** | Web links | — |
| **Label** | Text shown on the course page | — |
| **File** | Including the uploaded file itself | — |
| **Folder** | All the files in it, sub-folders included | — |
| **Book** | Every chapter, in order, with its text and pictures | — |
| **Forum** | The forum and its settings | Existing discussions and posts |
| **Wiki** | The wiki and its settings | The pages people wrote in it |
| **Assignment** | The task, dates, grading and marking settings, and which submission and feedback types are switched on | Student submissions, grades, feedback and extensions |
| **Quiz** | All the settings: timing, attempts, review rules, grading | **The questions.** You add those on this site |
| **Choice** | The question and every option, including any limits | The answers people gave |
| **Glossary** | All the settings | The entries people wrote |
| **Feedback** | Every question, in order, including questions that only appear depending on an earlier answer | The responses people gave |
| **Database** | Every field and the layout templates | The entries people added, and any custom CSS or JavaScript |
| **Workshop** | The settings and the whole assessment form reviewers fill in | Submissions, assessments and grades |
| **Lesson** | Every page, and every path between them, including where each answer leads | What students did in it, and the password |
| **H5P** | The interactive content itself and all its settings | Attempts and results |

Most of these copy the activity as a teacher set it up, not what anyone did in
it. That is on purpose: work students did belongs where they did it. Each copy
says on the results page what was left behind, so you are never left to find out
by opening it.

**A copied quiz has no questions in it.** Quiz questions live in a question bank,
which belongs to the site it is on, so there is no safe way to carry them to a
different site. The quiz arrives with every setting right and empty; add the
questions here.

Anything else in the other course is listed as skipped, so you know it was seen
and left.

### What you get back

A table of everything considered:

| Outcome | Means |
| --- | --- |
| **Copied** | Created in your course |
| **Flagged** | Something was in the way — see below |
| **Skipped** | Not a type Course Sync can copy yet |
| **Failed** | Something went wrong; it will be tried again next time |

### Seeing what has changed first

**See what has changed** lists what a sync would consider, without copying
anything. Useful for a look before committing.

## Flagged activities

A flagged activity is one Course Sync would have copied, but something in your
course already claims its place. **It never overwrites and never makes a second
copy.** It stops and tells you.

There are two kinds:

**"Already in this course"** — an activity here carries the identity this one
would use, and Course Sync did not put it there. Usually somebody made it by
hand. Look at what is already there and decide whether you still want the copy.

**"Changed on the other site"** — Course Sync copied this here earlier, and it
has since been edited on the other site. Your copy is untouched. Compare the two
and make the change yourself if you want it.

### After you have dealt with one

Once you have sorted it out — deleted the activity that was in the way, or
decided your version stands — an ordinary sync will not offer it again, because
it has moved past that point in time.

To bring it back, use **Check everything again** on the sync page. That looks at
the whole of the other course rather than only recent changes. Everything already
in your course is flagged rather than copied, so it is safe to run.

## Sync history

**View sync history** lists every sync, newest first, with a coloured label:

- **Completed** — everything it set out to do
- **Needs review** — something was flagged or failed
- **Failed** — it could not run at all

Open any run to see who started it, what it looked at, and the full list of what
was copied and flagged. Runs that did nothing are recorded too, so you can always
tell whether a sync was tried.

## Things worth knowing

**Images and files inside a page are not copied yet.** A page whose text contains
an embedded picture arrives with that picture missing, and the sync says so.
Files attached to a **File** or **Folder** activity *are* copied, and so are
pictures inside a **Book** chapter.

**Forum discussions, wiki pages and assignment submissions are not copied.** Those
activities arrive set up but empty. Anything people wrote or handed in stays on
the other site.

**A copied quiz has no questions.** See the table above. This is the one that
surprises people, so the sync says it against every quiz it copies.

**A copied database has no custom CSS or JavaScript.** If the original used
either, the copy is made without them and says so. Those two are run as code by
whichever site holds them, and Course Sync does not take code from another site
even when both sites are yours. Paste them across by hand if you wrote them.

**A copied H5P activity brings its content with it.** The interactive content is
a package file, and it is copied whole, so the copy works straight away without
anyone installing anything. If the other site's activity has somehow lost its
package, nothing is created and the sync says so rather than making an activity
that cannot be opened.

**A copied lesson is not password protected,** even if the original was. The
password itself is not copied, and leaving the setting on without it would let
anyone straight in, so the setting comes off too. Set a new one here if you need
it. The sync says so when this happens.

**A marking scale that does not exist here is dropped.** An assignment or forum
graded on a scale this site does not have arrives ungraded rather than pointed at
whatever scale happens to sit at that number. The sync says when it does this.

**Changing the other site's address clears the token and the course mapping.** A
token from one site means nothing on another, so Course Sync discards them rather
than leaving something that would fail confusingly later.

**Do not edit the ID number of a copied activity.** Course Sync uses it to
recognise what it has already brought across. Change it and the next sync will
make a second copy.

**Nothing is automatic.** Course Sync only runs when you choose **Sync now**.

## If you get stuck

The sync history shows exactly what happened and when, which is the first thing
to look at. Most connection problems are on the other site — a token that expired,
a permission not granted — and the message in the block usually names which.
