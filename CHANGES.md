# Changes in this version

What is new or changed in the current version of Course Sync (`block_coursesync`)
only. The full running history is in [changelog.md](changelog.md).

## v1.32.0-beta (build 2026111001, 2026-11-10)

### Added

- **You choose which grades to pull.** On the **Pull Grades** page every grade, and every quiz attempt, that would be
  written now has a tick box, ticked to begin with. Untick what you do not want and press **Pull the ticked grades**
  (**Pull the ticked grades and attempts** when quizzes are involved). Only what is ticked is written. What you left
  out is not remembered as pulled, so the next preview offers it again, and the page says "You left this unticked".
  Pressing the button with nothing ticked writes nothing and says so.
- **The page is grouped by student, and each student folds away.** Grades and quiz attempts for one person sit in one
  section, headed by their name and username and small counts ("1 to pull", "1 kept", "1 skipped"). A section opens
  by default when it has something to pull or something kept as it was; the rest start closed. Closing a section
  does not untick anything in it. Each section has its own select-all box, and there is one for the whole page.
  What is not about one student - a whole activity skipped, say - is listed last. The per-activity count tables
  stay as they were. Without JavaScript the sections stay open and the boxes still work.
- **Attempts and the quiz grade stay together.** If one of a student's attempts at a quiz is left unticked, the grade
  an earlier pull set for that quiz is **not** taken away, so the student is never left without a grade.

### Changed

- The button no longer shows a count (it could not follow your ticks): **Pull the grades (3)** is now **Pull the
  ticked grades**, and **Pull grades and attempts (3)** is **Pull the ticked grades and attempts**.
- The old lists "Grades to pull", "Kept as they were" and "Skipped", and the same three for attempts, are replaced by
  the student sections; the same facts are in each row's result and reason.

### Unchanged

- A pull still decides for itself what may be written. A tick can only narrow a pull: it cannot make a locked grade,
  a grade someone here set, or a student outside your groups get written. A pull started without the form (older
  links) pulls everything as before.
- No schema change, no new setting and no new permission. The upgrade only refreshes the plugin's caches, for the
  new strings.

### Upgrade

- Tested on Moodle 5.0, 5.1, 5.2 and 5.3: PHPUnit 771 tests (7 new) on each; Behat for the grade and quiz-attempt
  pull features on each (grade pull 6 scenarios / 189 steps including the new JavaScript one; quiz attempts 2 / 75),
  then the whole Behat suite on each: 65 scenarios, 1634 steps, all passing. phplint, phpcs with no warnings, phpdoc,
  validate, savepoints, the JavaScript build and the Gherkin lint on 5.1. Four mutations of the new code were each
  caught by a test.
- **Not tried:** the page in a real browser for spacing and the folding, a course with hundreds of students, and a
  pull between two real sites.
