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
 * Maps modname => activity_handler. The only place that needs editing to
 * teach "Sync now" about a new activity type. Activity types not listed
 * here are detected (block_coursesync_get_modified_activities lists every
 * type) but left alone by sync_runner - not pulled, not treated as a
 * failure, and not allowed to block lastsync from advancing past whatever
 * WAS handled (see sync_runner::compute_new_lastsync()).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_handler_registry {
    /** @var array<string, class-string<activity_handler>> modname => handler class. */
    protected static array $handlers = [
        'page' => page_activity_handler::class,
        'url' => url_activity_handler::class,
        'label' => label_activity_handler::class,
        'resource' => resource_activity_handler::class,
        'forum' => forum_activity_handler::class,
        'assign' => assign_activity_handler::class,
        'h5pactivity' => h5pactivity_activity_handler::class,
        'quiz' => quiz_activity_handler::class,
        'glossary' => glossary_activity_handler::class,
        'wiki' => wiki_activity_handler::class,
        'choice' => choice_activity_handler::class,
        'feedback' => feedback_activity_handler::class,
        'book' => book_activity_handler::class,
    ];

    /**
     * Whether this activity type can be pulled and reconstructed.
     *
     * @param string $modname
     * @return bool
     */
    public static function is_supported(string $modname): bool {
        return isset(self::$handlers[$modname]);
    }

    /**
     * Gets the handler for an activity type.
     *
     * @param string $modname
     * @return activity_handler|null Null if that type isn't supported.
     */
    public static function get_handler(string $modname): ?activity_handler {
        if (!isset(self::$handlers[$modname])) {
            return null;
        }

        $class = self::$handlers[$modname];

        return new $class();
    }
}
