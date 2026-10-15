# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026101504, v1.24.3-beta)

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
