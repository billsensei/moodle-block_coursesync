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
 * The outcome of a connection test.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ping_result {
    /** @var bool Whether the remote site answered successfully. */
    public readonly bool $success;

    /** @var string|null Language string identifier describing the failure. */
    public readonly ?string $errorkey;

    /** @var string Site name reported by the remote site. */
    public readonly string $sitename;

    /** @var string Moodle release reported by the remote site. */
    public readonly string $release;

    /** @var int block_coursesync version installed on the remote site. */
    public readonly int $pluginversion;

    /**
     * Use the success() and failure() factories instead.
     *
     * @param bool $success
     * @param string|null $errorkey
     * @param string $sitename
     * @param string $release
     * @param int $pluginversion
     */
    protected function __construct(
        bool $success,
        ?string $errorkey,
        string $sitename,
        string $release,
        int $pluginversion
    ) {
        $this->success = $success;
        $this->errorkey = $errorkey;
        $this->sitename = $sitename;
        $this->release = $release;
        $this->pluginversion = $pluginversion;
    }

    /**
     * The remote site answered.
     *
     * @param string $sitename
     * @param string $release
     * @param int $pluginversion
     * @return self
     */
    public static function success(string $sitename, string $release, int $pluginversion): self {
        return new self(true, null, $sitename, $release, $pluginversion);
    }

    /**
     * The call did not succeed.
     *
     * @param string $errorkey a language string identifier in block_coursesync
     * @return self
     */
    public static function failure(string $errorkey): self {
        return new self(false, $errorkey, '', '', 0);
    }

    /**
     * A sentence the user can act on.
     *
     * @return string
     */
    public function get_message(): string {
        if ($this->success) {
            // This message is rendered unescaped by core's notification
            // template, so what the other site told us about itself is escaped
            // here even though it was also cleaned on arrival.
            return get_string('teststatusok', 'block_coursesync', (object) [
                'sitename' => s($this->sitename),
                'release' => s($this->release),
            ]);
        }

        return get_string($this->errorkey, 'block_coursesync');
    }
}
