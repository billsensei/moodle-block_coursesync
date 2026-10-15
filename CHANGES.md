# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026101503, v1.24.2-beta)

### Security

- **Markdown from the other site is converted to cleaned HTML on the way in.** Moodle's
  `clean_text()` leaves Markdown that has no tags in it untouched, so a link such as
  `[click](javascript:alert(1))` stayed live, and question text and a question
  category's description are shown by core without cleaning. Every text that arrives
  with a format - activity descriptions, question text, feedback and answers, book
  chapters, lesson pages and answers, quiz overall feedback, page content, assignment
  and workshop and feedback text, assignment mark comments and online text - is now
  turned into HTML if it is Markdown, cleaned, and stored with the HTML format. Other
  formats are cleaned as before.

- **A request to a site name that cannot be looked up is refused.** Just before each
  request the address is checked and the connection pinned to what was checked. A
  name that gave no address skipped both, and the connection then looked the name up
  again unchecked, so a name that failed once and answered `127.0.0.1` the next time
  would get through. It is now refused with the "address could not be looked up"
  message. Saving an address still does not need DNS. Sites given as an IP address
  are unaffected.

### Changed

- Text the other site sent as Markdown is stored here as HTML. It reads the same, but
  it is edited in the HTML editor from then on.

### Tests

- A name that does not resolve is refused at request time and one that does is
  pinned (the first fails without the change).
- Markdown with `javascript:` links in a description, a setting, a child record and a
  question (four places in all); other formats keep their format; each fails without
  the conversion.
