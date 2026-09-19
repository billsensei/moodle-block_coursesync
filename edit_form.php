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
 * Instance configuration form for block_coursesync.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_coursesync\local\block_helper;
use block_coursesync\local\invalid_remote_url_exception;
use block_coursesync\local\setup_instructions;
use block_coursesync\local\url_validator;

/**
 * Asks which remote site and course this block instance pulls activities from.
 *
 * One connection is configured per block instance, so a course can pull from a
 * different remote course than its neighbours.
 */
class block_coursesync_edit_form extends block_edit_form {
    /**
     * Requires the capabilities needed to see or change a connection.
     *
     * The connection settings include a token for another site, so editing
     * them is restricted to the people allowed to run a sync, not just anyone
     * who can move blocks around the page.
     */
    protected function check_access_for_dynamic_submission(): void {
        parent::check_access_for_dynamic_submission();
        block_helper::require_manage_capability($this->connection_context());
    }

    /**
     * The context the connection capabilities are checked against.
     *
     * @return context The block context, or the page context while adding.
     */
    protected function connection_context(): context {
        return $this->block->instance->id
            ? context_block::instance($this->block->instance->id)
            : $this->page->context;
    }

    /**
     * Adds the connection fields, the checks, and the remote setup checklist.
     *
     * @param MoodleQuickForm $mform The form being built.
     */
    protected function specific_definition($mform) {
        global $OUTPUT;

        $config = $this->block->config ?? new stdClass();

        $mform->addElement('header', 'coursesyncconnection', get_string('connectionheader', 'block_coursesync'));
        $mform->setExpanded('coursesyncconnection', true);

        $mform->addElement('text', 'config_remoteurl', get_string('remoteurl', 'block_coursesync'), ['size' => 50]);
        $mform->setType('config_remoteurl', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('config_remoteurl', 'remoteurl', 'block_coursesync');

        $mform->addElement('text', 'config_remotecourse', get_string('remotecourse', 'block_coursesync'), ['size' => 30]);
        $mform->setType('config_remotecourse', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('config_remotecourse', 'remotecourse', 'block_coursesync');

        $mform->addElement('password', 'config_token', get_string('token', 'block_coursesync'), ['size' => 50]);
        $mform->setType('config_token', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('config_token', 'token', 'block_coursesync');

        // The stored token is never sent back to the browser, so say whether there is one.
        $tokenstatus = empty($config->tokenciphertext)
            ? get_string('tokennotstored', 'block_coursesync')
            : get_string('tokenstored', 'block_coursesync');
        $mform->addElement('static', 'coursesynctokenstatus', '', $tokenstatus);

        // The template carries its own {{#js}} block, so the buttons are wired
        // up whether the form is rendered in a page or fetched into the modal.
        $actions = $OUTPUT->render_from_template('block_coursesync/connection_actions', [
            'uniqid' => html_writer::random_id('block_coursesync_actions'),
            'blockid' => (int) $this->block->instance->id,
            'testlabel' => get_string('testconnection', 'block_coursesync'),
            'validatelabel' => get_string('validatecourse', 'block_coursesync'),
        ]);
        $mform->addElement('static', 'coursesyncactions', '', $actions);

        $lastvalidatedlabel = get_string('lastvalidated', 'block_coursesync');
        $mform->addElement('static', 'coursesynclastvalidated', $lastvalidatedlabel, $this->last_validated_summary($config));

        $mform->addElement('header', 'coursesyncsetup', get_string('setupheader', 'block_coursesync'));
        $mform->setExpanded('coursesyncsetup', false);

        $instructions = $OUTPUT->render_from_template('block_coursesync/setup_instructions', setup_instructions::context());
        $mform->addElement('static', 'coursesyncsetupsteps', '', $instructions);
    }

    /**
     * Describes when this connection was last confirmed to work.
     *
     * @param stdClass $config The block instance configuration.
     * @return string Text for the static form element.
     */
    protected function last_validated_summary(stdClass $config): string {
        $lastvalidated = (int) ($config->lastvalidated ?? 0);
        if ($lastvalidated <= 0) {
            return get_string('notvalidatedyet', 'block_coursesync');
        }

        return get_string('lastvalidatedvalue', 'block_coursesync', (object) [
            'time' => userdate($lastvalidated),
            'sitename' => (string) ($config->remotesitename ?? ''),
            'coursename' => (string) ($config->remotecoursefullname ?? ''),
        ]);
    }

    /**
     * Checks the submitted connection settings.
     *
     * Only checks that can be made without calling the remote site happen here.
     * The remote site itself is contacted when the settings are saved.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by form element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $remoteurl = trim((string) ($data['config_remoteurl'] ?? ''));
        if ($remoteurl === '') {
            $errors['config_remoteurl'] = get_string('error:urlempty', 'block_coursesync');
        } else {
            try {
                url_validator::validate($remoteurl);
            } catch (invalid_remote_url_exception $e) {
                $errors['config_remoteurl'] = $e->getMessage();
            }
        }

        if (trim((string) ($data['config_remotecourse'] ?? '')) === '') {
            $errors['config_remotecourse'] = get_string('error:missingcourse', 'block_coursesync');
        }

        // A stored token stands in for a blank field only while the connection
        // still points at the site that issued it. Aimed anywhere else it is
        // discarded on save, so ask for the new site's token here rather than
        // let the save fail its own connection check.
        $token = trim((string) ($data['config_token'] ?? ''));
        $reusable = !empty($this->block->config->tokenciphertext)
            && block_helper::is_stored_remote($this->block->config ?? null, $remoteurl);
        if ($token === '' && !$reusable) {
            $errors['config_token'] = get_string('error:tokenrequired', 'block_coursesync');
        }

        return $errors;
    }
}
