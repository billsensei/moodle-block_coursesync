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
 * The outcome of asking the remote site for students' grades.
 *
 * Every value in here has already been cleaned where it arrived, in
 * from_response(). Usernames and feedback are still remote data, and anything
 * showing them must escape them as usual.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grades_result {
    /** @var bool Whether the remote site answered. */
    public readonly bool $success;

    /** @var string|null Language string identifier describing the failure. */
    public readonly ?string $errorkey;

    /**
     * @var \stdClass[] Grade items: cmid, itemnumber, gradetype, grademin,
     *      grademax, scale (string[]), hidden, grades (\stdClass[]: username,
     *      grade, feedback, feedbackformat, hidden, timemodified).
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
     * Build the result from block_coursesync_get_grades' decoded response,
     * cleaning every value on the way in.
     *
     * @param mixed $data
     * @return self failure('errorbadresponse') if it is not the expected shape
     */
    public static function from_response($data): self {
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return self::failure('errorbadresponse');
        }

        $items = [];

        foreach ($data['items'] as $row) {
            if (!is_array($row) || !isset($row['cmid'], $row['gradetype']) || !is_array($row['grades'] ?? null)) {
                return self::failure('errorbadresponse');
            }

            $grades = [];

            foreach ($row['grades'] as $grade) {
                if (!is_array($grade) || !isset($grade['username'])) {
                    return self::failure('errorbadresponse');
                }

                $format = clean_param($grade['feedbackformat'] ?? FORMAT_MOODLE, PARAM_INT);

                if (!in_array($format, [FORMAT_MOODLE, FORMAT_HTML, FORMAT_PLAIN, FORMAT_MARKDOWN])) {
                    $format = FORMAT_MOODLE;
                }

                $grades[] = (object) [
                    // Cleaned to what this site allows in a username. One that
                    // cleaning changes cannot be a username here anyway, so it
                    // simply matches nobody.
                    'username' => clean_param((string) $grade['username'], PARAM_USERNAME),
                    'grade' => isset($grade['grade']) && is_numeric($grade['grade']) ? (float) $grade['grade'] : null,
                    'feedback' => clean_text((string) ($grade['feedback'] ?? ''), $format),
                    'feedbackformat' => $format,
                    'hidden' => max(0, clean_param($grade['hidden'] ?? 0, PARAM_INT)),
                    'timemodified' => max(0, clean_param($grade['timemodified'] ?? 0, PARAM_INT)),
                ];
            }

            $scale = trim((string) ($row['scale'] ?? ''));

            $items[] = (object) [
                'cmid' => clean_param($row['cmid'], PARAM_INT),
                'itemnumber' => clean_param($row['itemnumber'] ?? 0, PARAM_INT),
                'gradetype' => clean_param($row['gradetype'], PARAM_INT),
                'grademin' => (float) clean_param($row['grademin'] ?? 0, PARAM_FLOAT),
                'grademax' => (float) clean_param($row['grademax'] ?? 0, PARAM_FLOAT),
                'scale' => $scale === '' ? [] : self::scale_items($scale),
                'hidden' => max(0, clean_param($row['hidden'] ?? 0, PARAM_INT)),
                'grades' => $grades,
            ];
        }

        return self::success($items);
    }

    /**
     * A scale's items as a list, the way a scale is compared between sites.
     *
     * @param string $scale comma-separated, as stored in the scale table
     * @return string[]
     */
    public static function scale_items(string $scale): array {
        return array_map(
            static fn(string $item): string => clean_param(trim($item), PARAM_TEXT),
            explode(',', $scale)
        );
    }
}
