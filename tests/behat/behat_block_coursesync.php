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
 * Behat page resolver for block_coursesync.
 *
 * @package    block_coursesync
 * @category   test
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Lets scenarios open this plugin's pages by course shortname.
 *
 * @package    block_coursesync
 * @category   test
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_block_coursesync extends behat_base {
    /** @var string The account this site issues its own sync token to. */
    const SYNC_USERNAME = 'coursesync_behat';

    /** @var string|null The token this site issued to itself, for the scenario to paste. */
    protected static ?string $synctoken = null;
    /**
     * Recognised page types.
     *
     * Recognised page names are:
     * | Setup   | Course shortname | The connection setup wizard for that course's block |
     * | Preview | Course shortname | The remote change preview for that course's block   |
     * | Sync    | Course shortname | The sync page for that course's block               |
     * | History | Course shortname | The sync history for that course's block            |
     *
     * @param string $type identifies which type of page this is, e.g. 'Preview'
     * @param string $identifier identifies the particular page, here a course shortname
     * @return moodle_url the corresponding URL
     * @throws Exception with a meaningful error message if the specified page cannot be found
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        global $DB;

        $courseid = $this->get_course_id($identifier);
        $context = context_course::instance($courseid);

        $instance = $DB->get_record('block_instances', [
            'blockname' => 'coursesync',
            'parentcontextid' => $context->id,
        ]);

        if (!$instance) {
            throw new Exception("No Course Sync block in the course '{$identifier}'.");
        }

        $params = ['instanceid' => $instance->id, 'courseid' => $courseid];

        switch (strtolower($type)) {
            case 'setup':
                return new moodle_url('/blocks/coursesync/setup.php', $params);
            case 'preview':
                return new moodle_url('/blocks/coursesync/preview.php', $params);
            case 'sync':
                return new moodle_url('/blocks/coursesync/sync.php', $params);
            case 'history':
                return new moodle_url('/blocks/coursesync/history.php', $params);
            default:
                throw new Exception("Unrecognised page type '{$type}'.");
        }
    }

    /**
     * Set this site up so it can act as its own Course Sync source.
     *
     * A full teacher-facing test needs a source site to talk to. Rather than
     * depending on a second server being up, the Behat site is pointed at
     * itself: web services are switched on, the Course Sync service is enabled,
     * and a token is issued to an account that holds the three capabilities the
     * service needs. Everything after that is a real web service call.
     *
     * @Given /^this site is set up as a Course Sync source$/
     */
    public function this_site_is_set_up_as_a_course_sync_source() {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/webservice/lib.php');

        set_config('enablewebservices', 1);

        $protocols = empty($CFG->webserviceprotocols) ? [] : explode(',', $CFG->webserviceprotocols);
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
        }

        $service = $DB->get_record('external_services', ['shortname' => 'block_coursesync'], '*', MUST_EXIST);
        $service->enabled = 1;
        $DB->update_record('external_services', $service);

        $user = $DB->get_record('user', ['username' => self::SYNC_USERNAME]);

        if (!$user) {
            $new = new stdClass();
            $new->username = self::SYNC_USERNAME;
            $new->firstname = 'Course Sync';
            $new->lastname = 'Service';
            $new->email = 'coursesync-service@example.invalid';
            $new->auth = 'manual';
            $new->confirmed = 1;
            $new->mnethostid = $CFG->mnet_localhost_id;
            $new->password = 'Sync#Service1!';
            $user = $DB->get_record('user', ['id' => user_create_user($new, true, false)], '*', MUST_EXIST);
        }

        $syscontext = context_system::instance();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'coursesyncservice']);

        if (!$roleid) {
            $roleid = create_role('Course Sync service', 'coursesyncservice', 'Calls the Course Sync service.');
        }

        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability('block/coursesync:sync', CAP_ALLOW, $roleid, $syscontext->id, true);
        assign_capability('webservice/rest:use', CAP_ALLOW, $roleid, $syscontext->id, true);
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, $syscontext->id, true);
        role_assign($roleid, $user->id, $syscontext->id);
        reload_all_capabilities();

        if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $user->id])) {
            $authorised = new stdClass();
            $authorised->externalserviceid = $service->id;
            $authorised->userid = $user->id;
            $authorised->timecreated = time();
            (new webservice())->add_ws_authorised_user($authorised);
        }

        $existing = $DB->get_record('external_tokens', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ]);

        self::$synctoken = $existing
            ? $existing->token
            : \core_external\util::generate_token(
                EXTERNAL_TOKEN_PERMANENT,
                $service,
                $user->id,
                $syscontext,
                0,
                '',
                'Course Sync (behat)'
            );
    }

    /**
     * Record the remote site address on a course's Course Sync block.
     *
     * This stands in for the first step of the setup wizard, which cannot be
     * driven here: that step requires an https address and the Behat site is
     * served over plain http. Everything from the token onwards is done through
     * the interface by the scenario itself.
     *
     * @Given /^the Course Sync block in course "(?P<shortname>(?:[^"]|\\")*)" points at this site$/
     * @param string $shortname
     */
    public function the_course_sync_block_points_at_this_site(string $shortname) {
        global $CFG, $DB;

        $courseid = $this->get_course_id($shortname);
        $context = context_course::instance($courseid);

        $instance = $DB->get_record('block_instances', [
            'blockname' => 'coursesync',
            'parentcontextid' => $context->id,
        ], '*', MUST_EXIST);

        \block_coursesync\connection::set_url($instance->id, $courseid, $CFG->wwwroot);
    }

    /**
     * Type the token this site issued into the token field.
     *
     * @When /^I enter the Course Sync token$/
     */
    public function i_enter_the_course_sync_token() {
        if (self::$synctoken === null) {
            throw new Exception('No Course Sync token has been created. '
                . 'Use "Given this site is set up as a Course Sync source" first.');
        }

        $this->execute('behat_forms::i_set_the_field_to', ['Token', self::$synctoken]);
    }
}
