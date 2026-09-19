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
 * The pull history for a block instance.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_coursesync\local\block_helper;
use block_coursesync\output\history_page;

/** @var int Runs shown per page. */
const BLOCK_COURSESYNC_RUNS_PER_PAGE = 10;

$blockid = required_param('id', PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);

$block = block_helper::get_instance($blockid);
$context = $block->context;
$coursecontext = $context->get_course_context();
$course = get_course($coursecontext->instanceid);

require_login($course);
require_capability('block/coursesync:viewhistory', $context);

$url = new moodle_url('/blocks/coursesync/history.php', ['id' => $blockid]);

$PAGE->set_url($url, ['page' => $page]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('history:title', 'block_coursesync'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('pluginname', 'block_coursesync'));
$PAGE->navbar->add(get_string('history:title', 'block_coursesync'));

$renderable = new history_page($blockid, $page, BLOCK_COURSESYNC_RUNS_PER_PAGE);
$totalruns = $renderable->count_runs();

$renderer = $PAGE->get_renderer('block_coursesync');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('history:title', 'block_coursesync'));

if (has_capability('block/coursesync:trigger', $context)) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/blocks/coursesync/conflicts.php', ['id' => $blockid]),
            get_string('conflicts:title', 'block_coursesync')
        ),
        'mb-3'
    );
}

echo $renderer->render($renderable);
echo $OUTPUT->paging_bar($totalruns, $page, BLOCK_COURSESYNC_RUNS_PER_PAGE, $url);
echo $OUTPUT->footer();
