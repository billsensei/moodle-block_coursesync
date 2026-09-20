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
 * Guided setup for the connection to a remote source site.
 *
 * The wizard walks an administrator through the steps they have to perform by
 * hand on the other site. It deliberately does not try to change anything over
 * there: that would need far broader access than a sync needs.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_coursesync\connection;
use block_coursesync\form\remote_course_form;
use block_coursesync\form\remote_url_form;
use block_coursesync\form\token_form;
use block_coursesync\remote_client;
use core\output\html_writer;

$instanceid = required_param('instanceid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$step = optional_param('step', 0, PARAM_INT);
$test = optional_param('test', 0, PARAM_BOOL);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('block/coursesync:sync', $coursecontext);

$blockinstance = $DB->get_record('block_instances', ['id' => $instanceid, 'blockname' => 'coursesync'], '*', MUST_EXIST);
$blockcontext = context_block::instance($blockinstance->id);

// The block instance must really belong to this course.
if ($blockcontext->get_course_context(false)->instanceid != $course->id) {
    throw new moodle_exception('invalidcontext', 'error');
}

$pageurl = new moodle_url('/blocks/coursesync/setup.php', [
    'instanceid' => $instanceid,
    'courseid' => $courseid,
]);

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('setuptitle', 'block_coursesync'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('pluginname', 'block_coursesync'));
$PAGE->navbar->add(get_string('setuptitle', 'block_coursesync'));

$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$record = connection::get($instanceid);

// Work out which step to show when the caller did not ask for one.
if ($step === 0) {
    if ($record === null || $record->remoteurl === '') {
        $step = 1;
    } else if (empty($record->token)) {
        $step = 2;
    } else if ($record->status !== connection::STATUS_OK) {
        $step = 3;
    } else {
        $step = 4;
    }
}

// Both forms post back to this page. Moodle tells them apart by the hidden
// _qf__<formname> field it adds to each one.
$urlform = new remote_url_form($pageurl);
$tokenform = new token_form($pageurl);
$courseform = new remote_course_form($pageurl);

$urlform->set_data([
    'instanceid' => $instanceid,
    'courseid' => $courseid,
    'step' => 1,
    'remoteurl' => $record->remoteurl ?? '',
]);
$tokenform->set_data([
    'instanceid' => $instanceid,
    'courseid' => $courseid,
    'step' => 2,
]);
$courseform->set_data([
    'instanceid' => $instanceid,
    'courseid' => $courseid,
    'step' => 4,
    'remotecourse' => $record->remotecourseref ?? '',
]);

if ($urlform->is_cancelled() || $tokenform->is_cancelled() || $courseform->is_cancelled()) {
    redirect($courseurl);
}

if ($data = $urlform->get_data()) {
    connection::set_url($instanceid, $course->id, $data->remoteurl);
    redirect(new moodle_url($pageurl, ['step' => 2]));
}

if ($data = $tokenform->get_data()) {
    connection::set_token($instanceid, $data->token);
    // The sesskey has to be carried through: the step it redirects to runs the
    // connection test, which is a real action and is guarded accordingly.
    redirect(new moodle_url($pageurl, ['step' => 3, 'test' => 1, 'sesskey' => sesskey()]));
}

$coursemappingerror = null;

if ($data = $courseform->get_data()) {
    $token = connection::get_token($instanceid);
    $record = connection::get($instanceid);

    if ($record === null || $token === null) {
        $coursemappingerror = get_string('errortokenmissing', 'block_coursesync');
    } else {
        $resolved = remote_client::resolve_course($record->remoteurl, $token, $data->remotecourse);

        if ($resolved->success) {
            connection::set_remote_course($instanceid, $data->remotecourse, $resolved);
            redirect(
                new moodle_url($pageurl, ['step' => 4]),
                $resolved->get_message(),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }

        $coursemappingerror = $resolved->get_message();
    }

    $step = 4;
}

// A re-test from step 3.
if ($step === 3 && $test) {
    // Deliberately require_sesskey() rather than confirm_sesskey(): the latter
    // returns a bool, so a wrong key would silently do nothing and leave the
    // user staring at an unchanged page with no idea why.
    require_sesskey();

    $token = connection::get_token($instanceid);
    $record = connection::get($instanceid);

    if ($record === null || $record->remoteurl === '') {
        connection::record_failure($instanceid, 'errorurlempty');
    } else if ($token === null) {
        connection::record_failure($instanceid, 'errortokenunreadable');
    } else {
        $result = remote_client::ping($record->remoteurl, $token);

        if ($result->success) {
            connection::record_success($instanceid, $result->sitename, $result->release);
        } else {
            connection::record_failure($instanceid, $result->errorkey);
        }
    }

    redirect(new moodle_url($pageurl, ['step' => 3]));
}

$record = connection::get($instanceid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('setuptitle', 'block_coursesync'));

// Progress indicator.
$steps = [
    1 => get_string('setupstep1', 'block_coursesync'),
    2 => get_string('setupstep2', 'block_coursesync'),
    3 => get_string('setupstep3', 'block_coursesync'),
    4 => get_string('setupstep4', 'block_coursesync'),
];
$items = '';
foreach ($steps as $number => $label) {
    $classes = 'list-group-item';
    if ($number === $step) {
        $classes .= ' active';
    } else if ($number < $step) {
        $classes .= ' list-group-item-success';
    }
    $items .= html_writer::tag('li', $number . '. ' . $label, ['class' => $classes]);
}
echo html_writer::tag('ul', $items, ['class' => 'list-group list-group-horizontal-md mb-4']);

if ($step === 1) {
    echo html_writer::tag('p', get_string('setupstep1intro', 'block_coursesync'));
    $urlform->display();
} else if ($step === 2) {
    $remoteurl = $record->remoteurl ?? '';

    echo $OUTPUT->heading(get_string('setupstep2', 'block_coursesync'), 3);
    echo html_writer::tag('p', get_string('setupstep2intro', 'block_coursesync', s($remoteurl)));

    // The manual steps. These mirror docs/REMOTE_SETUP.md exactly; if you change
    // one, change the other.
    $instructions = '';
    for ($i = 1; $i <= 6; $i++) {
        $instructions .= html_writer::tag(
            'li',
            get_string('remotestep' . $i, 'block_coursesync', s($remoteurl)),
            ['class' => 'mb-2']
        );
    }
    echo html_writer::tag('ol', $instructions, ['class' => 'mb-4']);

    echo $OUTPUT->notification(get_string('setupdocshint', 'block_coursesync'), 'info', false);

    $tokenform->display();

    echo html_writer::link(
        new moodle_url($pageurl, ['step' => 1]),
        get_string('setupback', 'block_coursesync')
    );
} else if ($step === 3) {
    echo $OUTPUT->heading(get_string('setupstep3', 'block_coursesync'), 3);

    if ($record === null || empty($record->token)) {
        echo $OUTPUT->notification(get_string('errortokenmissing', 'block_coursesync'), 'warning', false);
    } else if ($record->status === connection::STATUS_OK) {
        echo $OUTPUT->notification(get_string('teststatusok', 'block_coursesync', (object) [
            'sitename' => s($record->remotesitename),
            'release' => s($record->remoterelease),
        ]), 'success', false);
    } else if ($record->status === connection::STATUS_ERROR) {
        echo $OUTPUT->notification(get_string($record->lasterror, 'block_coursesync'), 'error', false);
    } else {
        echo $OUTPUT->notification(get_string('teststatusuntested', 'block_coursesync'), 'info', false);
    }

    if ($record !== null) {
        $summary = html_writer::tag('dt', get_string('remoteurl', 'block_coursesync'))
            . html_writer::tag('dd', s($record->remoteurl))
            . html_writer::tag('dt', get_string('token', 'block_coursesync'))
            . html_writer::tag('dd', $record->tokenhint
                ? get_string('tokenstored', 'block_coursesync', s($record->tokenhint))
                : get_string('tokennotstored', 'block_coursesync'));

        if ($record->lastcheck > 0) {
            $summary .= html_writer::tag('dt', get_string('lastcheckedlabel', 'block_coursesync'))
                . html_writer::tag('dd', userdate($record->lastcheck));
        }

        echo html_writer::tag('dl', $summary, ['class' => 'row']);
    }

    $retesturl = new moodle_url($pageurl, ['step' => 3, 'test' => 1, 'sesskey' => sesskey()]);
    echo html_writer::link($retesturl, get_string('testconnection', 'block_coursesync'), [
        'class' => 'btn btn-primary me-2',
    ]);
    echo html_writer::link(new moodle_url($pageurl, ['step' => 2]), get_string('setupchangetoken', 'block_coursesync'), [
        'class' => 'btn btn-secondary me-2',
    ]);

    if ($record !== null && $record->status === connection::STATUS_OK) {
        echo html_writer::link(new moodle_url($pageurl, ['step' => 4]), get_string('setupnext', 'block_coursesync'), [
            'class' => 'btn btn-secondary me-2',
        ]);
    }

    echo html_writer::link($courseurl, get_string('setupfinish', 'block_coursesync'), ['class' => 'btn btn-link']);
} else {
    echo $OUTPUT->heading(get_string('setupstep4', 'block_coursesync'), 3);
    echo html_writer::tag('p', get_string('setupstep4intro', 'block_coursesync'));

    if ($coursemappingerror !== null) {
        echo $OUTPUT->notification($coursemappingerror, 'error', false);
    }

    if ($record !== null && !empty($record->remotecourseid)) {
        echo $OUTPUT->notification(get_string('coursemapped', 'block_coursesync', (object) [
            'fullname' => s($record->remotecoursename),
            'shortname' => s($record->remotecourseshortname),
        ]), 'success', false);
    }

    $courseform->display();

    if ($record !== null && !empty($record->remotecourseid)) {
        $previewurl = new moodle_url('/blocks/coursesync/preview.php', [
            'instanceid' => $instanceid,
            'courseid' => $courseid,
        ]);
        echo html_writer::link($previewurl, get_string('previewchanges', 'block_coursesync'), [
            'class' => 'btn btn-primary me-2',
        ]);
    }

    echo html_writer::link(new moodle_url($pageurl, ['step' => 3]), get_string('setupback', 'block_coursesync'), [
        'class' => 'btn btn-link',
    ]);
}

echo $OUTPUT->footer();
