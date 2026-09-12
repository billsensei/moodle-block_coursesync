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
 * Destination-side counterpart to activity_exporter: recreates one activity
 * type locally from the payload its matching activity_exporter produced.
 *
 * EXTENDING THIS FOR A NEW ACTIVITY TYPE (e.g. a v2 type beyond Phase 5's
 * page/url/label/resource/forum):
 *  1. Add a `<modname>_activity_handler` class here implementing this
 *     interface, following page_activity_handler's shape: create the
 *     course_modules row via add_course_module(), call that type's own
 *     `<modname>_add_instance()` to create its instance row (with
 *     ->coursemodule already set to the new cm id), then
 *     course_add_cm_to_section() and rebuild_course_cache().
 *     CHECK THAT `<modname>_add_instance()`'S OWN BODY before assuming it
 *     sets course_modules.instance for you: page/resource do; url/label/
 *     forum don't, and need an explicit
 *     $DB->set_field('course_modules', 'instance', $id, ['id' => $cmid])
 *     after calling it (see url_activity_handler, label_activity_handler,
 *     or forum_activity_handler). There's no way to tell which without
 *     checking - it's not part of any documented contract, just each
 *     module's own implementation choice, and getting it wrong doesn't
 *     throw: it silently leaves an invisible, broken course_modules row
 *     (instance=0) that Sync now still reports as "created". Phase 5's
 *     url_activity_handler got this wrong at first for exactly that
 *     reason - caught only by checking the destination course directly
 *     after a live sync, not by the sync's own success/failure counts.
 *  2. Register it in activity_handler_registry's $handlers map.
 *  3. Add a matching activity_exporter (see that interface) and register it
 *     in activity_exporter_registry.
 * sync_runner and block_coursesync only ever go through
 * activity_handler_registry - they never reference a specific type.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface activity_handler {
    /**
     * Creates this activity type in the destination course from remote data.
     *
     * @param int $courseid Destination course id.
     * @param int $sectionnum Destination course section number (0 = general).
     * @param array $data The payload this type's activity_exporter produced on the source.
     * @param string $idnumber idnumber to assign the new course module, so a later
     *                         sync can recognise it as already-synced instead of
     *                         duplicating it (see sync_runner::make_idnumber()).
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int;
}
