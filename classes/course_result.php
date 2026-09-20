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
 * The outcome of resolving a course reference on the remote site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_result {
    /** @var bool Whether the course was found. */
    public readonly bool $success;

    /** @var string|null Language string identifier describing the failure. */
    public readonly ?string $errorkey;

    /** @var int Course id on the remote site. */
    public readonly int $id;

    /** @var string Course shortname. */
    public readonly string $shortname;

    /** @var string Course full name. */
    public readonly string $fullname;

    /** @var bool Whether the course is visible on the remote site. */
    public readonly bool $visible;

    /** @var int How many activities the remote course holds. */
    public readonly int $activitycount;

    /**
     * Use the success() and failure() factories instead.
     *
     * @param bool $success
     * @param string|null $errorkey
     * @param int $id
     * @param string $shortname
     * @param string $fullname
     * @param bool $visible
     * @param int $activitycount
     */
    protected function __construct(
        bool $success,
        ?string $errorkey,
        int $id,
        string $shortname,
        string $fullname,
        bool $visible,
        int $activitycount
    ) {
        $this->success = $success;
        $this->errorkey = $errorkey;
        $this->id = $id;
        $this->shortname = $shortname;
        $this->fullname = $fullname;
        $this->visible = $visible;
        $this->activitycount = $activitycount;
    }

    /**
     * The course was found.
     *
     * @param int $id
     * @param string $shortname
     * @param string $fullname
     * @param bool $visible
     * @param int $activitycount
     * @return self
     */
    public static function success(
        int $id,
        string $shortname,
        string $fullname,
        bool $visible,
        int $activitycount
    ): self {
        return new self(true, null, $id, $shortname, $fullname, $visible, $activitycount);
    }

    /**
     * The course could not be resolved.
     *
     * @param string $errorkey a language string identifier in block_coursesync
     * @return self
     */
    public static function failure(string $errorkey): self {
        return new self(false, $errorkey, 0, '', '', false, 0);
    }

    /**
     * A sentence the user can act on.
     *
     * @return string
     */
    public function get_message(): string {
        if ($this->success) {
            // Rendered unescaped by core's notification template.
            return get_string('coursemapped', 'block_coursesync', (object) [
                'fullname' => s($this->fullname),
                'shortname' => s($this->shortname),
            ]);
        }

        return get_string($this->errorkey, 'block_coursesync');
    }
}
