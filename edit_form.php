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
 * Config form for block_coursesync instances.
 *
 * The remote site URL, a guided setup wizard for the manual steps on the
 * REMOTE site, the token field, and (Phase 3) the mapped remote course.
 * No sync controls yet - that's a later phase.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Config form for block_coursesync instances.
 */
class block_coursesync_edit_form extends block_edit_form {
    /**
     * Defines this block's own config fields: the guided setup wizard and the connection fields.
     *
     * @param MoodleQuickForm $mform
     */
    protected function specific_definition($mform) {
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        // Guided setup wizard: manual steps on the REMOTE site.
        $mform->addElement('header', 'wizardheader', get_string('wizardheading', 'block_coursesync'));
        $mform->setExpanded('wizardheader', false);

        $mform->addElement('static', 'wizardintro', '', get_string('wizardintro', 'block_coursesync'));

        $steps = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $list = '<ol>';
        foreach ($steps as $step) {
            $list .= '<li>' . get_string('wizardstep' . $step, 'block_coursesync') . '</li>';
        }
        $list .= '</ol>';
        $mform->addElement('html', $list);

        $mform->addElement('static', 'wizarddocnote', '', get_string('wizarddocnote', 'block_coursesync'));

        // Connection fields.
        $mform->addElement(
            'text',
            'config_remoteurl',
            get_string('remoteurl', 'block_coursesync'),
            ['size' => 60]
        );
        $mform->setType('config_remoteurl', PARAM_URL);
        $mform->addRule(
            'config_remoteurl',
            get_string('err_remoteurlrequired', 'block_coursesync'),
            'required',
            null,
            'client'
        );
        $mform->addHelpButton('config_remoteurl', 'remoteurl', 'block_coursesync');

        $mform->addElement(
            'advcheckbox',
            'config_allowinsecure',
            '',
            get_string('allowinsecure', 'block_coursesync')
        );
        $mform->setDefault('config_allowinsecure', 0);
        $mform->addHelpButton('config_allowinsecure', 'allowinsecure', 'block_coursesync');

        if (!empty($this->block->config->encryptedtoken)) {
            $mform->addElement(
                'static',
                'tokenstatus',
                get_string('tokenstatus', 'block_coursesync'),
                get_string('tokenstatussaved', 'block_coursesync')
            );
        } else {
            $mform->addElement(
                'static',
                'tokenstatus',
                get_string('tokenstatus', 'block_coursesync'),
                get_string('tokenstatusnone', 'block_coursesync')
            );
        }

        $mform->addElement(
            'passwordunmask',
            'config_token',
            get_string('token', 'block_coursesync'),
            ['size' => 60]
        );
        $mform->setType('config_token', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('config_token', 'token', 'block_coursesync');

        $this->add_course_mapping_fields($mform);
    }

    /**
     * Adds the "remote course" field and its cached mapping status.
     *
     * @param MoodleQuickForm $mform
     */
    protected function add_course_mapping_fields($mform): void {
        $mform->addElement(
            'text',
            'config_remotecourse',
            get_string('remotecourse', 'block_coursesync'),
            ['size' => 60]
        );
        $mform->setType('config_remotecourse', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('config_remotecourse', 'remotecourse', 'block_coursesync');

        if (!empty($this->block->config->coursemappingcourseid)) {
            // Fullname/shortname are the remote course's own (from check_course's
            // response) - untrusted, so escaped before going into a static
            // form element, which (like get_content()) renders its value as-is.
            $a = (object) [
                'fullname' => s($this->block->config->coursemappingfullname ?? ''),
                'courseid' => $this->block->config->coursemappingcourseid,
                'shortname' => s($this->block->config->coursemappingshortname ?? ''),
            ];
            $mform->addElement(
                'static',
                'coursemappingstatus',
                get_string('coursemappingstatus', 'block_coursesync'),
                get_string('coursemappingok', 'block_coursesync', $a)
            );
        } else {
            $mform->addElement(
                'static',
                'coursemappingstatus',
                get_string('coursemappingstatus', 'block_coursesync'),
                get_string('coursemappingnone', 'block_coursesync')
            );
        }
    }

    /**
     * Always starts the token field blank, even on a re-displayed form.
     */
    public function definition_after_data(): void {
        parent::definition_after_data();
        $mform = &$this->_form;
        // The token is never redisplayed once saved (it's stored encrypted,
        // and there is nothing meaningful to unmask) - always start blank.
        // Leaving it blank on submit keeps whatever is currently stored.
        if ($mform->elementExists('config_token') && !$this->is_submitted()) {
            $mform->getElement('config_token')->setValue('');
        }
    }

    /**
     * Validates the remote site URL: well-formed, HTTPS (unless the
     * development/testing checkbox allows HTTP), and - the same checkbox -
     * not a loopback/link-local/private-range address (see
     * \block_coursesync\local\url_safety, which is the actual check; this
     * just decides when it applies).
     *
     * @param array $data
     * @param array $files
     * @return array Validation errors, keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $remoteurl = trim((string) ($data['config_remoteurl'] ?? ''));
        $allowinsecure = !empty($data['config_allowinsecure']);

        if ($remoteurl === '') {
            $errors['config_remoteurl'] = get_string('err_remoteurlrequired', 'block_coursesync');
        } else if (!filter_var($remoteurl, FILTER_VALIDATE_URL)) {
            $errors['config_remoteurl'] = get_string('err_remoteurl_malformed', 'block_coursesync');
        } else {
            $scheme = parse_url($remoteurl, PHP_URL_SCHEME);
            if ($scheme !== 'https' && !($allowinsecure && $scheme === 'http')) {
                $errors['config_remoteurl'] = get_string('err_remoteurl_scheme', 'block_coursesync');
            } else if (!$allowinsecure && \block_coursesync\local\url_safety::is_private_or_loopback($remoteurl)) {
                $errors['config_remoteurl'] = get_string('err_remoteurl_privaterange', 'block_coursesync');
            }
        }

        return $errors;
    }
}
