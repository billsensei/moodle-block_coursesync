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
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the block_coursesync plugin.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool
 */
function xmldb_block_coursesync_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026092001) {
        // Phase 2: the connection between this block instance and a remote source site.
        $table = new xmldb_table('block_coursesync_connection');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('remoteurl', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('token', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('tokenhint', XMLDB_TYPE_CHAR, '8', null, null, null, null);
        $table->add_field('remotesitename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('remoterelease', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('setupstage', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'url');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'new');
        $table->add_field('lasterror', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('lastcheck', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('blockinstanceid', XMLDB_INDEX_UNIQUE, ['blockinstanceid']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026092001, 'coursesync');
    }

    if ($oldversion < 2026092002) {
        // Phase 3: which remote course this block is mapped to, and when it was
        // last actually synced.
        $table = new xmldb_table('block_coursesync_connection');

        $fields = [
            new xmldb_field('remotecourseref', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'remoterelease'),
            new xmldb_field('remotecourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'remotecourseref'),
            new xmldb_field('remotecourseshortname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'remotecourseid'),
            new xmldb_field('remotecoursename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'remotecourseshortname'),
            new xmldb_field('lastsync', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'lastcheck'),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_block_savepoint(true, 2026092002, 'coursesync');
    }

    if ($oldversion < 2026092005) {
        // Phase 6: a record of every sync run, so a teacher can see what was
        // pulled and what was flagged for review.
        $table = new xmldb_table('block_coursesync_run');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'ok');
        $table->add_field('errorkey', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('since', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastsyncmoved', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('pulledcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('conflictcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('skippedcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('failedcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('pulled', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('conflicts', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('others', XMLDB_TYPE_TEXT, null, null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('blockinstanceid-timestarted', XMLDB_INDEX_NOTUNIQUE, ['blockinstanceid', 'timestarted']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026092005, 'coursesync');
    }

    if ($oldversion < 2026092405) {
        // Phase 35: what each grade pull wrote, so a later pull can update
        // its own grades and leave everyone else's alone.
        $table = new xmldb_table('block_coursesync_grade');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('gradeitemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('remotecmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('itemnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('finalgrade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $table->add_field('feedbackhash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('remotetime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timepulled', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('gradeitemid-userid', XMLDB_INDEX_UNIQUE, ['gradeitemid', 'userid']);
        $table->add_index('blockinstanceid', XMLDB_INDEX_NOTUNIQUE, ['blockinstanceid']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026092405, 'coursesync');
    }

    if ($oldversion < 2026092407) {
        // Phase 37: grade pulls are written to the same history as syncs,
        // told apart by kind. Every earlier run copied activities.
        $table = new xmldb_table('block_coursesync_run');
        $field = new xmldb_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'activities', 'userid');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_block_savepoint(true, 2026092407, 'coursesync');
    }

    return true;
}
