# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026101502, v1.24.1-beta)

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
