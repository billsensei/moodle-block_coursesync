# Design: importing assignment marks and feedback

Status: **built in v1.22.0-beta (2026-10-10)**. Decisions taken with the user
before building are marked **(decided)**; the rest followed from them.

## Goal

Let a teacher on the destination site bring in the **mark and the written
comment** a teacher gave on the source site, for assignments Course Sync copied
or linked, so the destination's grader screen, gradebook and student view all
show it. Submissions travel separately (v1.21.0); this does not need them.

## Why it exists

Grade sync writes a gradebook **override**. The assignment's own grader screen
knows nothing about an override, so after a submission import it showed the work
as ungraded while the gradebook showed a grade. Writing the real `assign_grades`
row fixes that.

## Decisions

1. **Takes over from grade sync for assignments (decided).** After the real grade
   is written, a gradebook override that grade sync wrote and nobody has touched
   since is removed (same rule as the quiz release, `grade_pull::untouched()`);
   an override a teacher changed stays. Once a marks pull has written a student's
   mark, grade sync skips that student for that assignment (`gradeskipmarks`), so
   the two never fight. Grade sync still handles every other activity type.
2. **Mark and written comment (decided).** The number, the comments plugin's text
   and format, and files embedded in that comment. Not feedback files, annotated
   PDFs, rubrics, offline grading worksheets or other feedback plugins.
3. **Same shape as submissions, own switches and permissions.** `allowmarksexport` /
   `allowmarkspull`; `block/coursesync:exportmarks` (no role) /
   `block/coursesync:pullmarks` (editing teacher, manager) plus `mod/assign:grade`.
4. **Which attempt.** The mark for the student's latest *submitted* attempt (the
   one a submission pull carries), or their latest attempt if they have handed
   nothing in. It is written to the student's latest attempt here.
5. **Written through mod_assign.** `update_grade()` so the gradebook is pushed and
   the grader screen agrees. The submission pull deliberately bypassed the
   student-facing save path (deadlines, notifications); a grade has no such
   rules, and going around `update_grade()` would leave the gradebook stale.
6. **Who graded is not carried.** A teacher on the other site is not a user here;
   the grader recorded is the person who pulled.
7. **Point grades only.** A scale, an ungraded assignment and a team assignment
   are described with a reason and skipped. A different maximum is scaled
   proportionally (five decimals, the gradebook's precision).
8. **Marking workflow.** Only a *released* mark is sent (what the source's own
   gradebook shows); locally the user flag is set to released.
9. **Never overwritten.** Same rule as everything else: written only where the
   student has no mark and no comment here, or where an earlier pull wrote it and
   nobody has touched it; "removed here" is not brought back; a locked gradebook
   grade is skipped.

## Ledger

`block_coursesync_mark`: block instance, course, user, assignment, the
`assign_grades` row, the source's cmid and attempt, a fingerprint of what the
source held, a fingerprint of what was written here, times. Unique on
(assignid, userid). Never holds the mark or the comment.

## Source side

`get_marks(courseid, cmids, usernames)` returns per assignment a reason (`team`,
`scale`, `nograde`, `notassign` or empty), its maximum, and per student the
attempt, time, number, comment, format, files and a fingerprint.
`get_mark_file(cmid, username, filepath, filename, offset, length)` serves a
comment file only for a mark `get_marks` would describe.

## Not done

Other feedback plugins and feedback files; rubric / marking guide detail;
earlier attempts; team assignments; marker allocation; extensions; per-assignment
selection (a pull covers every copied assignment).
