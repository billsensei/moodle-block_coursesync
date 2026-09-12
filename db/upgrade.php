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
 * Moodle only creates tables from db/install.xml automatically on a FRESH
 * install - for a plugin already installed (this one has been since
 * Phase 1), a newly-added table needs an explicit step here, or it never
 * gets created at all. See lib/upgradelib.php's upgrade_plugins_blocks():
 * install_from_xmldb_file() is only called in its "block not installed
 * yet" branch.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the upgrade steps for block_coursesync.
 *
 * @param int $oldversion
 * @param stdClass $block Unused - required by upgrade_plugins_blocks()'s call signature for every block's
 *                        upgrade function, not just ones that need it.
 * @return bool
 */
function xmldb_block_coursesync_upgrade($oldversion, $block) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091600) {
        // Phase 6: sync history. Field/key/index definitions here must stay
        // in sync with db/install.xml - that file is what a FRESH install
        // reads; this is what an upgrade from an earlier version reads.
        $table = new xmldb_table('block_coursesync_synclog');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('success', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('errorcode', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('createdcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('conflictcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('failedcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('unsupportedcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('conflictsjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('failedjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('unsupportedjson', XMLDB_TYPE_TEXT, null, null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('blockinstanceid', XMLDB_KEY_FOREIGN, ['blockinstanceid'], 'block_instances', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('blockinstanceid-timecreated', XMLDB_INDEX_NOTUNIQUE, ['blockinstanceid', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026091600, 'coursesync');
    }

    return true;
}
