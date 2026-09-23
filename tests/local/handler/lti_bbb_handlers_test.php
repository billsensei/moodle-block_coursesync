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
use block_coursesync\local\source_on_this_site;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../source_on_this_site.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');

/**
 * Tests for external tools (LTI) and BigBlueButton rooms.
 *
 * Both point at something set up on each site separately - a tool, a
 * BigBlueButton server - so what is pinned is that the copy only ever uses
 * this site's own, and that no secret of the source's travels.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(lti_handler::class)]
#[CoversClass(bigbluebuttonbn_handler::class)]
final class lti_bbb_handlers_test extends advanced_testcase {
    use source_on_this_site;

    /**
     * A site tool, as an administrator's would be.
     *
     * The generator's site tools are left pending and have no domain, which
     * no tool saved through the administration screens has: those are
     * configured, and take their domain from their address
     * (lti_prepare_type_for_save()). Matching - Moodle's own, at launch as
     * here - goes by both, so the tool is given what a real one has.
     *
     * @param string $name
     * @param string $baseurl
     * @param array $config more of the tool's settings
     * @return int the tool's id
     */
    protected function make_tool(string $name, string $baseurl, array $config = []): int {
        return $this->getDataGenerator()->get_plugin_generator('mod_lti')->create_tool_types([
            'name' => $name,
            'baseurl' => $baseurl,
            'state' => LTI_TOOL_STATE_CONFIGURED,
            'lti_toolurl' => $baseurl,
        ] + $config);
    }

    /**
     * An external tool activity is linked to the matching tool set up here -
     * the right one of several - and its key and secret never leave the source.
     */
    public function test_an_external_tool_is_linked_to_the_matching_tool_here(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $this->make_tool('Some other tool', 'https://other.example.org/lti');
        $toolid = $this->make_tool('Quiz engine', 'https://tool.example.com/lti/launch');

        $lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $source->id,
            'typeid' => $toolid,
            'toolurl' => 'https://tool.example.com/lti/launch?unit=4',
            'instructorcustomparameters' => 'unit=4',
            'launchcontainer' => LTI_LAUNCH_CONTAINER_WINDOW,
            'resourcekey' => 'SOURCEKEY-do-not-copy',
            'password' => 'SOURCESECRET-do-not-copy',
        ]);

        $exported = get_activity::execute((int) $lti->cmid);
        $this->assertStringNotContainsString('do-not-copy', json_encode($exported), 'no secret may travel');

        $payload = activity_payload::from_response($exported);
        $handler = new lti_handler();
        $this->assertNull($handler->check_payload($payload));

        $cm = $handler->create_from_remote_data($target, $payload, 'coursesync-1');
        $copy = $DB->get_record('lti', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame($toolid, (int) $copy->typeid);
        $this->assertSame('https://tool.example.com/lti/launch?unit=4', $copy->toolurl);
        $this->assertSame('unit=4', $copy->instructorcustomparameters);
        $this->assertEquals(LTI_LAUNCH_CONTAINER_WINDOW, $copy->launchcontainer);
        $this->assertSame('', (string) $copy->resourcekey);
        $this->assertSame('', (string) $copy->password);
    }

    /**
     * What the activity asked to send about people is held to what this
     * site's tool allows, whatever the source's allowed.
     */
    public function test_this_sites_tool_decides_what_is_shared(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $toolid = $this->make_tool('Private tool', 'https://tool.example.com/lti', [
            'lti_sendname' => LTI_SETTING_NEVER,
            'lti_sendemailaddr' => LTI_SETTING_NEVER,
        ]);

        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $source->id, 'typeid' => $toolid]);

        // As a source whose own tool let the teacher send both.
        $DB->update_record('lti', (object) [
            'id' => $lti->id,
            'instructorchoicesendname' => 1,
            'instructorchoicesendemailaddr' => 1,
        ]);

        $payload = activity_payload::from_response(get_activity::execute((int) $lti->cmid));
        $this->assertSame(1, $payload->setting_int('instructorchoicesendname'));

        $cm = (new lti_handler())->create_from_remote_data($target, $payload, 'coursesync-1');
        $copy = $DB->get_record('lti', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertEquals(LTI_SETTING_NEVER, $copy->instructorchoicesendname);
        $this->assertEquals(LTI_SETTING_NEVER, $copy->instructorchoicesendemailaddr);
    }

    /**
     * With no matching tool here, nothing is created, and the refusal names
     * the tool an administrator would need to add.
     */
    public function test_without_a_matching_tool_nothing_is_created(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $toolid = $this->make_tool('Quiz engine', 'https://tool.example.com/lti');
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $source->id, 'typeid' => $toolid]);
        $payload = activity_payload::from_response(get_activity::execute((int) $lti->cmid));

        // This "site" does not have that tool.
        $DB->delete_records('lti_types', ['id' => $toolid]);
        $before = $DB->count_records('course_modules', ['course' => $target->id]);

        $handler = new lti_handler();

        try {
            $handler->create_from_remote_data($target, $payload, 'coursesync-1');
            $this->fail('Expected the activity to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorltinotool', $e->errorcode);
        }

        $this->assertSame($before, $DB->count_records('course_modules', ['course' => $target->id]));

        // Which the syncer asks before creating anything, so a missing tool
        // is an expected refusal rather than a failure partway through.
        $this->assertSame('errorltinotool', $handler->check_destination($target, $payload));
        $this->assertSame(
            [['syncltitoolneeded', 'Quiz engine (https://tool.example.com/lti)']],
            $handler->failure_notes($payload, 'errorltinotool')
        );
        $this->assertSame([], $handler->failure_notes($payload, 'errorcreatefailed'));
    }

    /**
     * An activity set up with its own key and secret, rather than a site tool,
     * is refused: without the secret it could not work.
     */
    public function test_an_activity_with_its_own_secret_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $source->id,
            'toolurl' => 'https://tool.example.com/lti',
            'resourcekey' => 'mykey',
            'password' => 'MYSECRET-do-not-copy',
        ]);

        $exported = get_activity::execute((int) $lti->cmid);
        $this->assertStringNotContainsString('do-not-copy', json_encode($exported));

        $payload = activity_payload::from_response($exported);
        $this->assertSame('errorltiownsecret', (new lti_handler())->check_payload($payload));
    }

    /**
     * A BigBlueButton room is set up afresh here: its own meeting and
     * passwords, none of the source's, and its settings as they were.
     * Participant rules for everyone and for roles come across, by role
     * short name; one naming a person does not, and is counted.
     */
    public function test_a_room_is_set_up_afresh_with_its_role_rules(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        \core\plugininfo\mod::enable_plugin('bigbluebuttonbn', 1);

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($source, 'student');

        $room = $this->getDataGenerator()->create_module('bigbluebuttonbn', [
            'course' => $source->id,
            'type' => \mod_bigbluebuttonbn\instance::TYPE_ROOM_ONLY,
            'welcome' => 'Welcome to %%CONFNAME%%',
            'wait' => 1,
            'muteonstart' => 1,
            'userlimit' => 25,
        ]);
        $editingteacher = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $DB->update_record('bigbluebuttonbn', (object) [
            'id' => $room->id,
            'voicebridge' => 4321,
            'participants' => json_encode([
                ['selectiontype' => 'all', 'selectionid' => 'all', 'role' => 'viewer'],
                ['selectiontype' => 'role', 'selectionid' => (string) $editingteacher, 'role' => 'moderator'],
                ['selectiontype' => 'user', 'selectionid' => (string) $student->id, 'role' => 'moderator'],
            ]),
        ]);
        $original = $DB->get_record('bigbluebuttonbn', ['id' => $room->id], '*', MUST_EXIST);

        $exported = get_activity::execute((int) $room->cmid);
        $json = json_encode($exported);

        foreach (['meetingid', 'moderatorpass', 'viewerpass', 'guestpassword', 'guestlinkuid'] as $secret) {
            $this->assertStringNotContainsString((string) $original->$secret, $json, "{$secret} must not travel");
        }

        $payload = activity_payload::from_response($exported);
        $handler = new bigbluebuttonbn_handler();
        $this->assertNull($handler->check_payload($payload));

        $cm = $handler->create_from_remote_data($target, $payload, 'coursesync-1');
        $copy = $DB->get_record('bigbluebuttonbn', ['id' => $cm->instance], '*', MUST_EXIST);

        foreach (['meetingid', 'moderatorpass', 'viewerpass', 'guestpassword'] as $secret) {
            $this->assertNotEmpty($copy->$secret, "the copy should have its own {$secret}");
            $this->assertNotSame($original->$secret, $copy->$secret);
        }

        foreach (['type', 'welcome', 'wait', 'muteonstart', 'userlimit'] as $field) {
            $this->assertEquals($original->$field, $copy->$field, "{$field} should have copied");
        }

        $this->assertSame(0, (int) $copy->voicebridge);
        $this->assertSame([
            ['selectiontype' => 'all', 'selectionid' => 'all', 'role' => 'viewer'],
            ['selectiontype' => 'role', 'selectionid' => (string) $editingteacher, 'role' => 'moderator'],
        ], json_decode($copy->participants, true));

        $notes = $handler->notes($payload);
        $this->assertContains('syncbbbfreshroom', $notes);
        $this->assertContains(['syncbbbparticipantsdropped', 1], $notes);
        $this->assertContains('syncbbbnovoicebridge', $notes);
    }

    /**
     * A room's preloaded presentation arrives and the room points at it.
     */
    public function test_a_rooms_presentation_arrives(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        \core\plugininfo\mod::enable_plugin('bigbluebuttonbn', 1);

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $room = $this->getDataGenerator()->create_module('bigbluebuttonbn', ['course' => $source->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($room->cmid)->id,
            'component' => 'mod_bigbluebuttonbn',
            'filearea' => 'presentation',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'slides.pdf',
        ], 'slide bytes');
        $DB->set_field('bigbluebuttonbn', 'presentation', '/slides.pdf', ['id' => $room->id]);

        [$cm] = $this->copy((int) $room->cmid, $target);

        $this->assertSame('/slides.pdf', $DB->get_field('bigbluebuttonbn', 'presentation', ['id' => $cm->instance]));
        $file = get_file_storage()->get_file(
            \context_module::instance($cm->id)->id,
            'mod_bigbluebuttonbn',
            'presentation',
            0,
            '/',
            'slides.pdf'
        );
        $this->assertSame('slide bytes', $file->get_content());
    }

    /**
     * On a site with BigBlueButton switched off, a room is refused.
     */
    public function test_a_room_is_refused_where_bigbluebutton_is_off(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        \core\plugininfo\mod::enable_plugin('bigbluebuttonbn', 1);
        $source = $this->getDataGenerator()->create_course();
        $room = $this->getDataGenerator()->create_module('bigbluebuttonbn', ['course' => $source->id]);
        $payload = activity_payload::from_response(get_activity::execute((int) $room->cmid));

        \core\plugininfo\mod::enable_plugin('bigbluebuttonbn', 0);

        $this->assertSame('errorbbbnotenabled', (new bigbluebuttonbn_handler())->check_payload($payload));
    }
}
