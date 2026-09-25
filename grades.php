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

$gradecounts = $result->counts(grade_pull_result::KIND_GRADE);
$attemptcounts = $result->counts(grade_pull_result::KIND_ATTEMPT);
$towrite = $result->changes();

if ($result->preview) {
    echo html_writer::tag('p', get_string('gradesintro', 'block_coursesync', s($record->remotecoursename)));

    if ($result->attemptquizzes !== [] || $result->of_kind(grade_pull_result::KIND_ATTEMPT) !== []) {
        echo html_writer::tag('p', get_string('gradesintroattempts', 'block_coursesync'));
    }
} else {
    echo $OUTPUT->notification(get_string('gradesdone', 'block_coursesync', (object) [
        'written' => $gradecounts[grade_pull_result::ADD] + $gradecounts[grade_pull_result::UPDATE],
        'added' => $gradecounts[grade_pull_result::ADD],
        'updated' => $gradecounts[grade_pull_result::UPDATE],
    ]), 'success', false);

    if ($attemptcounts[grade_pull_result::ADD] + $attemptcounts[grade_pull_result::UPDATE] > 0) {
        echo $OUTPUT->notification(get_string('attemptsdone', 'block_coursesync', (object) [
            'added' => $attemptcounts[grade_pull_result::ADD],
            'updated' => $attemptcounts[grade_pull_result::UPDATE],
        ]), 'success', false);
    }

    if ($gradecounts[grade_pull_result::RELEASED] > 0) {
        echo $OUTPUT->notification(
            get_string('gradesreleaseddone', 'block_coursesync', $gradecounts[grade_pull_result::RELEASED]),
            'success',
            false
        );
    }

    if ($gradecounts[grade_pull_result::CONFLICT] + $attemptcounts[grade_pull_result::CONFLICT] > 0) {
        echo $OUTPUT->notification(get_string('gradesconflictsleft', 'block_coursesync'), 'warning', false);
    }
}

// About the pull as a whole - for one, a source too old to share attempts.
foreach ($result->notes as $note) {
    echo $OUTPUT->notification(get_string($note, 'block_coursesync'), 'info', false);
}

if ($result->entries === []) {
    echo $OUTPUT->notification(get_string('gradesnothing', 'block_coursesync'), 'info', false);
    echo $backtocourse;
    echo $OUTPUT->footer();
    die;
}

// A table of counts per activity, for one kind of entry.
$rendersummary = static function (array $activities, array $columns, string $captionkey, string $id) use ($coursecontext): void {
    $head = html_writer::tag('tr', implode('', array_map(
        static fn(string $key): string => html_writer::tag('th', get_string($key, 'block_coursesync'), ['scope' => 'col']),
        array_keys($columns)
    )));
    $rows = '';

    foreach ($activities as $activity) {
        $cells = html_writer::tag('td', format_string($activity['name'], true, ['context' => $coursecontext]));

        foreach (array_slice($columns, 1) as $outcome) {
            $cells .= html_writer::tag('td', $activity[$outcome]);
        }

        $rows .= html_writer::tag('tr', $cells);
    }

    echo html_writer::tag(
        'table',
        html_writer::tag('caption', get_string($captionkey, 'block_coursesync'), ['class' => 'sr-only'])
        . html_writer::tag('thead', $head)
        . html_writer::tag('tbody', $rows),
        ['class' => 'table table-striped', 'id' => $id]
    );
};

// Names for every student mentioned, fetched once.
$userids = array_filter(array_unique(array_column($result->entries, 'userid')));
$users = $userids ? $DB->get_records_list('user', 'id', $userids) : [];
$studentname = static function (\stdClass $entry) use ($users): string {
    if (isset($users[$entry->userid])) {
        return fullname($users[$entry->userid]);
    }

    // No match here - or, with no username, a whole grade item or quiz.
    return $entry->username !== '' ? $entry->username : get_string('gradesallstudents', 'block_coursesync');
};

// One table of rows, under its heading.
$rendertable = static function (array $columns, string $rows, string $headingkey, string $id) use ($OUTPUT): void {
    $head = html_writer::tag('tr', implode('', array_map(
        static fn(string $key): string => html_writer::tag('th', get_string($key, 'block_coursesync'), ['scope' => 'col']),
        $columns
    )));

    echo $OUTPUT->heading(get_string($headingkey, 'block_coursesync'), 4);
    echo html_writer::tag(
        'table',
        html_writer::tag('caption', get_string($headingkey, 'block_coursesync'), ['class' => 'sr-only'])
        . html_writer::tag('thead', $head)
        . html_writer::tag('tbody', $rows),
        ['class' => 'table table-sm table-striped', 'id' => $id]
    );
};

// Grade items, fetched once each, to show grades the way the gradebook does.
$gradeitems = [];
$showgrade = static function (?float $value, int $gradeitemid) use (&$gradeitems): string {
    if ($value === null || $gradeitemid === 0) {
        return '-';
    }

    $gradeitems[$gradeitemid] ??= grade_item::fetch(['id' => $gradeitemid]);

    return $gradeitems[$gradeitemid] ? grade_format_gradevalue($value, $gradeitems[$gradeitemid]) : '-';
};

// Students' gradebook grades, for one outcome or more.
$rendergrades = static function (
    array $entries,
    string $headingkey,
    string $id
) use (
    $rendertable,
    $studentname,
    $showgrade,
    $coursecontext
): void {
    if ($entries === []) {
        return;
    }

    $rows = '';

    foreach ($entries as $entry) {
        $reason = match (true) {
            $entry->reason !== null => get_string($entry->reason, 'block_coursesync'),
            $entry->outcome === grade_pull_result::UPDATE => get_string('gradesreasonupdate', 'block_coursesync'),
            $entry->outcome === grade_pull_result::CONFLICT => get_string('gradesreasonconflict', 'block_coursesync'),
            $entry->outcome === grade_pull_result::RELEASED => get_string('gradesreasonreleased', 'block_coursesync'),
            default => '',
        };

        $rows .= html_writer::tag(
            'tr',
            html_writer::tag('td', s($studentname($entry)))
            . html_writer::tag('td', format_string($entry->activity, true, ['context' => $coursecontext]))
            . html_writer::tag('td', $entry->userid ? $showgrade($entry->grade, $entry->gradeitemid) : '-')
            . html_writer::tag('td', $showgrade($entry->localgrade, $entry->gradeitemid))
            . html_writer::tag('td', $reason)
        );
    }

    $rendertable(
        ['gradescolstudent', 'gradescolactivity', 'gradescolthere', 'gradescolhere', 'gradescolreason'],
        $rows,
        $headingkey,
        $id
    );
};

// Students' quiz attempts, for one outcome or more.
$renderattempts = static function (
    array $entries,
    string $headingkey,
    string $id
) use (
    $rendertable,
    $studentname,
    $coursecontext
): void {
    if ($entries === []) {
        return;
    }

    $marks = static fn(?float $value): string => $value === null ? '-' : format_float($value, 2);
    $rows = '';

    foreach ($entries as $entry) {
        $why = [];

        if ($entry->reason !== null) {
            $why[] = get_string($entry->reason, 'block_coursesync');
        } else if ($entry->outcome === grade_pull_result::UPDATE) {
            $why[] = get_string('attemptsreasonupdate', 'block_coursesync');
        }

        foreach ($entry->notes ?? [] as $note) {
            $why[] = get_string($note, 'block_coursesync');
        }

        $rows .= html_writer::tag(
            'tr',
            html_writer::tag('td', s($studentname($entry)))
            . html_writer::tag('td', format_string($entry->activity, true, ['context' => $coursecontext]))
            . html_writer::tag('td', $entry->attempt ? (int) $entry->attempt : '-')
            . html_writer::tag('td', $entry->username !== '' ? $marks($entry->grade) : '-')
            . html_writer::tag('td', $marks($entry->localgrade))
            . html_writer::tag('td', implode(' ', $why))
        );
    }

    $rendertable(
        ['gradescolstudent', 'attemptscolquiz', 'attemptscolattempt', 'attemptscolthere', 'attemptscolhere', 'gradescolreason'],
        $rows,
        $headingkey,
        $id
    );
};

// Gradebook grades. What wants the teacher's attention comes first.
if ($result->of_kind(grade_pull_result::KIND_GRADE) !== []) {
    echo $OUTPUT->heading(get_string('gradessectiongrades', 'block_coursesync'), 3);
    $rendersummary($result->by_activity(grade_pull_result::KIND_GRADE), [
        'gradescolactivity' => null,
        'gradescoladd' => grade_pull_result::ADD,
        'gradescolupdate' => grade_pull_result::UPDATE,
        'gradescolsame' => grade_pull_result::SAME,
        'gradescolconflict' => grade_pull_result::CONFLICT,
        'gradescolskipped' => grade_pull_result::SKIPPED,
        'gradescolreleased' => grade_pull_result::RELEASED,
    ], 'gradessummaryheading', 'coursesync-grades-summary');

    $kind = grade_pull_result::KIND_GRADE;
    $rendergrades($result->with_outcome(grade_pull_result::CONFLICT, $kind), 'gradesgroupconflict', 'coursesync-grades-conflicts');
    $rendergrades($result->with_outcome(grade_pull_result::SKIPPED, $kind), 'gradesgroupskipped', 'coursesync-grades-skipped');
    $rendergrades(
        $result->with_outcome(grade_pull_result::RELEASED, $kind),
        'gradesgroupreleased',
        'coursesync-grades-released'
    );
    $rendergrades(
        array_merge(
            $result->with_outcome(grade_pull_result::ADD, $kind),
            $result->with_outcome(grade_pull_result::UPDATE, $kind)
        ),
        $result->preview ? 'gradesgrouptowrite' : 'gradesgroupwritten',
        'coursesync-grades-written'
    );
}

// Quiz attempts, the same way round.
if ($result->of_kind(grade_pull_result::KIND_ATTEMPT) !== []) {
    echo $OUTPUT->heading(get_string('attemptssection', 'block_coursesync'), 3);
    $rendersummary($result->by_activity(grade_pull_result::KIND_ATTEMPT), [
        'attemptscolquiz' => null,
        'gradescoladd' => grade_pull_result::ADD,
        'gradescolupdate' => grade_pull_result::UPDATE,
        'attemptscolsame' => grade_pull_result::SAME,
        'gradescolconflict' => grade_pull_result::CONFLICT,
        'gradescolskipped' => grade_pull_result::SKIPPED,
    ], 'attemptssummaryheading', 'coursesync-attempts-summary');

    $kind = grade_pull_result::KIND_ATTEMPT;
    $renderattempts(
        $result->with_outcome(grade_pull_result::CONFLICT, $kind),
        'attemptsgroupconflict',
        'coursesync-attempts-conflicts'
    );
    $renderattempts(
        $result->with_outcome(grade_pull_result::SKIPPED, $kind),
        'attemptsgroupskipped',
        'coursesync-attempts-skipped'
    );
    $renderattempts(
        array_merge(
            $result->with_outcome(grade_pull_result::ADD, $kind),
            $result->with_outcome(grade_pull_result::UPDATE, $kind)
        ),
        $result->preview ? 'attemptsgrouptowrite' : 'attemptsgroupwritten',
        'coursesync-attempts-written'
    );
}

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
                'value' => get_string(
                    $result->of_kind(grade_pull_result::KIND_ATTEMPT) !== [] ? 'gradessubmitall' : 'gradessubmit',
                    'block_coursesync',
                    $towrite
                ),
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
