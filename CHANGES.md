# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026102001, v1.26.0-beta)

### Added

- **Copy a quiz's questions without the quiz.** Each quiz on the "Choose what to copy" page has a new
  "Questions only" checkbox. Ticking it copies the quiz's questions, and the categories they sit in, into this
  course's shared question bank, and creates no quiz. Nothing is left behind that a later run would take for the
  quiz, so the quiz stays on offer as new, and the run does not move the last-synced marker past it.
  - The questions are treated as they are with a whole quiz: reused by their ID number, and given a new version
    when the source has edited them since (v1.25.0).
  - Ticking the quiz itself as well copies the whole quiz and the questions-only box is ignored.
  - The results show a "Questions copied" row for the quiz, and the history lists it under the other results.
  - A Question bank activity could already be copied alone; this is for questions that sit in a quiz.
  - No schema change.

### Tests

- The questions arrive and no quiz is made; a second run after a source edit adds a version, and one with no
  edit adds none; ticking the quiz too copies the quiz; another activity type is untouched. The first two fail
  with the feature off (checked). A Behat scenario copies a quiz's questions alone.

