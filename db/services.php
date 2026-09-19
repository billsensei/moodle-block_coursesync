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
 * Web service definitions for block_coursesync.
 *
 * Two kinds of function live here. The AJAX-only pair back this site's own
 * configuration form and are deliberately in no service, so they cannot be
 * called with a token. The other pair is what a remote site exposes to let
 * this block read and copy its activities, and they are part of the plugin's
 * own "Course Sync Provider" service.
 *
 * The service is declared here rather than built by hand because Moodle will
 * not let a plugin add functions to a service a person created (see
 * external_update_services()), and because the plugin is installed on both
 * sites in any given pull, so it can own both ends.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_coursesync_test_connection' => [
        'classname' => 'block_coursesync\external\test_connection',
        'methodname' => 'execute',
        'description' => 'Check that a remote Moodle site answers to the given web service token.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/coursesync:trigger',
    ],

    'block_coursesync_validate_course' => [
        'classname' => 'block_coursesync\external\validate_course',
        'methodname' => 'execute',
        'description' => 'Resolve a course id or shortname on a remote Moodle site to exactly one course.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/coursesync:trigger',
    ],

    'block_coursesync_check_updates' => [
        'classname' => 'block_coursesync\external\check_updates',
        'methodname' => 'execute',
        'description' => 'List the activities a sync would bring into this course, without bringing any of them in.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/coursesync:trigger',
    ],

    'block_coursesync_sync_status' => [
        'classname' => 'block_coursesync\external\sync_status',
        'methodname' => 'execute',
        'description' => 'Report whether a queued sync for a block instance has finished.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/coursesync:viewhistory',
    ],

    'block_coursesync_list_activities' => [
        'classname' => 'block_coursesync\external\list_activities',
        'methodname' => 'execute',
        'description' => 'List a course\'s activities with a change signal for each.',
        'type' => 'read',
        'capabilities' => 'moodle/course:view, moodle/backup:backupactivity',
        'services' => ['coursesync_provider'],
    ],

    'block_coursesync_backup_activity' => [
        'classname' => 'block_coursesync\external\backup_activity',
        'methodname' => 'execute',
        'description' => 'Back up a single activity and return where the caller can download the resulting .mbz file.',
        'type' => 'write',
        'capabilities' => 'moodle/backup:backupactivity, moodle/backup:downloadfile',
        'services' => ['coursesync_provider'],
    ],
];

$services = [
    'Course Sync Provider' => [
        'shortname' => 'coursesync_provider',
        'functions' => [
            'core_webservice_get_site_info',
            'core_course_get_courses_by_field',
            'block_coursesync_list_activities',
            'block_coursesync_backup_activity',
        ],
        'enabled' => 1,
        // Only accounts the administrator explicitly authorises may use this,
        // and file download must be on for the .mbz to come back through
        // webservice/pluginfile.php.
        'restrictedusers' => 1,
        'downloadfiles' => 1,
        'uploadfiles' => 0,
    ],
];
