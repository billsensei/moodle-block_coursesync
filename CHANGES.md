# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026110301, v1.29.1-beta, 2026-11-03)

### Changed

- **The Choose page folds.** "Ready to copy", "Changed since it was copied", "Already on this course" and "Question
  content" are each a collapsible block (a native `<details>`, no script) with the number of rows in its title, so
  a long list no longer has to be scrolled past to reach the next one. "Ready to copy" and "Changed" start open;
  "Already on this course" and "Question content" start closed, except when there is nothing new or changed to look
  at, or the already-here group carries the collision warning. Ticks inside a closed block still count: Select all,
  Select none and the form submit reach them as before.

### Unchanged

- No schema change, no new strings, no change to what is copied or how. Nothing needs doing on the source site.
