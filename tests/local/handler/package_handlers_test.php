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
require_once($CFG->dirroot . '/mod/scorm/locallib.php');

/**
 * Tests for SCORM and IMS content packages.
 *
 * Both are activities whose content is a zip that Moodle unpacks and parses
 * when it is added. A copy whose package arrived byte for byte has only
 * passed a transfer test (LEARNFROMME P11.2), so every copy here is also
 * checked the way it would be opened: the SCORM's parsed structure and the
 * file its launch SCO points at, the IMS package's table of contents and the
 * page it opens on.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(scorm_handler::class)]
#[CoversClass(imscp_handler::class)]
#[CoversClass(h5pactivity_handler::class)]
final class package_handlers_test extends advanced_testcase {
    use source_on_this_site;

    /**
     * A SCORM's structure, in a form two sites can compare: every SCO's
     * identifier, title and launch file, in order.
     *
     * @param int $scormid
     * @return array
     */
    protected function scorm_structure(int $scormid): array {
        global $DB;

        return array_values(array_map(
            fn($sco) => [$sco->identifier, $sco->title, $sco->scormtype, $sco->launch],
            $DB->get_records('scorm_scoes', ['scorm' => $scormid], 'sortorder ASC, id ASC')
        ));
    }

    /**
     * Does the file a launch SCO opens exist in a SCORM's unpacked content?
     *
     * The same path player.php and loadSCO.php resolve.
     *
     * @param \stdClass $cm
     * @return bool
     */
    protected function launch_file_exists(\stdClass $cm): bool {
        global $DB;

        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);
        $sco = $DB->get_record('scorm_scoes', ['id' => $scorm->launch], '*', MUST_EXIST);
        $path = '/' . ltrim(strtok($sco->launch, '?#'), '/');

        return (bool) get_file_storage()->get_file(
            \context_module::instance($cm->id)->id,
            'mod_scorm',
            'content',
            0,
            dirname($path) === '/' ? '/' : dirname($path) . '/',
            basename($path)
        );
    }

    /**
     * A SCORM 2004 package arrives, is unpacked and parsed here into the
     * same structure as on the source, and opens; and how it is presented
     * and graded comes with it.
     */
    public function test_a_scorm_package_is_copied_and_opens(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $scorm = $this->getDataGenerator()->create_module('scorm', [
            'course' => $source->id,
            'maxgrade' => 50,
            'maxattempt' => 3,
            'grademethod' => GRADEHIGHEST,
            'hidetoc' => SCORM_TOC_POPUP,
            'popup' => 1,
            'width' => 800,
            'height' => 600,
            'scrollbars' => 1,
            'toolbar' => 1,
        ]);
        $original = $DB->get_record('scorm', ['id' => $scorm->id], '*', MUST_EXIST);
        $this->assertNotSame('ERROR', $original->version, 'the fixture should parse on the source');

        [$cm, $payload, $handler] = $this->copy((int) $scorm->cmid, $target);
        $copy = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertNull($handler->check_payload($payload));
        $this->assertSame(['syncscormnoattempts'], $handler->notes($payload));

        // The package, and what this site made of it.
        $this->assertSame($original->reference, $copy->reference);
        $this->assertSame($original->sha1hash, $copy->sha1hash);
        $this->assertSame($original->version, $copy->version);
        $this->assertEquals($this->scorm_structure((int) $original->id), $this->scorm_structure((int) $copy->id));
        $this->assertTrue($this->launch_file_exists($cm), 'the launch SCO should open a file that is here');

        // How it is presented and graded.
        foreach (['maxgrade', 'maxattempt', 'grademethod', 'hidetoc', 'popup', 'width', 'height', 'options'] as $field) {
            $this->assertEquals($original->$field, $copy->$field, "{$field} should have copied");
        }
        $this->assertStringContainsString('scrollbars=1', $copy->options);
    }

    /**
     * A SCORM 1.2 package too, which is parsed by a different datamodel.
     */
    public function test_a_scorm_12_package_is_copied_and_opens(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $scorm = $this->getDataGenerator()->create_module('scorm', [
            'course' => $source->id,
            'packagefilepath' => $CFG->dirroot . '/mod/scorm/tests/packages/RuntimeMinimumCalls_SCORM12.zip',
        ]);

        [$cm] = $this->copy((int) $scorm->cmid, $target);

        $this->assertSame('SCORM_1.2', $DB->get_field('scorm', 'version', ['id' => $cm->instance]));
        $this->assertEquals($this->scorm_structure((int) $scorm->id), $this->scorm_structure((int) $cm->instance));
        $this->assertTrue($this->launch_file_exists($cm));
    }

    /**
     * A package that arrives but does not parse here is not reported as a
     * copy: post_files() refuses it, and the syncer takes it back out.
     */
    public function test_a_package_that_does_not_open_is_refused(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $source->id]);

        // Swap the stored package for one with no manifest in it.
        $fs = get_file_storage();
        $context = \context_module::instance($scorm->cmid);
        $package = current($fs->get_area_files($context->id, 'mod_scorm', 'package', 0, 'id', false));
        $name = $package->get_filename();
        $package->delete();
        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'mod_scorm',
            'filearea' => 'package',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $name,
        ], $CFG->dirroot . '/mod/scorm/tests/packages/invalid.zip');

        try {
            $this->copy((int) $scorm->cmid, $target);
            $this->fail('A package that does not open should have been refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorpackagenotdeployed', $e->errorcode);
        }
    }

    /**
     * A SCORM that is only a link to a file elsewhere has nothing to copy,
     * and one kept in sync with a web address is copied as an upload and
     * says it will no longer update.
     */
    public function test_only_a_scorm_with_a_package_can_be_copied(): void {
        $this->resetAfterTest();

        $handler = new scorm_handler();
        $payload = fn(string $type, bool $withpackage) => activity_payload::from_response([
            'cmid' => 9,
            'modname' => 'scorm',
            'name' => 'Linked',
            'idnumber' => '',
            'sectionnum' => 0,
            'visible' => true,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'timemodified' => time(),
            'settings' => [['name' => 'scormtype', 'value' => $type]],
            'children' => [],
            'files' => $withpackage ? [[
                'filearea' => 'package', 'itemid' => 0, 'filepath' => '/', 'filename' => 'p.zip',
                'filesize' => 1, 'mimetype' => 'application/zip', 'sortorder' => 0, 'timemodified' => 0,
                'contenthash' => sha1('x'),
            ]] : [],
        ]);

        $this->assertSame('errorscormnotuploaded', $handler->check_payload($payload('external', false)));
        $this->assertSame('errorscormnotuploaded', $handler->check_payload($payload('aiccurl', false)));
        $this->assertSame('errornopackage', $handler->check_payload($payload('local', false)));
        $this->assertNull($handler->check_payload($payload('local', true)));

        $this->assertNull($handler->check_payload($payload('localsync', true)));
        $this->assertContains('syncscormnolongersynced', $handler->notes($payload('localsync', true)));
    }

    /**
     * An IMS content package arrives, is unpacked, and has the same table of
     * contents here, opening on a page that is really here.
     */
    public function test_an_ims_content_package_is_copied_and_opens(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $imscp = $this->getDataGenerator()->create_module('imscp', ['course' => $source->id]);
        $original = $DB->get_record('imscp', ['id' => $imscp->id], '*', MUST_EXIST);
        $this->assertNotEmpty($original->structure, 'the fixture should parse on the source');

        [$cm] = $this->copy((int) $imscp->cmid, $target);
        $copy = $DB->get_record('imscp', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame(1, (int) $copy->revision);
        $this->assertEquals(unserialize($original->structure), unserialize($copy->structure));

        // The fixture opens on a page in a folder: shared/launchpage.html.
        $path = '/' . unserialize($copy->structure)[0]['href'];
        $this->assertNotEmpty(get_file_storage()->get_file(
            \context_module::instance($cm->id)->id,
            'mod_imscp',
            'content',
            1,
            dirname($path) . '/',
            basename($path)
        ), 'the page it opens on should be here');
    }

    /**
     * Only the current revision's package travels, and becomes this site's
     * first; a package the source kept from an earlier upload stays there.
     */
    public function test_only_the_current_ims_revision_is_copied(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $imscp = $this->getDataGenerator()->create_module('imscp', ['course' => $source->id, 'keepold' => 1]);
        $context = \context_module::instance($imscp->cmid);

        // A second upload: revision 2, with revision 1's package kept.
        get_file_storage()->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'mod_imscp',
            'filearea' => 'backup',
            'itemid' => 2,
            'filepath' => '/',
            'filename' => 'second.zip',
        ], $CFG->dirroot . '/mod/imscp/tests/packages/singlescobasic.zip');
        $DB->set_field('imscp', 'revision', 2, ['id' => $imscp->id]);

        [$cm] = $this->copy((int) $imscp->cmid, $target);

        $this->assertSame(['/backup/second.zip'], $this->requested);
        $backups = get_file_storage()->get_area_files(
            \context_module::instance($cm->id)->id,
            'mod_imscp',
            'backup',
            false,
            'id',
            false
        );
        $this->assertSame(['1:second.zip'], array_values(array_map(
            fn($file) => $file->get_itemid() . ':' . $file->get_filename(),
            $backups
        )));
    }

    /**
     * An H5P activity with a description image but no package is still
     * refused: a description image is a file too, so "has any file" is no
     * longer the test.
     */
    public function test_an_h5p_description_image_is_not_taken_for_its_package(): void {
        $this->resetAfterTest();

        $payload = activity_payload::from_response([
            'cmid' => 9,
            'modname' => 'h5pactivity',
            'name' => 'No package',
            'idnumber' => '',
            'sectionnum' => 0,
            'visible' => true,
            'intro' => '<img src="@@PLUGINFILE@@/pic.png">',
            'introformat' => FORMAT_HTML,
            'timemodified' => time(),
            'settings' => [],
            'children' => [],
            'files' => [[
                'component' => 'mod_h5pactivity', 'filearea' => 'intro', 'itemid' => 0, 'filepath' => '/',
                'filename' => 'pic.png', 'filesize' => 1, 'mimetype' => 'image/png', 'sortorder' => 0,
                'timemodified' => 0, 'contenthash' => sha1('x'),
            ]],
        ]);

        $this->assertSame('errornopackage', (new h5pactivity_handler())->check_payload($payload));
    }
}
