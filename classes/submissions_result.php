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
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submissions_result {
    /** @var string[] The reasons a source may give for not describing an assignment. */
    public const REASONS = ['team', 'notassign', 'noplugins'];

    /** @var int Most attempts one student may have in an answer; a longer history is not believed. */
    public const MAX_ATTEMPTS = 100;

    /** @var bool Whether the remote site answered. */
    public readonly bool $success;

    /** @var string|null Language string identifier describing the failure. */
    public readonly ?string $errorkey;

    /**
     * @var \stdClass[] Assignments: cmid, reason, plugins (string[]),
     *      submissions (\stdClass[]: username, attemptnumber, timecreated,
     *      timemodified, onlinetext (string|null), onlineformat, fingerprint,
     *      files (array[]: area, filepath, filename, filesize, contenthash,
     *      timemodified), and attempts: null unless every attempt was asked for,
     *      then each attempt handed in, oldest first, in the same shape
     *      with the username added)).
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
     * @param bool $allattempts every attempt was asked for, so every student must come with them
     * @return self failure('errorbadresponse') if it is not the expected shape
     */
    public static function from_response($data, bool $allattempts = false): self {
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
                $clean = self::clean_submission($submission, $allattempts);

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
     * An assignment answered in several batches, for different students,
     * becomes one item holding all of them; a student held already is not
     * added again.
     *
     * @param self[] $answers
     * @return self
     */
    public static function merge(array $answers): self {
        $items = [];
        $seen = [];

        foreach ($answers as $answer) {
            foreach ($answer->items as $item) {
                if (!isset($items[$item->cmid])) {
                    $items[$item->cmid] = $item;
                    $seen[$item->cmid] = array_column($item->submissions, 'username', 'username');
                    continue;
                }

                // The same assignment answered again, for other students. A
                // student already held is not added twice.
                foreach ($item->submissions as $row) {
                    if (!isset($seen[$item->cmid][$row->username])) {
                        $seen[$item->cmid][$row->username] = $row->username;
                        $items[$item->cmid]->submissions[] = $row;
                    }
                }
            }
        }

        return self::success(array_values($items));
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
     * @param bool $allattempts every attempt was asked for
     * @return \stdClass|null null if it is not the expected shape
     */
    protected static function clean_submission($submission, bool $allattempts = false): ?\stdClass {
        $attempt = self::clean_attempt($submission);

        if ($attempt === null || !isset($submission['username'])) {
            return null;
        }

        $attempt->username = clean_param((string) $submission['username'], PARAM_USERNAME);
        $attempt->attempts = null;

        if (!$allattempts) {
            return $attempt;
        }

        // Asked for, so required: a student without the list is not described
        // as having one attempt, which would be believed to be all of them.
        $list = $submission['attempts'] ?? null;

        if (!is_array($list) || !$list || count($list) > self::MAX_ATTEMPTS) {
            return null;
        }

        $attempts = [];
        $previous = -1;

        foreach ($list as $row) {
            $one = self::clean_attempt($row);

            // Each attempt as clean as the latest, one number once, and in
            // order: two rows for one attempt would be written as two.
            if ($one === null || $one->attemptnumber <= $previous) {
                return null;
            }

            $previous = $one->attemptnumber;
            $one->username = $attempt->username;
            $attempts[] = $one;
        }

        $attempt->attempts = $attempts;

        return $attempt;
    }

    /**
     * One attempt's description, cleaned: what is the same at the top of a
     * student's entry and in each of their attempts.
     *
     * @param mixed $submission
     * @return \stdClass|null null if it is not the expected shape (no username: the caller adds it)
     */
    protected static function clean_attempt($submission): ?\stdClass {
        if (
            !is_array($submission) || !isset($submission['fingerprint'])
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
        // Markdown comes in as cleaned HTML, and is stored as such.
        $text = null;

        if (($submission['onlinetext'] ?? null) !== null) {
            [$text, $format] = activity_payload::clean_formatted((string) $submission['onlinetext'], $format);
        }

        return (object) [
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
