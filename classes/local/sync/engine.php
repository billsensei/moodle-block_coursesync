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
 * The sync run.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

use block_coursesync\local\activity_signature;
use block_coursesync\local\block_helper;
use block_coursesync\local\remote_client;
use block_coursesync\task\sync_course;

/**
 * Pulls new and changed activities from the configured remote course.
 *
 * The entry point is callable either straight from a request or from the
 * ad-hoc task, because backup and restore of several activities takes long
 * enough that a teacher should not be made to wait on it. One activity failing
 * never stops the rest of the run: each is attempted on its own, and whatever
 * happened is written to the audit log.
 */
class engine {
    /**
     * Queues a sync to run in the background.
     *
     * @param int $blockinstanceid Block instance to sync.
     * @param int $userid User the run acts as.
     */
    public static function queue(int $blockinstanceid, int $userid): void {
        $task = new sync_course();
        $task->set_custom_data(['blockinstanceid' => $blockinstanceid]);
        $task->set_userid($userid);

        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Whether a sync for this block instance is already waiting or running.
     *
     * @param int $blockinstanceid Block instance id.
     * @return bool
     */
    public static function is_queued(int $blockinstanceid): bool {
        foreach (\core\task\manager::get_adhoc_tasks(sync_course::class) as $task) {
            $data = $task->get_custom_data();
            if ((int) ($data->blockinstanceid ?? 0) === $blockinstanceid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs a sync now.
     *
     * @param int $blockinstanceid Block instance to sync.
     * @param int $userid User the run acts as.
     * @return run_result What the run did.
     */
    public static function run(int $blockinstanceid, int $userid): run_result {
        $runid = uniqid('csr', false);
        $result = new run_result($runid);
        $log = new audit_log($runid, $blockinstanceid, $userid);

        try {
            [$block, $course, $client] = self::prepare_context($blockinstanceid, $userid);
            $remoteactivities = $client->list_activities((int) $block->config->remotecourseid);
        } catch (\Throwable $e) {
            $log->record_run_failure($e->getMessage());
            $result->errors++;
            return $result;
        }

        $ledger = pull_ledger::for_block($blockinstanceid);
        $plan = (new planner())->plan(
            $remoteactivities,
            $ledger,
            self::local_signals($ledger),
            ...self::foreign_activities($course, $ledger)
        );

        $restorer = new restorer($client, $course, $userid);

        foreach ($plan as $item) {
            self::apply($item, $blockinstanceid, $restorer, $log, $result);
        }

        return $result;
    }

    /**
     * Pulls one named activity now, whatever the planner would have said.
     *
     * This is how a conflict gets resolved in the remote copy's favour: the
     * transfer that a normal run held back is carried out through exactly the
     * same path, so the ledger and the audit trail end up as they would have.
     *
     * @param int $blockinstanceid Block instance to sync.
     * @param int $remotecmid The remote course module to pull.
     * @param int $userid User the run acts as.
     * @return run_result What the run did.
     */
    public static function pull_one(int $blockinstanceid, int $remotecmid, int $userid): run_result {
        $runid = uniqid('csr', false);
        $result = new run_result($runid);
        $log = new audit_log($runid, $blockinstanceid, $userid);

        try {
            [$block, $course, $client] = self::prepare_context($blockinstanceid, $userid);
            $remoteactivities = $client->list_activities((int) $block->config->remotecourseid);
        } catch (\Throwable $e) {
            $log->record_run_failure($e->getMessage());
            $result->errors++;
            return $result;
        }

        $activity = null;
        foreach ($remoteactivities as $candidate) {
            if ((int) $candidate['cmid'] === $remotecmid) {
                $activity = $candidate;
                break;
            }
        }

        if ($activity === null) {
            $log->record_run_failure(get_string('error:remotegone', 'block_coursesync'));
            $result->errors++;
            return $result;
        }

        $ledger = pull_ledger::for_block($blockinstanceid);
        $entry = $ledger[$remotecmid] ?? null;
        $localcmid = $entry && !empty($entry->localcmid) ? (int) $entry->localcmid : null;
        if ($localcmid && !activity_signature::for_local_cmid($localcmid)) {
            // The copy this would have replaced is gone, so this becomes a fresh pull.
            $localcmid = null;
        }

        $item = new plan_item(
            $remotecmid,
            (string) $activity['modname'],
            (string) $activity['name'],
            (int) ($activity['sectionnum'] ?? 0),
            (string) ($activity['signal'] ?? ''),
            (string) ($activity['signalmethod'] ?? ''),
            $localcmid ? plan_item::ACTION_UPDATE : plan_item::ACTION_NEW,
            $localcmid
        );

        self::apply($item, $blockinstanceid, new restorer($client, $course, $userid), $log, $result);

        return $result;
    }

    /**
     * Carries out one planned item, absorbing any failure so the run continues.
     *
     * @param plan_item $item The planned item.
     * @param int $blockinstanceid Block instance id.
     * @param restorer $restorer Restorer for the target course.
     * @param audit_log $log Log for this run.
     * @param run_result $result Tally to update.
     */
    private static function apply(
        plan_item $item,
        int $blockinstanceid,
        restorer $restorer,
        audit_log $log,
        run_result $result
    ): void {
        if ($item->action === plan_item::ACTION_UNCHANGED) {
            $log->record(audit_log::OUTCOME_UNCHANGED, $item);
            $result->unchanged++;
            return;
        }

        if ($item->action === plan_item::ACTION_SKIPPED) {
            pull_ledger::record_skipped($blockinstanceid, $item);
            $log->record(audit_log::OUTCOME_SKIPPED, $item, null, $item->reason);
            $result->skipped++;
            return;
        }

        if ($item->action === plan_item::ACTION_CONFLICT) {
            pull_ledger::record_conflict($blockinstanceid, $item);
            $log->record(audit_log::OUTCOME_CONFLICT, $item, null, $item->reason);
            $result->conflicts++;
            return;
        }

        $replacing = $item->action === plan_item::ACTION_UPDATE ? $item->localcmid : null;

        try {
            $newcmid = $restorer->pull($item);

            // Only now that the replacement exists is it safe to drop the old copy.
            if ($replacing) {
                self::delete_module($replacing);
            }

            pull_ledger::record_success(
                $blockinstanceid,
                $item,
                $newcmid,
                activity_signature::for_local_cmid($newcmid) ?? ['signal' => '', 'method' => '']
            );

            $log->record(
                $replacing ? audit_log::OUTCOME_UPDATED : audit_log::OUTCOME_NEW,
                $item,
                $newcmid
            );

            if ($replacing) {
                $result->updated++;
            } else {
                $result->new++;
            }
        } catch (\Throwable $e) {
            $log->record(audit_log::OUTCOME_ERROR, $item, null, $e->getMessage());
            $result->errors++;
        }
    }

    /**
     * Loads and checks everything a run needs before it touches the remote site.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $userid User the run acts as.
     * @return array The block, the target course, and a client for the remote site.
     * @throws \moodle_exception If the block is not configured, or the user may not do this.
     */
    public static function prepare_context(int $blockinstanceid, int $userid): array {
        $block = block_helper::get_instance($blockinstanceid);
        $config = $block->config ?? new \stdClass();

        if (empty($config->remoteurl) || empty($config->remotecourseid) || empty($config->tokenciphertext)) {
            throw new \moodle_exception('error:notconfigured', 'block_coursesync');
        }

        $coursecontext = $block->context->get_course_context(false);
        if (!$coursecontext) {
            throw new \moodle_exception('error:notinacourse', 'block_coursesync');
        }

        // The run acts as a person, so it gets that person's permissions, not the block's.
        require_capability('block/coursesync:trigger', $block->context, $userid);
        require_capability('moodle/restore:restoreactivity', $coursecontext, $userid);
        require_capability('moodle/restore:restoretargetimport', $coursecontext, $userid);

        $client = new remote_client((string) $config->remoteurl, block_helper::stored_token($config));

        return [$block, get_course($coursecontext->instanceid), $client];
    }

    /**
     * Reads the current change signal of every local copy the ledger knows about.
     *
     * @param \stdClass[] $ledger Ledger rows keyed by remote cmid.
     * @return array Signals keyed by local cmid; missing means the copy is gone.
     */
    private static function local_signals(array $ledger): array {
        $signals = [];

        foreach ($ledger as $row) {
            $localcmid = (int) ($row->localcmid ?? 0);
            if (!$localcmid) {
                continue;
            }
            $signature = activity_signature::for_local_cmid($localcmid);
            $signals[$localcmid] = $signature['signal'] ?? '';
        }

        return $signals;
    }

    /**
     * Indexes the course's other activities, so an incoming one cannot quietly shadow them.
     *
     * @param \stdClass $course The target course.
     * @param \stdClass[] $ledger Ledger rows keyed by remote cmid.
     * @return array Two maps: local cmids by normalised name, and by id number.
     */
    private static function foreign_activities(\stdClass $course, array $ledger): array {
        $managed = [];
        foreach ($ledger as $row) {
            if (!empty($row->localcmid)) {
                $managed[(int) $row->localcmid] = true;
            }
        }

        $names = [];
        $idnumbers = [];
        foreach (get_fast_modinfo($course)->get_cms() as $cm) {
            if (isset($managed[(int) $cm->id])) {
                continue;
            }

            $names[planner::normalise_name($cm->name)] = (int) $cm->id;
            if ((string) $cm->idnumber !== '') {
                $idnumbers[(string) $cm->idnumber] = (int) $cm->id;
            }
        }

        return [$names, $idnumbers];
    }

    /**
     * Deletes a course module that has just been replaced.
     *
     * @param int $cmid Course module id.
     */
    private static function delete_module(int $cmid): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        course_delete_module($cmid);
    }
}
