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
 * The outcome of fetching one activity's full payload from the remote site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_result {
    /** @var bool Whether the payload arrived. */
    public readonly bool $success;

    /** @var string|null Language string identifier describing the failure. */
    public readonly ?string $errorkey;

    /** @var activity_payload|null What the source site sent. */
    public readonly ?activity_payload $payload;

    /**
     * Use the success() and failure() factories instead.
     *
     * @param bool $success
     * @param string|null $errorkey
     * @param activity_payload|null $payload
     */
    protected function __construct(bool $success, ?string $errorkey, ?activity_payload $payload) {
        $this->success = $success;
        $this->errorkey = $errorkey;
        $this->payload = $payload;
    }

    /**
     * The payload arrived.
     *
     * @param activity_payload $payload
     * @return self
     */
    public static function success(activity_payload $payload): self {
        return new self(true, null, $payload);
    }

    /**
     * The payload could not be fetched.
     *
     * @param string $errorkey a language string identifier in block_coursesync
     * @return self
     */
    public static function failure(string $errorkey): self {
        return new self(false, $errorkey, null);
    }

    /**
     * A sentence the user can act on.
     *
     * @return string
     */
    public function get_message(): string {
        return $this->success
            ? get_string('activityfetched', 'block_coursesync', $this->payload->name)
            : get_string($this->errorkey, 'block_coursesync');
    }
}
