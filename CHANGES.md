# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026110101, v1.28.0-beta, 2026-11-01)

### Added

- **Copied activities go in the same section as on the other site.** The destination now asks the source for its
  sections (new function `block_coursesync_get_sections`) and pairs each with a section here. An activity lands
  in the section that stands for its source section; sections that are missing are made, up to the one needed
  and in source order, with the source's names, and hidden if the source's are. A source section with no pairing
  takes the section here with the same number if nothing has claimed it (so a course that already syncs keeps
  its sections), and is renamed only if it had no name. Sections someone named or arranged are never renamed,
  hidden or moved. The result says how many sections were added.
- The pairing is stored by section, not by number (new table `block_coursesync_section`), so it survives
  sections being moved or inserted on either site. Deleting a paired section here just pairs it again next time.
- `block_coursesync_get_activity` also returns `visibleold`: what the activity goes back to when its section is
  shown. An activity from a hidden source section now comes back as it was when the section here is shown.

### Fixed

- **The "Choose what to copy" tables now line up.** Every table on the page (the activity groups and *Question
  content*) uses the same fixed column widths, so Activity, Type, Modified and Status start in the same place from
  one table to the next instead of each table sizing its own columns. Layout only (`sync.php`).

### Unchanged

- A source that cannot list its sections - an older version, or a web service built by hand without the new
  function - changes nothing: activities go in by section number as before, and the result says so.
- Not in this version: section descriptions, the order of activities within a section, updating the names of
  sections that already exist, and grouping the Choose page by section.

### Upgrade

- Adds one table. On the source site the built-in "Course Sync" web service gains the new function when the plugin
  is upgraded; a service built by hand needs `block_coursesync_get_sections` added to it.
