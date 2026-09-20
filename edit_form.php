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

use block_coursesync\connection;
use block_coursesync\remote_client;
use block_coursesync\remote_url;

/**
 * Instance configuration form for the Course Sync block.
 *
 * Only the remote site URL lives here. The token is handled by the setup
 * wizard, which can give the administrator the context and the remote-site
 * instructions that a single form field cannot.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_coursesync_edit_form extends block_edit_form {
    /**
     * Add the block-specific fields.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    protected function specific_definition($mform) {
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        $mform->addElement('text', 'config_remoteurl', get_string('remoteurl', 'block_coursesync'), ['size' => 60]);
        $mform->setType('config_remoteurl', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('config_remoteurl', 'remoteurl', 'block_coursesync');

        $mform->addElement('text', 'config_remotecourse', get_string('remotecourse', 'block_coursesync'), ['size' => 40]);
        $mform->setType('config_remotecourse', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('config_remotecourse', 'remotecourse', 'block_coursesync');

        $connection = empty($this->block->instance->id) ? null : connection::get($this->block->instance->id);

        if ($connection !== null) {
            $mform->setDefault('config_remoteurl', $connection->remoteurl);
            $mform->setDefault('config_remotecourse', (string) $connection->remotecourseref);
        }

        $wizardurl = new moodle_url('/blocks/coursesync/setup.php', [
            'instanceid' => $this->block->instance->id ?? 0,
            'courseid' => $this->page->course->id,
        ]);

        $mform->addElement('static', 'wizardlink', '', html_writer::link(
            $wizardurl,
            get_string('setupmanage', 'block_coursesync')
        ));
        $mform->addElement('static', 'wizardhint', '', get_string('remoteurlwizardhint', 'block_coursesync'));
    }

    /**
     * Reject anything that is not a well-formed HTTPS URL.
     *
     * @param array $data
     * @param array $files
     * @return array errors keyed by form element name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $url = trim((string) ($data['config_remoteurl'] ?? ''));

        if ($url === '') {
            // An empty value simply means "not configured yet".
            return $errors;
        }

        $problem = remote_url::validate($url);

        if ($problem !== null) {
            $errors['config_remoteurl'] = get_string($problem, 'block_coursesync');

            return $errors;
        }

        $courseproblem = $this->validate_remote_course($url, trim((string) ($data['config_remotecourse'] ?? '')));

        if ($courseproblem !== null) {
            $errors['config_remotecourse'] = $courseproblem;
        }

        return $errors;
    }

    /**
     * Confirm the remote course exists and the stored token can reach it.
     *
     * The successful result is kept on the block so that
     * block_coursesync::instance_config_save() can store it without asking the
     * remote site a second time.
     *
     * @param string $url the remote site URL as entered
     * @param string $courseref the course id or shortname as entered
     * @return string|null a message to show against the field, or null if it is fine
     */
    protected function validate_remote_course(string $url, string $courseref): ?string {
        $this->block->resolvedremotecourse = null;

        if ($courseref === '') {
            // Clearing the mapping is allowed.
            return null;
        }

        $instanceid = $this->block->instance->id ?? 0;

        // Checking the course against the remote site spends the stored token.
        // Being allowed to configure a block is not the same as being allowed to
        // use its connection, so the sync permission is required before any
        // outbound call is made on this user's behalf.
        if (!has_capability('block/coursesync:sync', $this->page->context)) {
            return get_string('errornosyncpermission', 'block_coursesync');
        }

        $token = $instanceid ? connection::get_token($instanceid) : null;

        if ($token === null) {
            return get_string('errornotokenyet', 'block_coursesync');
        }

        $connection = connection::get($instanceid);

        if ($connection === null || $connection->remoteurl !== remote_url::normalise($url)) {
            // The site is being changed in the same save, so the stored token
            // belongs to the old site and cannot confirm anything here.
            return get_string('errorsitechanged', 'block_coursesync');
        }

        $result = remote_client::resolve_course($connection->remoteurl, $token, $courseref);

        if (!$result->success) {
            return $result->get_message();
        }

        $this->block->resolvedremotecourse = $result;

        return null;
    }
}
