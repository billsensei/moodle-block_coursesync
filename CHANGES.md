# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026102003, v1.27.0-beta, 2026-10-20)

### Changed

- **"Choose what to copy" is now two sections, still one form and one button.** *Activities* is the list as
  before. *Question content* is new: each Question bank, and, for each quiz, a row "Questions in <quiz>" that
  copies the quiz's questions without the quiz (this replaces the "Questions only" column of v1.26.0).
  A Question bank no longer appears among the activities; a quiz is in both lists, ticked in the first it is
  copied whole, ticked in the second only its questions are. Question banks are ticked, selected and updated
  exactly as before. Nothing changes in the run, the lock, the last-synced marker or the history.
  Behat's field for a quiz's questions is now "Questions in <quiz>".

