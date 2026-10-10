# Design draft: importing assignment submissions

Status: **built in v1.21.0-beta (2026-10-06)**, with every recommendation in
"Open questions" accepted. The text below is the design as drafted; where the
build differs, the next section says so, and `DEVELOPER.md` ("Assignment
submissions") describes what exists.

## Where the build differs from this draft

- **Online text is stored as the source holds it, not cleaned with
  `clean_text()`.** mod_assign stores what students type raw and cleans it
  where it is shown (`format_text()`); storing it the same way keeps the
  fingerprints comparable. The format is checked; the text is not rewritten.
- **A file description that cleaning would change is refused**, not mended:
  `/../../` would otherwise have become `/`.
- **The size limit is the site's and the course's `maxbytes`**, not
  `get_max_upload_file_size()`, which also folds in PHP's upload limits.
- **`mod/assign:editothersubmission` is required, and core gives it to no
  role**, so an administrator has to grant it before teachers see the button.
  The draft listed it without noticing that.
- **Sharing is checked in a fixed order** and a team assignment is described as
  `reason: team` rather than omitted.
- **No quiet event is logged**; completion is updated directly instead.
- The ledger has `remoteattempt` and two fingerprints, and the upgrade step is
  `2026100501`.

## Goal

When a course is synced, let a teacher on the destination site also bring in
students' **assignment submissions** (uploaded files and online text) for
assignments that were copied or linked by Course Sync, so the destination course
holds the work the grades refer to.

Scope of v1: `mod_assign`, `assignsubmission_file` and `assignsubmission_onlinetext`
only. No other activity type (forum posts, workshop, lesson, choice) is touched.

## Non-goals (v1)

- Team submissions (`teamsubmission = 1`): refused and reported, not guessed at.
- Draft submissions: only submitted work travels.
- Earlier attempts of a reopened assignment: the latest submitted attempt only.
- `assignsubmission_comments`, other third-party submission plugins, submission
  statement acceptance, extensions, marking allocations, user flags.
- Writing `assign_grades` or `assignfeedback_*` rows (see Open questions: grades).

## How it fits what exists

It mirrors grade sync and quiz-attempt sync rather than inventing a new shape:

| Existing piece | Reused as |
| --- | --- |
| `get_grades` external function (gated, username-scoped, batch-capped) | Template for `get_submissions` |
| `grade_pull::reach()` / `usernames_known_here()` | Which students are asked about |
| `get_activity_file` + `file_chunk` + SHA1 check | Chunked transfer of submitted files |
| `block_coursesync_attempt` ledger | Template for `block_coursesync_submission` |
| `attempt_pull` preview / write split | Preview first, then write |
| `grades.php` page and sync history | Where it is shown and recorded |

## Source side

### Switches and permissions

| Item | Default | Meaning |
| --- | --- | --- |
| Setting `allowsubmissionexport` | off | Site lets other sites read submissions |
| Capability `block/coursesync:exportsubmissions` | nobody (`RISK_PERSONAL`) | The sync account may read them; not granted by upgrade |

Both are checked, before anything else, exactly as in `get_grades`: a site that
does not share submissions gives every caller the same refusal and reveals
nothing about its courses. `block/coursesync:sync` is also required.

### `block_coursesync_get_submissions(courseid, cmids[], usernames)`

Read-only. Same limits as `get_grades` (500 cmids; usernames one per line; empty
means everyone, kept only for symmetry). Only students the gradebook would list.
Per assignment, per student, it returns the **latest attempt in status
`submitted`**:

- `attemptnumber`, `timecreated`, `timemodified`
- `onlinetext`: text, format (if that plugin has content)
- `files[]`: `fileid`, `filename`, `filepath`, `filesize`, `contenthash` (SHA1)
- `fingerprint`: SHA1 over text, format and the file hashes, so the destination can
  detect change without refetching anything
- per-assignment flags: `teamsubmission`, enabled submission plugin names

Assignments that are team-based or deleted are left out with a reason code rather
than failing the call (same tolerance as `get_grades` for deleted activities).

### `block_coursesync_get_submission_file(courseid, cmid, fileid, offset, length)`

A **new** function, not an extension of `get_activity_file`. That function's
authorisation is "the file is in an area a handler declared", which is
activity-level; submission files are per-student. The new function refuses unless
the file belongs to a `submitted` submission of that assignment in that course
(`assignsubmission_file` / `submission_files` area, itemid = submission id) and the
same two gates above pass. It cannot read anything else.

## Destination side

### Switches and permissions

| Item | Default | Meaning |
| --- | --- | --- |
| Setting `allowsubmissionpull` | off | This site lets its teachers pull submissions |
| Capability `block/coursesync:pullsubmissions` | editingteacher, manager | May run a pull |
| Also required | | `mod/assign:grade` and `mod/assign:editothersubmission` in the course |

### Ledger: `block_coursesync_submission`

One row per submission written, like `block_coursesync_attempt`:

`blockinstanceid, courseid, userid, assignid, submissionid, remotecmid,
remoteattemptnumber, fingerprint` (what the source said), `localfingerprint`
(what was written here, to tell later local edits), `remotetime, timeimported`.
Unique on `(assignid, userid)`.

### Per student, per assignment, the rule

| Situation here | Result |
| --- | --- |
| Local assignment has no matching enabled submission type | Skipped, reported once per assignment |
| Student has **no** submission row (or status `new`) | **Written** |
| Ledger row exists, local fingerprint unchanged, source fingerprint changed | **Updated** in place |
| Ledger row exists, local content differs from `localfingerprint` | **Flagged**, untouched (a teacher or the student changed it) |
| A submission exists that Course Sync did not write | **Flagged**, untouched. Never overwritten, never duplicated |
| Source fingerprint equals ledger | Unchanged, nothing written |
| Submission deleted here after import | Not brought back (as for quiz attempts) |

This keeps the project's standing rule: nobody's work is ever overwritten.

### How it is written

Directly into `assign_submission`, `assignsubmission_onlinetext` and
`assignsubmission_file`, with files through `file_storage`, the way `mod_assign`'s
own restore does (the same reasoning as the settings child records in
`assign_handler`). Going through the student-facing save API would enforce
deadlines, cut-off dates and submission locks, and would send events and
notifications. Specifically:

- `status = submitted`, original `timecreated`/`timemodified`, `latest = 1`,
  `attemptnumber` as on the source (v1: latest only, so usually 0 here).
- Each file is fetched in chunks and its SHA1 checked against the source's value
  before it is stored. A mismatch refuses that student's submission and removes
  any partial files, as activity files do today.
- No notifications. The assignment's `submission_created` / `submission_updated`
  events are **not** fired; one quiet Course Sync event per run is logged instead.
  (To be confirmed against mod_assign so completion and caches are still updated.)
- Student is matched by username to an **active student in reach** (the same
  `reach()` rule, including separate groups). Anyone else is never asked about.

### Preview and UI

`grades.php` gains a "Submissions" section beside grades and quiz attempts: a
preview table (written / would update / flagged / skipped, per assignment, per
student, with the reason), then a confirm button. The history records **counts per
assignment, never which students**, as grade pulls do.

## Security

- Remote data is untrusted. Online text is cleaned with `clean_text()` for its
  format before storage; filenames and paths with `clean_param(PARAM_FILE)` and a
  normalised path; the file size is capped by the course's `maxbytes` and a
  per-run total, and anything over is skipped with a reason.
- Chunked transfer keeps whole files out of memory at both ends.
- SSRF layers, HTTPS-only and token encryption are untouched: all calls go through
  `remote_client`.
- The new source function's file authorisation is "belongs to a submitted
  submission of a student in the requested set", not "is in a declared area".

## Privacy

- New table is declared in the privacy provider, plus the existing
  `sourcesite` external location now also covers submission data flowing in.
- Export: ledger rows (remote ids and fingerprints). Delete: ledger rows only. The
  submission itself belongs to `mod_assign`'s own provider and is deleted there.
- Course reset and gradebook-style cleanup forget ledger rows; deleting a user
  forgets theirs; unenrolling changes nothing (same as grades).
- The existing privacy-drift test is extended so declared and exported fields
  cannot diverge.

## Phasing

| Phase | Content |
| --- | --- |
| 1 | Settings, capabilities, `get_submissions`, `get_submission_file`, source-side tests |
| 2 | Ledger table + upgrade step, destination write path for onlinetext |
| 3 | File transfer into `assignsubmission_file`, SHA1 and size handling |
| 4 | Preview/confirm UI on `grades.php`, history counts |
| 5 | Privacy provider, reset/delete handling, docs, Behat |

Each phase is logged in `LEARNFROMME.md` before it is called done.

## Tests

- PHPUnit: source gates (setting off, capability missing), username scoping, team
  assignments refused, draft ignored, never-overwrite and flag cases, update-only-if-
  untouched, deleted-after-import not resurrected, SHA1 mismatch cleanup, privacy
  export/delete, reset.
- Behat: preview then confirm, flagged case visible, setting off hides the section.
- Each gate proved non-vacuous (break the code, see the test fail).
- Needs both Docker sites; the `assign` plugin config on both must match.

## Open questions (need a decision before building)

1. **Grades.** Grade sync writes gradebook overrides, not `assign_grades`. After a
   submission import the grader screen would show the work as ungraded while the
   gradebook shows a grade. Options: leave as is (v1, noted in UI), or also import
   `assign_grades` and the feedback comments. Recommendation: leave for v2.
2. **Attempts.** Latest only (recommended), or every attempt for reopened
   assignments?
3. **Drafts.** Excluded (recommended), or opt-in?
4. **Team assignments.** Refuse (recommended), or map groups by name?
5. **Own button or part of grade pull?** Recommendation: separate pull, same page,
   and it is available only when the assignment is already synced.
6. **Size ceiling.** Use course `maxbytes` plus a per-run total, or a new admin
   setting?
