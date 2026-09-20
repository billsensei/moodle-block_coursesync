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

use block_coursesync\remote_url;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Step 1 of the setup wizard: where is the remote site?
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_url_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'remoteurl', get_string('remoteurl', 'block_coursesync'), ['size' => 60]);
        $mform->setType('remoteurl', PARAM_RAW_TRIMMED);
        $mform->addRule('remoteurl', get_string('errorurlempty', 'block_coursesync'), 'required', null, 'client');
        $mform->addHelpButton('remoteurl', 'remoteurl', 'block_coursesync');

        $mform->addElement('hidden', 'instanceid');
        $mform->setType('instanceid', PARAM_INT);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'step', 1);
        $mform->setType('step', PARAM_INT);

        $this->add_action_buttons(true, get_string('setupnext', 'block_coursesync'));
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

        $problem = remote_url::validate((string) ($data['remoteurl'] ?? ''));

        if ($problem !== null) {
            $errors['remoteurl'] = get_string($problem, 'block_coursesync');
        }

        return $errors;
    }
}
