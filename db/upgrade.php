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
 * Upgrade steps for block_coursesync.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin database.
 *
 * @param int $oldversion The currently installed version.
 * @return bool
 */
function xmldb_block_coursesync_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091902) {
        // Phase 2 told the remote site's administrator to create the "Course Sync Provider"
        // external service by hand. From this version the plugin declares that service itself,
        // and Moodle refuses to install a declared service whose shortname is already taken by
        // a hand-made one. Adopt the existing service instead, so its token and authorised
        // users survive the upgrade.
        $existing = $DB->get_record('external_services', ['shortname' => 'coursesync_provider']);
        if ($existing && empty($existing->component)) {
            $existing->component = 'block_coursesync';
            $existing->name = 'Course Sync Provider';
            $DB->update_record('external_services', $existing);
        }

        $table = new xmldb_table('block_coursesync_pulls');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('remotecmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('localcmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('remotesignal', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('remotesignalmethod', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('localsignal', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('localsignalmethod', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'synced');
        $table->add_field('conflictreason', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('timepulled', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('blockinstanceid-remotecmid', XMLDB_INDEX_UNIQUE, ['blockinstanceid', 'remotecmid']);
        $table->add_index('localcmid', XMLDB_INDEX_NOTUNIQUE, ['localcmid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('block_coursesync_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('runid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('remotecmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('localcmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('modname', XMLDB_TYPE_CHAR, '32', null, null, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('outcome', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('blockinstanceid-timecreated', XMLDB_INDEX_NOTUNIQUE, ['blockinstanceid', 'timecreated']);
        $table->add_index('runid', XMLDB_INDEX_NOTUNIQUE, ['runid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026091902, 'coursesync');
    }

    return true;
}
