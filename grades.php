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
 * Pulls students' grades from the mapped remote course: a preview first, then
 * the pull itself once the teacher confirms.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

use block_coursesync\connection;
use block_coursesync\grade_pull;
use block_coursesync\grade_pull_result;
use core\output\html_writer;

$instanceid = required_param('instanceid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('block/coursesync:pullgrades', $coursecontext);

$blockinstance = $DB->get_record('block_instances', ['id' => $instanceid, 'blockname' => 'coursesync'], '*', MUST_EXIST);
$blockcontext = context_block::instance($blockinstance->id);

if ($blockcontext->get_course_context(false)->instanceid != $course->id) {
    throw new moodle_exception('invalidcontext', 'error');
}

$pageurl = new moodle_url('/blocks/coursesync/grades.php', [
    'instanceid' => $instanceid,
    'courseid' => $courseid,
]);
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('gradestitle', 'block_coursesync'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('pluginname', 'block_coursesync'));
$PAGE->navbar->add(get_string('gradestitle', 'block_coursesync'));

$record = connection::get($instanceid);
$backtocourse = html_writer::link($courseurl, get_string('syncbacktocourse', 'block_coursesync'), [
    'class' => 'btn btn-primary me-2',
]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('gradestitle', 'block_coursesync'));

if (!connection::is_mapped($record)) {
    echo $OUTPUT->notification(get_string('errornotmappedaskmanager', 'block_coursesync'), 'warning', false);
    echo $backtocourse;
    echo $OUTPUT->footer();
    die;
}

if ($confirm) {
    require_sesskey();
    $result = grade_pull::run($instanceid, $course->id);
} else {
    // Following a link writes nothing: this asks the other site, shows what
    // a pull would do, and the button below (with a sesskey) does it.
    $result = grade_pull::preview($instanceid, $course->id);
}

if (!$result->success) {
    echo $OUTPUT->notification(get_string($result->errorkey, 'block_coursesync'), 'error', false);
    echo $backtocourse;
    echo $OUTPUT->footer();
    die;
}

$counts = $result->counts();
$towrite = $counts[grade_pull_result::ADD] + $counts[grade_pull_result::UPDATE];

if ($result->preview) {
    echo html_writer::tag('p', get_string('gradesintro', 'block_coursesync', s($record->remotecoursename)));
} else {
    echo $OUTPUT->notification(get_string('gradesdone', 'block_coursesync', (object) [
        'written' => $towrite,
        'added' => $counts[grade_pull_result::ADD],
        'updated' => $counts[grade_pull_result::UPDATE],
    ]), 'success', false);

    if ($counts[grade_pull_result::CONFLICT] > 0) {
        echo $OUTPUT->notification(get_string('gradesconflictsleft', 'block_coursesync'), 'warning', false);
    }
}

if ($result->entries === []) {
    echo $OUTPUT->notification(get_string('gradesnothing', 'block_coursesync'), 'info', false);
    echo $backtocourse;
    echo $OUTPUT->footer();
    die;
}

// Per activity, the counts.
$head = html_writer::tag('tr', implode('', array_map(
    static fn(string $key): string => html_writer::tag('th', get_string($key, 'block_coursesync'), ['scope' => 'col']),
    ['gradescolactivity', 'gradescoladd', 'gradescolupdate', 'gradescolsame', 'gradescolconflict', 'gradescolskipped']
)));
$rows = '';

foreach ($result->by_activity() as $activity) {
    $rows .= html_writer::tag(
        'tr',
        html_writer::tag('td', s($activity['name']))
        . html_writer::tag('td', $activity[grade_pull_result::ADD])
        . html_writer::tag('td', $activity[grade_pull_result::UPDATE])
        . html_writer::tag('td', $activity[grade_pull_result::SAME])
        . html_writer::tag('td', $activity[grade_pull_result::CONFLICT])
        . html_writer::tag('td', $activity[grade_pull_result::SKIPPED])
    );
}

echo $OUTPUT->heading(get_string('gradessummaryheading', 'block_coursesync'), 3);
echo html_writer::tag(
    'table',
    html_writer::tag('caption', get_string('gradessummaryheading', 'block_coursesync'), ['class' => 'sr-only'])
    . html_writer::tag('thead', $head)
    . html_writer::tag('tbody', $rows),
    ['class' => 'table table-striped', 'id' => 'coursesync-grades-summary']
);

// Names for every student mentioned, fetched once.
$userids = array_filter(array_unique(array_column($result->entries, 'userid')));
$users = $userids ? $DB->get_records_list('user', 'id', $userids) : [];

// Grade items, fetched once each, to show grades the way the gradebook does.
$gradeitems = [];
$showgrade = static function (?float $value, int $gradeitemid) use (&$gradeitems): string {
    if ($value === null || $gradeitemid === 0) {
        return '-';
    }

    $gradeitems[$gradeitemid] ??= grade_item::fetch(['id' => $gradeitemid]);

    return $gradeitems[$gradeitemid] ? grade_format_gradevalue($value, $gradeitems[$gradeitemid]) : '-';
};

// One table of students, for one outcome or more.
$rendergroup = static function (array $entries, string $headingkey, string $id) use ($OUTPUT, $users, $showgrade): void {
    if ($entries === []) {
        return;
    }

    $head = html_writer::tag('tr', implode('', array_map(
        static fn(string $key): string => html_writer::tag('th', get_string($key, 'block_coursesync'), ['scope' => 'col']),
        ['gradescolstudent', 'gradescolactivity', 'gradescolthere', 'gradescolhere', 'gradescolreason']
    )));
    $rows = '';

    foreach ($entries as $entry) {
        if (isset($users[$entry->userid])) {
            $student = fullname($users[$entry->userid]);
        } else if ($entry->username !== '') {
            $student = $entry->username;
        } else {
            // A whole grade item that could not be used.
            $student = get_string('gradesallstudents', 'block_coursesync');
        }

        $reason = match (true) {
            $entry->reason !== null => get_string($entry->reason, 'block_coursesync'),
            $entry->outcome === grade_pull_result::UPDATE => get_string('gradesreasonupdate', 'block_coursesync'),
            $entry->outcome === grade_pull_result::CONFLICT => get_string('gradesreasonconflict', 'block_coursesync'),
            default => '',
        };

        $rows .= html_writer::tag(
            'tr',
            html_writer::tag('td', s($student))
            . html_writer::tag('td', s($entry->activity))
            . html_writer::tag('td', $entry->userid ? $showgrade($entry->grade, $entry->gradeitemid) : '-')
            . html_writer::tag('td', $showgrade($entry->localgrade, $entry->gradeitemid))
            . html_writer::tag('td', $reason)
        );
    }

    echo $OUTPUT->heading(get_string($headingkey, 'block_coursesync'), 4);
    echo html_writer::tag(
        'table',
        html_writer::tag('caption', get_string($headingkey, 'block_coursesync'), ['class' => 'sr-only'])
        . html_writer::tag('thead', $head)
        . html_writer::tag('tbody', $rows),
        ['class' => 'table table-sm table-striped', 'id' => $id]
    );
};

// What wants the teacher's attention comes first.
$rendergroup($result->with_outcome(grade_pull_result::CONFLICT), 'gradesgroupconflict', 'coursesync-grades-conflicts');
$rendergroup($result->with_outcome(grade_pull_result::SKIPPED), 'gradesgroupskipped', 'coursesync-grades-skipped');
$rendergroup(
    array_merge($result->with_outcome(grade_pull_result::ADD), $result->with_outcome(grade_pull_result::UPDATE)),
    $result->preview ? 'gradesgrouptowrite' : 'gradesgroupwritten',
    'coursesync-grades-written'
);

if ($result->preview) {
    if ($towrite === 0) {
        echo $OUTPUT->notification(get_string('gradesnothingtowrite', 'block_coursesync'), 'info', false);
        echo $backtocourse;
    } else {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => 1]);
        echo html_writer::tag(
            'div',
            html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => get_string('gradessubmit', 'block_coursesync', $towrite),
                'class' => 'btn btn-primary me-2',
            ])
            . html_writer::link($courseurl, get_string('cancel'), ['class' => 'btn btn-secondary']),
            ['class' => 'mb-4']
        );
        echo html_writer::end_tag('form');
    }
} else {
    echo $backtocourse;
    echo html_writer::link(
        new moodle_url('/grade/report/grader/index.php', ['id' => $course->id]),
        get_string('gradesopengradebook', 'block_coursesync'),
        ['class' => 'btn btn-secondary me-2']
    );
    echo html_writer::link(
        new moodle_url('/blocks/coursesync/history.php', ['instanceid' => $instanceid, 'courseid' => $courseid]),
        get_string('historyview', 'block_coursesync'),
        ['class' => 'btn btn-secondary']
    );
}

echo $OUTPUT->footer();
