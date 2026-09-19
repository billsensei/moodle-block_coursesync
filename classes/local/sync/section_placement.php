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

/**
 * Putting a pulled activity where it sat in the course it came from.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

/**
 * Moves a freshly restored activity into the section it occupies remotely.
 *
 * A restore of a single activity drops it wherever the backup's own section
 * data lands, which for a course being pulled into is rarely the right place.
 * An activity that says it belongs to week 7 and sits in week 1 is worse than
 * one that is simply missing, so this puts it where the remote course has it.
 */
class section_placement {
    /**
     * Constructor.
     *
     * @param \stdClass $course The course being pulled into.
     */
    public function __construct(
        /** @var \stdClass The course being pulled into. */
        private readonly \stdClass $course,
    ) {
    }

    /**
     * Moves an activity into the section its remote original occupies.
     *
     * @param int $cmid The local course module id.
     * @param int $sectionnum Section number on the remote site.
     */
    public function place(int $cmid, int $sectionnum): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $cm = get_coursemodule_from_id('', $cmid, $this->course->id, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        // A few module types are never shown on the course page, and Moodle
        // keeps those in section 0; asking to move one elsewhere throws. The
        // question bank module is the one in core that does this.
        if ($sectionnum !== 0 && !\core_course\modinfo::is_mod_type_visible_on_course($cm->modname)) {
            return;
        }

        $section = $this->section_numbered($sectionnum);
        if (!$section || (int) $cm->section === (int) $section->id) {
            return;
        }

        moveto_module($cm, $section);
    }

    /**
     * Returns the course's section with a given number, extending the course if it stops short.
     *
     * A course being pulled into is often shorter than the one being pulled
     * from, so the sections up to that number are created, which is what
     * Moodle itself does when restoring a course into a shorter one.
     *
     * The site's own limit on how many sections a course may have is
     * respected: beyond it the activity is left where the restore put it,
     * rather than growing a course past what this site allows.
     *
     * @param int $sectionnum The section number wanted.
     * @return \stdClass|null The section record, or null if it cannot be had.
     */
    private function section_numbered(int $sectionnum): ?\stdClass {
        global $CFG, $DB;

        if ($sectionnum < 0 || $sectionnum > (int) ($CFG->maxsections ?? 52)) {
            return null;
        }

        $criteria = ['course' => $this->course->id, 'section' => $sectionnum];

        $section = $DB->get_record('course_sections', $criteria);
        if ($section && empty($section->component)) {
            return $section;
        }

        // Either the course is too short, or that number belongs to a section
        // delegated to a component, such as a subsection, which is not
        // somewhere an activity can simply be moved into. Asking for every
        // number up to this one turns it into an ordinary section and pushes
        // any delegated ones down, which is core's own behaviour.
        course_create_sections_if_missing($this->course, range(0, $sectionnum));

        return $DB->get_record('course_sections', $criteria) ?: null;
    }
}
