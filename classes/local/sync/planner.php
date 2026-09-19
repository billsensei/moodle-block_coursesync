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
 * Deciding what a sync run should do with each remote activity.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

/**
 * Works out, for each remote activity, whether to pull it, leave it, or stop.
 *
 * This is deliberately free of database and network access: it takes the three
 * things a decision needs (what the remote says now, what the ledger recorded
 * at the last pull, and what the local copies look like now) and returns plain
 * verdicts, so the conflict rules can be tested directly.
 */
class planner {
    /**
     * Plans a sync run.
     *
     * @param array $remoteactivities Rows from block_coursesync_list_activities.
     * @param array $ledger Ledger rows for this block instance, keyed by remote cmid.
     * @param array $localsignals Current signal of each local copy, keyed by local cmid; a missing or null
     *                            entry means the local copy is gone.
     * @param array $foreignnames Local cmids this block did not create, keyed by normalised name.
     * @param array $foreignidnumbers Local cmids this block did not create, keyed by id number.
     * @return plan_item[]
     */
    public function plan(
        array $remoteactivities,
        array $ledger,
        array $localsignals,
        array $foreignnames = [],
        array $foreignidnumbers = []
    ): array {
        $plan = [];

        foreach ($remoteactivities as $activity) {
            $plan[] = $this->classify($activity, $ledger, $localsignals, $foreignnames, $foreignidnumbers);
        }

        return $plan;
    }

    /**
     * Classifies one remote activity.
     *
     * @param array $activity One row from block_coursesync_list_activities.
     * @param array $ledger Ledger rows keyed by remote cmid.
     * @param array $localsignals Current signal of each local copy, keyed by local cmid.
     * @param array $foreignnames Local cmids this block did not create, keyed by normalised name.
     * @param array $foreignidnumbers Local cmids this block did not create, keyed by id number.
     * @return plan_item
     */
    private function classify(
        array $activity,
        array $ledger,
        array $localsignals,
        array $foreignnames,
        array $foreignidnumbers
    ): plan_item {
        $remotecmid = (int) $activity['cmid'];
        $modname = (string) $activity['modname'];
        $name = (string) $activity['name'];
        $sectionnum = (int) ($activity['sectionnum'] ?? 0);
        $idnumber = (string) ($activity['idnumber'] ?? '');
        $signal = (string) ($activity['signal'] ?? '');
        $method = (string) ($activity['signalmethod'] ?? '');

        $make = fn(string $action, ?int $localcmid = null, ?string $reason = null): plan_item => new plan_item(
            $remotecmid,
            $modname,
            $name,
            $sectionnum,
            $signal,
            $method,
            $action,
            $localcmid,
            $reason
        );

        if (!($activity['backupsupported'] ?? true)) {
            return $make(plan_item::ACTION_SKIPPED, null, plan_item::SKIP_NO_BACKUP_SUPPORT);
        }

        $entry = $ledger[$remotecmid] ?? null;
        $localcmid = $entry ? (int) ($entry->localcmid ?? 0) : 0;
        $localexists = $localcmid > 0 && !empty($localsignals[$localcmid]);

        // Nothing here yet, either never pulled or the local copy has since been deleted.
        if (!$entry || !$localexists) {
            $collision = self::find_collision($name, $idnumber, $foreignnames, $foreignidnumbers);
            if ($collision !== null) {
                return $make(plan_item::ACTION_CONFLICT, $collision, plan_item::CONFLICT_NAME_COLLISION);
            }

            return $make(plan_item::ACTION_NEW);
        }

        // Unchanged on the remote, so there is nothing to bring over. Local edits
        // are the teacher's business and are left alone.
        if ((string) $entry->remotesignal === $signal) {
            return $make(plan_item::ACTION_UNCHANGED, $localcmid);
        }

        // Changed remotely. Only safe to replace if the local copy is still
        // exactly as this block left it at the last pull.
        $localchanged = (string) $localsignals[$localcmid] !== (string) ($entry->localsignal ?? '');
        if ($localchanged) {
            return $make(plan_item::ACTION_CONFLICT, $localcmid, plan_item::CONFLICT_BOTH_CHANGED);
        }

        return $make(plan_item::ACTION_UPDATE, $localcmid);
    }

    /**
     * Finds a local activity this block does not manage that already claims the name or id number.
     *
     * Public and static because the same rule has to be applied again later: a
     * collision is recorded without the local cmid it found, so that a later
     * run cannot mistake someone else's activity for a copy of the remote one.
     * Anything that acts on a collision therefore has to ask this again, of the
     * course as it stands at that moment.
     *
     * @param string $name Remote activity name.
     * @param string $idnumber Remote activity id number, possibly empty.
     * @param array $foreignnames Local cmids keyed by normalised name.
     * @param array $foreignidnumbers Local cmids keyed by id number.
     * @return int|null The colliding local cmid, or null if there is none.
     */
    public static function find_collision(string $name, string $idnumber, array $foreignnames, array $foreignidnumbers): ?int {
        if ($idnumber !== '' && isset($foreignidnumbers[$idnumber])) {
            return (int) $foreignidnumbers[$idnumber];
        }

        $key = self::normalise_name($name);
        if ($key !== '' && isset($foreignnames[$key])) {
            return (int) $foreignnames[$key];
        }

        return null;
    }

    /**
     * Normalises an activity name so trivial differences do not hide a collision.
     *
     * @param string $name Activity name.
     * @return string
     */
    public static function normalise_name(string $name): string {
        return \core_text::strtolower(trim($name));
    }
}
