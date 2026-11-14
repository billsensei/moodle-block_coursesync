# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## v1.36.0-beta (build 2026111402, 2026-11-14)

### Added

- **Choose which files of a submission to pull.** On **Pull Submissions**, each uploaded file now has its own tick box
  under **Files**, ticked to begin with. Untick the files you do not need and only the ticked ones come across, with the
  student's online text, if any. The images inside online text are not listed: they always come with the text.
- **Files left out are offered again.** After a pull that left files out, the next preview shows that work as
  **Updated**, with the note "An earlier pull left some of these files out". The files already here are marked
  "already here" and have no box; tick the ones you now want and they are added without touching the rest. It stays on
  offer each time, so leave its files unticked if you meant to leave them out.
- If every file of work with no online text is unticked, nothing is pulled and it is offered again as it was. With
  **Include every attempt**, that holds back the same student's later attempts, as an unticked attempt does.

### Fixed

- **Ticking one student's box could also tick another's.** Core's tick-all script matches groups by the start of their
  name, so student 1's box also reached students 10 to 19 on a page with ten or more students. The groups now end in a
  dash. This is on Pull Submissions, Pull Marks and Pull Grades.

### Unchanged

- Nothing is overwritten, flagged or skipped differently. A file over your course's upload limit still stops the whole
  piece of work (it is not offered file by file). No schema change, no new setting or permission. Two strings are new
  (`subreasonmorefiles`, `submissionsfilehere`) and the two tick-box introductions say more.

### Upgrade

- Nothing to do. An earlier pull is not touched. Work that was pulled complete shows no new offer.

### Tested

- PHPUnit 792 tests, 4002 assertions, 1 skipped, identical on Moodle 5.0, 5.1, 5.2 and 5.3 (780 + 12 new, in
  `submission_files_pull_test`). Each of five deliberate breaks (not keeping files already here, no "more files" offer,
  ignoring the file ticks, counting every file against the run limit, no "nothing left" rule) makes at least one of the
  new tests fail.
- Behat: the whole suite, 69 scenarios and 1775 steps (67 + 2 new, in `assignment_submission_files.feature`), passes on
  5.0, 5.1, 5.2 and 5.3. phplint, phpcs with no warnings, phpdoc, validate, savepoints and `grunt --max-lint-warnings 0`
  clean. phpmd reports older findings in test classes and the upgrade test.
- **Not tried:** a pull between two real sites; a look in a real browser; a page with ten or more students (the
  tick-all fix follows from how core's script matches group names, and is not run with that many).
