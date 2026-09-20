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
 * One activity on the remote site, as reported by change detection.
 *
 * Metadata only. Phase 3 does not fetch activity content.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity {
    /** @var int Course module id on the remote site. */
    public readonly int $cmid;

    /** @var string Activity type, for example "assign". */
    public readonly string $modname;

    /** @var string Activity name. */
    public readonly string $name;

    /** @var string Activity ID number, empty if it has none. */
    public readonly string $idnumber;

    /** @var int When the activity was last modified on the remote site. */
    public readonly int $timemodified;

    /**
     * Build an activity record from what the remote site reported.
     *
     * @param int $cmid
     * @param string $modname
     * @param string $name
     * @param string $idnumber
     * @param int $timemodified
     */
    public function __construct(int $cmid, string $modname, string $name, string $idnumber, int $timemodified) {
        $this->cmid = $cmid;
        $this->modname = $modname;
        $this->name = $name;
        $this->idnumber = $idnumber;
        $this->timemodified = $timemodified;
    }

    /**
     * The activity type as a person would read it.
     *
     * @return string
     */
    public function get_type_name(): string {
        if (!get_string_manager()->string_exists('pluginname', 'mod_' . $this->modname)) {
            // An activity type this site does not have installed.
            return $this->modname;
        }

        return get_string('pluginname', 'mod_' . $this->modname);
    }
}
