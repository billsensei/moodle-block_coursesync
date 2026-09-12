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

// NOTE: no MOODLE_INTERNAL guard and no namespace, and no require_once for
// behat_base.php - Behat step definition files are a Moodle convention of
// their own, loaded directly by the Behat runner (which already makes
// behat_base available) rather than through the plugin's normal classes/
// autoloading. See blocks/tests/behat/behat_blocks.php for the same shape.

/**
 * Test-only steps for block_coursesync's Behat scenarios.
 *
 * The full teacher-facing flow (Phase 2's wizard) ends with pasting a
 * token generated on a REMOTE site's admin UI - not something a Behat
 * scenario can drive for real without a second live site to talk to. This
 * plugin's own Behat feature works around that by pointing the block at
 * the SAME site running the test (see sync_activities.feature) - these
 * two steps are what make that self-reference practical: one hands the
 * scenario this site's own address, the other creates a real, working
 * token for it without going through the source site's web services UI
 * (which is exactly what a teacher never does anyway - that's the remote
 * admin's job, per REMOTE_SETUP.md).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_block_coursesync extends behat_base {
    /**
     * Fills a field with this site's own wwwroot - used so the scenario
     * doesn't have to hardcode a URL/port that varies by environment.
     *
     * @Given /^I set the field "(?P<field_string>(?:[^"]|\\")*)" to this site's own address$/
     * @param string $field
     */
    public function i_set_the_field_to_this_sites_own_address(string $field): void {
        global $CFG;

        $this->execute('behat_forms::i_set_the_field_to', [$field, $CFG->wwwroot]);
    }

    /**
     * Creates a real, working Course Sync web service token for a user,
     * enabling the service and authorising the user for it as a side
     * effect (the same end state REMOTE_SETUP.md's wizard steps 2-7
     * produce by hand) - all in one step, since this plugin has no UI of
     * its own for token creation (that's core's Manage tokens page, on
     * whichever site is acting as source, which for this self-referencing
     * scenario is this same site).
     *
     * @Given /^a Course Sync token "(?P<token_string>(?:[^"]|\\")*)" exists for user "(?P<username_string>(?:[^"]|\\")*)"$/
     * @param string $token Plaintext token value - the scenario's choice, so it can be typed into a form field later.
     * @param string $username
     */
    public function a_coursesync_token_exists_for_user(string $token, string $username): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => 'block_coursesync'], '*', MUST_EXIST);

        if (!$service->enabled) {
            $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
        }

        $alreadyauthorised = $DB->record_exists('external_services_users', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ]);
        if (!$alreadyauthorised) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => $service->id,
                'userid' => $user->id,
                'timecreated' => time(),
            ]);
        }

        $DB->insert_record('external_tokens', (object) [
            'token' => $token,
            'privatetoken' => null,
            'tokentype' => 0, // EXTERNAL_TOKEN_PERMANENT.
            'userid' => $user->id,
            'externalserviceid' => $service->id,
            'contextid' => context_system::instance()->id,
            'creatorid' => $user->id,
            'iprestriction' => null,
            'validuntil' => 0,
            'timecreated' => time(),
        ]);
    }
}
