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

namespace block_coursesync\local;

/**
 * Renders block_coursesync_synclog rows (see sync_history) for history.php:
 * a chronological list, each run expandable (native <details>/<summary> -
 * no JS needed) to show exactly what was pulled, flagged as a conflict,
 * failed, or left unsupported.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history_renderer {
    /**
     * Renders every run, most recent first (sync_history::get_for_instance()
     * already orders them that way).
     *
     * @param array $runs block_coursesync_synclog rows.
     * @return string
     */
    public static function render_runs(array $runs): string {
        if (empty($runs)) {
            return \html_writer::tag('p', get_string('nosynchistory', 'block_coursesync'));
        }

        $html = '';
        $isfirst = true;
        foreach ($runs as $run) {
            // The most recent run (get_for_instance() orders newest first)
            // starts expanded, since that's the one a teacher who just
            // clicked "Sync now" almost certainly came here to check; older
            // runs start collapsed. $runs is keyed by record id (it comes
            // straight from $DB->get_records()), not sequentially from 0,
            // so this tracks "first iteration" explicitly rather than
            // testing the array key.
            $html .= self::render_run($run, $isfirst);
            $isfirst = false;
        }

        return $html;
    }

    /**
     * Renders one run as a <details> block.
     *
     * @param \stdClass $run One block_coursesync_synclog row.
     * @param bool $expanded
     * @return string
     */
    protected static function render_run(\stdClass $run, bool $expanded = false): string {
        $summary = self::summary_text($run);
        $body = \html_writer::tag('p', get_string('synclogtriggeredby', 'block_coursesync', self::describe_user($run->userid)));

        if (!$run->success) {
            $body .= \html_writer::tag('p', content_renderer::sync_result_text([
                'success' => false,
                'errorcode' => $run->errorcode,
                'technical' => null,
            ]));
        } else {
            $body .= self::render_created(json_decode($run->createdjson ?? '[]', true) ?: []);
            $body .= self::render_conflicts(json_decode($run->conflictsjson ?? '[]', true) ?: []);
            $body .= self::render_failed(json_decode($run->failedjson ?? '[]', true) ?: []);
        }

        $attributes = ['class' => 'block-coursesync-synclog-run'];
        if ($expanded) {
            // Native HTML5 boolean attribute - any rendered value (including
            // html_writer's own s($value)-escaped "open") counts as present.
            $attributes['open'] = 'open';
        }

        return \html_writer::tag(
            'details',
            \html_writer::tag('summary', $summary) . $body,
            $attributes
        );
    }

    /**
     * The one-line <summary> for a run.
     *
     * @param \stdClass $run
     * @return string
     */
    protected static function summary_text(\stdClass $run): string {
        $time = userdate($run->timecreated);
        $statuskey = $run->success ? 'synclogsuccess' : 'synclogfailed';
        $summary = get_string($statuskey, 'block_coursesync') . ' - ' . $time;

        if ($run->success) {
            $a = (object) [
                'created' => $run->createdcount,
                'conflicts' => $run->conflictcount,
                'failed' => $run->failedcount,
            ];
            $summary .= ' - ' . get_string('synclogcounts', 'block_coursesync', $a);
        }

        return s($summary);
    }

    /**
     * The "Created" section.
     *
     * @param array $items
     * @return string
     */
    protected static function render_created(array $items): string {
        if (empty($items)) {
            return '';
        }

        $listitems = '';
        foreach ($items as $item) {
            $a = self::item_vars($item);
            $listitems .= \html_writer::tag('li', get_string('synclogcreateditem', 'block_coursesync', $a));
        }

        return self::section('synclogcreatedheading', $listitems);
    }

    /**
     * The "Flagged as conflicts" section - what each one matched against locally.
     *
     * @param array $items
     * @return string
     */
    protected static function render_conflicts(array $items): string {
        if (empty($items)) {
            return '';
        }

        $listitems = '';
        foreach ($items as $item) {
            $a = self::item_vars($item);
            $a->conflictname = s($item['conflictname'] ?? '');
            $a->conflictcmid = (int) ($item['conflictcmid'] ?? 0);
            $listitems .= \html_writer::tag('li', get_string('synclogconflictitem', 'block_coursesync', $a));
        }

        return self::section('synclogconflictsheading', $listitems);
    }

    /**
     * The "Failed" section.
     *
     * @param array $items
     * @return string
     */
    protected static function render_failed(array $items): string {
        if (empty($items)) {
            return '';
        }

        $listitems = '';
        foreach ($items as $item) {
            $a = self::item_vars($item);
            $a->message = s($item['error'] ?? '');
            $listitems .= \html_writer::tag('li', get_string('synclogfaileditem', 'block_coursesync', $a));
        }

        return self::section('synclogfailedheading', $listitems);
    }

    /**
     * Wraps a heading + <ul> of list items into one section.
     *
     * @param string $headingkey
     * @param string $listitemshtml
     * @return string
     */
    protected static function section(string $headingkey, string $listitemshtml): string {
        $heading = \html_writer::tag('p', \html_writer::tag('strong', get_string($headingkey, 'block_coursesync')));

        return $heading . \html_writer::tag('ul', $listitemshtml);
    }

    /**
     * The name/modname pair every item type shares, pre-escaped for direct use in get_string().
     *
     * @param array $item
     * @return \stdClass
     */
    protected static function item_vars(array $item): \stdClass {
        return (object) [
            'name' => s($item['name'] ?? ''),
            'modname' => s($item['modname'] ?? ''),
        ];
    }

    /**
     * Display name for whoever triggered a run.
     *
     * @param int $userid
     * @return string
     */
    protected static function describe_user(int $userid): string {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, firstnamephonetic, ' .
            'lastnamephonetic, middlename, alternatename', IGNORE_MISSING);

        // Name fields are user-editable, so escaped like every other piece
        // of not-authored-by-this-plugin text that ends up in HTML here.
        return $user ? s(fullname($user)) : get_string('unknownuser', 'block_coursesync');
    }
}
