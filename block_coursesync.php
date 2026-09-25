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
use core\output\html_writer;

/**
 * Course Sync block.
 *
 * Shows the state of the course's connection to a source site, and links to
 * the setup wizard, the preview, the sync page and the history.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_coursesync extends block_base {
    /**
     * @var \block_coursesync\course_result|null The remote course resolved by the
     * edit form during validation, reused when saving so the remote site is
     * asked once per save rather than twice. Declared here because PHP 8.2
     * deprecates creating properties on the fly.
     */
    public $resolvedremotecourse = null;

    /**
     * Set the block title.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_coursesync');
    }

    /**
     * This block is only usable on a course main page.
     *
     * @return array
     */
    public function applicable_formats() {
        return ['course-view' => true];
    }

    /**
     * The site-wide switches for grade sync live in settings.php.
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }

    /**
     * This block has per-instance configuration.
     *
     * @return bool
     */
    public function instance_allow_config() {
        return true;
    }

    /**
     * Only one connection per course makes sense.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Save the instance configuration.
     *
     * The connection lives in the plugin's own table rather than in configdata,
     * so that it has one home and nothing sensitive travels inside a course
     * backup.
     *
     * Note that Moodle strips the "config_" prefix in
     * block_manager::save_block_data() before calling this, so the fields
     * arrive here as remoteurl and remotecourse, not config_remoteurl and
     * config_remotecourse.
     *
     * @param stdClass $data the data from the edit form
     * @param bool $nolongerused
     * @return void
     */
    public function instance_config_save($data, $nolongerused = false) {
        $courseid = $this->get_course_id();
        $coursecontext = $this->get_course_context();

        // Being allowed to edit a block is not being allowed to decide which
        // site and course it copies from - the same rule the setup wizard
        // follows. Without it the connection fields are ignored, whatever the
        // request carried.
        if ($coursecontext === null || !has_capability('block/coursesync:configure', $coursecontext)) {
            unset($data->remoteurl, $data->remotecourse);
            parent::instance_config_save($data, $nolongerused);

            return;
        }

        if (isset($data->remoteurl) && $courseid !== null) {
            $url = trim((string) $data->remoteurl);

            if ($url !== '' && \block_coursesync\remote_url::validate($url) === null) {
                connection::set_url($this->instance->id, $courseid, $url);
            }
        }

        if (isset($data->remotecourse)) {
            $this->save_remote_course(trim((string) $data->remotecourse));
        }

        // The connection table is the source of truth, so nothing is mirrored
        // into configdata.
        unset($data->remoteurl, $data->remotecourse);

        parent::instance_config_save($data, $nolongerused);
    }

    /**
     * Store the mapped remote course.
     *
     * The edit form has already resolved the reference against the remote site
     * during validation, so the result is reused rather than asking again.
     *
     * @param string $courseref what the administrator typed, possibly empty
     * @return void
     */
    protected function save_remote_course(string $courseref) {
        if ($courseref === '') {
            connection::clear_remote_course($this->instance->id);

            return;
        }

        $resolved = $this->resolvedremotecourse ?? null;

        if ($resolved instanceof \block_coursesync\course_result && $resolved->success) {
            connection::set_remote_course($this->instance->id, $courseref, $resolved);
        }
    }

    /**
     * Remove the connection when the block instance goes away.
     *
     * @return bool
     */
    public function instance_delete() {
        connection::delete($this->instance->id);
        \block_coursesync\history::delete_for_block_instance($this->instance->id);
        \block_coursesync\grade_pull::delete_for_block_instance($this->instance->id);

        return true;
    }

    /**
     * Build the block content.
     *
     * @return stdClass|null
     */
    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

        $coursecontext = $this->get_course_context();
        $cansync = $coursecontext !== null && has_capability('block/coursesync:sync', $coursecontext);
        $canconfigure = $coursecontext !== null && has_capability('block/coursesync:configure', $coursecontext);

        $connection = empty($this->instance->id) ? null : connection::get($this->instance->id);

        if (!connection::is_configured($connection)) {
            $this->content->text = html_writer::tag('p', get_string('notconfigured', 'block_coursesync'));

            if ($canconfigure && !empty($this->instance->id)) {
                $this->content->text .= $this->setup_link(get_string('setupstart', 'block_coursesync'));
            } else if ($cansync) {
                $this->content->text .= html_writer::tag(
                    'p',
                    get_string('notconfiguredaskmanager', 'block_coursesync'),
                    ['class' => 'small text-muted']
                );
            }

            return $this->content;
        }

        $this->content->text = $this->connection_summary($connection, $cansync, $canconfigure);

        return $this->content;
    }

    /**
     * Describe the mapped remote course, and offer the change preview.
     *
     * @param stdClass $connection
     * @param bool $cansync
     * @return string HTML
     */
    protected function mapping_summary(stdClass $connection, bool $cansync): string {
        if (empty($connection->remotecourseid)) {
            return html_writer::tag('p', get_string('statusnocourse', 'block_coursesync'), ['class' => 'small']);
        }

        $label = $connection->remotecoursename !== null && $connection->remotecoursename !== ''
            ? $connection->remotecoursename
            : (string) $connection->remotecourseid;

        $out = html_writer::tag('p', get_string('statuscourse', 'block_coursesync', s($label)), ['class' => 'small']);

        $lastsync = connection::get_last_sync($this->instance->id);
        $out .= html_writer::tag(
            'p',
            $lastsync === null
                ? get_string('lastsyncnever', 'block_coursesync')
                : get_string('lastsyncat', 'block_coursesync', userdate($lastsync)),
            ['class' => 'small text-muted']
        );

        if ($cansync) {
            $params = [
                'instanceid' => $this->instance->id,
                'courseid' => $this->get_course_id(),
            ];

            $syncurl = new moodle_url('/blocks/coursesync/sync.php', $params);
            $previewurl = new moodle_url('/blocks/coursesync/preview.php', $params);

            $out .= html_writer::tag('p', html_writer::link($syncurl, get_string('syncnow', 'block_coursesync'), [
                'class' => 'btn btn-primary btn-sm',
            ]));
            $out .= html_writer::tag('p', html_writer::link(
                $previewurl,
                get_string('previewchanges', 'block_coursesync'),
                ['class' => 'btn btn-secondary btn-sm']
            ));

            $historyurl = new moodle_url('/blocks/coursesync/history.php', $params);
            $out .= html_writer::tag('p', html_writer::link(
                $historyurl,
                get_string('historyview', 'block_coursesync'),
                ['class' => 'btn btn-secondary btn-sm']
            ));
        }

        // Offered only where it can work - grade pulling switched on for the
        // site and this person allowed to do it here. The page checks again.
        if (\block_coursesync\grade_pull::check_allowed($this->get_course_id()) === null) {
            $gradesurl = new moodle_url('/blocks/coursesync/grades.php', [
                'instanceid' => $this->instance->id,
                'courseid' => $this->get_course_id(),
            ]);
            $out .= html_writer::tag('p', html_writer::link(
                $gradesurl,
                get_string('gradespull', 'block_coursesync'),
                ['class' => 'btn btn-secondary btn-sm']
            ));
        }

        return $out;
    }

    /**
     * Render the state of a configured connection.
     *
     * @param stdClass $connection
     * @param bool $cansync whether the current user may sync
     * @param bool $canconfigure whether the current user may run the setup wizard
     * @return string HTML
     */
    protected function connection_summary(stdClass $connection, bool $cansync, bool $canconfigure): string {
        $out = '';

        if ($connection->status === connection::STATUS_OK) {
            $label = $connection->remotesitename !== null && $connection->remotesitename !== ''
                ? $connection->remotesitename
                : $connection->remoteurl;

            $out .= html_writer::tag('p', get_string('statusconnected', 'block_coursesync', s($label)), [
                'class' => 'text-success',
            ]);
        } else if ($connection->status === connection::STATUS_ERROR) {
            $message = $connection->lasterror !== null
                ? get_string($connection->lasterror, 'block_coursesync')
                : get_string('errorremoterefused', 'block_coursesync');

            $out .= html_writer::tag('p', get_string('statusproblem', 'block_coursesync'), ['class' => 'text-danger']);
            $out .= html_writer::tag('p', $message, ['class' => 'small']);
        } else {
            $out .= html_writer::tag('p', get_string('statusuntested', 'block_coursesync'));
        }

        if ($connection->lastcheck > 0) {
            $out .= html_writer::tag('p', get_string('lastchecked', 'block_coursesync', userdate($connection->lastcheck)), [
                'class' => 'small text-muted',
            ]);
        }

        if ($connection->status === connection::STATUS_OK) {
            $out .= $this->mapping_summary($connection, $cansync);
        }

        if ($canconfigure) {
            $out .= $this->setup_link(get_string('setupmanage', 'block_coursesync'));
        }

        return $out;
    }

    /**
     * A link into the setup wizard for this block instance.
     *
     * @param string $label
     * @return string HTML
     */
    protected function setup_link(string $label): string {
        $url = new moodle_url('/blocks/coursesync/setup.php', [
            'instanceid' => $this->instance->id,
            'courseid' => $this->get_course_id(),
        ]);

        return html_writer::tag('p', html_writer::link($url, $label, ['class' => 'btn btn-secondary btn-sm']));
    }

    /**
     * The course this block instance lives in.
     *
     * Usually the page knows, but a block can be instantiated without one - for
     * example when Moodle is listing the blocks available to add - so fall back
     * to the block instance's own context.
     *
     * @return int|null
     */
    protected function get_course_id(): ?int {
        if (!empty($this->page->course->id)) {
            return (int) $this->page->course->id;
        }

        $coursecontext = $this->get_course_context();

        return $coursecontext === null ? null : (int) $coursecontext->instanceid;
    }

    /**
     * The course context this block instance belongs to, if it has one.
     *
     * @return context_course|null
     */
    protected function get_course_context(): ?context_course {
        if (!empty($this->page->course->id)) {
            $context = context_course::instance($this->page->course->id, IGNORE_MISSING);

            return $context ?: null;
        }

        if (empty($this->context)) {
            return null;
        }

        $context = $this->context->get_course_context(false);

        return $context ?: null;
    }
}
