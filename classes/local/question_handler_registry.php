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
 * Maps qtype => question_handler. The question-bank equivalent of
 * activity_handler_registry - see question_exporter_registry's docblock for
 * v1's deliberately-scoped list of supported question types.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_handler_registry {
    /** @var array<string, class-string<question_handler>> qtype => handler class. */
    protected static array $handlers = [
        'multichoice' => multichoice_question_handler::class,
        'truefalse' => truefalse_question_handler::class,
        'shortanswer' => shortanswer_question_handler::class,
        'numerical' => numerical_question_handler::class,
        'essay' => essay_question_handler::class,
        'match' => match_question_handler::class,
        'description' => description_question_handler::class,
        'multianswer' => multianswer_question_handler::class,
    ];

    /**
     * Whether this question type can be pulled and reconstructed.
     *
     * @param string $qtype
     * @return bool
     */
    public static function is_supported(string $qtype): bool {
        return isset(self::$handlers[$qtype]);
    }

    /**
     * Gets the handler for a question type.
     *
     * @param string $qtype
     * @return question_handler|null Null if that type isn't supported.
     */
    public static function get_handler(string $qtype): ?question_handler {
        if (!isset(self::$handlers[$qtype])) {
            return null;
        }

        $class = self::$handlers[$qtype];

        return new $class();
    }
}
