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
 * "View sync history" page for a Course Sync block instance.
 *
 * A read-only, chronological list of past "Sync now" runs (see
 * classes/local/sync_history.php), each expandable to show exactly what
 * was pulled, flagged as a conflict, or failed. No actions happen here -
 * just the block's own capability check and a listing.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$instanceid = required_param('instanceid', PARAM_INT);

$blockinstance = $DB->get_record('block_instances', ['id' => $instanceid, 'blockname' => 'coursesync'], '*', MUST_EXIST);
$blockcontext = context_block::instance($instanceid);

$parentcontext = $blockcontext->get_parent_context();
if (!$parentcontext || $parentcontext->contextlevel != CONTEXT_COURSE) {
    throw new moodle_exception('syncnocourse', 'block_coursesync');
}
$course = $DB->get_record('course', ['id' => $parentcontext->instanceid], '*', MUST_EXIST);

require_login($course);
require_capability('block/coursesync:sync', $blockcontext);

$PAGE->set_context($blockcontext);
$PAGE->set_url(new moodle_url('/blocks/coursesync/history.php', ['instanceid' => $instanceid]));
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('viewsynchistory', 'block_coursesync'));
$PAGE->set_heading($course->fullname);

$runs = \block_coursesync\local\sync_history::get_for_instance($instanceid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('viewsynchistory', 'block_coursesync'));
echo \block_coursesync\local\history_renderer::render_runs($runs);
echo html_writer::tag('p', html_writer::link(course_get_url($course), get_string('backtocourse', 'block_coursesync')));
echo $OUTPUT->footer();
