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

/**
 * What both ends of an assignment marks pull agree on.
 *
 * A mark is what a teacher gave one student for one attempt at an assignment:
 * the number, and the written comment (with any files embedded in it). The
 * source reads it and describes it; the destination writes it back as a real
 * mod_assign grade. Both need the same idea of which mark counts and what
 * makes one mark different from another, so those live here once.
 *
 * The mark that counts is the one for the attempt the submission pull carries
 * - the student's latest submitted attempt - or, for a student who has handed
 * nothing in, their latest attempt. Only point grades travel (not scales, and
 * not an assignment that is not graded), and only the comments feedback
 * plugin. Who graded is not carried: the grade here is given by the person who
 * pulled it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marks {
    /** @var string Component of the feedback comments plugin. */
    public const COMPONENT = 'assignfeedback_comments';

    /** @var string File area for files embedded in a comment. */
    public const AREA = 'feedback';

    /** @var string The marking workflow state in which a mark is final. */
    public const STATE_RELEASED = 'released';

    /** @var int The decimal places a mark is compared at (the gradebook keeps five). */
    public const PRECISION = 5;

    /**
     * Is the comments feedback plugin switched on for an assignment?
     *
     * @param int $assignid
     * @return bool
     */
    public static function comments_enabled(int $assignid): bool {
        global $DB;

        return (bool) $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assignid,
            'plugin' => 'comments',
            'subtype' => 'assignfeedback',
            'name' => 'enabled',
        ]);
    }

    /**
     * Why an assignment's marks cannot travel, if they cannot.
     *
     * @param \stdClass $assign the assign row (id, grade, teamsubmission)
     * @return string|null a reason (team, scale, nograde), or null if they can
     */
    public static function assignment_problem(\stdClass $assign): ?string {
        if ($assign->teamsubmission) {
            return 'team';
        }

        if ((float) $assign->grade < 0) {
            return 'scale';
        }

        if ((float) $assign->grade == 0) {
            return 'nograde';
        }

        return null;
    }

    /**
     * The mark that counts for a student, if there is one.
     *
     * @param \stdClass $assign the assign row (id, markingworkflow)
     * @param int $userid
     * @return \stdClass|null the assign_grades row, or null if the student has
     *      no mark and no comment, or the mark is not yet released
     */
    public static function mark_for(\stdClass $assign, int $userid): ?\stdClass {
        global $DB;

        $submission = submissions::latest_submitted((int) $assign->id, $userid);
        $by = ['assignment' => $assign->id, 'userid' => $userid];

        if ($submission) {
            $by['attemptnumber'] = $submission->attemptnumber;
        }

        $rows = $DB->get_records('assign_grades', $by, 'attemptnumber DESC', '*', 0, 1);
        $grade = $rows ? reset($rows) : null;

        if (!$grade) {
            return null;
        }

        // Where a workflow decides when a mark is final, only a released mark
        // is the student's mark: the source's own gradebook shows nothing else.
        if ($assign->markingworkflow) {
            $state = (string) $DB->get_field('assign_user_flags', 'workflowstate', [
                'assignment' => $assign->id,
                'userid' => $userid,
            ]);

            if ($state !== self::STATE_RELEASED) {
                return null;
            }
        }

        return $grade;
    }

    /**
     * Is a grade row a mark - a number given - rather than just a placeholder?
     *
     * @param \stdClass $grade the assign_grades row
     * @return bool
     */
    public static function is_marked(\stdClass $grade): bool {
        return $grade->grade !== null && (float) $grade->grade >= 0;
    }

    /**
     * The comment on a mark: its text, format and embedded files.
     *
     * @param \context_module $context the assignment's context
     * @param int $gradeid the assign_grades id
     * @param int $assignid
     * @return array [text (string|null), format, files]. A file is area,
     *      filepath, filename, filesize, contenthash, timemodified.
     */
    public static function comment(\context_module $context, int $gradeid, int $assignid): array {
        global $DB;

        if (!self::comments_enabled($assignid)) {
            return [null, FORMAT_HTML, []];
        }

        $row = $DB->get_record('assignfeedback_comments', ['grade' => $gradeid]);

        if (!$row || trim((string) $row->commenttext) === '') {
            return [null, FORMAT_HTML, []];
        }

        return [(string) $row->commenttext, (int) $row->commentformat, self::files_in($context, $gradeid)];
    }

    /**
     * The files embedded in one comment, in a fixed order.
     *
     * @param \context_module $context
     * @param int $gradeid
     * @return array[]
     */
    public static function files_in(\context_module $context, int $gradeid): array {
        $stored = get_file_storage()->get_area_files(
            $context->id,
            self::COMPONENT,
            self::AREA,
            $gradeid,
            'filepath, filename',
            false
        );
        $files = [];

        foreach ($stored as $file) {
            $files[] = [
                'area' => self::AREA,
                'filepath' => $file->get_filepath(),
                'filename' => $file->get_filename(),
                'filesize' => (int) $file->get_filesize(),
                'contenthash' => $file->get_contenthash(),
                'timemodified' => (int) $file->get_timemodified(),
            ];
        }

        return $files;
    }

    /**
     * A short stand-in for a mark: the same mark and comment always give the
     * same value, and any change to the number, the text or a file gives a
     * different one.
     *
     * @param float|null $grade the number, null if none was given
     * @param string|null $text comment text, null if there is none
     * @param int $format the text's format
     * @param array[] $files as returned by comment()
     * @return string SHA-1
     */
    public static function fingerprint(?float $grade, ?string $text, int $format, array $files): string {
        $parts = [];

        foreach ($files as $file) {
            $parts[] = [(string) $file['filepath'], (string) $file['filename'], (string) $file['contenthash']];
        }

        sort($parts);

        return sha1(json_encode([
            $grade === null ? null : sprintf('%.' . self::PRECISION . 'F', $grade),
            $text === null ? null : (string) $text,
            $text === null ? 0 : $format,
            $parts,
        ]));
    }

    /**
     * Put a mark from the source into this assignment's range.
     *
     * @param float $grade the mark on the source
     * @param float $sourcemax what the source's assignment is out of
     * @param float $localmax what this one is out of
     * @return float
     */
    public static function convert(float $grade, float $sourcemax, float $localmax): float {
        if (!grade_floats_different($sourcemax, $localmax)) {
            return round($grade, self::PRECISION);
        }

        return round($grade * $localmax / $sourcemax, self::PRECISION);
    }

    /**
     * The checks every source-side marks function starts with, in order: the
     * site's switch, then the two permissions.
     *
     * Checked before anything else so a site that does not share marks gives
     * every caller the same answer and nothing about its courses.
     *
     * @param int $courseid
     * @return \stdClass the course
     * @throws \moodle_exception
     */
    public static function require_export(int $courseid): \stdClass {
        global $DB;

        if (!get_config('block_coursesync', 'allowmarksexport')) {
            throw new \moodle_exception('errormarksexportdisabled', 'block_coursesync');
        }

        $course = $DB->get_record('course', ['id' => $courseid]);

        if (!$course) {
            throw new \moodle_exception('errorcoursenotfound', 'block_coursesync');
        }

        $context = \context_course::instance($course->id);
        require_capability('block/coursesync:sync', $context);
        require_capability('block/coursesync:exportmarks', $context);

        return $course;
    }
}
