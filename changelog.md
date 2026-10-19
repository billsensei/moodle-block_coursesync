# Changelog

All notable changes to Course Sync (`block_coursesync`). Newest first, and every
version stays here. [CHANGES.md](CHANGES.md) holds only the current version.
Dates are the commit dates in git. The plugin is **beta**.

Builds before v1.3.0 predate the git history. v1.14.0 was a real build but was
never committed on its own: its changes are in the v1.15.0 commit, so git's
version file goes from v1.13.0 straight to v1.15.0.

## Unreleased (build 2026101901, v1.25.0-beta)

### Added

- **A question edited on the source is carried across.** Until now a question was copied once and then
  reused by its idnumber for ever, so editing it on the source never reached the destination, and editing
  a question did not even mark its quiz or Question bank as changed.
  - The source now counts a quiz as changed when a question it uses changed (the questions in its fixed
    slots, and everything in the categories its random slots draw from), and a Question bank as changed
    when a question in it did. Both then show as "Changed since it was copied" as any other change does.
  - On the destination, a question that was edited gets a **new version on the same bank entry**, so every
    quiz that uses it follows, and nothing is duplicated. The results page says how many were updated.
  - A question somebody edited **here** is not overwritten: if the newest version is not the one Course
    Sync wrote, it is left as it is and the results page counts it.
  - New table `block_coursesync_question` remembers, per bank entry, the version Course Sync wrote and a
    fingerprint of what the source sent. It holds nothing about a person. A question copied before this
    version has no row: it is updated if the source changed it after the copy and nobody edited it here,
    and otherwise its current state becomes the baseline. A source on an older version sends no change
    time for a question, so its questions only get a baseline, never an update.
  - A quiz kept as the old edition (because people have attempted it) points at the same bank entries, so
    it too uses the newest version of an edited question for new attempts.

### Fixed

- **Replacing a Question bank failed on Moodle 5.2 and 5.3.** The new bank was moved into the old one's place, and
  5.2+ refuse to move a module that is never shown on the course page (a Question bank sits hidden in section 0):
  "Modules with FEATURE_CAN_DISPLAY set to false can not be moved from section 0". The copy was rolled back and the
  results page said the update failed. Such a module is no longer moved. Found by the live check of question edits.

### Tests

- A Question bank can take its predecessor's setup (fails on 5.2 and 5.3 without the fix).
- A source edit makes a new version on the same entry; an unchanged question adds none; a question edited
  here is kept and counted; a question from before tracking is updated only if untouched here; a question
  edit makes its quiz and its bank count as changed. The version tests fail without the change (checked).

## v1.24.5-beta (build 2026101506), v1.24.5-beta)

### Changed

- **Supports Moodle 5.0 to 5.3** (`$plugin->requires` 2025041400, `$plugin->supported` 500-503;
  it was 5.1 only). Found by running the whole PHPUnit suite on 5.0, 5.2 and 5.3 sites, then
  pulling a course with a page, forum, assignment, label and a quiz with a random slot between
  every pair of 5.0, 5.1, 5.2 and 5.3:
  - 5.2 removed `ASSIGN_ATTEMPT_REOPEN_METHOD_NONE`, so every assignment sync failed. A source's
    "none" now becomes "manual" on a 5.2+ site.
  - 5.2 no longer gives a random quiz slot the question type "random" (it is null, with a
    `random` flag), so Pull Attempts treated every quiz with a random question as changed and
    skipped all its attempts. Both ends now say "random" for such a slot.
  - 5.2 deprecated `course_delete_module()` and `moveto_module()`. They now go through
    `local\course_modules`, which uses the course format actions where they exist.
- The CI workflow runs MOODLE_500 to MOODLE_503, each with its own PHP and database minimums.
- The Behat quiz scenario adds its random question with a new step, `quiz "X" has a random question from
  category "Y" on page N`. 5.2 removed the "random" type from core's questions generator, so the old setup row
  failed there. Tests only; no plugin code changed. Behat passes on 5.1, 5.2 and 5.3 locally and on 5.0 to 5.3 in CI.

### Fixed

- **Privacy: the grade `itemnumber` is declared and exported, and a deleted user's runs go with them.**
  `block_coursesync_grade.itemnumber` was the one column of the pull tables the provider did not
  declare or export (it only says which grade of an activity a row is). The `user_deleted` observer
  forgot a user's pulled grades, attempts, submissions and marks but kept the sync runs they started,
  leaving a user id that pointed at nobody; it now removes those too. Another user's runs stay.
- **The private-address check refuses `::ffff:0.0.0.0` and the SIIT form `::ffff:0:a.b.c.d`.** Every other
  IPv4-mapped address (`::ffff:127.0.0.1`, `::ffff:169.254.169.254`, ...) was already refused, because
  Moodle's `address_in_subnet()` unwraps it, but the mapped all-zeros address and the SIIT twin got through.
  `remote_url` now judges any mapped address by the IPv4 address inside it and blocks `::ffff:0:0:0/96`.
  Only an address an administrator enters is checked, and the private-address override is still off by default.

### Added

- **Pull Grades says when the other site is too old to send manual grade items.** A source
  from before v1.24.0 ignores the request for manual items and answers with activity grades
  only, which looked exactly like a course with none. When no manual item comes back, the
  pull now asks the other site's ping for its Course Sync version, and if it is older than
  v1.24.0 (or reports none) the results page says so and tells the teacher to upgrade it
  there. A source that did send manual items is never asked, and a ping that fails adds no
  note. This costs one extra small request only on pulls that returned no manual items.

### Tests

- A source reporting an older version, and one reporting no version, are noted; a current
  source with no manual items, one that sent manual items, and a ping that fails are not.
  The first two fail without the check.

## v1.24.4-beta (build 2026101505)

### Changed

- **The Pull Grades page heading** (and its page title and breadcrumb) now uses title case,
  to match the button that leads to it.
- **The copyright holder in every file header** is now Bill White, in place of the
  placeholder "Course Sync project".

## v1.24.3-beta (build 2026101504)

### Changed

- **The block's buttons are in a new order**, following the way a teacher works: Check Now,
  See Changes, Pull Submissions, Pull Marks and Feedback, Pull Grades, View sync history,
  Manage the Connections. Their labels now use title case. The Pull submissions, marks and
  grades buttons still appear only where that pull is switched on and allowed.

### Privacy

- **The sync history's privacy declaration and export now cover every column.** Six columns
  of `block_coursesync_run` were stored against the person who started a run but were
  neither declared nor exported: the skipped and failed counts, the list of skipped and
  failed activities, the error, the point in time the run asked about, and whether it moved
  the last-synced marker. All six are declared (with strings) and exported.

### Tests

- The privacy test that compares the export with the declaration expects the six new fields.
- The Behat features look for the new button labels.

## v1.24.2-beta (build 2026101503)

### Security

- **Markdown from the other site is converted to cleaned HTML on the way in.** Moodle's
  `clean_text()` leaves Markdown that has no tags in it untouched, so a link such as
  `[click](javascript:alert(1))` stayed live, and question text and a question
  category's description are shown by core without cleaning. Every text that arrives
  with a format - activity descriptions, question text, feedback and answers, book
  chapters, lesson pages and answers, quiz overall feedback, page content, assignment
  and workshop and feedback text, assignment mark comments and online text - is now
  turned into HTML if it is Markdown, cleaned, and stored with the HTML format. Other
  formats are cleaned as before.

- **A request to a site name that cannot be looked up is refused.** Just before each
  request the address is checked and the connection pinned to what was checked. A
  name that gave no address skipped both, and the connection then looked the name up
  again unchecked, so a name that failed once and answered `127.0.0.1` the next time
  would get through. It is now refused with the "address could not be looked up"
  message. Saving an address still does not need DNS. Sites given as an IP address
  are unaffected.

### Changed

- Text the other site sent as Markdown is stored here as HTML. It reads the same, but
  it is edited in the HTML editor from then on.

### Tests

- A name that does not resolve is refused at request time and one that does is
  pinned (the first fails without the change).
- Markdown with `javascript:` links in a description, a setting, a child record and a
  question (four places in all); other formats keep their format; each fails without
  the conversion.

## v1.24.1-beta (build 2026101502)

### Security

- **A manual grade item with a grade range this site cannot hold is skipped.** The
  other site's range and name for a manual item went straight into a new gradebook
  item. A range that is not a finite number, is larger than the gradebook's columns
  hold, or has its top at or below its bottom made the insert fail partway through a
  pull, leaving an error page and the grades before it already written. Such an item
  is now skipped with the usual "grade range cannot be converted" reason, and a name
  longer than 255 characters is cut.

- **A question category's name and description are cleaned when they arrive.** They
  were saved as the other site sent them, and core shows a category's description
  without cleaning it, so script from a modified source could run in a teacher's
  browser. The name is cleaned as text and cut to 255 characters. The description is
  cleaned for its format, and a Markdown one is converted to HTML first, because
  Moodle's own cleaning leaves a Markdown link such as `[x](javascript:...)` alone.
  An unknown format becomes HTML.

### Tests

- A category planted with script (HTML and Markdown) and an unknown format.
- Skipped for each kind of unusable range (too large, too small, overflowing,
  top below bottom, empty) and a long name cut; each fails without the fix.

## v1.24.0-beta (build 2026101501)

### Added

- **Grade sync now pulls manual grade items.** Items added by hand in the source
  course's gradebook (no activity behind them) were never pulled, because grade sync
  only looked at activities Course Sync had copied. They now come across with their
  grades and feedback. A manual item that is not here yet is created in the course's
  gradebook the first time a grade is written to it, named as on the other site and
  with the same range or scale; the preview creates nothing. It is found again by
  its idnumber (`coursesync-manual-<id on the other site>`), so renaming it here does
  not break the pairing. The same rules as any pulled grade apply: a different grade
  given here is kept and flagged, and a locked grade or item is skipped.
- Pulling grades now asks the other site even when nothing in the course was copied,
  since manual items need no copy.

### Notes

- A manual item on a scale this site does not have is skipped with the usual
  "different scale" reason; no scale is made up.
- A manual item already in the gradebook here is not paired by name with one on the
  other site; only items this plugin created are.
- The other site must run this version to send manual items. An older one ignores
  the request and sends activity grades only.

### Tests

- Source side: manual items sent only when asked for. Pull side: create and grade,
  preview creates nothing, found again and updated, a changed grade kept as a
  conflict, two items counted separately, unknown scale skipped, and a real source
  course end to end.

## v1.23.6-beta (build 2026101008)

### Security

- **The other site's reason for skipping an assignment is checked against a list.**
  The reason became part of a language string name, so a made-up or newer one showed
  as a broken "[[subreason...]]" with a debugging notice. A reason this version does
  not know is now shown as "unknown" with a note to update Course Sync on both sites.
- **A file transfer stops once it is longer than the other site said the file was.**
  Only the sizes the other site claimed were checked, so a site that kept sending
  could fill the temporary disk before the checksum was tried. The partial file is
  removed on any failed transfer.
- **A site no longer serves a file left behind in emptied online text.** A file in an
  assignment's online-text area is served only while the submission's text is not
  empty, which is exactly what the submission is described as holding.

### Changed

- The privacy descriptions now say assignment marks are shared and that their
  usernames are sent, as submissions already were.

### Tests

- A unit test for `question_cleaner` (every place text can sit, each format, files
  and unformatted strings), and tests for each change above.

## Unreleased (build 2026101007, v1.23.5-beta)

### Security

- **A permission taken away on one assignment is now respected by submission and
  marks pulls.** A pull checked the right to grade and to edit submissions for
  the whole course only, so a prohibit or override set on a single assignment was
  ignored and work could still be written into it. Each assignment is now checked
  itself; one that fails is skipped with its own reason
  (`subreasonnomodulepermission`) and the other site is not asked about it. The
  rest of the pull carries on.

## Unreleased (build 2026101006, v1.23.4-beta)

### Fixed

- **A pulled submission that a teacher has since reopened is no longer replaced.**
  Reopening an assignment for another attempt makes the earlier submission "not the
  latest". If the source then changed, the next pull treated the earlier copy as
  untouched, replaced it, and marked it the latest again, leaving it and the new
  attempt both marked latest, so assign could pick the wrong one (and a mark could
  land on it). A copy that is no longer the latest attempt is now reported as
  changed here (`subreasonchanged`) and left alone.

## Unreleased (build 2026101005, v1.23.3-beta)

### Security

- **Online text and feedback comments from the other site are now cleaned.** An
  assignment submission's online text and a mark's written comment were stored
  exactly as the source sent them, against the rule that everything from the
  other site is cleaned. Both are now cleaned with the rest of the answer, in
  their own format, before anything is written. mod_assign already cleans these
  when it shows them; this protects what reads the stored text directly
  (exports, plagiarism tools, downloads). Work already pulled is not changed.
  Deciding whether work already here is "the same" as the source's now compares
  the cleaned text, so cleaning does not turn identical work into a conflict.

## Unreleased (build 2026101004, v1.23.2-beta)

### Fixed

- **A source with no usable maximum could stop a marks pull with a fatal error.**
  A mark was scaled by dividing by the source assignment's maximum; a maximum of
  zero (or one that arrived as infinity, such as `1e999`) gave a division by zero
  that nothing caught, so the page died and no history row was written. A
  maximum that is not a finite number, or is negative, now makes the whole answer
  bad (`errorbadresponse`); a zero maximum skips that assignment with its own
  reason (`markreasonbadmax`). A mark that is not a finite number is refused the
  same way.
- **A mark outside what the assignment can hold is no longer written.** After
  scaling, a mark below zero or above the assignment's maximum here was written
  as it came (a negative grade is what assign reads as "not graded"). It is now
  skipped with `markreasonoutofrange`.

## Unreleased (build 2026101003, v1.23.1-beta)

### Security

- **Question text from the other site is now cleaned.** A question bank or quiz
  sync stored the text of each question exactly as the source's XML fragment
  held it, so script placed in question text, feedback, an answer or a hint on
  the source was stored on this site, against the rule that everything from
  the other site is cleaned. `question_cleaner` now cleans every formatted
  field (and the name) after the XML is parsed and before it is saved. Questions
  already copied are not changed.

## Unreleased (build 2026101002, v1.23.0-beta)

### Added

- **Choose which assignments a submissions or marks pull covers.** The preview
  now has a tick box beside each assignment (ticked where there is something to
  write), and **Pull the ticked assignments** pulls only those; the others wait
  for a later pull. Ticking nothing pulls nothing, and says so. The source is
  asked only about the ticked assignments. A selection can only narrow a pull:
  `submission_pull` and `marks_pull` take an optional list of source course
  module ids, and an id that is not one of the course's copies matches nothing.
  No schema change.

### Changed

- The buttons on the two preview pages read **Pull the ticked assignments**
  (they used to carry a count, which a selection would make wrong).

## Build 2026101001, v1.22.0-beta

### Added

- **Assignment marks and written feedback can be pulled.** Off by default on
  both sites, with its own switches (`allowmarksexport`, `allowmarkspull`) and
  permissions (`block/coursesync:exportmarks`, held by no role;
  `block/coursesync:pullmarks`, editing teachers and managers) - sharing grades
  or submissions does not share what a teacher wrote. A teacher previews, then
  pulls: for each student of a copied or linked assignment, the **mark for the
  attempt the submission pull carries** (their latest handed-in attempt), the
  teacher's written comment, and files embedded in the comment.
  - Written through mod_assign's own `update_grade()`, so the **grader screen
    and the gradebook agree** - this is what grade sync's override could not do.
    The person pulling is recorded as the grader; who graded on the other site
    is not carried.
  - **Marks sync takes over from grade sync for an assignment.** A gradebook
    override that grade sync wrote, and nobody has touched since, is removed
    once the real grade is in; an override a teacher changed stays. Grade sync
    then leaves a student alone once a marks pull has written their mark (the
    page says so).
  - A mark is written only where the student has none here, or where an earlier
    pull wrote it and nobody has touched it since; anything else is flagged and
    left as it is, a mark a teacher removed is not brought back, and a change
    made here is not flagged again while the source is unchanged.
  - Point grades only: a scale, an ungraded assignment, a team assignment, a
    locked gradebook grade, or a comment where this assignment's comments
    feedback is off are skipped with the reason. A mark is scaled if the two
    assignments are out of different totals. Under a marking workflow only a
    released mark is sent, and it lands released.
  - The person pulling needs `mod/assign:grade` in the course as well.
- New external functions `block_coursesync_get_marks` and
  `block_coursesync_get_mark_file` (a comment's file, for a mark that would be
  described, never a teacher's or an unreleased one).
- New table `block_coursesync_mark` (the upgrade step creates it); course reset
  of assignment submissions, user deletion and the privacy provider cover it.

### Changed

- `grade_pull::release()` and `untouched()` are public, so the marks pull can
  take away a grade sync override by the same rule.
- Sync history labels a marks pull **Marks**.

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
