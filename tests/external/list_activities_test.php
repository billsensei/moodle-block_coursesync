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
 * Tests for what a remote site reports about its activities.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\external;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \block_coursesync\external\list_activities.
 *
 * What this reports about where an activity sits is what the other site uses
 * to put its copy in the same place, so it has to be a section number that
 * an ordinary course can actually have.
 */
#[CoversClass(list_activities::class)]
final class list_activities_test extends \advanced_testcase {
    /**
     * Returns the reported activities, keyed by name.
     *
     * @param int $courseid Course to list.
     * @return array
     */
    private function listing(int $courseid): array {
        $listed = [];
        foreach (list_activities::execute($courseid)['activities'] as $activity) {
            $listed[$activity['name']] = $activity;
        }

        return $listed;
    }

    /**
     * An activity's own section number is reported as it stands.
     */
    public function test_the_section_an_activity_sits_in_is_reported(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 4]);
        $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'section' => 3, 'name' => 'Week 3 reading']
        );

        $listed = $this->listing((int) $course->id);

        $this->assertSame(3, $listed['Week 3 reading']['sectionnum']);
    }

    /**
     * An activity inside a subsection is reported against the section that subsection sits in.
     *
     * A subsection has a course section of its own, delegated to the
     * subsection module. Its number means nothing to a course that has no
     * such subsection, so reporting it would send the copy to an unrelated
     * part of that course, or make it grow sections to reach one.
     */
    public function test_an_activity_in_a_subsection_is_reported_against_its_parent(): void {
        if (!\core_component::get_plugin_directory('mod', 'subsection')) {
            $this->markTestSkipped('This site has no subsection module.');
        }

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 4]);
        $subsection = $this->getDataGenerator()->create_module(
            'subsection',
            ['course' => $course->id, 'section' => 2, 'name' => 'Extra material']
        );

        $delegated = $this->delegated_sectionnum((int) $course->id, (int) $subsection->id);
        $this->assertGreaterThan(0, $delegated);

        $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'section' => $delegated, 'name' => 'Tucked away']
        );

        $listed = $this->listing((int) $course->id);

        // Reported against the ordinary section holding the subsection, not
        // the delegated section number the activity is really in.
        $this->assertNotSame($delegated, 2);
        $this->assertSame(2, $listed['Tucked away']['sectionnum']);
        $this->assertSame(2, $listed['Extra material']['sectionnum']);
    }

    /**
     * Finds the section number a subsection module owns.
     *
     * @param int $courseid Course id.
     * @param int $subsectionid The subsection module instance id.
     * @return int The delegated section number, or 0 if there is none.
     */
    private function delegated_sectionnum(int $courseid, int $subsectionid): int {
        global $DB;

        return (int) $DB->get_field('course_sections', 'section', [
            'course' => $courseid,
            'component' => 'mod_subsection',
            'itemid' => $subsectionid,
        ]);
    }
}
