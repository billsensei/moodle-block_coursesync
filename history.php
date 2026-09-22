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
 * Shows what past syncs did.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_coursesync\history;
use block_coursesync\sync_result;
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

$pageurl = new moodle_url('/blocks/coursesync/history.php', [
    'instanceid' => $instanceid,
    'courseid' => $courseid,
]);

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('historytitle', 'block_coursesync'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('pluginname', 'block_coursesync'));
$PAGE->navbar->add(get_string('historytitle', 'block_coursesync'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('historytitle', 'block_coursesync'));

$runs = history::get_runs($instanceid);

if ($runs === []) {
    echo $OUTPUT->notification(get_string('historyempty', 'block_coursesync'), 'info', false);
} else {
    // Renders one row of an activity list inside a run.
    $renderitem = function (array $item): string {
        $detail = '';

        if (!empty($item['detail'])) {
            $detail = get_string($item['detail'], 'block_coursesync');
        }

        foreach ($item['notes'] ?? [] as $note) {
            $detail = trim($detail . ' ' . sync_result::describe_note($note));
        }

        return html_writer::tag(
            'tr',
            html_writer::tag('td', s($item['name']))
            . html_writer::tag('td', s($item['modname']))
            . html_writer::tag('td', $detail)
        );
    };

    // Renders a table of activities, or nothing when the list is empty.
    $rendergroup = function (array $items, string $headingkey, callable $renderitem): string {
        if ($items === []) {
            return '';
        }

        $rows = '';

        foreach ($items as $item) {
            $rows .= $renderitem($item);
        }

        $head = html_writer::tag(
            'tr',
            html_writer::tag('th', get_string('previewcolname', 'block_coursesync'))
            . html_writer::tag('th', get_string('previewcoltype', 'block_coursesync'))
            . html_writer::tag('th', get_string('syncdetail', 'block_coursesync'))
        );

        return html_writer::tag('h5', get_string($headingkey, 'block_coursesync'), ['class' => 'mt-3'])
            . html_writer::tag(
                'table',
                html_writer::tag('thead', $head) . html_writer::tag('tbody', $rows),
                ['class' => 'table table-sm table-striped']
            );
    };

    foreach ($runs as $run) {
        $badgeclass = match ($run->status) {
            history::STATUS_OK => 'bg-success',
            history::STATUS_REVIEW => 'bg-warning text-dark',
            default => 'bg-danger',
        };

        $summary = html_writer::tag('span', get_string('historystatus' . $run->status, 'block_coursesync'), [
            'class' => 'badge ' . $badgeclass . ' me-2',
        ]);
        $summary .= html_writer::tag('strong', userdate($run->timestarted));
        $summary .= ' — ' . get_string('historycounts', 'block_coursesync', (object) [
            'pulled' => $run->pulledcount,
            'conflicts' => $run->conflictcount,
        ]);

        $body = '';

        $user = core_user::get_user($run->userid);
        $body .= html_writer::tag('p', get_string('historystartedby', 'block_coursesync', (object) [
            'user' => $user ? fullname($user) : get_string('historyunknownuser', 'block_coursesync'),
            'when' => userdate($run->timestarted),
        ]), ['class' => 'small text-muted']);

        $body .= html_writer::tag('p', $run->since > 0
            ? get_string('historylookedsince', 'block_coursesync', userdate($run->since))
            : get_string('historylookedall', 'block_coursesync'), ['class' => 'small text-muted']);

        if ($run->status === history::STATUS_FAILED && $run->errorkey !== null) {
            $body .= $OUTPUT->notification(get_string($run->errorkey, 'block_coursesync'), 'error', false);
        }

        $body .= $rendergroup($run->pulled, 'historypulled', $renderitem);
        $body .= $rendergroup($run->conflicts, 'historyconflicts', $renderitem);
        $body .= $rendergroup($run->others, 'historyothers', $renderitem);

        if ($run->pulled === [] && $run->conflicts === [] && $run->others === []) {
            $body .= html_writer::tag('p', get_string('historynothing', 'block_coursesync'));
        }

        $body .= html_writer::tag('p', $run->lastsyncmoved
            ? get_string('synclastsyncmoved', 'block_coursesync')
            : get_string('synclastsyncheld', 'block_coursesync'), ['class' => 'small text-muted']);

        echo html_writer::tag(
            'details',
            html_writer::tag('summary', $summary, ['class' => 'p-2']) . html_writer::div($body, 'p-3'),
            ['class' => 'border rounded mb-2']
        );
    }
}

echo html_writer::link(
    new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('syncbacktocourse', 'block_coursesync'),
    ['class' => 'btn btn-primary me-2']
);
echo html_writer::link(
    new moodle_url('/blocks/coursesync/sync.php', ['instanceid' => $instanceid, 'courseid' => $courseid]),
    get_string('syncnow', 'block_coursesync'),
    ['class' => 'btn btn-secondary']
);

echo $OUTPUT->footer();
