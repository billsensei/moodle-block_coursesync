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
 * Where a block instance stands: what it has pulled and what needs attention.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

/**
 * Gathers the few numbers the block and its pages need to describe a connection.
 */
class status {
    /**
     * Summarises a block instance's sync state.
     *
     * @param int $blockinstanceid Block instance id.
     * @param \stdClass|null $config The block instance configuration.
     * @return array Keys: configured, remotesitename, remotecoursename, running,
     *               synced, conflicts, skipped, lastrun, lastrunid.
     */
    public static function for_block(int $blockinstanceid, ?\stdClass $config): array {
        global $DB;

        $config = $config ?? new \stdClass();

        $bystatus = $DB->get_records_sql(
            'SELECT status, COUNT(1) AS total
               FROM {block_coursesync_pulls}
              WHERE blockinstanceid = :blockinstanceid
           GROUP BY status',
            ['blockinstanceid' => $blockinstanceid]
        );

        $lastrunid = $DB->get_field_sql(
            'SELECT runid FROM {block_coursesync_log} WHERE blockinstanceid = ? ORDER BY id DESC',
            [$blockinstanceid],
            IGNORE_MULTIPLE
        );

        $lastrun = 0;
        if ($lastrunid) {
            $lastrun = (int) $DB->get_field_sql(
                'SELECT MAX(timecreated) FROM {block_coursesync_log} WHERE blockinstanceid = ? AND runid = ?',
                [$blockinstanceid, $lastrunid]
            );
        }

        return [
            'configured' => self::is_configured($config),
            'remotesitename' => (string) ($config->remotesitename ?? ''),
            'remotecoursename' => (string) ($config->remotecoursefullname ?? ($config->remotecourse ?? '')),
            'remoteurl' => (string) ($config->remoteurl ?? ''),
            'running' => engine::is_queued($blockinstanceid),
            'synced' => (int) ($bystatus[pull_ledger::STATUS_SYNCED]->total ?? 0),
            'conflicts' => (int) ($bystatus[pull_ledger::STATUS_CONFLICT]->total ?? 0),
            'skipped' => (int) ($bystatus[pull_ledger::STATUS_SKIPPED]->total ?? 0),
            'lastrun' => $lastrun,
            'lastrunid' => (string) ($lastrunid ?: ''),
        ];
    }

    /**
     * Whether a block instance has a connection that has actually been verified.
     *
     * @param \stdClass $config The block instance configuration.
     * @return bool
     */
    public static function is_configured(\stdClass $config): bool {
        return !empty($config->remoteurl)
            && !empty($config->remotecourseid)
            && !empty($config->tokenciphertext)
            && !empty($config->lastvalidated);
    }
}
