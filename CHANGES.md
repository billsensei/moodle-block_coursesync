# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## Unreleased (build 2026110401, v1.30.0-beta, 2026-11-04)

### Added

- **Pull every attempt of an assignment, not only the latest.** Until now a submissions pull and a marks pull brought
  across one attempt per student, the latest they handed in. With this version a teacher can tick **Include every
  attempt each student handed in, not only the latest** on the *Pull submissions* and *Pull marks and feedback* pages
  and press **Update the preview**. Nothing is written by that. It is **off** unless both sites switch it on (below).
  - **Each attempt arrives as the same attempt.** Attempt 2 on the other site is attempt 2 here, with a record of its
    own, and exactly one attempt is left marked as the latest. Nothing is reopened and no setting of the assignment
    is changed. Drafts, and attempts that were reopened but not handed in, never travel.
  - **Work already here is never replaced.** If a student already has different work for an attempt it is kept and
    listed, and the attempts *after* it wait until it is sorted out, so the two histories are not mixed. The attempts
    before it still come across. An attempt that cannot be brought (a file that does not arrive intact, a file over
    the course's limit, the 512 MB limit for one pull) holds back that student's later attempts, so there is no gap.
    The next pull carries on.
  - **A mark goes onto the same attempt, and only onto work it was given for.** If the student's submissions were
    pulled, a mark goes only onto an attempt recorded as that same attempt from the other site. It is never put on the
    student's own different work, and no attempt is made up to hold a mark. Only the **newest** attempt's mark changes
    the gradebook, the student's marking workflow state or a grade sync override; an earlier attempt's mark is history.
    A gradebook grade that is locked stops all of a student's attempts. Under a marking workflow on the other site only
    the newest mark is known to be released, so earlier ones are not pulled, and the page says so.
  - **The two ways of pulling do not mix for one student.** A pull of the latest alone writes the newest attempt over
    the first, so once a student's attempts or marks have been pulled it leaves that student alone and says why.
    Pulling every attempt again is always safe, and it puts a copy made the old way back to the attempt it really was,
    if nobody has touched it.
  - **The preview and the history.** A student's attempts are listed together, oldest first, with an **Attempt** column
    on the marks tables, and the counts are labelled as attempts. An assignment here that allows fewer attempts than
    the other site has gets a warning, not a stop: every attempt is still written. The history says "Submission
    attempts pulled" or "Mark attempts pulled", and still keeps counts only.
- **Two new settings**, both **off**: *Let other sites read every attempt of assignment submissions and marks from this
  site* (the source) and *Let teachers pull every attempt of assignment submissions into this site* (the destination).
  They are in addition to the submissions and marks switches and permissions, which still apply. There is **no new
  permission**.
- **The source tells the destination what it offers.** `block_coursesync_ping` returns a list of features, which
  contains `attempts1` while the source's switch is on. A destination asks for every attempt only of a source that
  says so, and otherwise refuses with a message, rather than pulling less than it was asked for or sending a
  parameter an older source would reject.
- **Web service functions.** `block_coursesync_get_submissions` and `block_coursesync_get_marks` take an optional
  `allattempts`, and `block_coursesync_get_submission_file` and `block_coursesync_get_mark_file` an optional
  `attemptnumber`. Without them every answer is exactly as before. The functions that describe an attempt and the
  ones that serve its files use the same rule for which attempts exist, so a file is served only for an attempt that
  was described: never a draft, never a student the gradebook does not list.

### Changed

- **Long pulls no longer time out or lock the session.** Confirming a sync, a grade pull, a submissions pull or a marks
  pull now raises the time and memory limits for that request, and releases the session lock, so the teacher's other
  tabs keep working during it. Previews are untouched.
- **Large courses are asked about in groups.** A submissions or marks pull of many students sends them to the source in
  groups of at most 400 student-and-assignment pairs per call (100 when every attempt is asked for), instead of naming
  every student in one request. The answers are put back together per assignment, so no student is dropped. The group
  size is an estimate from the number of queries, not a measurement on a large course.
- **Serving a file no longer rebuilds the student list for every piece.** `get_submission_file` and `get_mark_file`
  look up the one student named, with the same checks as before.

### Fixed

- **A failing pulled mark could add an attempt here.** Since v1.22.0, pulling a mark that fails the pass mark into an
  assignment set to reopen until the student passes made mod_assign add a new, empty attempt for the student - one the
  other site never described - and the pulled mark then looked missing. A marks pull now never reopens an assignment.
  It only affected assignments with that setting and a pass mark in the gradebook.
- Two LOW findings from a security review of the whole plugin (no critical, high or medium findings): the pull pages
  called `format_string()` without the course context for a checkbox label. Output was already escaped.

### Unchanged

- A source from before this version is pulled from as before, one attempt per student. Asking it for every attempt is
  refused with a message.
- Submissions and marks pulled with earlier versions are kept and keep working: the upgrade gives each record the
  attempt it was written to, read from the submission or grade it points at.
- The latest-only pull is the default and works as it did, except for the student whose attempts were pulled (above).

### Upgrade

- **Schema change.** `block_coursesync_submission` and `block_coursesync_mark` gain `attemptnumber`, and their unique key
  changes from (assignment, user) to (assignment, user, attempt). The upgrade step (2026110401) adds the column, fills
  it, adds the new key and only then drops the old one, checking each part first, so it can be run again if it stops
  part-way. It was tried on PostgreSQL (17.11 on the main test site) and MariaDB 10.11.
- To use the feature, upgrade **both** sites, then switch it on on both. Nothing needs doing on a source that is not
  asked for it. The built-in "Course Sync" web service already contains the functions; a service built by hand needs
  nothing added.
- Tested on Moodle 5.0, 5.1, 5.2 and 5.3: PHPUnit 764 tests and Behat 57 scenarios on each. **Not yet tried between two
  real sites**, and the group size above is unmeasured on a large course.
