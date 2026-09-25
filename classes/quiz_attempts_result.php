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
 * The outcome of asking the remote site for students' quiz attempts.
 *
 * Every value has been cleaned where it arrived, in from_response(). A
 * later format version may carry more; anything this version does not know
 * about is ignored rather than refused.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_attempts_result {
    /** @var bool Whether the remote site answered. */
    public readonly bool $success;

    /** @var string|null Language string identifier describing the failure. */
    public readonly ?string $errorkey;

    /** @var int The format version the source used. */
    public readonly int $formatversion;

    /**
     * @var \stdClass[] Quizzes: cmid, sumgrades, grade, slots (\stdClass[]: slot,
     *      page, maxmark, qtype), attempts (\stdClass[]: id, username, attempt,
     *      timestart, timefinish, sumgrades, marks (\stdClass[] by slot: slot,
     *      maxmark, mark, state)).
     */
    public readonly array $quizzes;

    /**
     * Use the success() and failure() factories instead.
     *
     * @param bool $success
     * @param string|null $errorkey
     * @param int $formatversion
     * @param \stdClass[] $quizzes
     */
    protected function __construct(bool $success, ?string $errorkey, int $formatversion, array $quizzes) {
        $this->success = $success;
        $this->errorkey = $errorkey;
        $this->formatversion = $formatversion;
        $this->quizzes = $quizzes;
    }

    /**
     * The call did not succeed.
     *
     * @param string $errorkey a language string identifier in block_coursesync
     * @return self
     */
    public static function failure(string $errorkey): self {
        return new self(false, $errorkey, 0, []);
    }

    /**
     * Several successful answers, for batches of one request, as one.
     *
     * @param self[] $answers
     * @return self
     */
    public static function merge(array $answers): self {
        $quizzes = [];
        $formatversion = 0;

        foreach ($answers as $answer) {
            $quizzes = array_merge($quizzes, $answer->quizzes);
            // The oldest format any batch used is the one all of it can be read as.
            $formatversion = $formatversion === 0 ? $answer->formatversion : min($formatversion, $answer->formatversion);
        }

        return new self(true, null, $formatversion, $quizzes);
    }

    /**
     * Build the result from block_coursesync_get_quiz_attempts' decoded
     * response, cleaning every value on the way in.
     *
     * @param mixed $data
     * @return self failure('errorbadresponse') if it is not the expected shape
     */
    public static function from_response($data): self {
        if (!is_array($data) || !isset($data['formatversion']) || !is_array($data['quizzes'] ?? null)) {
            return self::failure('errorbadresponse');
        }

        $quizzes = [];

        foreach ($data['quizzes'] as $row) {
            if (
                !is_array($row) || !isset($row['cmid']) || !is_array($row['slots'] ?? null)
                    || !is_array($row['attempts'] ?? null)
            ) {
                return self::failure('errorbadresponse');
            }

            $slots = [];

            foreach ($row['slots'] as $slot) {
                if (!is_array($slot) || !isset($slot['slot'], $slot['qtype'])) {
                    return self::failure('errorbadresponse');
                }

                $slots[] = (object) [
                    'slot' => clean_param($slot['slot'], PARAM_INT),
                    'page' => clean_param($slot['page'] ?? 0, PARAM_INT),
                    'maxmark' => (float) clean_param($slot['maxmark'] ?? 0, PARAM_FLOAT),
                    'qtype' => clean_param((string) $slot['qtype'], PARAM_ALPHANUMEXT),
                ];
            }

            $attempts = [];

            foreach ($row['attempts'] as $attempt) {
                if (!is_array($attempt) || !isset($attempt['id'], $attempt['username']) || !is_array($attempt['marks'] ?? null)) {
                    return self::failure('errorbadresponse');
                }

                $marks = [];

                foreach ($attempt['marks'] as $mark) {
                    if (!is_array($mark) || !isset($mark['slot'])) {
                        return self::failure('errorbadresponse');
                    }

                    $slotnumber = clean_param($mark['slot'], PARAM_INT);
                    $marks[$slotnumber] = (object) [
                        'slot' => $slotnumber,
                        'maxmark' => (float) clean_param($mark['maxmark'] ?? 0, PARAM_FLOAT),
                        'mark' => isset($mark['mark']) && is_numeric($mark['mark']) ? (float) $mark['mark'] : null,
                        'state' => clean_param((string) ($mark['state'] ?? ''), PARAM_ALPHA),
                    ];
                }

                ksort($marks);

                $attempts[] = (object) [
                    'id' => clean_param($attempt['id'], PARAM_INT),
                    'username' => clean_param((string) $attempt['username'], PARAM_USERNAME),
                    'attempt' => clean_param($attempt['attempt'] ?? 0, PARAM_INT),
                    'timestart' => max(0, clean_param($attempt['timestart'] ?? 0, PARAM_INT)),
                    'timefinish' => max(0, clean_param($attempt['timefinish'] ?? 0, PARAM_INT)),
                    'sumgrades' => isset($attempt['sumgrades']) && is_numeric($attempt['sumgrades'])
                        ? (float) $attempt['sumgrades'] : null,
                    'marks' => $marks,
                ];
            }

            $quizzes[] = (object) [
                'cmid' => clean_param($row['cmid'], PARAM_INT),
                'sumgrades' => (float) clean_param($row['sumgrades'] ?? 0, PARAM_FLOAT),
                'grade' => (float) clean_param($row['grade'] ?? 0, PARAM_FLOAT),
                'slots' => $slots,
                'attempts' => $attempts,
            ];
        }

        return new self(true, null, clean_param($data['formatversion'], PARAM_INT), $quizzes);
    }
}
