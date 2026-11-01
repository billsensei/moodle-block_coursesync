# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026110102, v1.29.0-beta, 2026-11-01)

### Added

- **Sections carry more across.** `block_coursesync_get_sections` now also returns each section's description, the
  activities in it in order, and the files its description uses; a new function `block_coursesync_get_section_file`
  serves those files in chunks (course context, `course`/`section` area only, the section must belong to the course
  asked about). A section made here gets the source's description and its files; one adopted from a same-number
  section gets them only if its description is empty. Descriptions are cleaned; a file that cannot be fetched is
  counted in the result and never fails the run.
- **Activities keep their order within a section.** A new activity is placed before the next one that follows it on
  the source and is already in that section here, whatever order they are copied in. Only the new activity is
  placed, so an arrangement made here is not undone.
- **The Choose page groups activities by section**, in the other course's order, under the section names. If the
  source cannot say, the list is flat as before.
- **Update existing sections (opt-in).** An unticked checkbox on the Choose page: pairs every source section (a
  missing one is made) and gives each the source's name and description. A source section with no name or
  description never blanks one here; visibility is never touched. It works even when no activity is ticked.

### Unchanged

- A source from before this version sends none of the new fields; nothing about descriptions or order is touched,
  and activities still go in the right sections.

### Upgrade

- No schema change. Upgrade both sites; on the source the built-in "Course Sync" web service gains
  `block_coursesync_get_section_file`, but a service built by hand needs it added (without it, descriptions arrive
  without their files).
