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
 * Capability definitions for block_coursesync.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Who may add a Course Sync block to a course.
    'block/coursesync:addinstance' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks',
    ],

    // Who may set up the connection: the other site's address, its token, and
    // which course over there this one copies from. Separate from syncing
    // because the token usually reaches every course the other site's sync
    // account can read, so choosing the course is choosing what this site may
    // read over there - an administrator's decision, not every teacher's.
    'block/coursesync:configure' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Who may trigger a sync of activities from the remote course.
    // It brings content from another site into this one, and replacing a
    // changed copy deletes the old one - hence the risks. What it may create
    // is still held to the syncing person's own permissions for each type
    // (activity_handler::check_permission()).
    'block/coursesync:sync' => [
        'riskbitmask' => RISK_XSS | RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // Who may read students' gradebook grades through the Course Sync web
    // service, so another site can pull them. Held by the source site's sync
    // account, never by default: copying activities and handing out people's
    // grades are separate decisions, and an account set up for the first must
    // not quietly gain the second on upgrade. Read-only here - the grades are
    // written on the other site, under its own permissions.
    'block/coursesync:exportgrades' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [],
    ],

    // Who may pull students' grades from the other site into this course.
    // The grades are written as gradebook overrides, so holding this is not
    // enough on its own: grade_pull also requires moodle/grade:edit here.
    // Nothing happens either way until an administrator turns grade pulling
    // on for the site (block_coursesync | allowgradepull).
    'block/coursesync:pullgrades' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
