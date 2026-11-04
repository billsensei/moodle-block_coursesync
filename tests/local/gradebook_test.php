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

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for finding the students the gradebook lists.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(gradebook::class)]
final class gradebook_test extends advanced_testcase {
    /**
     * One person is found exactly when the whole list would have held them.
     */
    public function test_a_single_student_is_found_when_the_list_would_hold_them(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $sam = $generator->create_and_enrol($course, 'student', ['username' => 'sam']);
        $generator->create_and_enrol($course, 'teacher', ['username' => 'tess']);
        $generator->create_and_enrol($course, 'student', ['username' => 'sue'], 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $generator->create_and_enrol($other, 'student', ['username' => 'olly']);

        $listed = array_column(gradebook::gradable_users((int) $course->id, true), 'username');
        sort($listed);
        $this->assertSame(['sam'], $listed);

        $this->assertEquals($sam->id, gradebook::student((int) $course->id, 'sam')->id);

        // A teacher, a suspended enrolment, a student of another course, nobody.
        foreach (['tess', 'sue', 'olly', 'nobody', ''] as $username) {
            $this->assertNull(gradebook::student((int) $course->id, $username), $username);
        }

        // A name that would only match once cleaned is not the stored name.
        $this->assertNull(gradebook::student((int) $course->id, 'SAM'));
        $this->assertNull(gradebook::student((int) $course->id, "sam\nsue"));
    }

    /**
     * A file is served in many pieces, one request each: finding the person
     * must not recalculate the gradebook every time.
     */
    public function test_finding_one_student_does_not_regrade(): void {
        global $CFG;

        $this->resetAfterTest();
        require_once($CFG->libdir . '/gradelib.php');

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['username' => 'sam']);

        grade_force_full_regrading($course->id);
        $this->assertNotEmpty(grade_needs_regrade_final_grades($course->id));

        $this->assertNotNull(gradebook::student((int) $course->id, 'sam'));
        $this->assertNotEmpty(grade_needs_regrade_final_grades($course->id));

        // The list for a grade or mark description does still recalculate.
        gradebook::gradable_users((int) $course->id, true);
        $this->assertEmpty(grade_needs_regrade_final_grades($course->id));
    }
}
