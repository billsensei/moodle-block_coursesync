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
use block_coursesync\external\get_activity;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Round-trip tests for the v1 activity handlers.
 *
 * Each test exports a real activity through the source-side code and rebuilds
 * it through the destination-side code in a second course, then compares. That
 * exercises both halves of a handler against each other, which is what actually
 * matters: a handler whose export and import disagree is the failure mode a
 * one-sided test would miss.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(url_handler::class)]
#[CoversClass(label_handler::class)]
#[CoversClass(resource_handler::class)]
#[CoversClass(forum_handler::class)]
final class handlers_test extends advanced_testcase {
    /**
     * Export an activity the way the source site would, and rebuild it in
     * another course the way the destination would.
     *
     * @param int $cmid the activity to export
     * @param \stdClass $target the course to rebuild it in
     * @param string $idnumber
     * @return array [the new course_modules record, the payload that travelled]
     */
    protected function round_trip(int $cmid, \stdClass $target, string $idnumber = 'coursesync-1'): array {
        $exported = get_activity::execute($cmid);
        $payload = activity_payload::from_response($exported);

        $handler = handler_registry::get($payload->modname);
        $this->assertNotNull($handler, "No handler for {$payload->modname}");
        $this->assertNull($handler->check_payload($payload));

        $cm = $handler->create_from_remote_data($target, $payload, $idnumber);

        return [$cm, $payload];
    }

    /**
     * A URL keeps its address, display setting and parameters.
     */
    public function test_url_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $url = $this->getDataGenerator()->create_module('url', [
            'course' => $source->id,
            'name' => 'Reference link',
            'externalurl' => 'https://example.edu/reading',
            'intro' => '<p>Further reading.</p>',
            'display' => RESOURCELIB_DISPLAY_NEW,
        ]);
        $DB->set_field('url', 'parameters', serialize(['userid' => 'id', 'course' => 'courseid']), ['id' => $url->id]);

        [$cm] = $this->round_trip($url->cmid, $target);

        $copy = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Reference link', $copy->name);
        $this->assertSame('https://example.edu/reading', $copy->externalurl);
        $this->assertSame((int) RESOURCELIB_DISPLAY_NEW, (int) $copy->display);
        $this->assertStringContainsString('Further reading', $copy->intro);

        $parameters = unserialize($copy->parameters, ['allowed_classes' => false]);
        $this->assertSame(['userid' => 'id', 'course' => 'courseid'], $parameters);
    }

    /**
     * A label carries its text, and its name is derived from that text the way
     * Moodle derives it rather than being copied.
     */
    public function test_label_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $source->id,
            'intro' => '<h3>Unit two</h3><p>Everything below is assessed.</p>',
            'introformat' => FORMAT_HTML,
        ]);

        [$cm] = $this->round_trip($label->cmid, $target);

        $copy = $DB->get_record('label', ['id' => $cm->instance], '*', MUST_EXIST);
        $original = $DB->get_record('label', ['id' => $label->id], '*', MUST_EXIST);

        $this->assertSame($original->intro, $copy->intro);
        $this->assertSame($original->name, $copy->name);
    }

    /**
     * A forum keeps its activity-level settings.
     */
    public function test_forum_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $source->id,
            'name' => 'Seminar discussion',
            'intro' => '<p>Talk about the reading here.</p>',
            'type' => 'general',
            'maxattachments' => 3,
            'forcesubscribe' => 1,
            'trackingtype' => 2,
            'scale' => 50,
        ]);

        [$cm] = $this->round_trip($forum->cmid, $target);

        $copy = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Seminar discussion', $copy->name);
        $this->assertSame('general', $copy->type);
        $this->assertSame(3, (int) $copy->maxattachments);
        $this->assertSame(1, (int) $copy->forcesubscribe);
        $this->assertSame(2, (int) $copy->trackingtype);
        // A positive scale is a point score and means the same on any site.
        $this->assertSame(50, (int) $copy->scale);
    }

    /**
     * A forum graded with a scale that exists on both sites finds it by name.
     */
    public function test_forum_scale_matched_by_name(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $scale = $this->getDataGenerator()->create_scale([
            'name' => 'Shared marking scale',
            'scale' => 'Poor,Fair,Good,Excellent',
        ]);

        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $source->id,
            'scale' => -$scale->id,
            'assessed' => 1,
        ]);

        [$cm, $payload] = $this->round_trip($forum->cmid, $target);

        $copy = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame(-((int) $scale->id), (int) $copy->scale);

        $handler = new forum_handler();
        $this->assertFalse($handler->lost_scale($payload));
    }

    /**
     * A forum graded with a scale the destination does not have is created
     * ungraded rather than pointed at whatever holds that id.
     */
    public function test_forum_scale_dropped_when_missing(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $scale = $this->getDataGenerator()->create_scale([
            'name' => 'Only on the source',
            'scale' => 'No,Yes',
        ]);

        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $source->id,
            'scale' => -$scale->id,
            'assessed' => 1,
        ]);

        $exported = get_activity::execute($forum->cmid);
        $payload = activity_payload::from_response($exported);

        // The scale disappears between export and import, standing in for a
        // destination site that never had it.
        $DB->delete_records('scale', ['id' => $scale->id]);

        $handler = new forum_handler();
        $this->assertTrue($handler->lost_scale($payload));

        $cm = $handler->create_from_remote_data($target, $payload, 'coursesync-1');
        $copy = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame(0, (int) $copy->scale);
    }

    /**
     * The scale name travels with the value, which is what makes matching possible.
     */
    public function test_forum_exports_the_scale_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $scale = $this->getDataGenerator()->create_scale(['name' => 'Named scale', 'scale' => 'No,Yes']);
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $source->id,
            'scale' => -$scale->id,
        ]);

        $exported = get_activity::execute($forum->cmid);
        $settings = array_column($exported['settings'], 'value', 'name');

        $this->assertSame((string) -$scale->id, $settings['scale']);
        $this->assertSame('Named scale', $settings['scalename']);
    }

    /**
     * Only the resource handler declares a file area, and it is the right one.
     */
    public function test_file_areas_are_declared_only_where_needed(): void {
        $this->resetAfterTest();

        $this->assertSame([['filearea' => 'content', 'itemid' => 0]], (new page_handler())->get_file_areas());
        $this->assertSame([], (new url_handler())->get_file_areas());
        $this->assertSame([], (new label_handler())->get_file_areas());
        $this->assertSame([], (new forum_handler())->get_file_areas());
        $this->assertSame(
            [['filearea' => 'content', 'itemid' => 0]],
            (new resource_handler())->get_file_areas()
        );

        // Every type also gets the description's area, without declaring it.
        $this->assertSame(
            [['component' => 'mod_url', 'filearea' => 'intro', 'itemid' => 0]],
            (new url_handler())->file_areas()
        );
        $this->assertSame(
            [
                ['component' => 'mod_page', 'filearea' => 'intro', 'itemid' => 0],
                ['component' => 'mod_page', 'filearea' => 'content', 'itemid' => 0],
            ],
            (new page_handler())->file_areas()
        );
    }

    /**
     * Every activity type this plugin claims to sync is registered.
     */
    public function test_all_supported_types_are_registered(): void {
        $this->resetAfterTest();

        $expected = [
            'page',
            'url',
            'label',
            'resource',
            'folder',
            'book',
            'forum',
            'wiki',
            'assign',
            'quiz',
            'choice',
            'glossary',
            'feedback',
            'data',
            'workshop',
            'lesson',
            'h5pactivity',
            'qbank',
            'scorm',
            'imscp',
            'lti',
            'bigbluebuttonbn',
            'subsection',
        ];

        foreach ($expected as $modname) {
            $this->assertTrue(handler_registry::supports($modname), "{$modname} is not registered");
            $this->assertSame($modname, handler_registry::get($modname)::get_modname());
        }

        $this->assertSame($expected, handler_registry::supported_modnames());

        // Every activity module standard Moodle ships has a handler. A new
        // Moodle version that adds one fails here, rather than its activities
        // quietly being left off the sync page.
        foreach (\core_plugin_manager::standard_plugins_list('mod') as $modname) {
            $this->assertTrue(handler_registry::supports($modname), "standard module {$modname} has no handler");
        }

        // Something nothing here handles, so the registry is known to be
        // answering rather than agreeing with everything: a third-party
        // module installed here if there is one, or one that is not.
        $unsupported = handler_registry::first_unsupported_modname() ?? 'thirdpartymodule';

        $this->assertFalse(handler_registry::supports($unsupported));
        $this->assertNull(handler_registry::get($unsupported));
    }

    /**
     * Every message a handler can report has a string behind it.
     *
     * A handler says what it could not bring across by returning message keys,
     * and those keys are looked up both when a run finishes and again when the
     * history is read back later. A key with nothing behind it would surface as
     * a missing string in front of a teacher, so each one is checked here
     * rather than discovered then.
     */
    public function test_every_handler_note_has_a_string(): void {
        $this->resetAfterTest();

        $payload = new activity_payload(11, 'page', 'Anything', '', 0, true, '', FORMAT_HTML, 0, []);

        foreach (handler_registry::supported_modnames() as $modname) {
            $handler = handler_registry::get($modname);

            foreach ($handler->notes($payload) as $note) {
                $this->assertTrue(
                    get_string_manager()->string_exists($note, 'block_coursesync'),
                    "{$modname} reports '{$note}', which has no string"
                );
            }
        }
    }
}
