# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026102002, v1.26.1-beta)

### Fixed

- **The v1.26.0 workflow stopped at the Grunt step.** The Gherkin lint refused `tests/behat/quiz_sync.feature`
  for a blank line left at the end of the file ("Multiple empty lines are not allowed"), so no job reached
  PHPUnit or Behat. Tests only; no plugin code changed since v1.26.0.

