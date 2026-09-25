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

use core\http_client;

/**
 * Pulls students' gradebook grades from the source course into this one.
 *
 * Only for activities this plugin copied here - the coursesync-<remote cmid>
 * idnumber is what pairs a copy with its original. A student is the account
 * here with the same username, and only if they are an active student in this
 * course: no account is ever created or enrolled.
 *
 * What happens to each grade:
 * - nothing here yet: the pulled grade is written;
 * - the same as the pulled grade already: nothing;
 * - written by an earlier pull and untouched since: replaced;
 * - anything else: kept, and reported as a conflict for the teacher.
 *
 * A pulled grade is written as a gradebook override. The activity here has
 * no attempts or submissions for these students, so the next time it pushed
 * its own grades - a quiz regrade, for one - a plain grade would be wiped.
 * An overridden grade is left alone.
 *
 * Grades removed on the source are not removed here, just as activities
 * deleted there are not deleted here.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_pull {
    /** @var string Recorded as the source of every grade this writes, in the grade history. */
    public const SOURCE = 'block_coursesync';

    /**
     * What a pull would do, without writing anything.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param http_client|null $client injected only by tests
     * @return grade_pull_result
     */
    public static function preview(int $blockinstanceid, int $courseid, ?http_client $client = null): grade_pull_result {
        $result = self::pull($blockinstanceid, $courseid, false, $client);
        $result->preview = true;

        return $result;
    }

    /**
     * Pull the grades.
     *
     * Holds the same per-block lock as a sync of activities, so grades are
     * never being written into an activity that a sync is replacing, and is
     * written to the same history.
     *
     * @param int $blockinstanceid
     * @param int $courseid the destination course
     * @param http_client|null $client injected only by tests
     * @return grade_pull_result
     */
    public static function run(int $blockinstanceid, int $courseid, ?http_client $client = null): grade_pull_result {
        global $USER;

        $timestarted = time();
        $lock = \core\lock\lock_config::get_lock_factory('block_coursesync')
            ->get_lock('run-' . $blockinstanceid, 0, syncer::LOCK_LIFETIME);

        if (!$lock) {
            $result = grade_pull_result::failure('errorsyncinprogress');
        } else {
            try {
                $result = self::pull($blockinstanceid, $courseid, true, $client);
            } finally {
                $lock->release();
            }
        }

        // Written down whatever happened, as a sync is - but not when pulling
        // was never allowed to begin: that is not a pull that happened.
        if (!in_array($result->errorkey, ['errornogradepullpermission', 'errorgradepulloff'], true)) {
            history::record_grade_pull($blockinstanceid, $courseid, (int) $USER->id, $timestarted, $result);
        }

        return $result;
    }

    /**
     * Why the current user may not pull grades into a course, if they may not.
     *
     * Checked by the engine itself, not only by the pages that call it, so
     * nothing reaching it by another route can skip it. A preview counts:
     * it shows other people's grades from the other site.
     *
     * @param int $courseid
     * @return string|null language string identifier, or null if allowed
     */
    public static function check_allowed(int $courseid): ?string {
        if (!get_config('block_coursesync', 'allowgradepull')) {
            return 'errorgradepulloff';
        }

        $context = \context_course::instance($courseid);

        // The grades are written as overrides in the gradebook, so the person
        // pulling them must be someone allowed to do that by hand.
        if (!has_all_capabilities(['block/coursesync:pullgrades', 'moodle/grade:edit'], $context)) {
            return 'errornogradepullpermission';
        }

        return null;
    }

    /**
     * The copies in a course that this plugin made, by the source's id for them.
     *
     * @param int $courseid
     * @return \cm_info[] remote cmid => the copy here
     */
    public static function local_copies(int $courseid): array {
        $copies = [];

        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }

            if (preg_match('/^coursesync-(\d+)$/', (string) $cm->idnumber, $matches)) {
                $copies[(int) $matches[1]] = $cm;
            }
        }

        return $copies;
    }

    /**
     * Forget what earlier pulls wrote for a block that is being deleted.
     *
     * The grades themselves stay: they are the students' grades now.
     *
     * @param int $blockinstanceid
     * @return void
     */
    public static function delete_for_block_instance(int $blockinstanceid): void {
        global $DB;

        $DB->delete_records('block_coursesync_grade', ['blockinstanceid' => $blockinstanceid]);
    }

    /**
     * Work through every grade, writing if asked to.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param bool $write false for a preview
     * @param http_client|null $client
     * @return grade_pull_result
     */
    protected static function pull(int $blockinstanceid, int $courseid, bool $write, ?http_client $client): grade_pull_result {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');

        $notallowed = self::check_allowed($courseid);

        if ($notallowed !== null) {
            return grade_pull_result::failure($notallowed);
        }

        $record = connection::get($blockinstanceid);

        if (!connection::is_mapped($record)) {
            return grade_pull_result::failure('errornotmapped');
        }

        $token = connection::get_token($blockinstanceid);

        if ($token === null) {
            return grade_pull_result::failure('errortokenunreadable');
        }

        $result = new grade_pull_result();
        $copies = self::local_copies($courseid);

        if (!$copies) {
            return $result;
        }

        $found = remote_client::get_grades(
            $record->remoteurl,
            $token,
            (int) $record->remotecourseid,
            array_keys($copies),
            $client
        );

        if (!$found->success) {
            return grade_pull_result::failure($found->errorkey);
        }

        // Final grades are only current once any pending recalculation has
        // run, and the list of gradable students refuses to run before it.
        if (grade_needs_regrade_final_grades($courseid)) {
            grade_regrade_final_grades($courseid);
        }

        $students = [];

        foreach (get_gradable_users($courseid, null, true) as $user) {
            $students[$user->username] = $user;
        }

        $knownhere = self::usernames_known_here($found->items, $students);
        $remote = [];

        foreach ($found->items as $item) {
            $remote[$item->cmid][$item->itemnumber] = $item;
        }

        foreach ($copies as $remotecmid => $cm) {
            $localitems = [];
            $gradeitems = \grade_item::fetch_all([
                'courseid' => $courseid,
                'itemtype' => 'mod',
                'itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance,
            ]) ?: [];

            foreach ($gradeitems as $gradeitem) {
                if ((int) $gradeitem->gradetype !== GRADE_TYPE_NONE) {
                    $localitems[(int) $gradeitem->itemnumber] = $gradeitem;
                }
            }

            ksort($localitems);

            foreach ($remote[$remotecmid] ?? [] as $itemnumber => $remoteitem) {
                self::pull_item(
                    $result,
                    $write,
                    $blockinstanceid,
                    $courseid,
                    $remotecmid,
                    $cm,
                    $remoteitem,
                    $localitems[$itemnumber] ?? null,
                    $students,
                    $knownhere
                );
            }

            // Graded here, but the source said nothing about it: most often
            // the original was deleted there.
            foreach ($localitems as $itemnumber => $gradeitem) {
                if (!isset($remote[$remotecmid][$itemnumber])) {
                    $result->add(self::entry($cm, $itemnumber, $gradeitem, grade_pull_result::SKIPPED, 'gradeskipnotonsource'));
                }
            }
        }

        return $result;
    }

    /**
     * Every grade in one of the source's grade items.
     *
     * @param grade_pull_result $result
     * @param bool $write
     * @param int $blockinstanceid
     * @param int $courseid
     * @param int $remotecmid
     * @param \cm_info $cm the copy here
     * @param \stdClass $remoteitem from grades_result
     * @param \grade_item|null $gradeitem the matching grade item here
     * @param \stdClass[] $students gradable users here, by username
     * @param bool[] $knownhere username => true for accounts that exist here
     * @return void
     */
    protected static function pull_item(
        grade_pull_result $result,
        bool $write,
        int $blockinstanceid,
        int $courseid,
        int $remotecmid,
        \cm_info $cm,
        \stdClass $remoteitem,
        ?\grade_item $gradeitem,
        array $students,
        array $knownhere
    ): void {
        global $DB;

        if (!$remoteitem->grades) {
            return;
        }

        $problem = self::item_problem($remoteitem, $gradeitem);

        if ($problem !== null) {
            $result->add(self::entry($cm, $remoteitem->itemnumber, $gradeitem, grade_pull_result::SKIPPED, $problem));

            return;
        }

        $userids = [];

        foreach ($remoteitem->grades as $remotegrade) {
            if (isset($students[$remotegrade->username])) {
                $userids[] = (int) $students[$remotegrade->username]->id;
            }
        }

        $localgrades = $userids ? \grade_grade::fetch_users_grades($gradeitem, $userids, false) : [];
        $pulled = $DB->get_records('block_coursesync_grade', ['gradeitemid' => $gradeitem->id], '', '*');
        $ours = [];

        foreach ($pulled as $row) {
            $ours[(int) $row->userid] = $row;
        }

        foreach ($remoteitem->grades as $remotegrade) {
            $entry = self::entry($cm, $remoteitem->itemnumber, $gradeitem, grade_pull_result::SKIPPED, null);
            $entry->username = $remotegrade->username;
            $user = $students[$remotegrade->username] ?? null;

            if (!$user) {
                $entry->reason = isset($knownhere[$remotegrade->username]) ? 'gradeskipnotenrolled' : 'gradeskipnouser';
                $result->add($entry);
                continue;
            }

            $entry->userid = (int) $user->id;
            $incoming = self::convert($remotegrade->grade, $remoteitem, $gradeitem);
            $existing = $localgrades[$user->id] ?? null;
            $entry->grade = $incoming;
            $entry->localgrade = self::has_grade($existing) ? self::finalgrade($existing) : null;

            if ($existing && $existing->is_locked()) {
                $entry->reason = 'gradeskiplocked';
            } else if (!self::has_grade($existing)) {
                $entry->outcome = grade_pull_result::ADD;
            } else if (self::says($existing, $incoming, $remotegrade->feedback)) {
                $entry->outcome = grade_pull_result::SAME;
            } else if (isset($ours[$user->id]) && self::untouched($existing, $ours[$user->id])) {
                $entry->outcome = grade_pull_result::UPDATE;
            } else {
                $entry->outcome = grade_pull_result::CONFLICT;
            }

            $writes = in_array($entry->outcome, [grade_pull_result::ADD, grade_pull_result::UPDATE], true);

            if ($write && $writes) {
                $hidden = $remotegrade->hidden ?: $remoteitem->hidden;

                if (!self::write($blockinstanceid, $courseid, $remotecmid, $gradeitem, $user, $incoming, $remotegrade, $hidden)) {
                    // Locked in the meantime, or its lock time has passed.
                    $entry->outcome = grade_pull_result::SKIPPED;
                    $entry->reason = 'gradeskiplocked';
                }
            }

            $result->add($entry);
        }
    }

    /**
     * Why a grade item as a whole cannot take the source's grades, if it cannot.
     *
     * @param \stdClass $remoteitem
     * @param \grade_item|null $gradeitem
     * @return string|null language string identifier
     */
    protected static function item_problem(\stdClass $remoteitem, ?\grade_item $gradeitem): ?string {
        if (!$gradeitem) {
            return 'gradeskipnoitem';
        }

        if ((int) $gradeitem->gradetype !== $remoteitem->gradetype) {
            return 'gradeskiptype';
        }

        if ($remoteitem->gradetype === GRADE_TYPE_SCALE) {
            $scale = $gradeitem->load_scale();

            if (!$scale || grades_result::scale_items((string) $scale->scale) !== $remoteitem->scale) {
                return 'gradeskipscale';
            }
        }

        if ($remoteitem->gradetype === GRADE_TYPE_VALUE && $remoteitem->grademax <= $remoteitem->grademin) {
            return 'gradeskiprange';
        }

        if ($gradeitem->is_locked()) {
            return 'gradeskipitemlocked';
        }

        return null;
    }

    /**
     * Write one grade and remember that this plugin wrote it.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param int $remotecmid
     * @param \grade_item $gradeitem
     * @param \stdClass $user
     * @param float|null $grade in local terms
     * @param \stdClass $remotegrade from grades_result
     * @param int $hidden 0, 1, or a time it is hidden until
     * @return bool false if the gradebook refused it
     */
    protected static function write(
        int $blockinstanceid,
        int $courseid,
        int $remotecmid,
        \grade_item $gradeitem,
        \stdClass $user,
        ?float $grade,
        \stdClass $remotegrade,
        int $hidden
    ): bool {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        // A feedback-only item has no grade to set; false leaves it alone.
        $finalgrade = (int) $gradeitem->gradetype === GRADE_TYPE_TEXT ? false : $grade;

        $written = $gradeitem->update_final_grade(
            $user->id,
            $finalgrade,
            self::SOURCE,
            $remotegrade->feedback,
            $remotegrade->feedbackformat
        );

        if (!$written) {
            $transaction->allow_commit();

            return false;
        }

        $stored = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $user->id]);

        // Hidden there, hidden here, so a pull never shows a student a grade
        // the source was keeping back. Set on every write, since the grade is
        // this plugin's until someone here changes it.
        if ((int) $stored->hidden !== $hidden) {
            $stored->grade_item = $gradeitem;
            $stored->set_hidden($hidden);
        }

        $row = (object) [
            'blockinstanceid' => $blockinstanceid,
            'courseid' => $courseid,
            'userid' => (int) $user->id,
            'gradeitemid' => (int) $gradeitem->id,
            'remotecmid' => $remotecmid,
            'itemnumber' => (int) $gradeitem->itemnumber,
            'finalgrade' => self::finalgrade($stored),
            'feedbackhash' => sha1((string) $stored->feedback),
            'remotetime' => $remotegrade->timemodified,
            'timepulled' => time(),
        ];

        $existing = $DB->get_record('block_coursesync_grade', [
            'gradeitemid' => $gradeitem->id,
            'userid' => $user->id,
        ]);

        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('block_coursesync_grade', $row);
        } else {
            $DB->insert_record('block_coursesync_grade', $row);
        }

        $transaction->allow_commit();

        return true;
    }

    /**
     * Put a source grade into this grade item's range.
     *
     * A scale grade is a position on the scale, which item_problem() has
     * already checked is the same scale, so it is used as it is.
     *
     * @param float|null $grade
     * @param \stdClass $remoteitem
     * @param \grade_item $gradeitem
     * @return float|null
     */
    protected static function convert(?float $grade, \stdClass $remoteitem, \grade_item $gradeitem): ?float {
        if ($grade === null || (int) $gradeitem->gradetype !== GRADE_TYPE_VALUE) {
            return (int) $gradeitem->gradetype === GRADE_TYPE_TEXT ? null : $grade;
        }

        $min = (float) $gradeitem->grademin;
        $max = (float) $gradeitem->grademax;

        if (!grade_floats_different($min, $remoteitem->grademin) && !grade_floats_different($max, $remoteitem->grademax)) {
            return $grade;
        }

        $scaled = $min + ($grade - $remoteitem->grademin) * ($max - $min) / ($remoteitem->grademax - $remoteitem->grademin);

        // The gradebook keeps five decimal places.
        return round($scaled, 5);
    }

    /**
     * Does a student have a grade here - a mark, or at least some feedback?
     *
     * @param \grade_grade|null $grade
     * @return bool
     */
    protected static function has_grade(?\grade_grade $grade): bool {
        return $grade !== null && ($grade->finalgrade !== null || trim((string) $grade->feedback) !== '');
    }

    /**
     * Does the grade here already say what the source says?
     *
     * @param \grade_grade $grade
     * @param float|null $incoming
     * @param string $feedback
     * @return bool
     */
    protected static function says(\grade_grade $grade, ?float $incoming, string $feedback): bool {
        return !grade_floats_different(self::finalgrade($grade), $incoming)
            && trim((string) $grade->feedback) === trim($feedback);
    }

    /**
     * Is a grade still exactly what an earlier pull wrote?
     *
     * @param \grade_grade $grade
     * @param \stdClass $pulled the block_coursesync_grade row
     * @return bool
     */
    protected static function untouched(\grade_grade $grade, \stdClass $pulled): bool {
        $then = $pulled->finalgrade === null ? null : (float) $pulled->finalgrade;

        return !grade_floats_different(self::finalgrade($grade), $then)
            && sha1((string) $grade->feedback) === $pulled->feedbackhash;
    }

    /**
     * A grade's final value as a number, or null.
     *
     * @param \grade_grade $grade
     * @return float|null
     */
    protected static function finalgrade(\grade_grade $grade): ?float {
        return $grade->finalgrade === null ? null : (float) $grade->finalgrade;
    }

    /**
     * Of the usernames the source sent that are not students here, which
     * belong to an account on this site at all - so a skipped student can be
     * reported as "not in this course" rather than "no such account".
     *
     * @param \stdClass[] $items from grades_result
     * @param \stdClass[] $students gradable users here, by username
     * @return bool[] username => true
     */
    protected static function usernames_known_here(array $items, array $students): array {
        global $CFG, $DB;

        $unmatched = [];

        foreach ($items as $item) {
            foreach ($item->grades as $grade) {
                if (!isset($students[$grade->username]) && $grade->username !== '') {
                    $unmatched[$grade->username] = true;
                }
            }
        }

        if (!$unmatched) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($unmatched), SQL_PARAMS_NAMED);
        $params['mnethostid'] = $CFG->mnet_localhost_id;
        $known = $DB->get_fieldset_select(
            'user',
            'username',
            "username {$insql} AND deleted = 0 AND mnethostid = :mnethostid",
            $params
        );

        return array_fill_keys($known, true);
    }

    /**
     * A fresh entry for the result.
     *
     * @param \cm_info $cm
     * @param int $itemnumber
     * @param \grade_item|null $gradeitem
     * @param string $outcome
     * @param string|null $reason
     * @return \stdClass
     */
    protected static function entry(
        \cm_info $cm,
        int $itemnumber,
        ?\grade_item $gradeitem,
        string $outcome,
        ?string $reason
    ): \stdClass {
        return (object) [
            'cmid' => (int) $cm->id,
            'activity' => $cm->name,
            'itemnumber' => $itemnumber,
            'gradeitemid' => $gradeitem ? (int) $gradeitem->id : 0,
            'userid' => 0,
            'username' => '',
            'outcome' => $outcome,
            'reason' => $reason,
            'grade' => null,
            'localgrade' => null,
        ];
    }
}
