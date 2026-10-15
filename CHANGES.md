# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026101008, v1.23.6-beta)

### Security

- **The other site's reason for skipping an assignment is checked against a list.**
  The reason became part of a language string name, so a made-up or newer one showed
  as a broken "[[subreason...]]" with a debugging notice. A reason this version does
  not know is now shown as "unknown" with a note to update Course Sync on both sites.
- **A file transfer stops once it is longer than the other site said the file was.**
  Only the sizes the other site claimed were checked, so a site that kept sending
  could fill the temporary disk before the checksum was tried. The partial file is
  removed on any failed transfer.
- **A site no longer serves a file left behind in emptied online text.** A file in an
  assignment's online-text area is served only while the submission's text is not
  empty, which is exactly what the submission is described as holding.

### Changed

- The privacy descriptions now say assignment marks are shared and that their
  usernames are sent, as submissions already were.

### Tests

- A unit test for `question_cleaner` (every place text can sit, each format, files
  and unformatted strings), and tests for each change above.

