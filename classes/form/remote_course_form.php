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
 * Step 4 of the setup wizard: which course on the remote site?
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_course_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'remotecourse', get_string('remotecourse', 'block_coursesync'), ['size' => 40]);
        $mform->setType('remotecourse', PARAM_RAW_TRIMMED);
        $mform->addRule('remotecourse', get_string('errorcourserefempty', 'block_coursesync'), 'required', null, 'client');
        $mform->addHelpButton('remotecourse', 'remotecourse', 'block_coursesync');

        $mform->addElement('hidden', 'instanceid');
        $mform->setType('instanceid', PARAM_INT);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'step', 4);
        $mform->setType('step', PARAM_INT);

        $this->add_action_buttons(true, get_string('setupsavecourse', 'block_coursesync'));
    }

    /**
     * Server-side validation. The reference is checked against the remote site
     * by the page, which has the connection to hand.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (trim((string) ($data['remotecourse'] ?? '')) === '') {
            $errors['remotecourse'] = get_string('errorcourserefempty', 'block_coursesync');
        }

        return $errors;
    }
}
