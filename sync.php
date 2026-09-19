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
 * Starts a sync run for a block instance.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_coursesync\local\block_helper;
use block_coursesync\local\sync\engine;
use block_coursesync\local\sync\status;

$blockid = required_param('id', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

require_sesskey();

$block = block_helper::get_instance($blockid);
$context = $block->context;
$coursecontext = $context->get_course_context();
$course = get_course($coursecontext->instanceid);

require_login($course);
require_capability('block/coursesync:trigger', $context);

$return = $returnurl !== ''
    ? new moodle_url($returnurl)
    : new moodle_url('/course/view.php', ['id' => $course->id]);

if (!status::is_configured($block->config ?? new stdClass())) {
    redirect(
        $return,
        get_string('error:notconfigured', 'block_coursesync'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

if (engine::is_queued($blockid)) {
    redirect(
        $return,
        get_string('status:alreadyrunning', 'block_coursesync'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

engine::queue($blockid, (int) $USER->id);

redirect(
    $return,
    get_string('status:syncstarted', 'block_coursesync'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
