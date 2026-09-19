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
 * The pull history a teacher sees.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\output;

/**
 * One page of sync runs, each expandable to the activities it touched.
 */
class history_page implements \renderable, \templatable {
    /**
     * Constructor.
     *
     * @param int $blockinstanceid Block instance whose history this is.
     * @param int $page Zero-based page number.
     * @param int $perpage Runs per page.
     */
    public function __construct(
        /** @var int Block instance whose history this is. */
        private readonly int $blockinstanceid,
        /** @var int Zero-based page number. */
        private readonly int $page,
        /** @var int Runs per page. */
        private readonly int $perpage,
    ) {
    }

    /**
     * Counts the runs recorded for this block instance.
     *
     * @return int
     */
    public function count_runs(): int {
        global $DB;

        return (int) $DB->count_records_sql(
            'SELECT COUNT(DISTINCT runid) FROM {block_coursesync_log} WHERE blockinstanceid = ?',
            [$this->blockinstanceid]
        );
    }

    /**
     * Builds the template context.
     *
     * @param \renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $runs = $this->load_runs();

        return [
            'hasruns' => !empty($runs),
            'runs' => $runs,
        ];
    }

    /**
     * Loads the page of runs, newest first, with the rows belonging to each.
     *
     * @return array
     */
    private function load_runs(): array {
        global $DB;

        $headers = $DB->get_records_sql(
            'SELECT runid, MAX(id) AS lastid, MAX(timecreated) AS timecreated, MIN(userid) AS userid
               FROM {block_coursesync_log}
              WHERE blockinstanceid = :blockinstanceid
           GROUP BY runid
           ORDER BY lastid DESC',
            ['blockinstanceid' => $this->blockinstanceid],
            $this->page * $this->perpage,
            $this->perpage
        );

        if (!$headers) {
            return [];
        }

        $rows = $this->load_rows(array_keys($headers));
        $users = $this->load_users(array_column($headers, 'userid'));
        $links = $this->load_activity_links($rows);

        $runs = [];
        foreach ($headers as $runid => $header) {
            $items = [];
            $tally = [];

            foreach ($rows[$runid] ?? [] as $row) {
                $tally[$row->outcome] = ($tally[$row->outcome] ?? 0) + 1;

                $items[] = [
                    'outcome' => $row->outcome,
                    'outcomelabel' => outcome::label($row->outcome),
                    'badge' => outcome::badge($row->outcome),
                    'modname' => $row->modname,
                    'name' => $row->name !== null && $row->name !== ''
                        ? $row->name
                        : get_string('history:norelatedactivity', 'block_coursesync'),
                    'hasurl' => isset($links[(int) $row->localcmid]),
                    'url' => $links[(int) $row->localcmid] ?? '',
                    'message' => $row->message,
                    'hasmessage' => !empty($row->message),
                ];
            }

            $summary = [];
            foreach ($tally as $outcomename => $count) {
                $summary[] = $count . ' ' . outcome::label($outcomename);
            }

            $runs[] = [
                'time' => userdate((int) $header->timecreated),
                'user' => $users[(int) $header->userid] ?? '',
                'summary' => implode(', ', $summary),
                'items' => $items,
            ];
        }

        return $runs;
    }

    /**
     * Loads every log row belonging to the given runs, grouped by run.
     *
     * @param string[] $runids Run identifiers.
     * @return array Arrays of rows, keyed by run id.
     */
    private function load_rows(array $runids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED);
        $params['blockinstanceid'] = $this->blockinstanceid;

        $rows = $DB->get_records_select(
            'block_coursesync_log',
            "blockinstanceid = :blockinstanceid AND runid $insql",
            $params,
            'id ASC'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->runid][] = $row;
        }

        return $grouped;
    }

    /**
     * Loads the display names of the people who ran these syncs.
     *
     * @param array $userids User ids.
     * @return string[] Full names keyed by user id.
     */
    private function load_users(array $userids): array {
        global $DB;

        $userids = array_filter(array_map('intval', $userids));
        if (!$userids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $userfields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;

        $names = [];
        foreach ($DB->get_records_select('user', "id $insql", $params, '', 'id, ' . $userfields) as $user) {
            $names[(int) $user->id] = fullname($user);
        }

        return $names;
    }

    /**
     * Works out which logged activities still exist locally, and where they live.
     *
     * @param array $rows Log rows grouped by run.
     * @return string[] Activity URLs keyed by local course module id.
     */
    private function load_activity_links(array $rows): array {
        global $DB;

        $cmids = [];
        foreach ($rows as $runrows) {
            foreach ($runrows as $row) {
                if (!empty($row->localcmid)) {
                    $cmids[(int) $row->localcmid] = true;
                }
            }
        }

        if (!$cmids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($cmids), SQL_PARAMS_NAMED);

        $links = [];
        $sql = "SELECT cm.id, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.deletioninprogress = 0 AND cm.id $insql";
        foreach ($DB->get_records_sql($sql, $params) as $cm) {
            $links[(int) $cm->id] = (new \moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]))->out(false);
        }

        return $links;
    }
}
