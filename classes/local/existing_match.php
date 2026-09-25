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

namespace block_coursesync\local;

use block_coursesync\activity;

/**
 * Recognises activities already in a course that Course Sync did not put
 * there - built by hand, restored from a backup, imported - so a first sync
 * does not offer them all again.
 *
 * An activity on the source is the same as one here when both are of the same
 * type and have the same name, compared as text: formatting, entities and
 * tags aside, letter case and runs of spaces ignored. Only activities here
 * without Course Sync's own marker are considered; those it already
 * recognises by the marker. And nothing is guessed: when more than one
 * activity of that type and name is here, or on the source, none of them is
 * matched.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class existing_match {
    /**
     * Match the source's activities to unmarked ones here.
     *
     * @param int $courseid the destination course
     * @param activity[] $remote everything the source listed - all of it, so
     *      a name used twice there is known to be ambiguous
     * @param int[] $candidates remote cmids worth matching: those nothing here
     *      carries the marker of
     * @return array{matched: \cm_info[], ambiguous: int[]} remote cmid => the
     *      activity here; and remote cmids that could only have been guessed
     */
    public static function find(int $courseid, array $remote, array $candidates): array {
        $context = \context_course::instance($courseid);
        $here = [];

        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if ($cm->deletioninprogress || str_starts_with((string) $cm->idnumber, 'coursesync-')) {
                continue;
            }

            $name = format_string($cm->name, true, ['context' => $context, 'escape' => false]);
            $here[self::key($cm->modname, $name)][] = $cm;
        }

        $there = [];

        foreach ($remote as $activity) {
            $key = self::key($activity->modname, $activity->name);
            $there[$key] = ($there[$key] ?? 0) + 1;
        }

        $matched = [];
        $ambiguous = [];

        foreach ($remote as $activity) {
            $cmid = (int) $activity->cmid;

            if (!in_array($cmid, $candidates, true)) {
                continue;
            }

            $key = self::key($activity->modname, $activity->name);
            $locals = $here[$key] ?? [];

            if ($locals === []) {
                continue;
            }

            if (count($locals) === 1 && $there[$key] === 1) {
                $matched[$cmid] = $locals[0];
            } else {
                $ambiguous[] = $cmid;
            }
        }

        return ['matched' => $matched, 'ambiguous' => $ambiguous];
    }

    /**
     * What two activities must share to be the same one.
     *
     * @param string $modname
     * @param string $name
     * @return string
     */
    public static function key(string $modname, string $name): string {
        return $modname . '|' . self::normalise($name);
    }

    /**
     * A name as plain text, for comparing: entities decoded, tags removed,
     * spaces collapsed, lower case.
     *
     * @param string $name
     * @return string
     */
    public static function normalise(string $name): string {
        $text = strip_tags(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return \core_text::strtolower($text);
    }
}
