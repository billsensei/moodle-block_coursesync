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

use block_coursesync\local\submissions;

/**
 * The outcome of asking the remote site what students have handed in.
 *
 * Every value has been cleaned where it arrived, in from_response(). A
 * submission's text is still remote data and is stored exactly as the source
 * holds it, as mod_assign stores what a student types; anything that shows it
 * must go through format_text(), as mod_assign does.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submissions_result {
    /** @var string[] The reasons a source may give for not describing an assignment. */
    public const REASONS = ['team', 'notassign', 'noplugins'];

    /** @var bool Whether the remote site answered. */
    public readonly bool $success;

    /** @var string|null Language string identifier describing the failure. */
    public readonly ?string $errorkey;

    /**
     * @var \stdClass[] Assignments: cmid, reason, plugins (string[]),
     *      submissions (\stdClass[]: username, attemptnumber, timecreated,
     *      timemodified, onlinetext (string|null), onlineformat, fingerprint,
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
     * Build the result from block_coursesync_get_submissions' decoded
     * response, cleaning every value on the way in.
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
            if (!is_array($row) || !isset($row['cmid']) || !is_array($row['submissions'] ?? null)) {
                return self::failure('errorbadresponse');
            }

            $submissionrows = [];

            foreach ($row['submissions'] as $submission) {
                $clean = self::clean_submission($submission);

                if ($clean === null) {
                    return self::failure('errorbadresponse');
                }

                $submissionrows[] = $clean;
            }

            $items[] = (object) [
                'cmid' => (int) $row['cmid'],
                'reason' => self::known_reason((string) ($row['reason'] ?? '')),
                'plugins' => array_values(array_intersect(
                    array_map('strval', (array) ($row['plugins'] ?? [])),
                    submissions::PLUGINS
                )),
                'submissions' => $submissionrows,
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
     * A reason the source gave, kept only if this version knows it.
     *
     * The reason is turned into a language string identifier, so one that is
     * not on the list (a newer source's, or a made-up one) becomes "unknown".
     *
     * @param string $reason
     * @return string empty for none, a REASONS value, or "unknown"
     */
    protected static function known_reason(string $reason): string {
        $reason = clean_param($reason, PARAM_ALPHA);

        return $reason === '' || in_array($reason, self::REASONS, true) ? $reason : 'unknown';
    }

    /**
     * One student's submission, cleaned.
     *
     * @param mixed $submission
     * @return \stdClass|null null if it is not the expected shape
     */
    protected static function clean_submission($submission): ?\stdClass {
        if (
            !is_array($submission) || !isset($submission['username'], $submission['fingerprint'])
                || !is_array($submission['files'] ?? null)
        ) {
            return null;
        }

        $files = [];

        foreach ($submission['files'] as $file) {
            $clean = self::clean_file($file);

            // A file that cannot be described cleanly is never fetched.
            if ($clean === null) {
                return null;
            }

            $files[] = $clean;
        }

        $format = (int) ($submission['onlineformat'] ?? FORMAT_HTML);

        if (!in_array($format, [FORMAT_MOODLE, FORMAT_HTML, FORMAT_PLAIN, FORMAT_MARKDOWN], true)) {
            $format = FORMAT_HTML;
        }

        // Cleaned here, like every other text from the other site, so nothing
        // downstream has to remember to. What is compared with work already
        // here is worked out from this cleaned text (see submission_pull).
        $text = ($submission['onlinetext'] ?? null) === null
            ? null
            : activity_payload::clean_html((string) $submission['onlinetext'], $format);

        return (object) [
            'username' => clean_param((string) $submission['username'], PARAM_USERNAME),
            'attemptnumber' => max(0, (int) ($submission['attemptnumber'] ?? 0)),
            'timecreated' => max(0, (int) ($submission['timecreated'] ?? 0)),
            'timemodified' => max(0, (int) ($submission['timemodified'] ?? 0)),
            'onlinetext' => $text,
            'onlineformat' => $format,
            'files' => $files,
            'fingerprint' => clean_param((string) $submission['fingerprint'], PARAM_ALPHANUM),
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

        $area = (string) $file['area'];
        $path = clean_param((string) $file['filepath'], PARAM_PATH);
        $name = clean_param((string) $file['filename'], PARAM_FILE);
        $hash = clean_param((string) $file['contenthash'], PARAM_ALPHANUM);

        // Only the two areas carried; a path and a name that cleaning left
        // exactly as they were (anything it had to change was not an honest
        // description, and is refused rather than quietly mended); a path
        // that is a plain folder path, starting and ending with a slash; and
        // a SHA-1.
        if (
            !isset(submissions::AREAS[$area]) || $name === '' || $name !== (string) $file['filename']
                || $path !== (string) $file['filepath'] || $path === '' || $path[0] !== '/' || substr($path, -1) !== '/'
                || str_contains($path, '..') || $hash !== (string) $file['contenthash'] || strlen($hash) !== 40
        ) {
            return null;
        }

        return [
            'area' => $area,
            'filepath' => $path,
            'filename' => $name,
            'filesize' => max(0, (int) ($file['filesize'] ?? 0)),
            'contenthash' => $hash,
            'timemodified' => max(0, (int) ($file['timemodified'] ?? 0)),
        ];
    }
}
