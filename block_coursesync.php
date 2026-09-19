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
 * Block class for block_coursesync.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_coursesync\local\block_helper;
use block_coursesync\local\connection;
use block_coursesync\local\remote_client;
use block_coursesync\local\sync\audit_log;
use block_coursesync\local\sync\available;
use block_coursesync\local\sync\pull_ledger;
use block_coursesync\local\token_store;
use block_coursesync\local\url_validator;

/**
 * Lets a teacher or manager pull new/updated activities into this course
 * from a course on another Moodle site over the Web Services REST API.
 */
class block_coursesync extends block_base {
    /**
     * Sets the block title.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_coursesync');
    }

    /**
     * Returns the content displayed in the block.
     *
     * @return stdClass
     */
    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (empty($this->instance->id) || empty($this->context) || empty($this->page)) {
            return $this->content;
        }

        // Nothing here is of any use to a student, and the block hides itself
        // when its content is empty.
        $canview = has_capability('block/coursesync:viewhistory', $this->context)
            || has_capability('block/coursesync:trigger', $this->context);
        if (!$canview) {
            return $this->content;
        }

        $renderable = new \block_coursesync\output\block_content(
            (int) $this->instance->id,
            $this->context,
            $this->config ?? null,
            $this->page->url
        );

        $this->content->text = $this->page->get_renderer('block_coursesync')->render($renderable);

        return $this->content;
    }

    /**
     * Defines the page formats this block may appear on.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'course-view' => true,
        ];
    }

    /**
     * This block has no site-level (admin) settings.
     *
     * @return bool
     */
    public function has_config() {
        return false;
    }

    /**
     * Only one instance of this block is allowed per page.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Discards this instance's pull ledger and history when the block is removed.
     *
     * @return bool
     */
    public function instance_delete() {
        $blockinstanceid = (int) $this->instance->id;

        pull_ledger::delete_for_block($blockinstanceid);
        audit_log::delete_for_block($blockinstanceid);
        available::invalidate($blockinstanceid);

        return true;
    }

    /**
     * Stores the connection settings, encrypting the token and verifying the remote site.
     *
     * The token arrives from the form in clear and is replaced by its
     * ciphertext before anything is written, because block configuration is
     * stored in the database as plain serialised data.
     *
     * @param stdClass $data Configuration to store.
     * @param bool $nolongerused Unused, kept for signature compatibility.
     */
    public function instance_config_save($data, $nolongerused = false) {
        $config = clone($data);

        $submittedtoken = isset($config->token) ? trim((string) $config->token) : '';
        unset($config->token);

        if ($submittedtoken !== '') {
            $config->tokenciphertext = token_store::encrypt($submittedtoken);
        } else if (!block_helper::is_stored_remote($this->config ?? null, (string) ($config->remoteurl ?? ''))) {
            // The connection has been pointed at a different site without a new
            // token being given. The old one belongs to the old site and must
            // not be sent to the new one, so it is discarded rather than reused.
            $config->tokenciphertext = '';
        }

        if (!empty($config->remoteurl)) {
            try {
                $config->remoteurl = url_validator::validate((string) $config->remoteurl);
            } catch (moodle_exception $e) {
                // Keep whatever was given; the form rejects invalid URLs before reaching here.
                debugging('block_coursesync stored an unvalidated remote URL: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $this->verify_connection($config);

        // The connection may now point somewhere else entirely, so anything the
        // last check said about what is waiting no longer describes this block.
        available::invalidate((int) $this->instance->id);

        parent::instance_config_save($config, $nolongerused);
    }

    /**
     * Confirms the saved settings actually reach the remote course.
     *
     * This runs on save rather than trusting the browser, so that the stored
     * "last validated" time always reflects a check this server made itself.
     *
     * @param stdClass $config Configuration being saved, updated in place.
     */
    protected function verify_connection(stdClass $config): void {
        $token = token_store::decrypt((string) ($config->tokenciphertext ?? ''));

        if (empty($config->remoteurl) || empty($config->remotecourse) || $token === '') {
            $config->lastvalidated = 0;
            return;
        }

        try {
            $client = new remote_client((string) $config->remoteurl, $token);
            $site = connection::describe_site($client);
            $course = connection::resolve_course($client, (string) $config->remotecourse);
        } catch (moodle_exception $e) {
            $config->lastvalidated = 0;
            \core\notification::warning(get_string('savedbutnotvalidated', 'block_coursesync', $e->getMessage()));
            return;
        }

        $config->remotesitename = $site['sitename'];
        $config->remotecourseid = $course['id'];
        $config->remotecoursefullname = $course['fullname'];
        $config->lastvalidated = time();

        \core\notification::success(get_string('savedandvalidated', 'block_coursesync', (object) [
            'sitename' => $site['sitename'],
            'coursename' => $course['fullname'],
        ]));
    }
}
