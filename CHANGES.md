# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026101506, v1.24.5-beta)

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

### Fixed

- **Privacy: the grade `itemnumber` is declared and exported, and a deleted user's runs go with them.**
  `block_coursesync_grade.itemnumber` was the one column of the pull tables the provider did not
  declare or export (it only says which grade of an activity a row is). The `user_deleted` observer
  forgot a user's pulled grades, attempts, submissions and marks but kept the sync runs they started,
  leaving a user id that pointed at nobody; it now removes those too. Another user's runs stay.

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
