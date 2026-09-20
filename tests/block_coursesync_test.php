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

use advanced_testcase;
use block_coursesync as coursesync_block;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Smoke tests for the Course Sync block stub.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(coursesync_block::class)]
final class block_coursesync_test extends advanced_testcase {
    /**
     * Load the block classes, which are not autoloaded.
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/../../moodleblock.class.php');
        require_once(__DIR__ . '/../block_coursesync.php');
        parent::setUpBeforeClass();
    }

    /**
     * The block reports the plugin name as its title.
     */
    public function test_init_sets_title(): void {
        $block = new coursesync_block();
        $block->init();

        $this->assertSame(get_string('pluginname', 'block_coursesync'), $block->title);
    }

    /**
     * The stub renders the placeholder message and nothing else.
     */
    public function test_get_content_returns_placeholder(): void {
        $this->resetAfterTest();

        $block = new coursesync_block();
        $block->init();
        $content = $block->get_content();

        $this->assertStringContainsString(
            get_string('notconfigured', 'block_coursesync'),
            $content->text
        );
        $this->assertSame('', $content->footer);
    }

    /**
     * The block is offered on course pages only.
     */
    public function test_applicable_formats(): void {
        $block = new coursesync_block();

        $this->assertSame(['course-view' => true], $block->applicable_formats());
    }

    /**
     * Moodle strips the "config_" prefix before calling instance_config_save(),
     * so the block must read the unprefixed names. Getting this wrong meant the
     * remote site URL silently vanished when saved through the block's own
     * configuration form.
     */
    #[CoversNothing]
    public function test_instance_config_save_stores_the_remote_url(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = new \moodle_page();
        $page->set_context(\context_course::instance($course->id));
        $page->set_course($course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $course->format);
        $page->set_url('/course/view.php', ['id' => $course->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');

        $instance = $DB->get_record('block_instances', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($course->id)->id,
        ], '*', MUST_EXIST);

        $block = block_instance('coursesync', $instance, $page);

        // Exactly the shape block_manager::save_block_data() passes through.
        $config = (object) ['remoteurl' => 'https://source.example.edu/'];
        $block->instance_config_save($config);

        $record = connection::get($instance->id);

        $this->assertNotNull($record, 'The connection record was not created.');
        $this->assertSame('https://source.example.edu', $record->remoteurl);

        // And nothing was mirrored into configdata.
        $saved = $DB->get_field('block_instances', 'configdata', ['id' => $instance->id]);
        $decoded = $saved ? unserialize(base64_decode($saved)) : new \stdClass();
        $this->assertObjectNotHasProperty('remoteurl', (object) $decoded);
    }

    /**
     * An address that is not https never reaches storage.
     */
    #[CoversNothing]
    public function test_instance_config_save_rejects_plain_http(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = new \moodle_page();
        $page->set_context(\context_course::instance($course->id));
        $page->set_course($course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $course->format);
        $page->set_url('/course/view.php', ['id' => $course->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');

        $instance = $DB->get_record('block_instances', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($course->id)->id,
        ], '*', MUST_EXIST);

        $block = block_instance('coursesync', $instance, $page);
        $block->instance_config_save((object) ['remoteurl' => 'http://source.example.edu']);

        $this->assertNull(connection::get($instance->id));
    }

    /**
     * Both capabilities are declared and have a language string.
     */
    #[CoversNothing]
    public function test_capabilities_are_defined(): void {
        $this->resetAfterTest();

        foreach (['block/coursesync:addinstance', 'block/coursesync:sync'] as $capability) {
            $info = get_capability_info($capability);
            $this->assertNotEmpty($info, "Capability {$capability} is not defined.");
            $this->assertNotEmpty(get_capability_string($capability));
        }
    }

    /**
     * Editing teachers and managers may sync by default, students may not.
     */
    #[CoversNothing]
    public function test_sync_capability_defaults(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertTrue(has_capability('block/coursesync:sync', $context, $teacher));
        $this->assertFalse(has_capability('block/coursesync:sync', $context, $student));

        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        $this->assertContains(
            'block/coursesync:sync',
            array_keys(get_default_capabilities($managerrole->archetype))
        );
    }
}
