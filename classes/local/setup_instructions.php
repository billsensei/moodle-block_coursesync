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
 * The guided instructions shown for setting up the remote site.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

/**
 * Builds the checklist handed to the administrator of the remote site.
 *
 * Moodle's REST protocol authenticates with a static token that an
 * administrator creates against an external service. Moodle exposes no web
 * service function for creating either, on purpose, because that would be a
 * privilege escalation path. So this plugin cannot provision anything on the
 * remote site: it can only tell the local teacher what to ask the remote
 * administrator for, and then check whatever they are given.
 */
class setup_instructions {
    /** @var string Suggested name for the external service on the remote site. */
    public const SUGGESTED_SERVICE_NAME = 'Course Sync Provider';

    /**
     * Returns the rendering context for the setup_instructions template.
     *
     * @return array Template context.
     */
    public static function context(): array {
        $steps = [];

        foreach (['enablews', 'installplugin', 'serviceaccount', 'capabilities', 'authorise', 'createtoken'] as $step) {
            $lines = [];
            $index = 1;
            while (get_string_manager()->string_exists('setup:' . $step . ':line' . $index, 'block_coursesync')) {
                $lines[] = ['text' => get_string('setup:' . $step . ':line' . $index, 'block_coursesync')];
                $index++;
            }

            $steps[] = [
                'title' => get_string('setup:' . $step, 'block_coursesync'),
                'lines' => $lines,
            ];
        }

        return [
            'intro' => get_string('setup:intro', 'block_coursesync'),
            'note' => get_string('setup:scopenote', 'block_coursesync'),
            'steps' => $steps,
        ];
    }
}
