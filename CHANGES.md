# Changelog

All notable changes to Course Sync (`block_coursesync`). Newest first.
Dates are the commit dates in git. The plugin is **beta**.

Builds before v1.3.0 predate the git history. v1.14.0 was a real build but was
never committed on its own: its changes are in the v1.15.0 commit, so git's
version file goes from v1.13.0 straight to v1.15.0.

## Unreleased (build 2026100501, v1.21.0-beta)

### Added

- **Assignment submissions can be pulled.** Off by default on both sites, with
  its own switches (`allowsubmissionexport`, `allowsubmissionpull`) and its own
  permissions (`block/coursesync:exportsubmissions`, held by no role;
  `block/coursesync:pullsubmissions`, editing teachers and managers) - sharing
  grades does not share the work. A teacher previews, then pulls: for each
  student of a copied or linked assignment, the **latest attempt they handed
  in**, as online text and uploaded files (and files embedded in the text).
  - Work is written only where the student has nothing here, or where an
    earlier pull wrote it and nobody has touched it since. Anything else is
    flagged and left exactly as it is; work a teacher removed is not brought
    back; a change made here is not flagged again while the source is unchanged.
  - Drafts, team assignments and other submission plugins are not carried; the
    page says why. A pull stops at 512 MB of files and carries on next time.
  - The person pulling needs `mod/assign:grade` and
    `mod/assign:editothersubmission` in the course as well. **Core gives the
    second to no role**, so an administrator has to grant it before editing
    teachers see the button.
  - Written straight into the assignment's tables and file storage (as
    mod_assign's own restore does), so deadlines and locks do not apply to
    work handed in elsewhere, and nobody is notified. Handing in counts for
    activity completion.
  - Marks are a separate pull: the grader screen shows work as ungraded while
    the gradebook may show a grade.
- New external functions `block_coursesync_get_submissions` and
  `block_coursesync_get_submission_file`. The file function is separate from
  `get_activity_file` because its promise is about a student, not an activity.
- New table `block_coursesync_submission` (the upgrade step creates it); course
  reset of assignment submissions and deleting a user forget its rows.
- Privacy provider, history ("Submissions" kind, counts per assignment only),
  Behat feature `assignment_submission_pull.feature`, and 73 new PHPUnit tests.

- **The plugin forgets what it remembered when the data is gone.**
  - A course reset that removes quiz attempts forgets the course's imported-attempt
    records, and one that removes the gradebook's grades or items forgets its
    pulled-grade records.
  - Deleting a user forgets their pulled-grade and imported-attempt records.
  - The sync history is kept; it already shows "unknown user" for someone who no
    longer exists, and the privacy provider still erases it on request.
  - Unenrolling a student deliberately changes nothing: their grades are
    restored from grade history if they return, and their quiz attempts never
    left, so the records must stay (otherwise a returning student's attempts
    would be imported a second time).
- **GitHub Actions workflow** (`.github/workflows/ci.yml`): Moodle 5.1 on PHP 8.2
  and 8.4, against PostgreSQL 15 and MariaDB 10.11. Includes a step that allows
  the Behat site to sync from itself.
- Tests for all of the above, including the real reset, delete-user and
  unenrol-then-re-enrol paths.

### Changed

- Internal: the chunked download loop in `local\file_sync` is a public
  `download()` shared by activity files and submission files, and
  `remote_client` parses a file chunk in one place. No change in behaviour.
- Internal only, no change in behaviour: the seven longest methods in the plugin
  (sync run, grade pull, quiz-attempt pull, remote calls, quiz export and import)
  are split into smaller named steps. Behaviour is pinned by the existing tests,
  which were not changed.

### Fixed

- **A fresh install no longer prints an XMLDB warning.** `remoteurl` was declared
  NOT NULL with an empty-string default, which XMLDB rejects. A fresh install
  raised a debugging notice, and PHPUnit's initialisation treats that as fatal,
  so the CI install step would have failed. The database column is unchanged, so
  there is no upgrade step.
- **Quiz attempts removed by a course reset come back on the next pull.** The
  plugin remembered which attempts it had imported, and after a reset it read
  "no attempt here" as "somebody deleted it" and never brought them back. An
  attempt a teacher deletes by hand, without a reset, still stays deleted.
- **Deprecated Bootstrap 4 class** `sr-only` replaced by `visually-hidden` on the
  Sync and Grades pages (table captions), as Moodle 5.1 expects.
- The language file is sorted by key again.

### Privacy

- The privacy provider now declares the other direction of data flow: when this
  site is the destination, it sends the usernames of the students it wants grades
  and attempts for to the source site (`sourcesite` external location).
- What the provider declares and what a data export contains now match. The
  sync-run record also declares its two counts (`pulledcount`, `conflictcount`),
  which were already exported, and its export now includes `kind`; a pulled grade
  and an imported attempt now export the other site's activity and attempt ids and
  the feedback fingerprint, which were declared but left out. A test fails if the
  two drift apart again.

## v1.20.0-beta (2026-09-25)

### Added

- **Activities that are already in the course are recognised on the first sync**
  (restored from a backup, imported, or built by hand). An activity with the same
  type and the same name (capitals and extra spaces ignored) as one on the other
  site is listed as "Already here" and linked to the original when you sync,
  instead of being copied again. From then on it receives updates, grades and quiz
  attempts like any other synced activity.
  - An activity that already has its own ID number here is left exactly as it is.
  - When the name is not enough to tell (two activities called "Quiz"), nothing is
    guessed: it stays under "Ready to copy", unticked, with a note.

### Changed

- Sync page and history wording and layout (UX improvements).

## v1.19.1-beta (2026-09-25)

### Changed

- **Only the students that are needed are asked about.** The destination now sends
  the usernames of its own active students within the puller's reach, one per
  line, and the source returns nobody else's grades or attempts. With nobody in
  reach, the source is not called at all. A source older than this version refuses
  the unknown parameter, and the destination then asks as before, so both sites
  need upgrading for this to take effect.
- Separate groups limit a grade pull the way they limit the gradebook; students
  outside the puller's groups are not previewed, written, or mentioned.
- The sync account's `exportgrades` capability is documented as also covering
  grades hidden from students (they stay hidden on the destination).

### Fixed

- Bug fixes to grade and quiz-attempt pulls.

## v1.19.0-beta (2026-09-25)

### Added

- **Quiz attempts can be synced.** Students' finished quiz attempts come across as
  times and per-question marks (never answers) into the quiz's own reports. An
  attempt is never brought twice, one deleted here is not brought back, and one
  a teacher has regraded here is never overwritten. No messages are sent to
  anyone. The quiz's outline (slots, maximum marks, question types) must match the
  source's, or the quiz is refused as a whole.
- New external function `block_coursesync_get_quiz_attempts`.

## v1.18.0-beta (2026-09-25)

### Added

- **Grades can be synced.** Off by default on both sites. Students' gradebook
  grades and feedback for copied activities are matched by username and written
  as overridden grades, never replacing a grade a teacher gave. A grade pull is
  previewed first, flags conflicts, and is recorded in the sync history (counts
  per activity, never which students).
- New external function `block_coursesync_get_grades`, and capabilities
  `block/coursesync:exportgrades` and `block/coursesync:pullgrades`.

## v1.17.0-beta (2026-09-24)

### Security

The result of a security audit; all ten findings fixed.

- Stored XSS through the "files that could not be brought across" message.
- A manager-only setup permission (`block/coursesync:configure`), and source
  tokens scoped to a category.
- A sync is held to the syncing person's own permissions.
- Large files are no longer read or held whole.
- Only one sync per block at a time.
- Replacing a copy keeps the course's own set-up and never deletes group
  overrides.
- One activity's unforeseen error no longer ends the whole run.
- The block's own settings form follows the setup permission.
- Outgoing requests: more address ranges refused, no redirects followed, and the
  checked address is the one connected to.
- Informational findings and code tidy-up.

## v1.16.0-beta (2026-09-24)

### Added

- **Copying again.** Syncs can be repeated. The sync page now always lists every
  activity, not only changed ones (filtering by the last sync made "Check now"
  report "There is nothing in the other course to copy" once everything was
  copied), and activities already in the course can be ticked and copied again:
  replaced when nobody has data in the old copy, otherwise added beside it as
  "(copy)".

## v1.15.0-beta (2026-09-23)

### Added

- **Subsections** sync, with the activities inside them. Every standard Moodle
  activity type now has a handler: **all 23** sync. A subsection is never
  replaced on update (deleting one deletes its contents); it is renamed in place.

### Fixed

- Placing an activity into a section now ignores sections owned by another
  component (such as a subsection's), as an ordinary placement should.

## v1.14.0-beta (2026-09-23, committed together with v1.15.0)

### Added

- **LTI and BigBlueButton.** An LTI activity is linked only to a tool the
  destination site's administrator has already set up, and is refused (naming the
  tool) when there is none; a key and secret of its own are refused and secrets are
  never exported. A BigBlueButton room gets a fresh meeting ID and passwords;
  role-based participant rules are kept, per-user rules are dropped and counted.

## v1.13.0-beta (2026-09-23)

### Added

- SCORM and IMS content packages.

## v1.12.0-beta (2026-09-23)

### Added

- Quiz overall feedback.

## v1.11.0-beta (2026-09-23)

### Added

- Files embedded in text fields travel with the activity.

## v1.10.0-beta (2026-09-23)

### Added

- Calculated question types.

## v1.9.0-beta (2026-09-23)

### Added

- More question types: select missing words, ordering, random matching, and
  description.

## v1.8.0-beta (2026-09-23)

### Added

- **Updating changed activities.** An edited source activity can be offered as an
  update, either replacing the copy or adding a new edition beside it, never
  overwriting anyone's work.

## v1.7.0-beta (2026-09-23)

### Added

- Drag-and-drop question types.

## v1.6.1-beta, v1.6.0-beta, v1.5.0-beta (2026-09-22)

### Added

- **Question banks and quiz questions** sync, with their categories.
- A cleaned-up "what to sync" page.

## v1.4.1-beta, v1.4.0-beta, v1.3.0-beta (2026-09-20 to 2026-09-22)

The first builds in git: the cross-site connection and setup wizard, mapping a
course on the other site, change detection, pulling and rebuilding activities,
conflict flags and the sync history, security hardening, a long list of activity
types, H5P, and choosing what to sync with checkboxes. See `LEARNFROMME.md`,
phases 1-12, for the detail.
