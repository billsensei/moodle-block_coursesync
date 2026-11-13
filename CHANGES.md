# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## v1.34.0-beta (build 2026111302, 2026-11-13)

### Changed

- **Submissions removed here are offered again.** Before, work that an earlier pull had brought across and that someone
  then removed here (a teacher removing the submission, say) was skipped for good with "It is not brought back". Now the
  next preview lists it as **New**, with the note "An earlier pull brought this across and it has since been removed here,
  so it is offered again. Untick it to leave it out." It has a tick box like any other line, so it is written only if
  ticked, and it is written into the same record, so nothing is counted twice. With "Include every attempt", a single
  removed attempt comes back on its own as the same attempt, without touching the others.
- This applies to **Pull Submissions** only. A mark removed here after it was pulled is still not brought back by
  **Pull Marks and Feedback**; say if you want the same for marks.

### Unchanged

- Work that is still here is judged as before: never overwritten, flagged when someone has changed it. If a student has
  since started new work in the removed one's place, that new work is the one in the way and is left alone. No schema
  change, no new setting or permission. One string was replaced by another (`subreasondeleted` by `subreasonreturned`).

### Upgrade

- Nothing to do. A teacher who removed pulled work on purpose will see it offered on the next preview and should leave it
  unticked; unticked work is not pulled and is offered again each time.
### Tested

- PHPUnit 780 tests, 3902 assertions, 1 skipped, identical on Moodle 5.0, 5.1, 5.2 and 5.3 (779 + 1 new; one test changed
  from "not brought back" to "offered again"). With the old rule put back, both the changed test and the new one fail.
- Behat: the three assignment features (15 scenarios, 519 steps) pass on 5.0, 5.1, 5.2 and 5.3. The rest of the suite was
  not re-run: nothing else reads the changed rule. phplint, phpcs with no warnings, phpdoc, validate, savepoints and
  `grunt --max-lint-warnings 0` clean.
- **Not tried:** a pull between two real sites, and a Behat scenario for a removed submission (Behat has no step to remove
  one; it is covered by PHPUnit).
