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
 * Site-wide settings for block_coursesync.
 *
 * Grade sync is the one part of Course Sync that moves people's data between
 * sites, so it is off until an administrator turns it on - separately for
 * each direction, since a site is usually only one side of the connection.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'block_coursesync/gradesheading',
        get_string('settingsgrades', 'block_coursesync'),
        get_string('settingsgrades_desc', 'block_coursesync')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_coursesync/allowgradeexport',
        get_string('allowgradeexport', 'block_coursesync'),
        get_string('allowgradeexport_desc', 'block_coursesync'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_coursesync/allowgradepull',
        get_string('allowgradepull', 'block_coursesync'),
        get_string('allowgradepull_desc', 'block_coursesync'),
        0
    ));
}
