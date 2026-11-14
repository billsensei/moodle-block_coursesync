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
 * Pulls students' assignment submissions from the mapped remote course: a
 * preview first, then the pull itself once the teacher confirms.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_coursesync\connection;
use block_coursesync\grade_pull_result;
use block_coursesync\local\attempts;
use block_coursesync\local\long_run;
use block_coursesync\local\student_sections;
use block_coursesync\submission_pull;
use core\output\html_writer;

$instanceid = required_param('instanceid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
// The work ticked on the preview, as keys that only the pull itself can read. A form
// that says nothing was ticked is a decision, not a request for everything.
$picking = optional_param('picking', 0, PARAM_BOOL);
$pick = optional_param_array('pick', [], PARAM_ALPHANUM);
// The uploaded files ticked within that work, which can only leave files out of it.
$pickfile = optional_param_array('pickfile', [], PARAM_ALPHANUM);
// Every attempt each student handed in, not only the latest. Asked for on the preview and carried to the
// confirmation; the pull itself checks that this site allows it.
$allattempts = (bool) optional_param('allattempts', 0, PARAM_BOOL);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('block/coursesync:pullsubmissions', $coursecontext);

$blockinstance = $DB->get_record('block_instances', ['id' => $instanceid, 'blockname' => 'coursesync'], '*', MUST_EXIST);
$blockcontext = context_block::instance($blockinstance->id);

if ($blockcontext->get_course_context(false)->instanceid != $course->id) {
    throw new moodle_exception('invalidcontext', 'error');
}

$pageurl = new moodle_url('/blocks/coursesync/submissions.php', [
    'instanceid' => $instanceid,
    'courseid' => $courseid,
]);
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('submissionstitle', 'block_coursesync'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('pluginname', 'block_coursesync'));
$PAGE->navbar->add(get_string('submissionstitle', 'block_coursesync'));

$record = connection::get($instanceid);
$backtocourse = html_writer::link($courseurl, get_string('syncbacktocourse', 'block_coursesync'), [
    'class' => 'btn btn-primary me-2',
]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('submissionstitle', 'block_coursesync'));

if (!connection::is_mapped($record)) {
    echo $OUTPUT->notification(get_string('errornotmappedaskmanager', 'block_coursesync'), 'warning', false);
    echo $backtocourse;
    echo $OUTPUT->footer();
    die;
}

if ($confirm) {
    require_sesskey();

    $chosen = $picking ? array_fill_keys(array_values($pick), true) : [];

    if ($chosen === []) {
        echo $OUTPUT->notification(get_string('pullnothingchosen', 'block_coursesync'), 'info', false);
        echo $backtocourse;
        echo $OUTPUT->footer();
        die;
    }

    long_run::begin();
    $result = submission_pull::run(
        $instanceid,
        $course->id,
        null,
        null,
        $allattempts,
        $chosen,
        array_fill_keys(array_values($pickfile), true)
    );
} else {
    // Following a link writes nothing: this asks the other site, shows what
    // a pull would do, and the button below (with a sesskey) does it.
    $result = submission_pull::preview($instanceid, $course->id, null, null, $allattempts);
}

if (!$result->success) {
    echo $OUTPUT->notification(get_string($result->errorkey, 'block_coursesync'), 'error', false);

    if ($allattempts) {
        echo html_writer::link($pageurl, get_string('pullattemptslatestinstead', 'block_coursesync'), [
            'class' => 'btn btn-secondary me-2',
        ]);
    }

    echo $backtocourse;
    echo $OUTPUT->footer();
    die;
}

$counts = $result->counts(grade_pull_result::KIND_SUBMISSION);
$towrite = $result->changes();

if ($result->preview) {
    echo html_writer::tag('p', get_string(
        $allattempts ? 'submissionsintroattempts' : 'submissionsintro',
        'block_coursesync',
        s($record->remotecoursename)
    ));
    echo attempts::choice_form($pageurl, $allattempts);
} else {
    $donekey = $allattempts ? 'submissionsdoneattempts' : 'submissionsdone';
    echo $OUTPUT->notification(get_string($donekey, 'block_coursesync', (object) [
        'written' => $counts[grade_pull_result::ADD] + $counts[grade_pull_result::UPDATE],
        'added' => $counts[grade_pull_result::ADD],
        'updated' => $counts[grade_pull_result::UPDATE],
    ]), 'success', false);

    if ($counts[grade_pull_result::CONFLICT] > 0) {
        echo $OUTPUT->notification(get_string('submissionsconflictsleft', 'block_coursesync'), 'warning', false);
    }
}

// Things worth knowing that do not stop the pull.
foreach ($result->warnings as $warning) {
    $a = clone $warning->a;
    $a->activity = format_string($a->activity, true, ['context' => $coursecontext]);
    echo $OUTPUT->notification(get_string($warning->key, 'block_coursesync', $a), 'warning', false);
}

if ($allattempts && $result->entries !== []) {
    echo html_writer::tag('p', get_string('pullattemptscounts', 'block_coursesync'), ['class' => 'text-muted']);
}

if ($result->entries === []) {
    echo $OUTPUT->notification(get_string('submissionsnothing', 'block_coursesync'), 'info', false);
    echo $backtocourse;
    echo $OUTPUT->footer();
    die;
}

// A table of counts per assignment.
$columns = [
    'submissionscolassignment' => null,
    'gradescoladd' => grade_pull_result::ADD,
    'gradescolupdate' => grade_pull_result::UPDATE,
    'submissionscolsame' => grade_pull_result::SAME,
    'gradescolconflict' => grade_pull_result::CONFLICT,
    'gradescolskipped' => grade_pull_result::SKIPPED,
];
$head = html_writer::tag('tr', implode('', array_map(
    static fn(string $key): string => html_writer::tag('th', get_string($key, 'block_coursesync'), ['scope' => 'col']),
    array_keys($columns)
)));
$rows = '';

foreach ($result->by_activity(grade_pull_result::KIND_SUBMISSION) as $activity) {
    $cells = html_writer::tag('td', format_string($activity['name'], true, ['context' => $coursecontext]));

    foreach (array_slice($columns, 1) as $outcome) {
        $cells .= html_writer::tag('td', $activity[$outcome]);
    }

    $rows .= html_writer::tag('tr', $cells);
}

echo html_writer::tag(
    'table',
    html_writer::tag('caption', get_string('submissionssummaryheading', 'block_coursesync'), ['class' => 'visually-hidden'])
    . html_writer::tag('thead', $head)
    . html_writer::tag('tbody', $rows),
    ['class' => 'table table-striped', 'id' => 'coursesync-submissions-summary']
);

// On the preview, each student's work can be ticked: the form wraps the sections,
// so the button at the end sends the ticks. Everything starts ticked.
$choosing = $result->preview && $towrite > 0;

if ($choosing) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $pageurl->out(false),
        'id' => 'coursesync-submissions-form',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => 1]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'picking', 'value' => 1]);

    if ($allattempts) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'allattempts', 'value' => 1]);
    }

    echo student_sections::pick_all($allattempts ? 'submissionspickintroattempts' : 'submissionspickintro');
}

// Names for every student mentioned, fetched once.
$userids = array_filter(array_unique(array_column($result->entries, 'userid')));
$users = $userids ? $DB->get_records_list('user', 'id', $userids) : [];
$columns = ['submissionscolassignment', 'submissionscolattempt', 'submissionscolfiles', 'gradescolresult', 'gradescolreason'];
$outcomelabels = [
    grade_pull_result::ADD => 'gradescoladd',
    grade_pull_result::UPDATE => 'gradescolupdate',
    grade_pull_result::SAME => 'gradescolsame',
    grade_pull_result::CONFLICT => 'gradescolconflict',
    grade_pull_result::SKIPPED => 'gradescolskipped',
];

echo $OUTPUT->heading(get_string('gradessectionstudents', 'block_coursesync'), 3);
echo student_sections::render(
    $result->of_kind(grade_pull_result::KIND_SUBMISSION),
    $users,
    $columns,
    static function (stdClass $entry, ?array $tick = null) use ($outcomelabels, $coursecontext): array {
        $reason = match (true) {
            $entry->reason !== null => get_string($entry->reason, 'block_coursesync'),
            $entry->outcome === grade_pull_result::UPDATE => get_string('submissionsreasonupdate', 'block_coursesync'),
            default => '',
        };

        return [
            format_string($entry->activity, true, ['context' => $coursecontext]),
            $entry->userid ? (string) ((int) $entry->attempt + 1) : '-',
            $entry->userid ? (string) (int) $entry->files . student_sections::file_list($entry, $tick) : '-',
            get_string($outcomelabels[$entry->outcome], 'block_coursesync'),
            $reason,
        ];
    },
    $choosing,
    $result->preview,
    static fn(stdClass $entry, string $student): string => get_string(
        $allattempts ? 'pullpickattempt' : 'pullpickone',
        'block_coursesync',
        (object) [
            'student' => $student,
            'activity' => format_string($entry->activity, true, ['context' => $coursecontext]),
            'attempt' => (int) $entry->attempt + 1,
        ]
    )
);

if ($result->preview) {
    if ($towrite === 0) {
        echo $OUTPUT->notification(get_string('submissionsnothingtowrite', 'block_coursesync'), 'info', false);
        echo $backtocourse;
    } else {
        echo html_writer::tag(
            'div',
            html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => get_string('submissionssubmit', 'block_coursesync'),
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
        new moodle_url('/blocks/coursesync/history.php', ['instanceid' => $instanceid, 'courseid' => $courseid]),
        get_string('historyview', 'block_coursesync'),
        ['class' => 'btn btn-secondary']
    );
}

echo $OUTPUT->footer();
