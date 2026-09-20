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

namespace block_coursesync\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Step 2 of the setup wizard: paste the token generated on the remote site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('password', 'token', get_string('token', 'block_coursesync'), ['size' => 40]);
        $mform->setType('token', PARAM_ALPHANUM);
        $mform->addRule('token', get_string('errortokenempty', 'block_coursesync'), 'required', null, 'client');
        $mform->addHelpButton('token', 'token', 'block_coursesync');

        $mform->addElement('hidden', 'instanceid');
        $mform->setType('instanceid', PARAM_INT);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'step', 2);
        $mform->setType('step', PARAM_INT);

        $this->add_action_buttons(true, get_string('setupsaveandtest', 'block_coursesync'));
    }

    /**
     * Server-side validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $token = trim((string) ($data['token'] ?? ''));

        if ($token === '') {
            $errors['token'] = get_string('errortokenempty', 'block_coursesync');
        } else if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
            // Moodle tokens are 32 hex characters. Catching this here saves the
            // user a round trip to the remote site to be told the token is wrong.
            $errors['token'] = get_string('errortokenmalformed', 'block_coursesync');
        }

        return $errors;
    }
}
