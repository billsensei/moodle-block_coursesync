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
 * Pulls activities from the mapped remote course, as the teacher chooses.
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
    if (has_capability('block/coursesync:configure', $coursecontext)) {
        echo $OUTPUT->notification(get_string('errornotmapped', 'block_coursesync'), 'warning', false);
        echo html_writer::link(
            new moodle_url('/blocks/coursesync/setup.php', ['instanceid' => $instanceid, 'courseid' => $courseid]),
            get_string('setupmanage', 'block_coursesync'),
            ['class' => 'btn btn-primary']
        );
    } else {
        echo $OUTPUT->notification(get_string('errornotmappedaskmanager', 'block_coursesync'), 'warning', false);
    }
    echo $OUTPUT->footer();
    die;
}

if (!$confirm) {
    // Nothing is created by following a link. This page asks the other site what
    // it has, shows all of it against what is here, and lets the teacher choose;
    // the form below carries a sesskey.
    //
    // Always everything, never only what changed since the last sync: a list
    // filtered by that marker comes back empty once everything has been
    // copied, which read as "the other course has nothing in it".
    $lastsync = connection::get_last_sync($instanceid);
    $candidates = syncer::list_candidates($instanceid, $course->id, true);

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

    // Everything on the other site that this page can do something with,
    // tagged with what ticking it would do. An activity of a type nothing
    // here handles is not shown at all - there is no choice to make about it
    // on this page, so naming it here would be noise, not help. "New" gets
    // its own group at the top, pre-ticked, because that is the whole point
    // of this page; "changed" next; what is already here last. Nothing but
    // "new" starts ticked, because everything else replaces or duplicates
    // something already in this course.
    $bycmid = static fn(array $a, array $b): int => $a['activity']->cmid <=> $b['activity']->cmid;
    $rows = static function (array $activities, callable $status) use ($bycmid): array {
        $rows = array_map(
            static fn(activity $activity): array => ['activity' => $activity, 'status' => $status($activity)],
            $activities
        );
        usort($rows, $bycmid);

        return $rows;
    };

    // A name used more than once, here or there, is not guessed at: still
    // on offer, but never pre-ticked, and the row says why.
    $newrows = $rows(
        $candidates->new,
        static fn(activity $activity): string => $candidates->is_ambiguous($activity->cmid) ? 'ambiguous' : 'new'
    );
    $changedrows = $rows($candidates->changed, static fn(activity $activity): string => match (true) {
        $candidates->is_in_place($activity->cmid) => 'changedinplace',
        $candidates->is_new_edition($activity->cmid) => 'changednewedition',
        default => 'changedreplace',
    });
    $alreadyrows = $rows(
        array_merge($candidates->present, $candidates->collisions, $candidates->matched),
        static fn(activity $activity): string => match (true) {
            in_array($activity, $candidates->matched, true) => match (true) {
                $candidates->is_matched_but_owned($activity->cmid) => 'matchedowned',
                $candidates->matched_has_data($activity->cmid) => 'matchedcopy',
                default => 'matched',
            },
            in_array($activity, $candidates->collisions, true) => 'collision',
            $candidates->recopy_adds_copy($activity->cmid) => 'presentcopy',
            default => 'presentreplace',
        }
    );

    if ($newrows === [] && $changedrows === [] && $alreadyrows === []) {
        echo $OUTPUT->notification(get_string('syncnothingatall', 'block_coursesync'), 'success', false);
    } else {
        echo $OUTPUT->heading(get_string('syncchooseheading', 'block_coursesync'), 3);
        echo html_writer::tag('p', get_string('syncchooseintro', 'block_coursesync'), ['class' => 'text-muted']);

        if ($newrows === [] && $changedrows === []) {
            echo $OUTPUT->notification(
                get_string('syncnothingnew', 'block_coursesync', count($alreadyrows)),
                'success',
                false
            );
        }

        $statuslabels = [
            'new' => get_string('syncstatusnew', 'block_coursesync'),
            'changedreplace' => get_string('syncstatuschangedreplace', 'block_coursesync'),
            'changednewedition' => get_string('syncstatuschangednewedition', 'block_coursesync'),
            'changedinplace' => get_string('syncstatuschangedinplace', 'block_coursesync'),
            'presentreplace' => get_string('syncstatuspresentreplace', 'block_coursesync'),
            'presentcopy' => get_string('syncstatuspresentcopy', 'block_coursesync'),
            'collision' => get_string('syncstatuscollisioncopy', 'block_coursesync'),
            'ambiguous' => get_string('syncstatusambiguous', 'block_coursesync'),
            'matched' => get_string('syncstatusmatched', 'block_coursesync'),
            'matchedcopy' => get_string('syncstatusmatchedcopy', 'block_coursesync'),
            'matchedowned' => get_string('syncstatusmatchedowned', 'block_coursesync'),
        ];

        $head = html_writer::tag(
            'tr',
            html_writer::tag('th', get_string('syncchoosecolumn', 'block_coursesync'), ['scope' => 'col'])
            . html_writer::tag('th', get_string('previewcolname', 'block_coursesync'), ['scope' => 'col'])
            . html_writer::tag('th', get_string('previewcoltype', 'block_coursesync'), ['scope' => 'col'])
            . html_writer::tag('th', get_string('previewcolmodified', 'block_coursesync'), ['scope' => 'col'])
            . html_writer::tag('th', get_string('syncstatuscolumn', 'block_coursesync'), ['scope' => 'col'])
        );

        // Every row can be chosen; only new ones start ticked. "Select all"
        // reaches only new and changed ones (data-coursesync-bulk), so one
        // click never copies everything already here a second time.
        $renderrows = static function (array $rows) use ($statuslabels): string {
            $out = '';

            foreach ($rows as $row) {
                $activity = $row['activity'];
                $id = 'coursesync-cm-' . (int) $activity->cmid;
                $already = in_array($row['status'], ['presentreplace', 'presentcopy', 'collision'], true);
                $matched = in_array($row['status'], ['matched', 'matchedcopy', 'matchedowned'], true);

                // Select all reaches only what is plainly new or changed.
                $bulk = !$already && !$matched && $row['status'] !== 'ambiguous';

                $checkbox = html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'cmids[]',
                    'value' => (int) $activity->cmid,
                    'id' => $id,
                    'checked' => $row['status'] === 'new' ? 'checked' : null,
                    'data-coursesync-bulk' => $bulk ? 1 : null,
                    'class' => 'form-check-input',
                ]);

                $out .= html_writer::tag(
                    'tr',
                    html_writer::tag('td', $checkbox)
                    . html_writer::tag('td', html_writer::tag('label', s($activity->name), ['for' => $id]))
                    . html_writer::tag('td', s($activity->get_type_name()))
                    . html_writer::tag('td', userdate($activity->timemodified))
                    . html_writer::tag('td', $statuslabels[$row['status']])
                );
            }

            return $out;
        };

        $rendergroup = static function (
            array $rows,
            string $headingkey,
            string $captionkey,
            string $before = ''
        ) use (
            $OUTPUT,
            $head,
            $renderrows
        ): void {
            if ($rows === []) {
                return;
            }

            echo $OUTPUT->heading(get_string($headingkey, 'block_coursesync'), 4);
            echo $before;
            echo html_writer::tag(
                'table',
                html_writer::tag('caption', get_string($captionkey, 'block_coursesync'), ['class' => 'sr-only'])
                . html_writer::tag('thead', $head)
                . html_writer::tag('tbody', $renderrows($rows)),
                ['class' => 'table table-striped']
            );
        };

        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $pageurl->out(false),
            'id' => 'coursesync-choose',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => 1]);

        // See amd/src/choose.js.
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

        $rendergroup($newrows, 'syncgroupnew', 'syncchoosecaptionnew');
        $rendergroup($changedrows, 'syncgroupchanged', 'syncchoosecaptionchanged');

        // The one thing in the last group that wants a person's attention:
        // something here already carries a synced activity's identity, and
        // Course Sync did not put it there.
        $collisionwarning = '';

        if ($candidates->needs_review()) {
            $names = [];

            foreach ($candidates->collisions as $activity) {
                $names[] = s($activity->name) . ' (' . s($activity->get_type_name()) . ')';
            }

            $collisionwarning = $OUTPUT->notification(get_string('synccollisions', 'block_coursesync', (object) [
                'count' => count($names),
                'list' => implode(', ', $names),
            ]), 'warning', false);
        }

        $rendergroup($alreadyrows, 'syncgrouppresent', 'syncchoosecaptionpresent', $collisionwarning);

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

    echo $OUTPUT->footer();
    die;
}

require_sesskey();

// Only what was ticked. An empty selection is a decision, not a request to copy
// everything, so it is passed through as the empty set rather than as null.
$result = syncer::run($instanceid, $course->id, true, null, $chosen);

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
            'updated' => html_writer::tag('span', get_string('syncupdated', 'block_coursesync'),
                ['class' => 'badge bg-info text-dark']),
            'conflict' => html_writer::tag('span', get_string('syncconflicted', 'block_coursesync'),
                ['class' => 'badge bg-warning text-dark']),
            'skipped' => html_writer::tag('span', get_string('syncskipped', 'block_coursesync'),
                ['class' => 'badge bg-secondary']),
            'linked' => html_writer::tag('span', get_string('synclinkedbadge', 'block_coursesync'),
                ['class' => 'badge bg-info text-dark']),
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
