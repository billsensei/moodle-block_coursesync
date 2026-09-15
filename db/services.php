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
 * Installing this plugin on a Moodle site makes it able to act as a
 * "source" site, via four functions so far:
 *  - block_coursesync_ping: confirms the service is reachable and the
 *    caller's token is valid (Phase 2).
 *  - block_coursesync_check_course: resolves and access-checks a course
 *    identifier, for validating course mapping (Phase 3).
 *  - block_coursesync_get_modified_activities: lists a course's activities
 *    modified after a given time - metadata only, no content (Phase 3).
 *  - block_coursesync_get_activity_content: returns the full settings/
 *    content payload for one activity - only for types this plugin has an
 *    activity_exporter for (see classes/local/activity_exporter_registry.php;
 *    Page, URL, Label, Resource, and Forum as of Phase 5, Assignment
 *    and H5P as of Phase 9, Quiz as of Phase 10, and Glossary added after).
 *
 * Pre-registering the "Course Sync" service here (rather than asking the
 * remote admin to hand-build one in the web services UI) means the setup
 * wizard only has to walk them through enabling it, not creating it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_coursesync_ping' => [
        'classname'    => 'block_coursesync\external\ping',
        'methodname'   => 'execute',
        'description'  => 'Confirms the Course Sync web service is reachable and the caller\'s ' .
            'token is valid. Returns basic site identification only - no course or activity data.',
        'type'         => 'read',
        'ajax'         => false,
        'capabilities' => 'block/coursesync:sync',
    ],
    'block_coursesync_check_course' => [
        'classname'    => 'block_coursesync\external\check_course',
        'methodname'   => 'execute',
        'description'  => 'Resolves a course ID or shortname and confirms the caller\'s token can access it.',
        'type'         => 'read',
        'ajax'         => false,
        'capabilities' => 'block/coursesync:sync',
    ],
    'block_coursesync_get_modified_activities' => [
        'classname'    => 'block_coursesync\external\get_modified_activities',
        'methodname'   => 'execute',
        'description'  => 'Lists a course\'s activities modified after a given time - metadata only ' .
            '(course module id, type, name, idnumber, and when it was last modified), no content.',
        'type'         => 'read',
        'ajax'         => false,
        'capabilities' => 'block/coursesync:sync',
    ],
    'block_coursesync_get_activity_content' => [
        'classname'    => 'block_coursesync\external\get_activity_content',
        'methodname'   => 'execute',
        'description'  => 'Returns the full settings/content payload needed to recreate one activity - ' .
            'only for activity types this plugin currently supports pulling.',
        'type'         => 'read',
        'ajax'         => false,
        'capabilities' => 'block/coursesync:sync',
    ],
];

$services = [
    'Course Sync' => [
        'functions'       => [
            'block_coursesync_ping',
            'block_coursesync_check_course',
            'block_coursesync_get_modified_activities',
            'block_coursesync_get_activity_content',
        ],
        'restrictedusers' => 1,
        'enabled'         => 0,
        'shortname'       => 'block_coursesync',
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
];
