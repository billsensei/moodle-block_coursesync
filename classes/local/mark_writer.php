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

namespace block_coursesync\local;

use block_coursesync\file_chunk;
use block_coursesync\grade_pull;
use block_coursesync\remote_client;
use core\http_client;

/**
 * Writes one student's mark and written feedback as a real mod_assign grade.
 *
 * This is the part of a marks pull that changes anything, kept apart from the
 * part that decides what to do (marks_pull). The grade goes in through
 * mod_assign's own update_grade(), so the grader screen, the gradebook and the
 * student's view all agree; the comments plugin's row and its files are
 * written the way mod_assign's restore does.
 *
 * The person who pulled is recorded as the grader. Who graded on the other
 * site is not carried: it names a teacher there, who is not necessarily a user
 * here.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mark_writer {
    /**
     * Fetch every file embedded in a mark's comment into temporary files.
     *
     * @param \stdClass $record the block's connection
     * @param string $token
     * @param int $remotecmid
     * @param \stdClass $mark
     * @param http_client|null $client
     * @return string[] temporary path for each file, in the same order as the mark's files
     * @throws \moodle_exception if any file cannot be fetched or fails its check
     */
    public static function fetch_files(
        \stdClass $record,
        string $token,
        int $remotecmid,
        \stdClass $mark,
        ?http_client $client
    ): array {
        $paths = [];

        try {
            foreach ($mark->files as $file) {
                $paths[] = file_sync::download(
                    static fn(int $offset): file_chunk => remote_client::get_mark_file(
                        $record->remoteurl,
                        $token,
                        $remotecmid,
                        $mark->username,
                        $file['filepath'],
                        $file['filename'],
                        $offset,
                        file_sync::CHUNK,
                        $client
                    ),
                    $file['contenthash']
                );
            }
        } catch (\moodle_exception $e) {
            foreach ($paths as $path) {
                @unlink($path);
            }

            throw $e;
        }

        return $paths;
    }

    /**
     * The grade here that a mark would go to or already has: the row for the
     * student's latest attempt, or the latest grade row if they have no
     * submission.
     *
     * @param int $assignid
     * @param int $userid
     * @return \stdClass|null the assign_grades row
     */
    public static function local_grade(int $assignid, int $userid): ?\stdClass {
        global $DB;

        $by = ['assignment' => $assignid, 'userid' => $userid, 'groupid' => 0, 'latest' => 1];
        $submissions = $DB->get_records('assign_submission', $by, 'attemptnumber DESC', 'id, attemptnumber', 0, 1);
        $where = ['assignment' => $assignid, 'userid' => $userid];

        if ($submissions) {
            $where['attemptnumber'] = reset($submissions)->attemptnumber;
        }

        $rows = $DB->get_records('assign_grades', $where, 'attemptnumber DESC', '*', 0, 1);

        return $rows ? reset($rows) : null;
    }

    /**
     * Is there a mark here - a number given, or a comment written?
     *
     * @param \context_module $context
     * @param \stdClass|null $grade the assign_grades row
     * @param int $assignid
     * @return bool
     */
    public static function has_mark(\context_module $context, ?\stdClass $grade, int $assignid): bool {
        if (!$grade) {
            return false;
        }

        return marks::is_marked($grade) || marks::comment($context, (int) $grade->id, $assignid)[0] !== null;
    }

    /**
     * The fingerprint of what a grade here holds.
     *
     * @param \context_module $context
     * @param \stdClass $grade the assign_grades row
     * @param int $assignid
     * @return string
     */
    public static function fingerprint_here(\context_module $context, \stdClass $grade, int $assignid): string {
        [$text, $format, $files] = marks::comment($context, (int) $grade->id, $assignid);

        return marks::fingerprint(marks::is_marked($grade) ? (float) $grade->grade : null, $text, $format, $files);
    }

    /**
     * Is the student's gradebook grade for an assignment locked?
     *
     * @param \stdClass $course
     * @param int $assignid
     * @param int $userid
     * @return bool
     */
    public static function gradebook_locked(\stdClass $course, int $assignid, int $userid): bool {
        $item = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assignid,
            'itemnumber' => 0,
        ]);

        if (!$item) {
            return false;
        }

        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);

        return $item->is_locked() || ($grade && $grade->is_locked());
    }

    /**
     * The gradebook override a grade sync wrote for this student, if one is
     * there and nobody has touched it since.
     *
     * A marks pull takes over from it: the assignment now has the real grade,
     * so the override that stood in for it is taken away.
     *
     * @param \stdClass $course
     * @param int $assignid
     * @param int $userid
     * @return array|null [grade_item, grade_grade, block_coursesync_grade row], or null if there is none to release
     */
    public static function releasable_override(\stdClass $course, int $assignid, int $userid): ?array {
        global $DB;

        $item = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assignid,
            'itemnumber' => 0,
        ]);

        if (!$item) {
            return null;
        }

        $pulled = $DB->get_record('block_coursesync_grade', ['gradeitemid' => $item->id, 'userid' => $userid]);
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);

        if ($pulled && $grade && $grade->is_overridden() && !$grade->is_locked() && grade_pull::untouched($grade, $pulled)) {
            return [$item, $grade, $pulled];
        }

        return null;
    }

    /**
     * Write one mark, and the record of having written it.
     *
     * @param \stdClass $run what the whole pull shares: course, blockinstanceid
     * @param \cm_info $cm the assignment here
     * @param \stdClass $mark what the source described
     * @param float|null $grade the mark in this assignment's terms, null for a comment only
     * @param \stdClass $student
     * @param int $remotecmid
     * @param \stdClass|false $ledger the record of an earlier pull, if any
     * @param string[] $paths the fetched files, in the mark's order
     * @return bool whether a grade sync's override was taken away
     * @throws \moodle_exception if mod_assign refuses the grade
     */
    public static function write(
        \stdClass $run,
        \cm_info $cm,
        \stdClass $mark,
        ?float $grade,
        \stdClass $student,
        int $remotecmid,
        $ledger,
        array $paths
    ): bool {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $assignid = (int) $cm->instance;
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $run->course);
        $released = false;
        $createdfiles = null;
        $transaction = $DB->start_delegated_transaction();

        try {
            // Makes the student's submission row too if they have none - as
            // marking someone who has handed nothing in does.
            $row = $assign->get_user_grade((int) $student->id, true);
            $createdfiles = (int) $row->id;

            self::write_comment($context, $row, $mark, $student, $assignid, $paths);

            $row->grade = $grade === null ? -1 : $grade;
            $row->grader = $USER->id;

            if (!$assign->update_grade($row)) {
                throw new \moodle_exception('errormarkrefused', 'block_coursesync');
            }

            self::release_workflow($assign, $assignid, (int) $student->id);
            self::save_record($context, $row, $mark, $student, $run, $assignid, $remotecmid, $ledger);

            // After the grade is in: releasing makes the gradebook ask the
            // assignment for its own grade, which must be there to be asked for.
            $released = self::release_override($run->course, $assignid, (int) $student->id);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The rows roll back; files do not.
            if ($createdfiles !== null && !$ledger) {
                get_file_storage()->delete_area_files($context->id, marks::COMPONENT, marks::AREA, $createdfiles);
            }

            $transaction->rollback($e);
        }

        return $released;
    }

    /**
     * Put the comment's row and files where the comments plugin keeps them.
     *
     * @param \context_module $context
     * @param \stdClass $row the assign_grades row
     * @param \stdClass $mark what the source described
     * @param \stdClass $student
     * @param int $assignid
     * @param string[] $paths the fetched files, in the mark's order
     * @return void
     */
    protected static function write_comment(
        \context_module $context,
        \stdClass $row,
        \stdClass $mark,
        \stdClass $student,
        int $assignid,
        array $paths
    ): void {
        global $DB;

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, marks::COMPONENT, marks::AREA, $row->id);
        $DB->delete_records('assignfeedback_comments', ['grade' => $row->id]);

        if ($mark->comment === null) {
            return;
        }

        $DB->insert_record('assignfeedback_comments', (object) [
            'assignment' => $assignid,
            'grade' => $row->id,
            'commenttext' => $mark->comment,
            'commentformat' => $mark->commentformat,
        ]);

        foreach ($mark->files as $index => $file) {
            $fs->create_file_from_pathname((object) [
                'contextid' => $context->id,
                'component' => marks::COMPONENT,
                'filearea' => marks::AREA,
                'itemid' => $row->id,
                'filepath' => $file['filepath'],
                'filename' => $file['filename'],
                'userid' => $student->id,
                'timecreated' => $row->timecreated,
                'timemodified' => $file['timemodified'] ?: $mark->timemodified,
            ], $paths[$index]);
        }
    }

    /**
     * Where a marking workflow decides when a mark is final, a pulled mark is
     * final: the source only reports marks that were released.
     *
     * @param \assign $assign
     * @param int $assignid
     * @param int $userid
     * @return void
     */
    protected static function release_workflow(\assign $assign, int $assignid, int $userid): void {
        if (!$assign->get_instance()->markingworkflow) {
            return;
        }

        $flags = $assign->get_user_flags($userid, true);
        $flags->workflowstate = marks::STATE_RELEASED;
        $assign->update_user_flags($flags);
    }

    /**
     * Take away the override a grade sync wrote, if it is still as it was
     * written, so the assignment's own grade shows.
     *
     * @param \stdClass $course
     * @param int $assignid
     * @param int $userid
     * @return bool whether there was one
     */
    protected static function release_override(\stdClass $course, int $assignid, int $userid): bool {
        $found = self::releasable_override($course, $assignid, $userid);

        if (!$found) {
            return false;
        }

        grade_pull::release(...$found);

        return true;
    }

    /**
     * Record that the mark was brought across: what the source held and what
     * is here now, so a later pull can tell either changing.
     *
     * @param \context_module $context
     * @param \stdClass $row the assign_grades row, as saved
     * @param \stdClass $mark what the source described
     * @param \stdClass $student
     * @param \stdClass $run
     * @param int $assignid
     * @param int $remotecmid
     * @param \stdClass|false $ledger the record of an earlier pull, if any
     * @return void
     */
    protected static function save_record(
        \context_module $context,
        \stdClass $row,
        \stdClass $mark,
        \stdClass $student,
        \stdClass $run,
        int $assignid,
        int $remotecmid,
        $ledger
    ): void {
        global $DB;

        $saved = $DB->get_record('assign_grades', ['id' => $row->id], '*', MUST_EXIST);
        $record = (object) [
            'blockinstanceid' => $run->blockinstanceid,
            'courseid' => $run->course->id,
            'userid' => $student->id,
            'assignid' => $assignid,
            'gradeid' => $saved->id,
            'remotecmid' => $remotecmid,
            'remoteattempt' => $mark->attemptnumber,
            'fingerprint' => $mark->fingerprint,
            'localfingerprint' => self::fingerprint_here($context, $saved, $assignid),
            'remotetime' => $mark->timemodified,
            'timeimported' => time(),
        ];

        if ($ledger) {
            $record->id = $ledger->id;
            $DB->update_record('block_coursesync_mark', $record);
        } else {
            $DB->insert_record('block_coursesync_mark', $record);
        }
    }
}
