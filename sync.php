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
 * Pulls new activities from the mapped remote course.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_coursesync\connection;
use block_coursesync\local\handler\handler_registry;
use block_coursesync\syncer;
use core\output\html_writer;

$instanceid = required_param('instanceid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$full = optional_param('full', 0, PARAM_BOOL);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('block/coursesync:sync', $coursecontext);

$blockinstance = $DB->get_record('block_instances', ['id' => $instanceid, 'blockname' => 'coursesync'], '*', MUST_EXIST);
$blockcontext = context_block::instance($blockinstance->id);

if ($blockcontext->get_course_context(false)->instanceid != $course->id) {
    throw new moodle_exception('invalidcontext', 'error');
}

$pageurl = new moodle_url('/blocks/coursesync/sync.php', [
    'instanceid' => $instanceid,
    'courseid' => $courseid,
]);
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$previewurl = new moodle_url('/blocks/coursesync/preview.php', [
    'instanceid' => $instanceid,
    'courseid' => $courseid,
]);

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('synctitle', 'block_coursesync'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('pluginname', 'block_coursesync'));
$PAGE->navbar->add(get_string('synctitle', 'block_coursesync'));

$record = connection::get($instanceid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('synctitle', 'block_coursesync'));

if (!connection::is_mapped($record)) {
    echo $OUTPUT->notification(get_string('errornotmapped', 'block_coursesync'), 'warning', false);
    echo html_writer::link(
        new moodle_url('/blocks/coursesync/setup.php', ['instanceid' => $instanceid, 'courseid' => $courseid]),
        get_string('setupmanage', 'block_coursesync'),
        ['class' => 'btn btn-primary']
    );
    echo $OUTPUT->footer();
    die;
}

if (!$confirm) {
    // Creating activities is not something to do by following a link, so the
    // run is behind a confirmation with a sesskey.
    $lastsync = connection::get_last_sync($instanceid);

    echo html_writer::tag('p', get_string('syncconfirm', 'block_coursesync', (object) [
        'course' => s($record->remotecoursename),
        'types' => implode(', ', handler_registry::supported_names()),
    ]));
    echo html_writer::tag('p', $lastsync === null
        ? get_string('syncconfirmnever', 'block_coursesync')
        : get_string('syncconfirmsince', 'block_coursesync', userdate($lastsync)));

    echo $OUTPUT->confirm(
        get_string('syncconfirmquestion', 'block_coursesync'),
        new moodle_url($pageurl, ['confirm' => 1, 'sesskey' => sesskey()]),
        $courseurl
    );

    // A conflict that has been dealt with by hand would otherwise never be
    // offered again, because the last synced marker has moved past it.
    echo html_writer::tag('p', html_writer::link(
        new moodle_url($pageurl, ['confirm' => 1, 'full' => 1, 'sesskey' => sesskey()]),
        get_string('syncfullrecheck', 'block_coursesync')
    ) . ' ' . html_writer::tag(
        'span',
        get_string('syncfullrecheckhint', 'block_coursesync'),
        ['class' => 'text-muted small']
    ), ['class' => 'mt-4']);

    echo $OUTPUT->footer();
    die;
}

require_sesskey();

$result = syncer::run($instanceid, $course->id, $full);

if (!$result->success) {
    echo $OUTPUT->notification($result->get_message(), 'error', false);
} else if ($result->needs_review()) {
    echo $OUTPUT->notification($result->get_message(), 'warning', false);
    echo $OUTPUT->notification(get_string('syncneedsreview', 'block_coursesync'), 'info', false);
} else {
    echo $OUTPUT->notification($result->get_message(), 'success', false);
}

if ($result->items !== []) {
    $rows = '';

    foreach ($result->items as $item) {
        $badge = match ($item['outcome']) {
            'created' => html_writer::tag('span', get_string('synccreated', 'block_coursesync'),
                ['class' => 'badge bg-success']),
            'conflict' => html_writer::tag('span', get_string('syncconflicted', 'block_coursesync'),
                ['class' => 'badge bg-warning text-dark']),
            'skipped' => html_writer::tag('span', get_string('syncskipped', 'block_coursesync'),
                ['class' => 'badge bg-secondary']),
            default => html_writer::tag(
                'span',
                get_string('syncfailed', 'block_coursesync'),
                ['class' => 'badge bg-danger']
            ),
        };

        $parts = [];

        if ($item['detail'] !== null) {
            $parts[] = get_string($item['detail'], 'block_coursesync');
        }

        foreach ($item['notes'] as $note) {
            $parts[] = get_string($note, 'block_coursesync');
        }

        $detail = implode(' ', $parts);

        $rows .= html_writer::tag(
            'tr',
            html_writer::tag('td', $badge)
            . html_writer::tag('td', s($item['name']))
            . html_writer::tag('td', s($item['modname']))
            . html_writer::tag('td', $detail)
        );
    }

    $head = html_writer::tag(
        'tr',
        html_writer::tag('th', get_string('syncoutcome', 'block_coursesync'))
        . html_writer::tag('th', get_string('previewcolname', 'block_coursesync'))
        . html_writer::tag('th', get_string('previewcoltype', 'block_coursesync'))
        . html_writer::tag('th', get_string('syncdetail', 'block_coursesync'))
    );

    echo html_writer::tag(
        'table',
        html_writer::tag('thead', $head) . html_writer::tag('tbody', $rows),
        ['class' => 'table table-striped']
    );
}

if ($result->success) {
    echo $OUTPUT->notification(
        $result->lastsyncupdated
            ? get_string('synclastsyncmoved', 'block_coursesync')
            : get_string('synclastsyncheld', 'block_coursesync'),
        $result->lastsyncupdated ? 'info' : 'warning',
        false
    );
}

echo html_writer::link($courseurl, get_string('syncbacktocourse', 'block_coursesync'), [
    'class' => 'btn btn-primary me-2',
]);
echo html_writer::link($previewurl, get_string('previewchanges', 'block_coursesync'), [
    'class' => 'btn btn-secondary me-2',
]);
echo html_writer::link(
    new moodle_url('/blocks/coursesync/history.php', ['instanceid' => $instanceid, 'courseid' => $courseid]),
    get_string('historyview', 'block_coursesync'),
    ['class' => 'btn btn-secondary']
);

echo $OUTPUT->footer();
