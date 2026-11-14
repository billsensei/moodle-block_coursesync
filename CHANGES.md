# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## v1.36.1-beta (build 2026111403, 2026-11-14)

### Changed

- **The privacy declaration says what leaves the site when every attempt is shared.** The notes on what another site can
  read (Site administration > Users > Privacy and policies > Plugin privacy registry) said "latest submitted work" and
  "the student's mark". When an administrator has ticked "Let other sites read every attempt of assignment submissions
  and marks from this site", every attempt each student handed in, with its mark, can be read. Both notes now say so.
  Only the wording changed: nothing is sent or stored differently.

### Unchanged

- No code, schema, setting or permission changed. Two strings were reworded.

### Upgrade

- Nothing to do. If you keep your own copy of the privacy registry text, update it to match.

### Tested

- PHPUnit 792 tests, 4002 assertions, 1 skipped, identical on Moodle 5.0, 5.1, 5.2 and 5.3. That includes the privacy tests
  that check every declared string exists and that the export matches what is declared. phplint, phpcs with no warnings,
  phpdoc, validate, savepoints and `grunt --max-lint-warnings 0` clean.
- Behat was not re-run: only two privacy strings were reworded, and no Behat scenario reads them.
