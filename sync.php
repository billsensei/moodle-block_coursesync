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

use block_coursesync\activity;
use block_coursesync\connection;
use block_coursesync\local\handler\handler_registry;
use block_coursesync\sync_result;
use block_coursesync\syncer;
use core\output\html_writer;

$instanceid = required_param('instanceid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$full = optional_param('full', 0, PARAM_BOOL);
$chosen = optional_param_array('cmids', [], PARAM_INT);

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
    // Nothing is created by following a link. This page asks the other site what
    // it has, shows what is not here yet, and lets the teacher choose; the form
    // below carries a sesskey.
    $lastsync = connection::get_last_sync($instanceid);
    $candidates = syncer::list_candidates($instanceid, $course->id, (bool) $full);

    if (!$candidates->success) {
        echo $OUTPUT->notification(get_string($candidates->errorkey, 'block_coursesync'), 'error', false);
        echo html_writer::link($courseurl, get_string('syncbacktocourse', 'block_coursesync'), [
            'class' => 'btn btn-primary',
        ]);
        echo $OUTPUT->footer();
        die;
    }

    echo html_writer::tag('p', get_string('syncconfirm', 'block_coursesync', (object) [
        'course' => s($record->remotecoursename),
        'types' => implode(', ', handler_registry::supported_names()),
    ]));
    echo html_writer::tag('p', $lastsync === null
        ? get_string('syncconfirmnever', 'block_coursesync')
        : get_string('syncconfirmsince', 'block_coursesync', userdate($lastsync)));

    // Everything on the other site, tagged with why it is or is not offered.
    // A teacher wants to see the whole course, not just the copyable slice, so
    // only "new" gets a live checkbox — the rest are listed next to it, ticked
    // never, so the picture of what is already here is never a guess.
    $tag = static fn(string $status): \Closure => static fn(activity $activity): array => [
        'activity' => $activity,
        'status' => $status,
    ];

    $everything = array_merge(
        array_map($tag('new'), $candidates->new),
        array_map($tag('present'), $candidates->present),
        array_map($tag('collision'), $candidates->collisions),
        array_map($tag('unsupported'), $candidates->unsupported)
    );

    if ($everything === []) {
        echo $OUTPUT->notification(get_string('syncnothingatall', 'block_coursesync'), 'success', false);
    } else {
        usort($everything, static fn(array $a, array $b): int => $a['activity']->cmid <=> $b['activity']->cmid);

        if (!$candidates->has_any()) {
            echo $OUTPUT->notification(
                get_string('syncnothingnew', 'block_coursesync', $candidates->present_count()),
                'success',
                false
            );
        }

        echo $OUTPUT->heading(get_string('syncchooseheading', 'block_coursesync'), 3);
        echo html_writer::tag('p', get_string('syncchooseintro', 'block_coursesync'), ['class' => 'text-muted']);

        if ($candidates->has_any()) {
            echo html_writer::start_tag('form', [
                'method' => 'post',
                'action' => $pageurl->out(false),
                'id' => 'coursesync-choose',
            ]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => 1]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'full', 'value' => (int) $full]);

            // Only the enabled (new) checkboxes respond to these; the ones
            // shown for reference are never touched. See amd/src/choose.js.
            echo html_writer::tag(
                'div',
                html_writer::tag('button', get_string('syncselectall', 'block_coursesync'), [
                    'type' => 'button',
                    'data-action' => 'coursesync-select-all',
                    'class' => 'btn btn-link btn-sm p-0 me-3',
                ])
                . html_writer::tag('button', get_string('syncselectnone', 'block_coursesync'), [
                    'type' => 'button',
                    'data-action' => 'coursesync-select-none',
                    'class' => 'btn btn-link btn-sm p-0',
                ]),
                ['class' => 'mb-2']
            );

            $PAGE->requires->js_call_amd('block_coursesync/choose', 'init');
        }

        $statuslabels = [
            'new' => get_string('syncstatusnew', 'block_coursesync'),
            'present' => get_string('syncstatuspresent', 'block_coursesync'),
            'collision' => get_string('syncstatuscollision', 'block_coursesync'),
            'unsupported' => get_string('syncstatusunsupported', 'block_coursesync'),
        ];

        $rows = '';

        foreach ($everything as $row) {
            $activity = $row['activity'];
            $isnew = $row['status'] === 'new';
            $id = 'coursesync-cm-' . (int) $activity->cmid;

            // Only what is not on this course yet can be copied. Everything
            // else is shown for reference with its checkbox disabled, so it
            // cannot be ticked and is not sent even if a browser ignores that.
            $checkbox = html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => $isnew ? 'cmids[]' : null,
                'value' => (int) $activity->cmid,
                'id' => $id,
                'checked' => $isnew ? 'checked' : null,
                'disabled' => $isnew ? null : 'disabled',
                'class' => 'form-check-input',
            ]);

            $rows .= html_writer::tag(
                'tr',
                html_writer::tag('td', $checkbox)
                . html_writer::tag('td', html_writer::tag('label', s($activity->name), ['for' => $id]))
                . html_writer::tag('td', s($activity->get_type_name()))
                . html_writer::tag('td', userdate($activity->timemodified))
                . html_writer::tag('td', $statuslabels[$row['status']])
            );
        }

        $head = html_writer::tag(
            'tr',
            html_writer::tag('th', get_string('syncchoosecolumn', 'block_coursesync'), ['scope' => 'col'])
            . html_writer::tag('th', get_string('previewcolname', 'block_coursesync'), ['scope' => 'col'])
            . html_writer::tag('th', get_string('previewcoltype', 'block_coursesync'), ['scope' => 'col'])
            . html_writer::tag('th', get_string('previewcolmodified', 'block_coursesync'), ['scope' => 'col'])
            . html_writer::tag('th', get_string('syncstatuscolumn', 'block_coursesync'), ['scope' => 'col'])
        );

        echo html_writer::tag(
            'table',
            html_writer::tag('caption', get_string('syncchoosecaption', 'block_coursesync'), ['class' => 'sr-only'])
            . html_writer::tag('thead', $head)
            . html_writer::tag('tbody', $rows),
            ['class' => 'table table-striped']
        );

        if ($candidates->has_any()) {
            echo html_writer::tag(
                'div',
                html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'value' => get_string('syncchoosesubmit', 'block_coursesync'),
                    'class' => 'btn btn-primary me-2',
                ])
                . html_writer::link($courseurl, get_string('cancel'), ['class' => 'btn btn-secondary']),
                ['class' => 'mb-4']
            );

            echo html_writer::end_tag('form');
        }
    }

    // The one thing on this page that wants a person's attention: something in
    // this course already carries a synced activity's identity, and Course Sync
    // did not put it there. It is not offered, and it is not quietly counted
    // among the ordinary already-here ones either.
    if ($candidates->needs_review()) {
        $names = [];

        foreach ($candidates->collisions as $activity) {
            $names[] = s($activity->name) . ' (' . s($activity->get_type_name()) . ')';
        }

        echo $OUTPUT->notification(get_string('synccollisions', 'block_coursesync', (object) [
            'count' => count($names),
            'list' => implode(', ', $names),
        ]), 'warning', false);
    }

    // Types this plugin cannot copy are named rather than silently missing, so
    // a teacher knows to move them by hand.
    if ($candidates->unsupported !== []) {
        $names = [];

        foreach ($candidates->unsupported as $activity) {
            $names[] = s($activity->name) . ' (' . s($activity->get_type_name()) . ')';
        }

        echo html_writer::tag('p', get_string('syncunsupportedhere', 'block_coursesync', (object) [
            'count' => count($names),
            'list' => implode(', ', $names),
        ]), ['class' => 'text-muted small']);
    }

    // A conflict that has been dealt with by hand would otherwise never be
    // offered again, because the last synced marker has moved past it.
    if (!$full) {
        echo html_writer::tag('p', html_writer::link(
            new moodle_url($pageurl, ['full' => 1]),
            get_string('syncfullrecheck', 'block_coursesync')
        ) . ' ' . html_writer::tag(
            'span',
            get_string('syncfullrecheckhint', 'block_coursesync'),
            ['class' => 'text-muted small']
        ), ['class' => 'mt-4']);
    }

    echo $OUTPUT->footer();
    die;
}

require_sesskey();

// Only what was ticked. An empty selection is a decision, not a request to copy
// everything, so it is passed through as the empty set rather than as null.
$result = syncer::run($instanceid, $course->id, (bool) $full, null, $chosen);

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
            $parts[] = sync_result::describe_note($note);
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
