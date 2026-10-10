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

use block_coursesync\activity_payload;

/**
 * Cleans a question the XML reader built from a fragment another site sent.
 *
 * Everything else that arrives from the other site is cleaned when the payload
 * is read. A question travels as an XML fragment that only the importer can
 * take apart, so its text is cleaned here, once the importer has done so and
 * before anything is saved.
 *
 * Text in a parsed question comes in two shapes: an array with 'text' and
 * 'format' (answers, feedback, hints), and a string next to a field named like
 * it plus 'format' (questiontext and questiontextformat). Both are cleaned by
 * their own format. Strings with no format are plain data and are left to the
 * question type, which is the same trust the importer gives them.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_cleaner {
    /**
     * Clean every piece of formatted text in a parsed question, in place.
     *
     * @param \stdClass $question as built by qformat_xml
     * @return void
     */
    public static function clean(\stdClass $question): void {
        if (isset($question->name)) {
            $question->name = clean_param((string) $question->name, PARAM_TEXT);
        }

        self::walk($question);
    }

    /**
     * Clean one level of a structure, then the levels below it.
     *
     * @param \stdClass|array $node
     * @return void
     */
    private static function walk(&$node): void {
        $isobject = is_object($node);
        $keys = $isobject ? array_keys(get_object_vars($node)) : array_keys($node);
        $get = static fn($key) => $isobject ? $node->$key : $node[$key];
        $set = static function ($key, $value) use (&$node, $isobject): void {
            if ($isobject) {
                $node->$key = $value;
            } else {
                $node[$key] = $value;
            }
        };

        // An array holding text and its format.
        if (!$isobject && isset($node['text'], $node['format']) && is_string($node['text'])) {
            $node['text'] = activity_payload::clean_html($node['text'], (int) $node['format']);
        }

        foreach ($keys as $key) {
            $value = $get($key);

            if (is_string($value) && in_array($key . 'format', $keys, true)) {
                $set($key, activity_payload::clean_html($value, (int) $get($key . 'format')));
            } else if (($key !== 'files') && (is_object($value) || is_array($value))) {
                self::walk($value);
                $set($key, $value);
            }
        }
    }
}
