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

use block_coursesync\local\marks;

/**
 * The outcome of asking the remote site what marks and feedback teachers gave.
 *
 * Every value has been cleaned where it arrived, in from_response(). A
 * comment is still remote data and is stored exactly as the source holds it,
 * as mod_assign stores what a teacher types; anything that shows it must go
 * through format_text(), as mod_assign does.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marks_result {
    /** @var bool Whether the remote site answered. */
    public readonly bool $success;

    /** @var string|null Language string identifier describing the failure. */
    public readonly ?string $errorkey;

    /**
     * @var \stdClass[] Assignments: cmid, reason, grademax (float), marks
     *      (\stdClass[]: username, attemptnumber, timemodified, grade
     *      (float|null), comment (string|null), commentformat, fingerprint,
     *      files (array[]: area, filepath, filename, filesize, contenthash,
     *      timemodified)).
     */
    public readonly array $items;

    /**
     * Use the success() and failure() factories instead.
     *
     * @param bool $success
     * @param string|null $errorkey
     * @param \stdClass[] $items
     */
    protected function __construct(bool $success, ?string $errorkey, array $items) {
        $this->success = $success;
        $this->errorkey = $errorkey;
        $this->items = $items;
    }

    /**
     * The remote site answered.
     *
     * @param \stdClass[] $items
     * @return self
     */
    public static function success(array $items): self {
        return new self(true, null, $items);
    }

    /**
     * The call did not succeed.
     *
     * @param string $errorkey a language string identifier in block_coursesync
     * @return self
     */
    public static function failure(string $errorkey): self {
        return new self(false, $errorkey, []);
    }

    /**
     * Build the result from block_coursesync_get_marks' decoded response,
     * cleaning every value on the way in.
     *
     * @param mixed $data
     * @return self failure('errorbadresponse') if it is not the expected shape
     */
    public static function from_response($data): self {
        if (!is_array($data) || !is_array($data['items'] ?? null)) {
            return self::failure('errorbadresponse');
        }

        $items = [];

        foreach ($data['items'] as $row) {
            if (!is_array($row) || !isset($row['cmid']) || !is_array($row['marks'] ?? null)) {
                return self::failure('errorbadresponse');
            }

            $markrows = [];

            foreach ($row['marks'] as $mark) {
                $clean = self::clean_mark($mark);

                if ($clean === null) {
                    return self::failure('errorbadresponse');
                }

                $markrows[] = $clean;
            }

            // A maximum that is not a finite number, or is below nothing, is not
            // one a mark could be scaled by. (Zero is fine: a source that has
            // no grade says why instead, and the pull refuses to scale by it.)
            $grademax = (float) ($row['grademax'] ?? 0);

            if (!is_finite($grademax) || $grademax < 0) {
                return self::failure('errorbadresponse');
            }

            $items[] = (object) [
                'cmid' => (int) $row['cmid'],
                'reason' => clean_param((string) ($row['reason'] ?? ''), PARAM_ALPHA),
                'grademax' => $grademax,
                'marks' => $markrows,
            ];
        }

        return self::success($items);
    }

    /**
     * Several successful answers, for batches of one request, as one.
     *
     * @param self[] $answers
     * @return self
     */
    public static function merge(array $answers): self {
        $items = [];

        foreach ($answers as $answer) {
            $items = array_merge($items, $answer->items);
        }

        return self::success($items);
    }

    /**
     * One student's mark, cleaned.
     *
     * @param mixed $mark
     * @return \stdClass|null null if it is not the expected shape
     */
    protected static function clean_mark($mark): ?\stdClass {
        if (!is_array($mark) || !isset($mark['username'], $mark['fingerprint']) || !is_array($mark['files'] ?? null)) {
            return null;
        }

        $files = [];

        foreach ($mark['files'] as $file) {
            $clean = self::clean_file($file);

            // A file that cannot be described cleanly is never fetched.
            if ($clean === null) {
                return null;
            }

            $files[] = $clean;
        }

        $format = (int) ($mark['commentformat'] ?? FORMAT_HTML);

        if (!in_array($format, [FORMAT_MOODLE, FORMAT_HTML, FORMAT_PLAIN, FORMAT_MARKDOWN], true)) {
            $format = FORMAT_HTML;
        }

        $grade = $mark['grade'] ?? null;

        // A finite number, or nothing. Anything else is not a mark ("1e999"
        // is numeric, and arrives as infinity).
        if ($grade !== null && (!is_numeric($grade) || !is_finite((float) $grade))) {
            return null;
        }

        return (object) [
            'username' => clean_param((string) $mark['username'], PARAM_USERNAME),
            'attemptnumber' => max(0, (int) ($mark['attemptnumber'] ?? 0)),
            'timemodified' => max(0, (int) ($mark['timemodified'] ?? 0)),
            'grade' => $grade === null ? null : (float) $grade,
            'comment' => ($mark['comment'] ?? null) === null
                ? null
                : activity_payload::clean_html((string) $mark['comment'], $format),
            'commentformat' => $format,
            'files' => $files,
            'fingerprint' => clean_param((string) $mark['fingerprint'], PARAM_ALPHANUM),
        ];
    }

    /**
     * One file's description, cleaned.
     *
     * @param mixed $file
     * @return array|null null if it is not the expected shape, or names somewhere this site does not put files
     */
    protected static function clean_file($file): ?array {
        if (!is_array($file) || !isset($file['area'], $file['filepath'], $file['filename'], $file['contenthash'])) {
            return null;
        }

        $path = clean_param((string) $file['filepath'], PARAM_PATH);
        $name = clean_param((string) $file['filename'], PARAM_FILE);
        $hash = clean_param((string) $file['contenthash'], PARAM_ALPHANUM);

        // Only the one area carried; a path and a name that cleaning left
        // exactly as they were; a plain folder path, starting and ending with
        // a slash; and a SHA-1.
        if (
            (string) $file['area'] !== marks::AREA || $name === '' || $name !== (string) $file['filename']
                || $path !== (string) $file['filepath'] || $path === '' || $path[0] !== '/' || substr($path, -1) !== '/'
                || str_contains($path, '..') || $hash !== (string) $file['contenthash'] || strlen($hash) !== 40
        ) {
            return null;
        }

        return [
            'area' => marks::AREA,
            'filepath' => $path,
            'filename' => $name,
            'filesize' => max(0, (int) ($file['filesize'] ?? 0)),
            'contenthash' => $hash,
            'timemodified' => max(0, (int) ($file['timemodified'] ?? 0)),
        ];
    }
}
