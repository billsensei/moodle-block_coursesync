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

namespace block_coursesync\external;

use advanced_testcase;
use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for change detection on the source site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_modified_activities::class)]
final class get_modified_activities_test extends advanced_testcase {
    /**
     * Give an activity a known modification time.
     *
     * @param \stdClass $module the module record from the generator
     * @param string $modname
     * @param int $time
     * @return void
     */
    protected function set_modified(\stdClass $module, string $modname, int $time): void {
        global $DB;

        $DB->set_field($modname, 'timemodified', $time, ['id' => $module->id]);
        rebuild_course_cache($module->course, true);
    }

    /**
     * Only activities changed after the timestamp come back.
     */
    public function test_filters_by_timestamp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $old = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Old page']);
        $new = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'New page']);

        $this->set_modified($old, 'page', 1000);
        $this->set_modified($new, 'page', 3000);

        $result = external_api::clean_returnvalue(
            get_modified_activities::execute_returns(),
            get_modified_activities::execute($course->id, 2000)
        );

        $this->assertCount(1, $result);
        $this->assertSame('New page', $result[0]['name']);
        $this->assertSame(3000, $result[0]['timemodified']);
        $this->assertSame('page', $result[0]['modname']);
    }

    /**
     * A boundary timestamp is exclusive: "since X" means strictly after X, so
     * an activity saved in the same second as the last sync is not missed by
     * being reported and then never again.
     */
    public function test_boundary_is_exclusive(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->set_modified($module, 'page', 2000);

        $atboundary = get_modified_activities::execute($course->id, 2000);
        $justbefore = get_modified_activities::execute($course->id, 1999);

        $this->assertCount(0, $atboundary);
        $this->assertCount(1, $justbefore);
    }

    /**
     * A zero timestamp asks for everything.
     */
    public function test_zero_returns_everything(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $result = get_modified_activities::execute($course->id, 0);

        $this->assertCount(3, $result);
    }

    /**
     * Results are scoped to the course asked about.
     */
    public function test_scoped_to_the_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $wanted = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->create_module('page', ['course' => $wanted->id, 'name' => 'Wanted']);
        $this->getDataGenerator()->create_module('page', ['course' => $other->id, 'name' => 'Not wanted']);

        $result = get_modified_activities::execute($wanted->id, 0);

        $this->assertCount(1, $result);
        $this->assertSame('Wanted', $result[0]['name']);
    }

    /**
     * Activities are returned oldest change first.
     */
    public function test_ordered_by_modification_time(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'First']);
        $second = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Second']);
        $third = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Third']);

        $this->set_modified($first, 'page', 5000);
        $this->set_modified($second, 'page', 3000);
        $this->set_modified($third, 'page', 4000);

        $result = get_modified_activities::execute($course->id, 0);

        $this->assertSame(['Second', 'Third', 'First'], array_column($result, 'name'));
    }

    /**
     * The metadata asked for is present, and nothing more.
     */
    public function test_returns_expected_fields_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Essay one',
            'idnumber' => 'ESSAY1',
        ]);

        $result = external_api::clean_returnvalue(
            get_modified_activities::execute_returns(),
            get_modified_activities::execute($course->id, 0)
        );

        $this->assertCount(1, $result);
        $this->assertSame(
            ['cmid', 'modname', 'name', 'idnumber', 'timemodified'],
            array_keys($result[0])
        );
        $this->assertSame('Essay one', $result[0]['name']);
        $this->assertSame('ESSAY1', $result[0]['idnumber']);
        $this->assertSame('assign', $result[0]['modname']);
        $this->assertGreaterThan(0, $result[0]['cmid']);
    }

    /**
     * An activity awaiting deletion is not offered.
     */
    public function test_skips_activities_being_deleted(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $keep = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Keep']);
        $going = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Going']);

        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $going->cmid]);
        rebuild_course_cache($course->id, true);

        $result = get_modified_activities::execute($course->id, 0);

        $this->assertCount(1, $result);
        $this->assertSame('Keep', $result[0]['name']);
    }

    /**
     * An empty course reports nothing rather than failing.
     */
    public function test_empty_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $this->assertSame([], get_modified_activities::execute($course->id, 0));
    }

    /**
     * An unknown course is refused.
     */
    public function test_unknown_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        try {
            get_modified_activities::execute(-1, 0);
            $this->fail('Expected a moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorcoursenotfound', $e->errorcode);
        }
    }

    /**
     * A negative timestamp is rejected rather than quietly treated as zero.
     */
    public function test_negative_since_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\invalid_parameter_exception::class);
        get_modified_activities::execute($course->id, -5);
    }

    /**
     * The capability is required in that course.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        get_modified_activities::execute($course->id, 0);
    }
}
