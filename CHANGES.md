# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## v1.32.1-beta (build 2026111002, 2026-11-10)

### Fixed

- **A student's name was not escaped in the new tick-box labels on the Pull Grades page (v1.32.0-beta).** For a student
  with no matching account here, the name shown is the username the other site sent, and the labels of the tick boxes
  (one per grade or attempt, and the select-all for a student) put it into the page as it came. A username with markup
  in it could have run script in a teacher's browser. The student's section title already escaped it; the labels now do
  too, from one escaped copy per section. **Upgrade from v1.32.0-beta** if you installed it.

### Unchanged

- Everything else is as in v1.32.0-beta (choosing which grades to pull, a section per student): see
  [changelog.md](changelog.md). No schema change, no new string, setting or permission.

### Upgrade

- Tested on Moodle 5.0, 5.1, 5.2 and 5.3: the whole Behat suite (65 scenarios, 1634 steps) on each with the v1.32.0-beta
  code; after the fix, phpcs with no warnings and the grade and quiz-attempt pull features (8 scenarios, 264 steps) on 5.1.
- **Not tried:** a test that feeds in a hostile username (the fix is checked by reading the code and by the existing
  tests still passing), and the fix on 5.0, 5.2 and 5.3 (plain PHP, no version-specific code).
