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
 * Source-side counterpart to activity_handler: builds the settings/content
 * payload for one activity type, ready to be sent over the web service and
 * handed to that type's activity_handler on the other end.
 *
 * EXTENDING THIS FOR A NEW ACTIVITY TYPE (e.g. in Phase 5):
 *  1. Add a `<modname>_activity_exporter` class here implementing this
 *     interface. It only needs to know its own type's table shape.
 *  2. Register it in activity_exporter_registry's $exporters map.
 *  3. Add a matching activity_handler (see that interface) and register it
 *     in activity_handler_registry.
 * Nothing else in this plugin - the get_activity_content external function,
 * sync_runner, block_coursesync_get_modified_activities - needs to change:
 * they all go through the registries, not any specific type.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface activity_exporter {
    /**
     * Builds this activity's settings/content payload.
     *
     * The shape of the returned array is entirely up to the activity type -
     * it travels as an opaque JSON blob over the web service (see
     * classes/external/get_activity_content.php) and is only ever read back
     * by this same type's activity_handler on the destination site.
     *
     * @param \cm_info $cm The course module to export. $cm->modname must be this exporter's type.
     * @return array
     */
    public function export(\cm_info $cm): array;
}
