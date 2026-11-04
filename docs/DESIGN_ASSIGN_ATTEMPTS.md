# Design draft: importing every attempt and its mark

Status: **built in v1.30.0-beta (2026-11-04)**; drafted 2026-11-03 against v1.29.1-beta, build 2026110301.
The text below is the design as drafted. The "Built in Phase N" sections say where the build
differs from it, and the phasing table records what was done. Every open question at the end
was settled by taking its recommendation (questions 3, 5 and 6 at the start of Phase 1; 2, 4 and 7
as the phases were built), so none is open.

## Goal

Today a pull brings across **one** submission and **one** mark per student per
assignment: the latest *submitted* attempt, written here as attempt 0, with the mark that
belongs to it (`DESIGN_ASSIGN_SUBMISSIONS.md`, `DESIGN_ASSIGN_MARKS.md`). For an assignment
that was reopened on the source, the earlier attempts, and the marks and comments the
teacher gave them, are lost.

This note covers carrying **every submitted attempt, each with its own mark and written
comment**, so the destination's grader screen shows the same attempt history, and the
gradebook shows the grade of the latest attempt, as it does on the source.

It does **not** cover the audit trail of who changed a grade and when (grade history and
logs). That is a different problem and is left out on purpose; see "Not done".

## What mod_assign does that this depends on

Read in `mod/assign/locallib.php` (Moodle 5.1 here; to be re-checked on 5.0, 5.2 and 5.3):

- One `assign_submission` row per attempt, `attemptnumber` from 0. Only the newest has
  `latest = 1`. `add_attempt()` makes the next one with status `reopened`.
- One `assign_grades` row per attempt, matched to the submission by `attemptnumber`.
- **`update_grade()` pushes to the gradebook only for the most recent attempt.** For an
  older attempt it saves the row and returns `true` early ("Only push to gradebook if the
  update is for the most recent attempt"). So writing earlier attempts through
  `update_grade()` cannot disturb the gradebook. Their grades are history only.
- `maxattempts` and `attemptreopenmethod` gate *adding* an attempt. They do not stop rows
  from existing. `assign_handler` already carries both settings across when the assignment
  is synced, so a synced assignment normally allows as many attempts as the source did.
- The marking workflow state (`assign_user_flags.workflowstate`) is **per student, not per
  attempt**. Whether an *earlier* attempt's mark was released is not recorded anywhere.

## Pre-existing question to settle first

**Settled 2026-11-04 (LEARNFROMME Phase 97): it was real, on 5.0 to 5.3, and is fixed** by
`mark_assign`, a subclass of `\assign` that never reopens. The text below is the question as it was
asked. Open question 1 and phase 0 below are therefore done.

`mark_writer::write()` calls `update_grade($row)` with `$reopenattempt` false. In core,
`update_grade()` goes on to `reopen_submission_if_required()`. With
`attemptreopenmethod = untilpass` (the default the handler falls back to), a pass mark set
in the gradebook and a **failing** mark, that adds a new `reopened` attempt here. The
source did that on its own site already, so the copy would gain an attempt the pull never
described.

This is read from the core code; **no test or Behat scenario covers it** (nothing in
`marks_pull_test.php` or `assignment_marks_pull.feature` sets `gradepass` or `untilpass`).
It exists today, so it should be tried and, if real, fixed **before** anything in this
note is built, because per-attempt writing would multiply it. Fix idea: have the writer
stop `reopen_submission_if_required()` from acting (for example by writing the grade row
and gradebook directly the way the submission writer writes its rows), and say so in the
tests.

## Decisions (each with a recommendation)

### 1. Which attempts travel

**Every attempt in status `submitted`, oldest to newest.** Not `draft`, `new` or `reopened`
(those hold no handed-in work), exactly as today. An attempt that is a gap in the numbers
(source attempt 1 never submitted, attempt 2 submitted) is carried under its own number.

A mark for an attempt travels only if that attempt travels, **or** the attempt has a mark
but no submission (a teacher marked a student who handed nothing in). The latter is carried
as a grade row with no submission, as `mark_writer` does today.

### 2. How source attempts map onto destination attempts

**Same number.** Source attempt *n* becomes destination attempt *n*. This replaces the
current "always attempt 0" rule. It keeps the mapping trivial and stable; the ledger key
becomes (assignid, userid, attemptnumber).

Where the destination already has its own attempt at number *n* that the plugin did not
write, that one attempt is a conflict and is left alone (the existing rule, now applied per
attempt). The other attempts still travel. The alternative, appending after the
destination's own attempts, renumbers things and makes the mapping unrecoverable; not
recommended.

### 3. The `latest` flag is the plugin's to manage, per student

Today `submission_pull::classify()` treats a copy that is no longer `latest = 1` as
touched by a person (a local reopen) and refuses to replace it. With several attempts that
rule would flag every earlier attempt on every pull.

New rule: after a pull, the highest-numbered attempt **here** has `latest = 1` and every
other attempt of that student has `latest = 0`, with one transaction setting both. The
"no longer latest" test becomes "a row **with a higher number than any the plugin wrote**
exists here", which is a local reopen and still a conflict for that student's later
attempts. A student's attempt rows are written oldest first, so the final state is right
even if a later one is refused.

### 4. Marks per attempt, and the marking workflow

Each attempt's mark is carried with that attempt: number, comment, format, embedded files,
fingerprint. The latest attempt's mark goes through `update_grade()` as now (gradebook
included); **earlier attempts' marks go through the same call**, which core already treats
as history-only.

Under a **marking workflow**, only the latest attempt's release state is known. Recommended:
**earlier attempts' marks do not travel under a marking workflow**, reported with a reason
(`markreasonworkflowhistory`), until the real behaviour of `add_attempt()` on the workflow
state has been tried on a real source. The alternative (treat an earlier attempt as released
because it was superseded) risks showing a student a mark the source had not released.

Point grades only, and a different maximum is scaled, as today.

### 5. Destination settings are never changed

If the destination assignment's `maxattempts` is lower than the number of attempts to
write (a hand-made assignment, or settings changed since the sync), **write the rows
anyway** and tell the teacher in the preview ("5 attempts on the source, this assignment
allows 2"). Restore does the same, the rows are history, and quietly changing a teacher's
settings is worse. Option: refuse instead, by a preview reason. Recommendation above stands.

### 6. Opt-in, not the default

A new switch pair, so a site that is happy with latest-only changes nothing:
`allowattemptsexport` / `allowattemptspull`, and the pull button offers "include earlier
attempts" only when the destination switch is on **and** the source answers it. Existing
behaviour stays the default and is the fallback.

Capabilities: the existing `exportsubmissions` / `exportmarks` and `pullsubmissions` /
`pullmarks` cover it. Earlier attempts hold the same kind of data as the latest, so no new
capability is added. (Recommended; the alternative is one more no-role export capability.)

## Wire format

Both source functions gain one optional parameter, `allattempts` (bool, default false).

- `false`: exactly today's answer. Nothing changes for current callers.
- `true`: each student's entry carries `attempts[]`, oldest first, each shaped like today's
  single entry (`attemptnumber`, times, content or mark, `files[]`, `fingerprint`), plus a
  per-student `fingerprint` over the whole list so the destination can tell "nothing at all
  changed" without comparing every attempt.

**Compatibility trap.** Moodle's `validate_parameters()` *rejects* an unknown parameter, so
an older source would refuse a call that names `allattempts`. The destination must not send
it unless the source says it understands it. **Built in Phase 1 (changed from the first
draft):** `ping` returns `features`, a list of words, and a source that can describe every
attempt *and* has its switch on says `attempts1`. The destination asks only if `ping_result::offers('attempts1')`,
and otherwise refuses with an error (changed in Phase 3 from "falls back to latest-only": see
"Built in Phase 3" below). A feature word was chosen over
comparing `pluginversion` with a build number because it needs no version bump (an unreleased
build can never become a gate target), and the number in the word (`attempts1`) is the
shape of `attempts[]`: it changes if that shape does. `grade_pull` already pings at the
start of a pull, so the destination has a place to check; the check itself is Phase 3.

**Built in Phase 1, wire details.** Each student's entry keeps today's fields (the latest
attempt carried) and gains optional `attempts[]` and `historyfingerprint`, so the entry is not
flattened to one row per attempt (the merge keeps a username once per assignment, Phase 96, and
would drop all but the first). A marking workflow makes `get_marks` answer with the latest
attempt's mark only and say `attemptsreason: workflowhistory` on the assignment. The file
functions take `attemptnumber` (-1, the default, is exactly the old behaviour); naming one, even
the latest, needs the attempts switch. A named attempt must be in `submissions::submitted_attempts()`
(the same helper the description uses), and a named mark file must be listed in that mark's comment.

`get_submission_file` and `get_mark_file` gain an `attemptnumber` parameter (optional,
default: the latest, as now) and keep their rule: a file is served only for a submission
or mark the describing function would have reported. They must apply the *same* attempt
filter the describing function used, or a crafted request could read a draft attempt.

Both functions keep the batching from Phase 96. With `allattempts` each pair can be several
attempts, so `PAIRS_PER_CALL` needs to count attempts, not students; this is the most
likely thing to be wrong in a first version and wants a test with a heavily reopened
assignment.

## Ledgers

`block_coursesync_submission` and `block_coursesync_mark` both gain `attemptnumber` and
change their unique key from (assignid, userid) to (assignid, userid, attemptnumber).

**Built in Phase 2 (changed from the first draft).** The upgrade backfills each existing row from
the row it points at here (`assign_submission.attemptnumber` or `assign_grades.attemptnumber`),
not from `remoteattempt` and not always 0, and leaves it 0 if that row has since gone. The first
draft said 0; that is right for submissions (a pull always writes attempt 0) but not for marks,
which are written to the student's *latest* attempt here, so a mark written when the student
already had a second attempt here belongs to attempt 1. Existing rows stay valid either way.
The step adds the column, fills it, adds the new unique key and only then drops the old one, and
every part checks first, so it can be run again from any point a stopped upgrade left it.
The writers record the attempt they wrote to. The lookups by (assignid, userid) are unchanged in
Phase 2 because there is still one row per pair; Phases 3 and 4 add the attempt to them. The
privacy provider declares and exports the new field.

Tried on PostgreSQL 17 (Moodle 5.0 to 5.3) and MariaDB 10.11 (5.1).

## Per-attempt rule

For each (student, attempt), the existing outcomes apply unchanged, using that attempt's
ledger row and fingerprints:

| Situation here | Result |
| --- | --- |
| No row at that attempt number | **Written** |
| Ledger row, local unchanged, source changed | **Updated** |
| Ledger row, local changed | **Flagged**, left alone |
| A row the plugin did not write | **Flagged**, or **same** if identical |
| Source fingerprint equals ledger | Unchanged |
| Row deleted here after import | Not brought back |
| A higher-numbered attempt exists here that the plugin did not write | Earlier attempts still travel; **later ones are flagged** |

The preview groups by assignment, then student, then attempt. The sync history keeps
**counts per assignment, never which students**, as now; it gains a count of attempts.

## Built in Phase 3 (submissions, destination side)

Entry point: `submission_pull::preview/run(..., $only, bool $allattempts = false)`. Nothing in the
pages passes `true` yet (Phase 5).

- **Gates, in the engine.** `check_allowed(course, true)` also needs `allowattemptspull` (moved up from
  Phase 5, for the reason the export switch moved up in Phase 1: the engine is where rights are checked).
  Then a ping: if it fails, its error is passed on; if the source does not offer `attempts1` the pull
  **refuses** with `errorattemptssourceunable`. The first draft said "fall back to latest-only". A silent
  fallback gives less than was asked for, and it would also run into the mixing guard below.
- **Same number, one record each.** Source attempt *n* is written as attempt *n*, with a record for
  (assignment, student, *n*). A student's records and rows are read once, not once per attempt.
- **One latest.** After each attempt is written (same transaction) the student's highest-numbered row
  here gets `latest = 1` and every other row 0 - including when the highest is work here that did not
  come from the other site. The old "no longer the latest means someone reopened it" rule applies only
  to a latest-only pull; with every attempt an earlier attempt is not the latest.
- **Where the histories part.** The highest submitted/draft row here that no record points at, and that
  is not exactly the other site's attempt of that number, is the limit: attempts at or below it go through
  the ordinary rules, attempts above it are left (`subreasonreopenedhere`). An identical row does not
  count (it is the same work, whoever put it there). Rows that were only opened (`new`/`reopened`) hold
  no work and are used by the attempt of their number. *Not handled:* a limit at *m* when the source has
  no attempt *m* but has some above it; they are left too.
- **No holes.** A failed transfer, a file that fails its check, a file too big or a type this assignment
  does not take, or the run's byte budget, holds back that student's later attempts
  (`subreasonearlierskipped`); the next pull carries on. "Same" and "kept because changed here" do not.
- **The two ways of pulling.** A latest-only pull writes the newest as attempt 0. So it leaves any student
  who has a record above attempt 0 alone (`subreasonattemptsdone`), or it would overwrite the real attempt 0.
  The other way mends itself: an every-attempt pull updates an untouched latest-only copy back to the
  attempt it really was (the record's `remoteattempt` differs from its attempt number) and adds the rest.
- **On the wire.** `allattempts` and `attemptnumber` are sent only in every-attempt mode (an old source
  refuses unknown parameters). `attempts[]` is required for every student when asked for, must be
  strictly ascending with no repeated number, at most 100 long, and each attempt is cleaned like the
  latest. Calls carry at most `ATTEMPT_PAIRS_PER_CALL` = 100 student-assignment pairs - an estimate, a
  quarter of the latest-only figure, not a measurement.

**For Phase 4 (found while building this).** A marks pull writes to the student's *latest* attempt here
and looks for the mark there. Once an every-attempt submission pull has added a later attempt, a
latest-only marks pull no longer finds the mark it pulled before (it is on attempt 0) and reports it as
"removed here", so changes to it stop coming. Nothing is damaged, but Phase 3 must not be released
without Phase 4.

## Built in Phase 4 (marks, destination side)

Entry point: `marks_pull::preview/run(..., $only, bool $allattempts = false)`; the gates are the same as for
submissions (`allowattemptspull`, the ping offering `attempts1`, a refusal and not a fallback).

- **What a mark is put on.** The grade row of the attempt with the same number, written with
  `get_user_grade($userid, true, $n)`. That call creates a submission row if there is none at *n*, so it is
  only made when attempt *n* exists here as `submitted` or `draft`. The one exception is today's case: a
  student with no work here at all is marked at attempt 0, and only there.
- **Pairing, tighter than "attempt *n* exists".** If the student has submission records here (their
  submissions were pulled), a mark goes only onto an attempt recorded as pulled from that same source
  attempt - same number, and the record points at the row that is there now (`markreasonmismatch`
  otherwise). That keeps a mark off the student's own different work, off a latest-only copy (the newest
  attempt written as attempt 0), and off a row that replaced what was pulled. If the student has no
  submission records, pairing is by number alone, as it always was for the latest. *Limit:* a pull of
  submissions that wrote nothing (everything conflicted) leaves no records, so it cannot be told from one
  that never ran.
- **An earlier attempt's mark is history.** `update_grade()` already keeps it out of the gradebook, but the
  writer also released the workflow state (per student) and took away a grade sync's override; both now
  happen only when the attempt written is the student's newest *here* (decided from the rows here, not from
  the source's numbering) - in the write and in the preview's "released" line. Writing is oldest first, so the
  newest attempt's effect on the gradebook comes last.
- **The gradebook lock** is checked for every attempt, not only the newest. Writing an earlier attempt would
  not touch the gradebook, but a locked grade means nobody wants this student's grade changed; the cautious
  choice costs only a skipped history.
- **No hold-back.** Unlike submissions, a mark that fails leaves no hole (a grade row is independent of the
  others), so the other attempts carry on.
- **The two ways of pulling.** A latest-only marks pull puts the mark where the student's latest attempt is
  *here*. It now leaves a student alone (`markreasonattemptsdone`) when they have more than one mark record,
  or any submission record above attempt 0 - the case found at the end of Phase 3, where the mark it had
  pulled was reported as "removed here". The check is before the lookup of the record, which may now find
  several rows. An every-attempt pull mends an untouched latest-only copy: the record says `remoteattempt` 2 at
  attempt 0, the mark for attempt 0 differs, so it is updated.
- **Marking workflow on the source.** Only the newest attempt's mark comes (see Phase 1). The destination adds
  one note for the assignment (`markreasonworkflowhistory`) and still works through the students. The history
  counts it as one skipped entry for the assignment, as it counts every whole-assignment reason.
- **On the wire.** As for submissions: the new parameters go only in every-attempt mode (`allattempts`,
  `attemptnumber`), calls hold at most `ATTEMPT_PAIRS_PER_CALL` pairs, and `attempts[]` is required for every
  student, strictly ascending, at most 100, with each mark held to what the latest one is (a finite number
  or none). `attemptsreason` is kept only if it is `workflowhistory`.

**Not changed (and found on the way).** After the *destination's own* teacher reopens an assignment, a
latest-only marks pull still reports a mark it pulled before as "removed here" (the record is at the old
attempt, the latest has no grade). That is how v1.22.0 behaved and it is not about this feature, so it is
left; the new guard only covers students whose attempts were pulled.

## Built in Phase 5 (the pages)

- **The choice.** `submissions.php` and `marks.php` take `allattempts` (`PARAM_BOOL`). A small GET form above the
  preview, **Include every attempt each student handed in, not only the latest** with **Update the preview**,
  asks for another preview and writes nothing; it is offered only where `allowattemptspull` is on
  (`attempts::choice_form()`). The confirmation carries the choice as a hidden field. The engine still checks the
  switch, so a value forced into the URL meets `errorattemptspulloff`.
- **If the source cannot.** The error is shown with a link, **Preview the latest attempts instead**.
- **The preview.** Entries are sorted by assignment, student, attempt, so a student's attempts sit together and
  oldest first; the counts are labelled as attempts. The marks tables gain an **Attempt** column in this mode only.
- **Warnings are not outcomes.** `grade_pull_result::$warnings` carries notes that stop nothing; the one use is an
  assignment here that allows fewer attempts than the source has (`submissionswarnmaxattempts`), shown in the preview
  and after the pull, with all the attempts written. Open question 4 is therefore settled as "write anyway and warn".
- **History.** When `everyattempt` is set each activity's record is marked `every`, and the history page words the
  counts as "Submission attempts pulled" / "Mark attempts pulled". Still counts only: no students, no attempts listed.
- **Not done:** a warning on the marks page for `maxattempts` (marks are written onto attempts that already exist, so
  the limit is not what constrains them); a per-assignment choice (the choice is for the whole pull). The docs gave
  no release number until the version was chosen (v1.30.0-beta).

## Privacy and lifecycle

No new table, so no new declaration, but the two ledger tables gain a column that the
privacy provider's declared fields and its export must list (the existing drift test will
fail if they are not). Delete, course reset and block-instance deletion already clear both
ledgers by block, course or user, which stays right.

Submissions and marks written here are mod_assign's own rows, deleted by its own provider.

## Not done on purpose

- **The audit trail** (grade history table, logs, who graded). The teachers on the other
  site are not users here; the grader recorded stays the person who pulled. Writing history
  rows with invented users and old timestamps would create false records. The cheap
  alternative, if wanted: add the source teacher's display name and time to the comment, or
  show them on the sync-history page.
- Team assignments, scales, other submission or feedback plugins, rubric detail: unchanged
  from the existing notes.
- Drafts. An attempt is carried only once submitted.

## Phasing

| Phase | Content |
| --- | --- |
| 0 | **Done (Phase 97):** the `untilpass` side effect was real; fixed and tested on 5.0 to 5.3 |
| 1 | **Done (Phase 98):** `allattempts` on both source functions, the file functions' `attemptnumber`, the source-side switch `allowattemptsexport` (moved up from Phase 5: the data must be gated the moment it can be asked for), the `ping` feature word, source tests |
| 2 | **Done (Phase 99):** ledger column `attemptnumber` and the index swap, with the upgrade step and its tests; build 2026110401 |
| 3 | **Done (Phase 100):** submissions: per-attempt classify/write, the `latest` rule, conflict handling, the engine's pull-side switch and the ping gate. Reachable from code only; no page offers it yet |
| 4 | **Done (Phase 101):** marks: per-attempt classify/write, the marking-workflow reason, the pairing rule and the guards. Reachable from code only |
| 5 | **Done (Phase 102):** the pages' checkbox, the preview in attempt order, history counts, the too-few-attempts warning, docs and Behat (the pull-side switch itself moved to Phase 3) |
| 6 | Behat (a reopened assignment end to end), then 5.0, 5.1, 5.2 and 5.3, then a real pair of sites |

Each phase is logged in `LEARNFROMME.md` before it is called done.

## Tests

- PHPUnit: a student with 3 submitted attempts writes 3 rows, numbered the same, and only
  the highest is `latest`; a second pull is all "same"; the source changing attempt 1 only
  updates attempt 1; a local reopen (a row above the plugin's) flags only later attempts; a
  destination row at attempt 1 that the plugin did not write is flagged and attempt 0 and 2
  still travel; a gap in the numbers; a marked attempt with no submission; a draft attempt
  is not carried and its files cannot be fetched; the old-source fallback; the marking
  workflow reason; `maxattempts` lower than the attempt count; the batch size counting
  attempts; the upgrade backfill including a ledger row whose `remoteattempt` is 2.
- Behat: reopen on the source, pull, see all attempts and the latest grade in the
  gradebook; switch off hides the option.
- Each gate shown non-vacuous by breaking the code and watching the test fail.
- Needs both Docker sites, and the real pair, because `add_attempt()` and the reopen
  methods differ in small ways between 5.0 and 5.3.

## Size

Larger than the marks sync: the wire change, a unique-index migration, and rewriting the
`latest` handling in `classify()` all touch code that is currently simple and well covered.
Phase 0 is small and worth doing regardless of whether the rest goes ahead.

## Open questions (need a decision before building)

Phase 1 was built on 2026-11-04 taking the recommended answer to questions 3, 5 and 6 (the
marking workflow reason, the opt-in switch, the existing capabilities). They are cheap to change
at this stage; the others are still open.

1. ~~Phase 0 first?~~ Done, see above.
2. **Same-number mapping** (recommended), or append after the destination's own attempts?
3. **Marking workflow:** skip earlier attempts' marks with a reason (recommended), or treat
   a superseded attempt as released?
4. **`maxattempts` too low here:** write anyway and warn (recommended), or refuse?
5. **Opt-in switch pair** (recommended), or fold into the existing switches?
6. **Reuse the existing capabilities** (recommended), or add a separate no-role export one?
7. **Audit trail:** leave out (recommended), or add the source grader's name and time to
   the comment text?
