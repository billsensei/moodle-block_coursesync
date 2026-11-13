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

use block_coursesync\grade_pull_result;
use core\output\html_writer;

/**
 * What a pull would do, or did, laid out by student: one section for each, which can be
 * folded away, and (before the pull) a tick box for each line that would be written.
 *
 * Used by the Submissions and Marks pages. The tick boxes carry grade_pull_result::key()
 * values, which only ever narrow a pull: the pull works out for itself what may be written.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_sections {
    /** @var string[] The outcomes a tick box is offered for. */
    public const WRITABLE = [grade_pull_result::ADD, grade_pull_result::UPDATE];

    /**
     * The line that explains the tick boxes and the box that ticks them all.
     *
     * Core's tick-all script is told which boxes to follow by role names that
     * changed in Moodle 5.1 (master and slave became toggler and target).
     *
     * @param string $introkey language string identifier of the explanation
     * @return string HTML
     */
    public static function pick_all(string $introkey): string {
        global $PAGE;

        $PAGE->requires->js_call_amd('core/checkbox-toggleall', 'init');

        return html_writer::tag('p', get_string($introkey, 'block_coursesync'))
            . html_writer::div(
                html_writer::checkbox('coursesync-pickall', 1, true, get_string('gradespickall', 'block_coursesync'), [
                    'id' => 'coursesync-pickall',
                    'data-action' => 'toggle',
                    'data-toggle' => self::roles()[0],
                    'data-togglegroup' => 'coursesync',
                ]),
                'mb-3'
            );
    }

    /**
     * The roles core's tick-all script uses for the box that ticks and the boxes that are ticked.
     *
     * @return string[] [toggler, target]
     */
    protected static function roles(): array {
        global $CFG;

        return (int) $CFG->branch >= 501 ? ['toggler', 'target'] : ['master', 'slave'];
    }

    /**
     * The entries grouped by student, in the order they are shown.
     *
     * A student with no account here is grouped by the username the other site gave. What
     * is not about one student - a whole assignment skipped - comes last.
     *
     * @param \stdClass[] $entries
     * @param \stdClass[] $users the accounts here, by id
     * @return array[] name, username and entries for each section
     */
    public static function group(array $entries, array $users): array {
        $sections = [];

        foreach ($entries as $entry) {
            $key = $entry->username === '' ? '' : ($entry->userid ? 'u' . $entry->userid : 'n' . $entry->username);

            if (!isset($sections[$key])) {
                $name = isset($users[$entry->userid])
                    ? fullname($users[$entry->userid])
                    : ($entry->username !== '' ? $entry->username : get_string('gradeswholeactivity', 'block_coursesync'));
                $sections[$key] = ['name' => $name, 'username' => $entry->username, 'entries' => []];
            }

            $sections[$key]['entries'][] = $entry;
        }

        uksort($sections, static function (string $a, string $b) use ($sections): int {
            if (($a === '') !== ($b === '')) {
                return $a === '' ? 1 : -1;
            }

            return strnatcasecmp($sections[$a]['name'], $sections[$b]['name']);
        });

        return array_values($sections);
    }

    /**
     * Every section, folded or open.
     *
     * A section starts open when something in it is to be written or was kept as it was, so
     * what needs a look is not hidden. Closing a section does not untick anything.
     *
     * @param \stdClass[] $entries
     * @param \stdClass[] $users the accounts here, by id
     * @param string[] $columns language string identifiers of the column headings after the tick box
     * @param callable $cells fn(\stdClass $entry): string[] the HTML of each cell for one entry, in the order of $columns
     * @param bool $ticking whether tick boxes are offered (a preview with something to write)
     * @param bool $preview whether this is a preview, not a pull that happened
     * @param callable $label fn(\stdClass $entry, string $student): string the name of one tick box; $student is already escaped
     * @return string HTML
     */
    public static function render(
        array $entries,
        array $users,
        array $columns,
        callable $cells,
        bool $ticking,
        bool $preview,
        callable $label
    ): string {
        [$toggler, $target] = self::roles();
        $html = '';
        $number = 0;

        foreach (self::group($entries, $users) as $section) {
            $number++;
            $forastudent = $section['username'] !== '';
            $group = 'coursesync-s' . $number;
            // The name can be a username from the other site, and labels are not escaped for us.
            $safename = s($section['name']);
            $counts = array_fill_keys(grade_pull_result::OUTCOMES, 0);
            $rows = '';

            usort($section['entries'], static fn(\stdClass $a, \stdClass $b): int => [$a->activity, (int) $a->attempt]
                <=> [$b->activity, (int) $b->attempt]);

            foreach ($section['entries'] as $entry) {
                $counts[$entry->outcome]++;
                $pick = '';

                if ($ticking && $forastudent && in_array($entry->outcome, self::WRITABLE, true)) {
                    $pick = html_writer::checkbox(
                        'pick[]',
                        grade_pull_result::key($entry),
                        true,
                        $label($entry, $safename),
                        [
                            'id' => 'coursesync-pick-' . grade_pull_result::key($entry),
                            'data-action' => 'toggle',
                            'data-toggle' => $target,
                            'data-togglegroup' => $group,
                        ],
                        ['class' => 'visually-hidden']
                    );
                }

                $rows .= html_writer::tag('tr', html_writer::tag('td', $pick) . implode('', array_map(
                    static fn(string $cell): string => html_writer::tag('td', $cell),
                    $cells($entry)
                )));
            }

            $towrite = $counts[grade_pull_result::ADD] + $counts[grade_pull_result::UPDATE];
            $master = '';

            if ($ticking && $forastudent && $towrite > 0) {
                $master = html_writer::checkbox('', 1, true, get_string('gradespickstudent', 'block_coursesync', $safename), [
                    'id' => 'coursesync-pickstudent-' . $number,
                    'data-action' => 'toggle',
                    'data-toggle' => $toggler,
                    'data-togglegroup' => $group,
                ], ['class' => 'visually-hidden']);
            }

            $head = html_writer::tag('tr', html_writer::tag('th', $master, ['scope' => 'col']) . implode('', array_map(
                static fn(string $column): string => html_writer::tag(
                    'th',
                    get_string($column, 'block_coursesync'),
                    ['scope' => 'col']
                ),
                $columns
            )));

            $badges = '';

            foreach (
                [
                    [$towrite, $preview ? 'gradesbadgetopull' : 'gradesbadgepulled', 'bg-success'],
                    [$counts[grade_pull_result::CONFLICT], 'gradesbadgekept', 'bg-warning text-dark'],
                    [$counts[grade_pull_result::SKIPPED], 'gradesbadgeskipped', 'bg-secondary'],
                ] as [$count, $string, $class]
            ) {
                if ($count > 0) {
                    $badges .= ' ' . html_writer::span(get_string($string, 'block_coursesync', $count), 'badge ' . $class);
                }
            }

            $title = $safename;

            if ($forastudent) {
                $title .= ' (' . s($section['username']) . ')';
            }

            $attention = !$forastudent || $towrite > 0 || $counts[grade_pull_result::CONFLICT] > 0;
            $html .= html_writer::tag(
                'details',
                html_writer::tag('summary', $title . $badges, ['class' => 'fw-bold py-1'])
                . html_writer::tag(
                    'table',
                    html_writer::tag('caption', $title, ['class' => 'visually-hidden'])
                    . html_writer::tag('thead', $head)
                    . html_writer::tag('tbody', $rows),
                    ['class' => 'table table-sm table-striped', 'id' => 'coursesync-student-' . $number]
                ),
                array_filter([
                    'class' => 'coursesync-student mb-2 border rounded px-2',
                    'id' => 'coursesync-student-section-' . $number,
                    'open' => $attention ? 'open' : null,
                ])
            );
        }

        return $html;
    }
}
