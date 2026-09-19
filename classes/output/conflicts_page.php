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
 * The conflicts awaiting a person's decision.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\output;

use block_coursesync\local\activity_signature;
use block_coursesync\local\sync\plan_item;
use block_coursesync\local\sync\pull_ledger;
use block_coursesync\local\sync\resolver;

/**
 * Everything a teacher needs to decide what to do about each divergence.
 */
class conflicts_page implements \renderable, \templatable {
    /**
     * Constructor.
     *
     * @param int $blockinstanceid Block instance whose conflicts these are.
     * @param \moodle_url $actionurl Where the decision forms post to.
     */
    public function __construct(
        /** @var int Block instance whose conflicts these are. */
        private readonly int $blockinstanceid,
        /** @var \moodle_url Where the decision forms post to. */
        private readonly \moodle_url $actionurl,
    ) {
    }

    /**
     * Builds the template context.
     *
     * @param \renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $conflicts = $this->load_conflicts();

        return [
            'hasconflicts' => !empty($conflicts),
            'conflicts' => $conflicts,
            'actionurl' => $this->actionurl->out(false),
            'sesskey' => sesskey(),
            'keeplocal' => resolver::ACTION_KEEP_LOCAL,
            'pullremote' => resolver::ACTION_PULL_REMOTE,
            'defer' => resolver::ACTION_DEFER,
        ];
    }

    /**
     * Loads the conflicted ledger rows with enough context to judge them.
     *
     * @return array
     */
    private function load_conflicts(): array {
        global $DB;

        $rows = $DB->get_records(
            'block_coursesync_pulls',
            ['blockinstanceid' => $this->blockinstanceid, 'status' => pull_ledger::STATUS_CONFLICT],
            'remotecmid ASC'
        );

        if (!$rows) {
            return [];
        }

        $descriptions = $this->load_descriptions(array_column($rows, 'remotecmid'));

        $conflicts = [];
        foreach ($rows as $row) {
            $remotecmid = (int) $row->remotecmid;
            $described = $descriptions[$remotecmid] ?? null;
            $localcmid = (int) ($row->localcmid ?? 0);

            $conflicts[] = [
                'remotecmid' => $remotecmid,
                'name' => $described->name ?? get_string('conflicts:unnamed', 'block_coursesync'),
                'modname' => $described->modname ?? '',
                'reason' => $this->describe_reason((string) $row->conflictreason),
                'haslocal' => $localcmid > 0,
                'localmodified' => $this->describe_local_change($localcmid, (string) $row->localsignalmethod),
                'lastpulled' => $row->timepulled
                    ? userdate((int) $row->timepulled)
                    : get_string('conflicts:neverpulled', 'block_coursesync'),
                // Taking the remote version is offered for every conflict. Where it would
                // replace an activity this block did not create, it is offered with a
                // warning and a confirmation, not quietly.
                'willreplaceforeign' => (string) $row->conflictreason === plan_item::CONFLICT_NAME_COLLISION,
            ];
        }

        return $this->absent_from_this_course_first($conflicts);
    }

    /**
     * Puts the activities this course has no copy of at the top.
     *
     * A name collision is an activity that never arrived: something else is
     * standing where it would go. Those are the ones a teacher is most likely
     * to be looking for, so they come before the ones that are already here
     * and have merely diverged.
     *
     * @param array $conflicts Conflicts in remote course module order.
     * @return array The same conflicts, those with no local copy first.
     */
    private function absent_from_this_course_first(array $conflicts): array {
        usort($conflicts, fn(array $a, array $b): int => ($a['haslocal'] <=> $b['haslocal']));

        return $conflicts;
    }

    /**
     * Explains a conflict reason in words.
     *
     * @param string $reason The stored conflict reason.
     * @return string
     */
    private function describe_reason(string $reason): string {
        $key = 'conflicts:reason:' . $reason;

        if (!get_string_manager()->string_exists($key, 'block_coursesync')) {
            return get_string('conflicts:reason:unknown', 'block_coursesync');
        }

        return get_string($key, 'block_coursesync');
    }

    /**
     * Says when the local copy was last touched, where that is knowable.
     *
     * @param int $localcmid The local course module id, or 0.
     * @param string $method How the local signal is derived.
     * @return string
     */
    private function describe_local_change(int $localcmid, string $method): string {
        if (!$localcmid) {
            return '';
        }

        $signature = activity_signature::for_local_cmid($localcmid);
        if (!$signature) {
            return get_string('conflicts:localdeleted', 'block_coursesync');
        }

        // Only the timemodified method yields a value that means anything as a date.
        if ($signature['method'] !== activity_signature::METHOD_TIMEMODIFIED) {
            return get_string('conflicts:localchanged', 'block_coursesync');
        }

        return get_string('conflicts:localchangedon', 'block_coursesync', userdate((int) $signature['signal']));
    }

    /**
     * Loads the last known name and type of each conflicted activity from the log.
     *
     * @param array $remotecmids Remote course module ids.
     * @return \stdClass[] Keyed by remote cmid.
     */
    private function load_descriptions(array $remotecmids): array {
        global $DB;

        $remotecmids = array_filter(array_map('intval', $remotecmids));
        if (!$remotecmids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($remotecmids, SQL_PARAMS_NAMED);
        $params['blockinstanceid'] = $this->blockinstanceid;

        $described = [];
        $rows = $DB->get_records_select(
            'block_coursesync_log',
            "blockinstanceid = :blockinstanceid AND remotecmid $insql",
            $params,
            'id ASC',
            'id, remotecmid, modname, name'
        );

        // Ordered oldest first, so the last row seen for a cmid is the most recent.
        foreach ($rows as $row) {
            if ($row->name !== null && $row->name !== '') {
                $described[(int) $row->remotecmid] = $row;
            }
        }

        return $described;
    }
}
