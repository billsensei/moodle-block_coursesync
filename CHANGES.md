# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026101501, v1.24.0-beta)

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
