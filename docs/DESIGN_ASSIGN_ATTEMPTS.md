# Design draft: importing every attempt and its mark

Status: **proposed, not built** (drafted 2026-11-03 against v1.29.1-beta, build 2026110301).
Nothing here is decided. Each choice has a recommendation; "Open questions" at the end
need an answer before building.

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
and falls back to latest-only (with a preview note) otherwise. A feature word was chosen over
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
| 3 | Submissions: per-attempt classify/write, the `latest` rule, conflict handling |
| 4 | Marks: per-attempt classify/write, the marking-workflow reason |
| 5 | The pull-side switch, preview grouping, history counts, privacy, docs |
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
