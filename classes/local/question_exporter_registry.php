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
 * Maps qtype => question_exporter. The question-bank equivalent of
 * activity_exporter_registry - the only place that needs editing to teach
 * the source side of this plugin about a new question type.
 *
 * v1 (Phase 10) deliberately covers the question types most quizzes actually
 * use, not every qtype Moodle ships: multichoice, true/false, short answer,
 * numerical, essay, matching, description, and multianswer/Cloze (the last
 * added after v1 shipped - see multianswer_question_exporter's docblock for
 * why it's able to support any embedded sub-type the destination site has
 * installed, not just the ones in this list). A quiz slot using any other
 * qtype (calculated/calculatedsimple/calculatedmulti, the drag-and-drop
 * family, gapselect, ordering, randomsamatch, ...) is detected by
 * quiz_activity_exporter (it appears in the "not yet supported" list, same
 * as an unsupported activity type) but is not pulled - see
 * DEVELOPER_NOTES.md for what adding one of those involves.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_exporter_registry {
    /** @var array<string, class-string<question_exporter>> qtype => exporter class. */
    protected static array $exporters = [
        'multichoice' => multichoice_question_exporter::class,
        'truefalse' => truefalse_question_exporter::class,
        'shortanswer' => shortanswer_question_exporter::class,
        'numerical' => numerical_question_exporter::class,
        'essay' => essay_question_exporter::class,
        'match' => match_question_exporter::class,
        'description' => description_question_exporter::class,
        'multianswer' => multianswer_question_exporter::class,
    ];

    /**
     * Whether this question type can be exported.
     *
     * @param string $qtype
     * @return bool
     */
    public static function is_supported(string $qtype): bool {
        return isset(self::$exporters[$qtype]);
    }

    /**
     * Gets the exporter for a question type.
     *
     * @param string $qtype
     * @return question_exporter|null Null if that type isn't supported.
     */
    public static function get_exporter(string $qtype): ?question_exporter {
        if (!isset(self::$exporters[$qtype])) {
            return null;
        }

        $class = self::$exporters[$qtype];

        return new $class();
    }
}
