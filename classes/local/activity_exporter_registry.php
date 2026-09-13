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
 * Maps modname => activity_exporter. The only place that needs editing to
 * teach the source side of this plugin about a new activity type.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_exporter_registry {
    /** @var array<string, class-string<activity_exporter>> modname => exporter class. */
    protected static array $exporters = [
        'page' => page_activity_exporter::class,
        'url' => url_activity_exporter::class,
        'label' => label_activity_exporter::class,
        'resource' => resource_activity_exporter::class,
        'forum' => forum_activity_exporter::class,
        'assign' => assign_activity_exporter::class,
        'h5pactivity' => h5pactivity_activity_exporter::class,
    ];

    /**
     * Whether this activity type can be exported.
     *
     * @param string $modname
     * @return bool
     */
    public static function is_supported(string $modname): bool {
        return isset(self::$exporters[$modname]);
    }

    /**
     * Gets the exporter for an activity type.
     *
     * @param string $modname
     * @return activity_exporter|null Null if that type isn't supported.
     */
    public static function get_exporter(string $modname): ?activity_exporter {
        if (!isset(self::$exporters[$modname])) {
            return null;
        }

        $class = self::$exporters[$modname];

        return new $class();
    }
}
