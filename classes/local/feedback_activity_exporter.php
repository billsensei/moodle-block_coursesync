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
 * Exports a mod_feedback instance's settings AND its items (`feedback_item`
 * - the questions/labels/pagebreaks that make up the survey) - always, same
 * "settings-only would be unsatisfying" reasoning as glossary/quiz/choice.
 * Every item is copied close to verbatim (typ/name/label/presentation/
 * hasvalue/position/required/options - see feedback_item's own schema),
 * since unlike quiz's qtype plugins, mod_feedback has no separate
 * per-type handler registry of its own to go through: an item IS its row.
 *
 * `dependitem` (an item id another item's visibility depends on) is
 * exported as `dependitemindex` - a position into this payload's own
 * `items` list, same position-not-id pattern as glossary's categoryindexes
 * - since the destination's items get entirely new ids. Resolved back to a
 * real id only after every item has been created - see
 * feedback_activity_handler::set_dependencies().
 *
 * `feedback_completed`/`feedback_value` (WHO answered WHAT) is never
 * exported unless the pulling block instance's config_includeanswers is on
 * (Phase 12), and even then only as anonymised aggregates, via
 * activity_exporter_with_options - see that interface's docblock and
 * export_item_answer_summary() below for exactly what's aggregated per
 * item type and, importantly, what ISN'T: a free-text item (textfield/
 * textarea) only ever gets a response COUNT, never any of the text itself
 * - even without a name attached, someone's actual written answer is
 * still personal content, not a safe aggregate, so this plugin never lets
 * it cross sites at all.
 *
 * NOT exported (documented carve-outs, not oversights): item-level embedded
 * files (component mod_feedback, filearea 'item' - used by label-type items
 * to embed images; a future phase could add this the same way glossary's
 * entry files are handled) and feedback_template (site-level reusable
 * templates - a site-specific concept the destination may not even have
 * the same ones for).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_activity_exporter implements activity_exporter_with_options {
    /**
     * Builds the payload with no options - see export_with_options().
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        return $this->export_with_options($cm, []);
    }

    /**
     * Builds the payload feedback_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @param array $options 'includeanswers' (bool) adds per-item/overall 'answersummary' - see class docblock.
     * @return array
     */
    public function export_with_options(\cm_info $cm, array $options): array {
        global $DB;

        $feedback = $DB->get_record('feedback', ['id' => $cm->instance], '*', MUST_EXIST);
        $includeanswers = !empty($options['includeanswers']);

        [$items, $itemindexbyid] = $this->export_items((int) $feedback->id, $includeanswers);

        $data = [
            'name' => $feedback->name,
            'intro' => (string) $feedback->intro,
            'introformat' => (int) $feedback->introformat,
            'anonymous' => (int) $feedback->anonymous,
            'email_notification' => (int) $feedback->email_notification,
            'multiple_submit' => (int) $feedback->multiple_submit,
            'autonumbering' => (int) $feedback->autonumbering,
            'site_after_submit' => (string) $feedback->site_after_submit,
            'page_after_submit' => (string) $feedback->page_after_submit,
            'page_after_submitformat' => (int) $feedback->page_after_submitformat,
            'publish_stats' => (int) $feedback->publish_stats,
            'timeopen' => (int) $feedback->timeopen,
            'timeclose' => (int) $feedback->timeclose,
            'completionsubmit' => (int) $feedback->completionsubmit,
            'items' => $items,
        ];

        unset($itemindexbyid); // Only needed inside export_items() itself, for dependitem resolution.

        if ($includeanswers) {
            $data['answersummary'] = [
                'totalresponses' => $DB->count_records('feedback_completed', ['feedback' => $feedback->id]),
            ];
        }

        return $data;
    }

    /**
     * Exports every item, in position order, resolving each one's
     * dependitem to a position in this same list (see class docblock).
     *
     * @param int $feedbackid
     * @param bool $includeanswers
     * @return array{0: array<int, array>, 1: array<int, int>} [items, sourceitemid => position]
     */
    protected function export_items(int $feedbackid, bool $includeanswers): array {
        global $DB;

        $rows = $DB->get_records('feedback_item', ['feedback' => $feedbackid], 'position ASC');

        $indexbyid = [];
        foreach (array_values($rows) as $index => $row) {
            $indexbyid[(int) $row->id] = $index;
        }

        $items = [];
        foreach (array_values($rows) as $index => $row) {
            $entry = [
                'typ' => (string) $row->typ,
                'name' => (string) $row->name,
                'label' => (string) $row->label,
                'presentation' => (string) $row->presentation,
                'hasvalue' => (int) $row->hasvalue,
                'position' => $index + 1,
                'required' => (int) $row->required,
                'dependitemindex' => $indexbyid[(int) $row->dependitem] ?? -1,
                'dependvalue' => (string) $row->dependvalue,
                'options' => (string) $row->options,
            ];

            if ($includeanswers) {
                $entry['answersummary'] = $this->export_item_answer_summary($row);
            }

            $items[] = $entry;
        }

        return [$items, $indexbyid];
    }

    /**
     * Aggregates one item's responses, never further than an anonymous
     * count for any item type this doesn't specifically know how to
     * decode a closed set of options for - see class docblock for why a
     * count is always safe but option/text content is not.
     *
     * @param \stdClass $item A feedback_item row.
     * @return array{responsecount: int, optioncounts: ?array<int, array{text: string, count: int}>,
     *     average: ?float, min: ?float, max: ?float}
     */
    protected function export_item_answer_summary(\stdClass $item): array {
        global $DB;

        $values = array_values($DB->get_records('feedback_value', ['item' => $item->id], '', 'id, value'));
        $responsecount = count($values);

        $summary = [
            'responsecount' => $responsecount,
            'optioncounts' => null,
            'average' => null,
            'min' => null,
            'max' => null,
        ];

        if ($responsecount < self::MIN_RESPONDENTS_FOR_BREAKDOWN) {
            // Below this, a breakdown could fully reveal one respondent's
            // exact answer, not just approximate anonymity - see
            // MIN_RESPONDENTS_FOR_BREAKDOWN's docblock. responsecount itself
            // is still safe to show (build_item_summary_line() always does).
            return $summary;
        }

        if ($item->typ === 'multichoice') {
            $summary['optioncounts'] = $this->export_multichoice_optioncounts($item, $values);
        } else if ($item->typ === 'numeric') {
            $numbers = array_filter(array_map(
                fn($row): ?float => is_numeric($row->value) ? (float) $row->value : null,
                $values
            ), fn($n) => $n !== null);

            if (!empty($numbers)) {
                $summary['average'] = array_sum($numbers) / count($numbers);
                $summary['min'] = min($numbers);
                $summary['max'] = max($numbers);
            }
        }
        // Every other typ (textfield, textarea, multichoicerated, info,
        // label, pagebreak, captcha, ...) gets a response count only - see
        // class docblock. multichoicerated's own value format (a weight/text
        // pair per option, not the plain 1-based index multichoice uses) is
        // deliberately not decoded here, to avoid getting its rating
        // semantics subtly wrong rather than just not offering a
        // per-option breakdown for it yet.

        return $summary;
    }

    /**
     * Decodes a 'multichoice' item's presentation into its option labels,
     * then tallies each stored value against them - using mod_feedback's
     * OWN constants/format (FEEDBACK_MULTICHOICE_TYPE_SEP/LINE_SEP/
     * ADJUST_SEP - see mod/feedback/item/multichoice/lib.php's get_info(),
     * get_printval() and get_analysed(), which this mirrors) rather than
     * hardcoding the separators, so a future core change to them wouldn't
     * silently desync this from what's actually stored.
     *
     * A subtype 'c' (checkbox) item can select several options at once,
     * stored as their indices joined by LINE_SEP; 'r' (radio) and 'd'
     * (dropdown) store a single index. Any value that isn't a resolvable
     * index (never sent by mod_feedback itself, but this doesn't trust
     * stored data to be well-formed either) is simply not counted.
     *
     * @param \stdClass $item
     * @param array<int, \stdClass> $values Rows from feedback_value, each with ->value.
     * @return array<int, array{text: string, count: int}>
     */
    protected function export_multichoice_optioncounts(\stdClass $item, array $values): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/feedback/item/multichoice/lib.php');

        $parts = explode(FEEDBACK_MULTICHOICE_TYPE_SEP, (string) $item->presentation);
        $subtype = $parts[0] ?? 'r';
        $presentation = $parts[1] ?? '';

        if ($subtype !== 'd') {
            $presentation = explode(FEEDBACK_MULTICHOICE_ADJUST_SEP, $presentation)[0];
        }

        $labels = array_values(array_filter(
            explode(FEEDBACK_MULTICHOICE_LINE_SEP, $presentation),
            fn($label) => $label !== ''
        ));

        $counts = array_fill(0, count($labels), 0);

        foreach ($values as $row) {
            $indices = $subtype === 'c'
                ? explode(FEEDBACK_MULTICHOICE_LINE_SEP, (string) $row->value)
                : [(string) $row->value];

            foreach ($indices as $indexstring) {
                if (!ctype_digit($indexstring)) {
                    continue;
                }
                $position = ((int) $indexstring) - 1;
                if (isset($counts[$position])) {
                    $counts[$position]++;
                }
            }
        }

        $result = [];
        foreach ($labels as $index => $label) {
            $result[] = ['text' => trim($label), 'count' => $counts[$index]];
        }

        return $result;
    }
}
