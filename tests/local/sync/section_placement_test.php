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
 * Tests for placing a pulled activity in the section it came from.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \block_coursesync\local\sync\section_placement.
 *
 * The restore itself needs a real backup file from a real remote site, so it
 * is not exercised here; where an activity ends up afterwards is, because
 * that is what makes a pulled course recognisable.
 */
#[CoversClass(section_placement::class)]
final class section_placement_test extends \advanced_testcase {
    /**
     * The section number an activity currently sits in.
     *
     * @param int $cmid Course module id.
     * @return int
     */
    private function section_of(int $cmid): int {
        global $DB;

        return (int) $DB->get_field_sql(
            'SELECT cs.section
               FROM {course_sections} cs
               JOIN {course_modules} cm ON cm.section = cs.id
              WHERE cm.id = ?',
            [$cmid]
        );
    }

    /**
     * How many sections the course has.
     *
     * @param int $courseid Course id.
     * @return int
     */
    private function section_count(int $courseid): int {
        global $DB;

        return $DB->count_records('course_sections', ['course' => $courseid]);
    }

    /**
     * An activity is moved into the section the remote course has it in.
     */
    public function test_an_activity_goes_into_the_section_it_came_from(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 5]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);

        (new section_placement($course))->place((int) $page->cmid, 4);

        $this->assertSame(4, $this->section_of((int) $page->cmid));
    }

    /**
     * A course shorter than the one being pulled from is grown to fit.
     */
    public function test_a_shorter_course_gains_the_sections_it_needs(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);

        // Sections 0, 1 and 2 exist; the activity belongs in week 7.
        $this->assertSame(3, $this->section_count($course->id));

        (new section_placement($course))->place((int) $page->cmid, 7);

        $this->assertSame(7, $this->section_of((int) $page->cmid));
        $this->assertSame(8, $this->section_count($course->id));
    }

    /**
     * The general section is a perfectly good destination.
     */
    public function test_an_activity_from_the_general_section_goes_to_the_general_section(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 2]);

        (new section_placement($course))->place((int) $page->cmid, 0);

        $this->assertSame(0, $this->section_of((int) $page->cmid));
    }

    /**
     * An activity already in the right place is left alone.
     */
    public function test_an_activity_already_in_place_is_not_moved(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 2]);

        (new section_placement($course))->place((int) $page->cmid, 2);

        $this->assertSame(2, $this->section_of((int) $page->cmid));
        $this->assertSame(4, $this->section_count($course->id));
    }

    /**
     * A course is never grown past what the site allows.
     */
    public function test_the_sites_own_limit_on_sections_is_respected(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $CFG->maxsections = 4;

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);

        (new section_placement($course))->place((int) $page->cmid, 40);

        $this->assertSame(1, $this->section_of((int) $page->cmid));
        $this->assertSame(3, $this->section_count($course->id));
    }

    /**
     * An activity that has gone since the restore is not chased.
     */
    public function test_a_missing_activity_is_left_to_be_someone_elses_problem(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);

        (new section_placement($course))->place(-1, 3);

        // Nothing was moved, and no sections were invented on the way.
        $this->assertSame(3, $this->section_count($course->id));
    }
}
