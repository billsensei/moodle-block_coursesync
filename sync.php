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
 * Handles a click on a Course Sync block's "Sync now" link.
 *
 * Runs the sync (see block_coursesync::sync_now()) and redirects straight
 * back to the course with a plain-language notification - there's no
 * separate confirmation or results page (that's a later phase's UI work).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$instanceid = required_param('instanceid', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

if (!confirm_sesskey($sesskey)) {
    throw new moodle_exception('invalidsesskey', 'error');
}

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
$PAGE->set_url(new moodle_url('/blocks/coursesync/sync.php', ['instanceid' => $instanceid]));

$block = block_instance('coursesync', $blockinstance);
$result = $block->sync_now();

$returnurl = course_get_url($course);
$message = $block->describe_sync_result($result);

if ($result['success'] && empty($result['failed'])) {
    redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

redirect($returnurl, $message, null, \core\output\notification::NOTIFY_WARNING);
