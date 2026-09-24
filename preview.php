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
 * Shows what has changed in the mapped remote course.
 *
 * Read-only: nothing is pulled and nothing is marked as synced. It lists what
 * changed since the last sync; sync.php lists everything.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_coursesync\connection;
use block_coursesync\remote_client;
use core\output\html_writer;

$instanceid = required_param('instanceid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('block/coursesync:sync', $coursecontext);

$blockinstance = $DB->get_record('block_instances', ['id' => $instanceid, 'blockname' => 'coursesync'], '*', MUST_EXIST);
$blockcontext = context_block::instance($blockinstance->id);

if ($blockcontext->get_course_context(false)->instanceid != $course->id) {
    throw new moodle_exception('invalidcontext', 'error');
}

$pageurl = new moodle_url('/blocks/coursesync/preview.php', [
    'instanceid' => $instanceid,
    'courseid' => $courseid,
]);

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('previewtitle', 'block_coursesync'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('pluginname', 'block_coursesync'));
$PAGE->navbar->add(get_string('previewtitle', 'block_coursesync'));

$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$setupurl = new moodle_url('/blocks/coursesync/setup.php', [
    'instanceid' => $instanceid,
    'courseid' => $courseid,
]);

$record = connection::get($instanceid);
$canconfigure = has_capability('block/coursesync:configure', $coursecontext);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('previewtitle', 'block_coursesync'));

if (!connection::is_mapped($record)) {
    if ($canconfigure) {
        echo $OUTPUT->notification(get_string('errornotmapped', 'block_coursesync'), 'warning', false);
        echo html_writer::link($setupurl, get_string('setupmanage', 'block_coursesync'), ['class' => 'btn btn-primary']);
    } else {
        echo $OUTPUT->notification(get_string('errornotmappedaskmanager', 'block_coursesync'), 'warning', false);
    }
    echo $OUTPUT->footer();
    die;
}

$token = connection::get_token($instanceid);

if ($token === null) {
    echo $OUTPUT->notification(get_string('errortokenunreadable', 'block_coursesync'), 'error', false);

    if ($canconfigure) {
        echo html_writer::link($setupurl, get_string('setupmanage', 'block_coursesync'), ['class' => 'btn btn-primary']);
    }
    echo $OUTPUT->footer();
    die;
}

// Only what changed since the last sync. Nothing here moves that marker.
$lastsync = connection::get_last_sync($instanceid);
$since = $lastsync ?? 0;

$result = remote_client::get_modified_activities($record->remoteurl, $token, (int) $record->remotecourseid, $since);

// Where we looked.
$summary = html_writer::tag('dt', get_string('remoteurl', 'block_coursesync'))
    . html_writer::tag('dd', s($record->remoteurl))
    . html_writer::tag('dt', get_string('remotecourse', 'block_coursesync'))
    . html_writer::tag('dd', s($record->remotecoursename ?? '') . ' (' . s($record->remotecourseshortname ?? '') . ')')
    . html_writer::tag('dt', get_string('lastsynclabel', 'block_coursesync'))
    . html_writer::tag('dd', $lastsync === null
        ? get_string('lastsyncnever', 'block_coursesync')
        : userdate($lastsync));
echo html_writer::tag('dl', $summary, ['class' => 'row']);

if (!$result->success) {
    echo $OUTPUT->notification($result->get_message($lastsync), 'error', false);
} else {
    echo $OUTPUT->notification($result->get_message($lastsync), $result->count() ? 'info' : 'success', false);

    if ($result->count() > 0) {
        $rows = '';

        foreach ($result->activities as $activity) {
            $rows .= html_writer::tag(
                'tr',
                html_writer::tag('td', s($activity->name))
                . html_writer::tag('td', s($activity->get_type_name()))
                . html_writer::tag('td', s($activity->idnumber))
                . html_writer::tag('td', userdate($activity->timemodified))
                . html_writer::tag('td', (string) $activity->cmid, ['class' => 'text-muted'])
            );
        }

        $head = html_writer::tag(
            'tr',
            html_writer::tag('th', get_string('previewcolname', 'block_coursesync'))
            . html_writer::tag('th', get_string('previewcoltype', 'block_coursesync'))
            . html_writer::tag('th', get_string('previewcolidnumber', 'block_coursesync'))
            . html_writer::tag('th', get_string('previewcolmodified', 'block_coursesync'))
            . html_writer::tag('th', get_string('previewcolcmid', 'block_coursesync'))
        );

        echo html_writer::tag(
            'table',
            html_writer::tag('thead', $head) . html_writer::tag('tbody', $rows),
            ['class' => 'table table-striped']
        );
    }

    echo $OUTPUT->notification(get_string('previewnotsynced', 'block_coursesync'), 'info', false);
}

$syncurl = new moodle_url('/blocks/coursesync/sync.php', [
    'instanceid' => $instanceid,
    'courseid' => $courseid,
]);
echo html_writer::link($syncurl, get_string('syncnow', 'block_coursesync'), ['class' => 'btn btn-primary me-2']);
echo html_writer::link($pageurl, get_string('previewrefresh', 'block_coursesync'), ['class' => 'btn btn-secondary me-2']);
echo html_writer::link($courseurl, get_string('setupfinish', 'block_coursesync'), ['class' => 'btn btn-link']);

echo $OUTPUT->footer();
