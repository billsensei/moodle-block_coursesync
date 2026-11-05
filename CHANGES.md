# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## v1.31.0-beta (build 2026110501, 2026-11-05)

### Added

- **The sections on the "Choose what to copy" page can be opened and closed.** Inside each group the activities are
  listed under the section they are in on the other course. A section's name is now a button: it folds that section's
  activities away or opens them again, and a count beside the name says how many activities it holds. This is for
  courses with many sections, where the page was a long scroll.
  - **What starts open.** A section starts **open** when it holds a new or changed activity, or one that carries the
    identity of a synced activity Course Sync did not put there. The rest start **closed** - but only when there is
    something new or changed to look at. With nothing new anywhere, every section starts open, so the page still shows
    everything it did before.
  - **Closing a section does not untick anything.** The activities of a closed section are hidden, not removed, so
    what is ticked in them is still copied.
  - **Select this section** next to a section's name ticks the new and changed activities in that section only (never
    what is already here, as with Select all). **Expand all** and **Collapse all** sit beside Select all and Select
    none; Expand all also opens the four groups.
  - Without JavaScript every section stays open.

### Unchanged

- Nothing is copied differently: the same activities are offered, in the same order and the same state, and the form
  sends the same fields. Only how the lists are shown changes.
- No schema change, no new setting and no new permission. The upgrade only refreshes the plugin's caches, for the
  three new strings and the new `styles.css`.

### Upgrade

- Tested on Moodle 5.0, 5.1, 5.2 and 5.3: Behat 62 scenarios (the 5 new ones among them) and 1537 steps on each,
  phplint, phpcs with no warnings, phpdoc, validate, savepoints, the JavaScript build and the Gherkin lint. PHPUnit was
  not re-run: nothing on the server side changed except the page's markup, and the 764 tests of v1.30.0-beta do not
  touch it.
- **Not tried:** a look at the arrow and spacing in a real browser, and a course with a very long list of sections.
