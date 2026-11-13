# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## v1.33.0-beta (build 2026111301, 2026-11-13)

### Changed

- **Pull Submissions and Pull Marks and Feedback: choose which work to pull, one collapsible section per student.** The
  two pages now work as Pull Grades does. After a table of counts per assignment, each student has a section of their
  own - click the name to open or close it; it opens by itself when it has something to pull or something that was kept.
  Every line that would be written (**New** or **Updated**) has a tick box, ticked to begin with; the box at the top of a
  student's section ticks or unticks all of theirs and **Select all / none** does the whole page. This replaces the
  one box per assignment, so a single student's work in an assignment can now be left out.
- **What is left unticked is not pulled and is not remembered.** It is skipped with its own reason ("You left this
  unticked"), writes nothing and records nothing, so the next preview offers it again. It is also not fetched and does
  not use up the pull's 512 MB limit for file data, so it cannot push ticked work out.
- **Every attempt: a box for each attempt.** With "Include every attempt" on, each attempt has its own box. For
  submissions, an attempt that would be written but is left unticked holds back that student's later attempts of the
  same assignment, as a failed one does, so their history has no hole (an attempt that is already here has no box and
  holds nothing back). Marks leave no hole to guard against, so each attempt's mark is pulled on its own.
- **Ticking nothing still pulls nothing**, as before. A form that did not come from the preview (no tick information at
  all) also pulls nothing, rather than everything.
- The three tables per page (kept, skipped, to pull) are gone: every line now shows its own result, and the section
  header counts them. Button texts are now **Pull the ticked work** and **Pull the ticked marks**.

### Unchanged

- What is pulled, and what is never overwritten, is as in v1.32.1-beta. No schema change, no new setting or permission.
  Ten strings that only the old tables used were removed; seven were added.

### Upgrade

- Nothing to do. Pull Grades is as in v1.32.1-beta.

### Tested

- PHPUnit 779 tests, 3877 assertions, 1 skipped, identical on Moodle 5.0, 5.1, 5.2 and 5.3 (771 + 8 new). Seven of the
  eight fail when the not-chosen check is removed; the eighth asserts that nothing is held back and so passes either way.
- Behat, the whole suite: 67 scenarios, 1707 steps, all passed on 5.0, 5.1, 5.2 and 5.3. phplint, phpcs with no warnings,
  phpdoc, validate, savepoints and `grunt --max-lint-warnings 0` clean.
- **Not tried:** a pull between two real sites with this version, and a look at the pages in a real browser beyond the
  JavaScript scenario that switches the boxes.
