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
 * What a sync would bring in, worked out ahead of running one.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

/**
 * The answer to "what would a sync bring in?", held between page loads.
 *
 * A check asks the remote site what it has and plans against it exactly as a
 * run would, but transfers nothing. The answer is kept in a cache so that the
 * list is still there after a page reload, and so that drawing a course page
 * never makes a request to another site. Anything that changes what a run
 * would do throws the cached answer away rather than trying to amend it.
 */
class available {
    /** @var string The cache area holding one answer per block instance. */
    private const CACHE_AREA = 'available';

    /**
     * Asks the remote site what is there now and records the answer.
     *
     * @param int $blockinstanceid Block instance to check.
     * @param int $userid User the check acts as.
     * @return array Template context describing what was found.
     * @throws \moodle_exception If the block is unconfigured, the user may not check, or the remote site fails.
     */
    public static function refresh(int $blockinstanceid, int $userid): array {
        $items = [];

        foreach (engine::preview($blockinstanceid, $userid) as $item) {
            // Only what a run would actually carry over: an activity already in
            // step, one held back for review, or one that cannot be backed up
            // at all is not something pressing Sync now would bring in.
            if (!$item->requires_transfer()) {
                continue;
            }

            $items[] = [
                'remotecmid' => $item->remotecmid,
                'modname' => $item->modname,
                'name' => $item->name,
                'action' => $item->action,
            ];
        }

        $record = ['timechecked' => time(), 'items' => $items];
        self::cache()->set($blockinstanceid, $record);

        return self::describe($record);
    }

    /**
     * Describes the last check, without going near the remote site.
     *
     * @param int $blockinstanceid Block instance id.
     * @return array Template context; its "checked" flag is false if no check has been kept.
     */
    public static function context(int $blockinstanceid): array {
        $record = self::cache()->get($blockinstanceid);

        return self::describe(is_array($record) ? $record : null);
    }

    /**
     * Discards the last check, because it can no longer be trusted to be true.
     *
     * @param int $blockinstanceid Block instance id.
     */
    public static function invalidate(int $blockinstanceid): void {
        self::cache()->delete($blockinstanceid);
    }

    /**
     * Turns a stored check, or the absence of one, into something a template can render.
     *
     * @param array|null $record A stored check, or null if there is none.
     * @param string $error A message to show instead of a fresh answer.
     * @return array Template context for block_coursesync/available_list.
     */
    public static function describe(?array $record, string $error = ''): array {
        $items = [];

        foreach ($record['items'] ?? [] as $item) {
            $isnew = ($item['action'] ?? '') === plan_item::ACTION_NEW;

            $items[] = [
                'name' => (string) ($item['name'] ?? ''),
                'modname' => self::module_name((string) ($item['modname'] ?? '')),
                'isnew' => $isnew,
                'actionlabel' => get_string($isnew ? 'available:new' : 'available:changed', 'block_coursesync'),
            ];
        }

        return [
            'checked' => $record !== null,
            'lastchecked' => isset($record['timechecked']) ? userdate((int) $record['timechecked']) : '',
            'hasitems' => (bool) $items,
            'count' => count($items),
            'items' => $items,
            'error' => $error,
        ];
    }

    /**
     * Names an activity type the way the rest of Moodle does.
     *
     * @param string $modname The module's directory name, for example "quiz".
     * @return string The translated module name, or the raw one if the module is not installed here.
     */
    private static function module_name(string $modname): string {
        if ($modname === '' || !get_string_manager()->string_exists('modulename', 'mod_' . $modname)) {
            return $modname;
        }

        return get_string('modulename', 'mod_' . $modname);
    }

    /**
     * The cache holding one answer per block instance.
     *
     * @return \core_cache\cache
     */
    private static function cache(): \core_cache\cache {
        return \core_cache\cache::make('block_coursesync', self::CACHE_AREA);
    }
}
