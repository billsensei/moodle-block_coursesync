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

namespace block_coursesync\local\handler;

use advanced_testcase;
use block_coursesync\activity_payload;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for rebuilding a page from a remote payload.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(page_handler::class)]
#[CoversClass(activity_handler::class)]
final class page_handler_test extends advanced_testcase {
    /**
     * Build a payload of the shape the source site sends.
     *
     * @param array $overrides
     * @param array $settings
     * @return activity_payload
     */
    protected function make_payload(array $overrides = [], array $settings = []): activity_payload {
        $defaults = [
            'cmid' => 99,
            'modname' => 'page',
            'name' => 'Week 1 Notes',
            'idnumber' => '',
            'sectionnum' => 1,
            'visible' => true,
            'intro' => '<p>What this page covers.</p>',
            'introformat' => FORMAT_HTML,
            'timemodified' => 1750000000,
        ];
        $data = array_merge($defaults, $overrides);

        return new activity_payload(
            $data['cmid'],
            $data['modname'],
            $data['name'],
            $data['idnumber'],
            $data['sectionnum'],
            $data['visible'],
            $data['intro'],
            $data['introformat'],
            $data['timemodified'],
            array_merge([
                'content' => '<h2>Reading list</h2><p>Chapter one.</p>',
                'contentformat' => (string) FORMAT_HTML,
                'display' => '0',
                'printintro' => '1',
                'printlastmodified' => '0',
                'popupwidth' => '620',
                'popupheight' => '450',
            ], $settings)
        );
    }

    /**
     * A page is created with its content, in the right section, and is usable.
     */
    public function test_creates_a_working_page(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $handler = new page_handler();

        $cm = $handler->create_from_remote_data($course, $this->make_payload(), 'coursesync-99');

        $this->assertNotEmpty($cm->id);
        $this->assertSame('coursesync-99', $cm->idnumber);
        $this->assertSame(1, (int) $cm->visible);

        $instance = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Week 1 Notes', $instance->name);
        $this->assertStringContainsString('Reading list', $instance->content);
        $this->assertStringContainsString('What this page covers', $instance->intro);
        $this->assertSame((int) FORMAT_HTML, (int) $instance->contentformat);

        // It really is in the course, in the section asked for, and Moodle can
        // see it through the normal course machinery.
        $modinfo = get_fast_modinfo($course->id);
        $cminfo = $modinfo->get_cm($cm->id);

        $this->assertSame('page', $cminfo->modname);
        $this->assertSame('Week 1 Notes', $cminfo->name);
        // The section number arrives from modinfo as a string.
        $this->assertSame(1, (int) $cminfo->sectionnum);
        $this->assertTrue($cminfo->uservisible);

        // The module context exists, which it would not if creation stopped half way.
        $this->assertNotEmpty(\context_module::instance($cm->id));
    }

    /**
     * The serialized display options mod_page expects are rebuilt from the
     * plain values the payload carries.
     */
    public function test_display_options_are_repacked(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $handler = new page_handler();

        $cm = $handler->create_from_remote_data($course, $this->make_payload([], [
            'printintro' => '1',
            'printlastmodified' => '0',
        ]), 'coursesync-99');

        $instance = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
        $options = unserialize($instance->displayoptions, ['allowed_classes' => false]);

        $this->assertSame(1, (int) $options['printintro']);
        $this->assertSame(0, (int) $options['printlastmodified']);
    }

    /**
     * A hidden activity on the source arrives hidden.
     */
    public function test_visibility_is_carried_across(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $handler = new page_handler();

        $cm = $handler->create_from_remote_data($course, $this->make_payload(['visible' => false]), 'coursesync-99');

        $this->assertSame(0, (int) $cm->visible);
    }

    /**
     * A section beyond the end of the destination course does not silently
     * create sections; the activity lands in the last one that exists.
     */
    public function test_section_beyond_the_end_is_clamped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $handler = new page_handler();

        $cm = $handler->create_from_remote_data($course, $this->make_payload(['sectionnum' => 25]), 'coursesync-99');

        $cminfo = get_fast_modinfo($course->id)->get_cm($cm->id);

        $this->assertLessThanOrEqual(2, (int) $cminfo->sectionnum);
        $this->assertGreaterThan(0, (int) $cminfo->sectionnum);
    }

    /**
     * Missing settings fall back to sensible defaults rather than failing, so a
     * payload from an older version of the plugin still produces a page.
     */
    public function test_sparse_payload_still_creates_a_page(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $handler = new page_handler();

        $sparse = new activity_payload(99, 'page', 'Bare page', '', 0, true, '', FORMAT_HTML, 0, []);
        $cm = $handler->create_from_remote_data($course, $sparse, 'coursesync-99');

        $instance = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Bare page', $instance->name);
        $this->assertSame('', $instance->content);
    }

    /**
     * The handler refuses a payload for a different activity type.
     */
    public function test_refuses_the_wrong_type(): void {
        $this->resetAfterTest();

        $handler = new page_handler();

        $this->assertNull($handler->check_payload($this->make_payload()));
        $this->assertSame('errorwronghandler', $handler->check_payload($this->make_payload(['modname' => 'forum'])));
    }

    /**
     * Content that points at embedded files is flagged, because the files are
     * not copied yet and the links would not resolve.
     */
    public function test_file_references_are_detected(): void {
        $this->resetAfterTest();

        $plain = $this->make_payload();
        $withfile = $this->make_payload([], [
            'content' => '<p>See <img src="@@PLUGINFILE@@/diagram.png" alt="diagram"></p>',
        ]);

        $this->assertFalse($plain->references_files());
        $this->assertTrue($withfile->references_files());
    }

    /**
     * The registry resolves the page handler. The full list of supported types
     * is checked in handlers_test.
     */
    public function test_registry(): void {
        $this->resetAfterTest();

        $this->assertTrue(handler_registry::supports('page'));
        $this->assertInstanceOf(page_handler::class, handler_registry::get('page'));

        // Something nothing handles, looked up rather than named so it cannot
        // go stale - or, now that every standard type has a handler, a
        // third-party module's name. The full list is checked in handlers_test.
        $unsupported = handler_registry::first_unsupported_modname() ?? 'thirdpartymodule';
        $this->assertFalse(handler_registry::supports($unsupported));
        $this->assertNull(handler_registry::get($unsupported));

        $this->assertContains(get_string('pluginname', 'mod_page'), handler_registry::supported_names());
    }
}
