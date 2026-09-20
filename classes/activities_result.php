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
 * The outcome of asking the remote site what has changed.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activities_result {
    /** @var bool Whether the remote site answered. */
    public readonly bool $success;

    /** @var string|null Language string identifier describing the failure. */
    public readonly ?string $errorkey;

    /** @var activity[] The activities reported, oldest change first. */
    public readonly array $activities;

    /**
     * Use the success() and failure() factories instead.
     *
     * @param bool $success
     * @param string|null $errorkey
     * @param activity[] $activities
     */
    protected function __construct(bool $success, ?string $errorkey, array $activities) {
        $this->success = $success;
        $this->errorkey = $errorkey;
        $this->activities = $activities;
    }

    /**
     * The remote site answered.
     *
     * @param activity[] $activities
     * @return self
     */
    public static function success(array $activities): self {
        return new self(true, null, $activities);
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
     * How many activities were reported.
     *
     * @return int
     */
    public function count(): int {
        return count($this->activities);
    }

    /**
     * A sentence summarising the outcome.
     *
     * @param int|null $since the timestamp asked about, or null for "never synced"
     * @return string
     */
    public function get_message(?int $since): string {
        if (!$this->success) {
            return get_string($this->errorkey, 'block_coursesync');
        }

        if ($this->count() === 0) {
            return $since === null
                ? get_string('previewnoneever', 'block_coursesync')
                : get_string('previewnonesince', 'block_coursesync', userdate($since));
        }

        return $since === null
            ? get_string('previewcountever', 'block_coursesync', $this->count())
            : get_string('previewcountsince', 'block_coursesync', (object) [
                'count' => $this->count(),
                'since' => userdate($since),
            ]);
    }
}
