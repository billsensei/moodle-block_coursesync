# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

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

