# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## v1.35.0-beta (build 2026111401, 2026-11-14)

### Changed

- **Marks removed here are offered again.** Before, a mark that an earlier pull had brought across and that someone then
  removed here (the grade and comment taken off) was skipped for good with "It is not brought back". Now the next preview
  lists it as **New**, with the note "An earlier pull brought this mark across and it has since been removed here, so it is
  offered again. Untick it to leave it out." It has a tick box like any other mark, so it is written only if ticked, and it
  is written into the same record, so nothing is counted twice.
- This applies to **Pull Marks and Feedback**. Pull Submissions already did the same since v1.34.0-beta.

### Unchanged

- A mark that is still here is judged as before: never overwritten, flagged when someone has changed it, skipped when the
  gradebook is locked. No schema change, no new setting or permission. One string was replaced by another
  (`markreasondeleted` by `markreasonreturned`).

### Upgrade

- Nothing to do. A teacher who removed a pulled mark on purpose will see it offered on the next preview and should leave it
  unticked; an unticked mark is not pulled and is offered again each time.

### Tested

- PHPUnit 780 tests, 3908 assertions, 1 skipped, identical on Moodle 5.0, 5.1, 5.2 and 5.3 (one test changed from
  "not brought back" to "offered again", and now also checks the preview and that the ledger row count stays the same).
  With the old rule put back, that test fails.
- Behat: the whole suite, 67 scenarios and 1707 steps, passes on 5.0, 5.1, 5.2 and 5.3. phplint, phpcs with no warnings,
  phpdoc, validate, savepoints and `grunt --max-lint-warnings 0` clean. phpmd reports 2 older findings in code this
  version did not touch.
- **Not tried:** a pull between two real sites, and a Behat scenario for a removed mark (Behat has no step to remove one;
  PHPUnit covers it).
