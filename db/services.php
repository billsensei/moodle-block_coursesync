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
 * External service definitions for block_coursesync.
 *
 * Moodle core has no generic activity-export service between sites, so the
 * source site exposes its own. Everything here runs on the SOURCE site; the
 * destination site is only a client.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_coursesync_ping' => [
        'classname' => 'block_coursesync\external\ping',
        'methodname' => 'execute',
        'description' => 'Confirms that a destination site can reach this site and is authorised to talk to it.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'block/coursesync:sync',
        'loginrequired' => true,
    ],

    'block_coursesync_get_course' => [
        'classname' => 'block_coursesync\\external\\get_course',
        'methodname' => 'execute',
        'description' => 'Resolves a course id or shortname on this site, so a destination can confirm a mapping.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'block/coursesync:sync',
        'loginrequired' => true,
    ],

    'block_coursesync_get_modified_activities' => [
        'classname' => 'block_coursesync\\external\\get_modified_activities',
        'methodname' => 'execute',
        'description' => 'Lists activity metadata for a course, limited to activities modified after a given time.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'block/coursesync:sync',
        'loginrequired' => true,
    ],

    'block_coursesync_get_activity' => [
        'classname' => 'block_coursesync\\external\\get_activity',
        'methodname' => 'execute',
        'description' => 'Returns everything needed to rebuild one activity on another site.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'block/coursesync:sync',
        'loginrequired' => true,
    ],

    'block_coursesync_get_activity_file' => [
        'classname' => 'block_coursesync\\external\\get_activity_file',
        'methodname' => 'execute',
        'description' => 'Serves one chunk of one file belonging to a synced activity.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'block/coursesync:sync',
        'loginrequired' => true,
    ],

    'block_coursesync_get_quiz_attempts' => [
        'classname' => 'block_coursesync\\external\\get_quiz_attempts',
        'methodname' => 'execute',
        'description' => 'Returns students\' finished quiz attempts, as marks per slot, for chosen quizzes, by username.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'block/coursesync:sync, block/coursesync:exportgrades',
        'loginrequired' => true,
    ],

    'block_coursesync_get_grades' => [
        'classname' => 'block_coursesync\\external\\get_grades',
        'methodname' => 'execute',
        'description' => 'Returns students\' gradebook grades for chosen activities, identified by username.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'block/coursesync:sync, block/coursesync:exportgrades',
        'loginrequired' => true,
    ],
];

// A pre-built service so an administrator can generate a token without having to
// assemble the function list by hand. It is not enabled by default: the setup
// wizard walks the administrator through switching it on deliberately.
$services = [
    'Course Sync' => [
        'shortname' => 'block_coursesync',
        'functions' => [
            'block_coursesync_ping',
            'block_coursesync_get_course',
            'block_coursesync_get_modified_activities',
            'block_coursesync_get_activity',
            'block_coursesync_get_activity_file',
            'block_coursesync_get_grades',
            'block_coursesync_get_quiz_attempts',
        ],
        'restrictedusers' => 1,
        'enabled' => 0,
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
