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
 * Steps for testing the Course sync block without a second live site.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Behat\Gherkin\Node\TableNode;
use block_coursesync\local\activity_signature;
use block_coursesync\local\http\transport_factory;
use block_coursesync\local\sync\engine;
use block_coursesync\local\token_store;

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Arranges a remote site that does not exist.
 *
 * The plugin's transport is scripted through plugin config rather than stood
 * up as a second Moodle: the step process and the web request under test are
 * different processes, so the script has to live somewhere both can read.
 */
class behat_block_coursesync extends behat_base {
    /**
     * Puts a configured Course sync block into a course.
     *
     * @Given /^the Course sync block is configured in course "(?P<shortname>[^"]*)"$/
     * @param string $shortname Course shortname.
     */
    public function the_block_is_configured_in_course(string $shortname): void {
        global $DB;

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $coursecontext = context_course::instance($course->id);

        $config = (object) [
            // An address literal, not a name: the URL rules resolve any host
            // name before a request is made, and this fictional remote has no
            // DNS record to find. A public-looking address short-circuits that
            // lookup without weakening the rules being exercised.
            'remoteurl' => 'https://93.184.216.34',
            'remotecourse' => 'SRC101',
            'remotecourseid' => 42,
            'remotesitename' => 'Remote Example',
            'remotecoursefullname' => 'Source Biology',
            'tokenciphertext' => token_store::encrypt('a-behat-token'),
            'lastvalidated' => time(),
        ];

        $this->create_block($coursecontext, $config);
    }

    /**
     * Puts a Course sync block into a course without configuring it.
     *
     * @Given /^an unconfigured Course sync block is in course "(?P<shortname>[^"]*)"$/
     * @param string $shortname Course shortname.
     */
    public function an_unconfigured_block_is_in_course(string $shortname): void {
        global $DB;

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);

        $this->create_block(context_course::instance($course->id), new stdClass());
    }

    /**
     * Scripts what the remote site will report.
     *
     * @Given /^the remote site offers the following activities:$/
     * @param TableNode $table Columns: cmid, name, signal.
     */
    public function the_remote_site_offers(TableNode $table): void {
        $activities = [];
        foreach ($table->getHash() as $row) {
            $activities[] = [
                'cmid' => (int) $row['cmid'],
                'modname' => $row['modname'] ?? 'page',
                'name' => $row['name'],
                'sectionnum' => (int) ($row['sectionnum'] ?? 1),
                'idnumber' => $row['idnumber'] ?? '',
                'signal' => (string) $row['signal'],
                'signalmethod' => 'timemodified',
                'backupsupported' => true,
            ];
        }

        set_config(transport_factory::BEHAT_SETTING, json_encode([
            'block_coursesync_list_activities' => ['courseid' => 42, 'activities' => $activities],
        ]), 'block_coursesync');
    }

    /**
     * Records that an activity has already been pulled, and creates its local copy.
     *
     * Used instead of running a real transfer, which would need a genuine
     * backup file from a site that is not there.
     *
     * @Given /^course "(?P<shortname>[^"]*)" has already pulled "(?P<cmid>\d+)" as "(?P<name>[^"]*)" signal "(?P<signal>[^"]*)"$/
     * @param string $shortname Course shortname.
     * @param int $cmid Remote course module id.
     * @param string $name Activity name.
     * @param string $signal Remote signal recorded at that pull.
     */
    public function course_has_already_pulled(string $shortname, int $cmid, string $name, string $signal): void {
        global $DB;

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $generator = testing_util::get_data_generator();
        $page = $generator->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => $name,
        ]);

        $local = activity_signature::for_local_cmid((int) $page->cmid);

        $DB->insert_record('block_coursesync_pulls', (object) [
            'blockinstanceid' => $this->block_id($course->id),
            'remotecmid' => $cmid,
            'localcmid' => (int) $page->cmid,
            'remotesignal' => $signal,
            'remotesignalmethod' => 'timemodified',
            'localsignal' => $local['signal'],
            'localsignalmethod' => 'timemodified',
            'status' => 'synced',
            'timepulled' => time() - HOURSECS,
            'timemodified' => time() - HOURSECS,
        ]);
    }

    /**
     * Edits a local copy, so it no longer matches what was pulled.
     *
     * @Given /^the local copy of "(?P<name>[^"]*)" has been edited$/
     * @param string $name Activity name.
     */
    public function the_local_copy_has_been_edited(string $name): void {
        global $DB;

        $page = $DB->get_record('page', ['name' => $name], '*', MUST_EXIST);
        $page->content = '<p>Edited in this course.</p>';
        $page->timemodified = time() + 100;
        $DB->update_record('page', $page);

        rebuild_course_cache($page->course, true);
    }

    /**
     * Runs a sync for the course's block, as the site administrator would.
     *
     * @Given /^a course sync has run in course "(?P<shortname>[^"]*)"$/
     * @param string $shortname Course shortname.
     */
    public function a_course_sync_has_run(string $shortname): void {
        global $DB;

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);

        engine::run($this->block_id($course->id), (int) get_admin()->id);
    }

    /**
     * Inserts a block instance with the given configuration.
     *
     * @param context_course $coursecontext Course to put the block in.
     * @param stdClass $config Block instance configuration.
     */
    private function create_block(context_course $coursecontext, stdClass $config): void {
        global $DB;

        $record = (object) [
            'blockname' => 'coursesync',
            'parentcontextid' => $coursecontext->id,
            'showinsubcontexts' => 0,
            'requiredbytheme' => 0,
            'pagetypepattern' => 'course-view-*',
            'subpagepattern' => null,
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => base64_encode(serialize($config)),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $record->id = $DB->insert_record('block_instances', $record);

        context_block::instance($record->id);
    }

    /**
     * Finds the Course sync block instance in a course.
     *
     * @param int $courseid Course id.
     * @return int Block instance id.
     */
    private function block_id(int $courseid): int {
        global $DB;

        return (int) $DB->get_field(
            'block_instances',
            'id',
            [
                'blockname' => 'coursesync',
                'parentcontextid' => context_course::instance($courseid)->id,
            ],
            MUST_EXIST
        );
    }
}
