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

namespace block_coursesync\local\handler;

/**
 * Knows which activity types Course Sync can handle.
 *
 * This is the single place a new activity type has to be mentioned. Everything
 * else - the external function, the syncer, the block UI - asks here rather
 * than naming activity types itself.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class handler_registry {
    /**
     * Every handler this plugin ships.
     *
     * Adding a type means adding a class here; nothing else needs to change.
     *
     * @var string[]
     */
    private const HANDLERS = [
        page_handler::class,
        url_handler::class,
        label_handler::class,
        resource_handler::class,
        folder_handler::class,
        book_handler::class,
        forum_handler::class,
        wiki_handler::class,
        assign_handler::class,
        quiz_handler::class,
        choice_handler::class,
        glossary_handler::class,
        feedback_handler::class,
        data_handler::class,
        workshop_handler::class,
        lesson_handler::class,
        h5pactivity_handler::class,
        qbank_handler::class,
    ];

    /**
     * The handler for an activity type, or null if there is not one.
     *
     * @param string $modname activity type, for example 'page'
     * @return activity_handler|null
     */
    public static function get(string $modname): ?activity_handler {
        foreach (self::HANDLERS as $classname) {
            if ($classname::get_modname() === $modname) {
                return new $classname();
            }
        }

        return null;
    }

    /**
     * Can this plugin sync that activity type?
     *
     * @param string $modname
     * @return bool
     */
    public static function supports(string $modname): bool {
        return self::get($modname) !== null;
    }

    /**
     * Every activity type this plugin can sync.
     *
     * @return string[]
     */
    public static function supported_modnames(): array {
        return array_map(
            static fn(string $classname): string => $classname::get_modname(),
            self::HANDLERS
        );
    }

    /**
     * An activity type installed on this site that this plugin does not handle.
     *
     * This exists for tests. A test that needs "a type with no handler" used to
     * name one, which quietly became wrong the day that type gained a handler -
     * it happened twice. Asking the question is the only way to keep the answer
     * true.
     *
     * @return string|null null if this plugin handles everything installed
     */
    public static function first_unsupported_modname(): ?string {
        global $DB;

        foreach ($DB->get_records('modules', null, 'name', 'id,name') as $module) {
            if (!self::supports($module->name)) {
                return $module->name;
            }
        }

        return null;
    }

    /**
     * The supported activity types, named the way a person would read them.
     *
     * @return string[]
     */
    public static function supported_names(): array {
        return array_map(static function (string $modname): string {
            return get_string_manager()->string_exists('pluginname', 'mod_' . $modname)
                ? get_string('pluginname', 'mod_' . $modname)
                : $modname;
        }, self::supported_modnames());
    }
}
