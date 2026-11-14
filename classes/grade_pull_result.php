<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace block_coursesync;

/**
 * What a grade pull did - or, for a preview, would do - with each grade.
 *
 * One entry per student per grade item, plus one per grade item that could not
 * be used at all (its username is then empty). Grades are held in this site's
 * terms: a pulled grade has already been converted to the local grade range.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_pull_result {
    /** @var string No grade here yet; the pulled one is (or would be) written. */
    public const ADD = 'add';

    /** @var string A grade an earlier pull wrote, untouched since, is (or would be) replaced. */
    public const UPDATE = 'update';

    /** @var string The grade here already says the same. Nothing to do. */
    public const SAME = 'same';

    /** @var string Someone here gave a different grade. It is kept; the teacher is told. */
    public const CONFLICT = 'conflict';

    /** @var string Could not be used; the reason says why. */
    public const SKIPPED = 'skipped';

    /**
     * @var string An override an earlier pull wrote, untouched since, is (or
     *      would be) removed, so the quiz's own grade from its attempts shows.
     */
    public const RELEASED = 'released';

    /** @var string[] Every outcome, in the order they are shown. */
    public const OUTCOMES = [self::ADD, self::UPDATE, self::SAME, self::CONFLICT, self::SKIPPED, self::RELEASED];

    /** @var string An entry about a gradebook grade. */
    public const KIND_GRADE = 'grade';

    /** @var string An entry about a quiz attempt. */
    public const KIND_ATTEMPT = 'attempt';

    /** @var string An entry about an assignment submission. */
    public const KIND_SUBMISSION = 'submission';

    /** @var string An entry about a teacher's mark and feedback on an assignment. */
    public const KIND_MARK = 'mark';

    /** @var bool Whether the pull got as far as looking at grades. */
    public bool $success = true;

    /** @var string|null Why it did not, as a language string identifier. */
    public ?string $errorkey = null;

    /** @var bool True for a preview: nothing was written. */
    public bool $preview = false;

    /**
     * @var array[] Quizzes here that can take the source's attempts: local
     *      cmid => [username => true] for everyone with attempts there.
     */
    public array $attemptquizzes = [];

    /** @var string[] Things to tell the teacher about the pull as a whole, as language string identifiers. */
    public array $notes = [];

    /** @var bool True when every attempt each student handed in was asked for, so the counts are of attempts. */
    public bool $everyattempt = false;

    /**
     * @var \stdClass[] Things worth knowing that do not stop anything: key (a language string
     *      identifier) and a (its placeholders). Not outcomes, so they are not counted.
     */
    public array $warnings = [];

    /**
     * @var \stdClass[] kind (KIND_GRADE or KIND_ATTEMPT), cmid (local; 0 for a
     *      manual grade item, which has remoteitemid instead), activity,
     *      itemnumber, gradeitemid, userid (0 if none), username, outcome,
     *      reason (language string identifier or null), grade (pulled, local
     *      terms), localgrade (before the pull); an attempt also has attempt
     *      (its number on the source), attemptid (here) and notes, and its
     *      grade and localgrade are the attempt's total marks
     */
    public array $entries = [];

    /**
     * Which grades and attempts the person ticked, as a set of keys (see
     * key()), or null for "everything" - a preview, or a pull nobody narrowed.
     *
     * It can only narrow a pull: a key says "this one may be written", and the
     * pull still works out for itself whether it can be. A key it does not
     * recognise, or one for a student outside the person's groups, does nothing.
     *
     * @var bool[]|null key => true
     */
    public ?array $chosen = null;

    /**
     * A pull that could not start or could not reach the other site.
     *
     * @param string $errorkey
     * @return self
     */
    public static function failure(string $errorkey): self {
        $result = new self();
        $result->success = false;
        $result->errorkey = $errorkey;

        return $result;
    }

    /**
     * The key that names one grade or attempt, the same on the preview and on
     * the pull that follows it.
     *
     * Built from who and what it is, never from a row id: a manual grade item
     * a preview would create has none yet. Hashed, so it is safe to put in a
     * form and to read back as PARAM_ALPHANUM, whatever a username holds.
     *
     * @param \stdClass $entry see $entries
     * @return string
     */
    public static function key(\stdClass $entry): string {
        return sha1(implode('|', [
            $entry->kind ?? self::KIND_GRADE,
            (int) ($entry->cmid ?? 0),
            (int) ($entry->remoteitemid ?? 0),
            (int) ($entry->itemnumber ?? 0),
            (string) ($entry->username ?? ''),
            (int) ($entry->attempt ?? 0),
        ]));
    }

    /**
     * The identity of one file of one entry's submission, for the tick box beside it.
     *
     * Like key(), it only narrows a pull: a key that matches no file of the submission as
     * the source describes it does nothing. It includes the content hash, so a file that has
     * changed on the source is a different file to tick.
     *
     * @param \stdClass $entry see $entries
     * @param array $file as described by the source: area, filepath, filename and contenthash
     * @return string
     */
    public static function file_key(\stdClass $entry, array $file): string {
        return sha1(implode('|', [
            self::key($entry),
            (string) $file['area'],
            (string) $file['filepath'],
            (string) $file['filename'],
            (string) $file['contenthash'],
        ]));
    }

    /**
     * May this entry be written, as far as the person's ticks go?
     *
     * @param \stdClass $entry see $entries
     * @return bool
     */
    public function is_chosen(\stdClass $entry): bool {
        return $this->chosen === null || isset($this->chosen[self::key($entry)]);
    }

    /**
     * Was any attempt of this student's at this quiz left unticked?
     *
     * A quiz's grade override can only be taken away once its attempts are
     * here; with one of them left out, taking it away would leave the student
     * with no grade at all.
     *
     * @param int $cmid the quiz here
     * @param string $username
     * @return bool
     */
    public function attempt_left_out(int $cmid, string $username): bool {
        foreach ($this->entries as $entry) {
            if (
                ($entry->kind ?? '') === self::KIND_ATTEMPT && (int) $entry->cmid === $cmid
                && $entry->username === $username && ($entry->reason ?? null) === 'attemptskipnotchosen'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record what happened to one grade, or to a whole grade item.
     *
     * @param \stdClass $entry see $entries
     * @return void
     */
    public function add(\stdClass $entry): void {
        $this->entries[] = $entry;
    }

    /**
     * Say something about the pull that is not an outcome.
     *
     * @param string $key a language string identifier in block_coursesync
     * @param \stdClass $a its placeholders
     * @return void
     */
    public function warn(string $key, \stdClass $a): void {
        $this->warnings[] = (object) ['key' => $key, 'a' => $a];
    }

    /**
     * The entries of one kind, or all of them.
     *
     * @param string|null $kind KIND_GRADE, KIND_ATTEMPT, or null for every entry
     * @return \stdClass[]
     */
    public function of_kind(?string $kind = null): array {
        return $kind === null
            ? $this->entries
            : array_values(array_filter($this->entries, static fn($e) => ($e->kind ?? self::KIND_GRADE) === $kind));
    }

    /**
     * The entries with a given outcome.
     *
     * @param string $outcome one of the constants
     * @param string|null $kind only entries of this kind
     * @return \stdClass[]
     */
    public function with_outcome(string $outcome, ?string $kind = null): array {
        return array_values(array_filter($this->of_kind($kind), static fn($e) => $e->outcome === $outcome));
    }

    /**
     * What happened in each activity, as counts.
     *
     * @param string|null $kind only entries of this kind
     * @return array[] one per activity (or manual grade item): [cmid (0 for a
     *     manual item), name, and a count for each outcome], in the order first met
     */
    public function by_activity(?string $kind = null): array {
        $activities = [];

        foreach ($this->of_kind($kind) as $entry) {
            // A manual grade item has no activity, so no cmid: the source's
            // grade item id tells one from another.
            $key = $entry->cmid ?: 'manual' . ($entry->remoteitemid ?? 0);

            if (!isset($activities[$key])) {
                $activities[$key] = array_merge(
                    ['cmid' => $entry->cmid, 'name' => $entry->activity],
                    array_fill_keys(self::OUTCOMES, 0)
                );
            }

            $activities[$key][$entry->outcome]++;
        }

        return $activities;
    }

    /**
     * How many entries have each outcome.
     *
     * @param string|null $kind only entries of this kind
     * @return int[] outcome => count, every outcome present
     */
    public function counts(?string $kind = null): array {
        $counts = array_fill_keys(self::OUTCOMES, 0);

        foreach ($this->of_kind($kind) as $entry) {
            $counts[$entry->outcome]++;
        }

        return $counts;
    }

    /**
     * How many grades and attempts a pull writes (or, for a preview, would).
     *
     * @return int
     */
    public function changes(): int {
        $counts = $this->counts();

        return $counts[self::ADD] + $counts[self::UPDATE] + $counts[self::RELEASED];
    }
}
