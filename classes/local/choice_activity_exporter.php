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
 * Exports a mod_choice instance's settings AND its options (`choice_options`
 * - the list of things a student can pick between) - always. Options are
 * this activity's whole point, same reasoning as glossary's entries or
 * quiz's questions: settings-only would leave an empty picker.
 *
 * `choice_answers` (WHO picked WHAT) is never exported unless the pulling
 * block instance has "include response summaries" enabled (Phase 12's
 * config_includeanswers), and even then only as an anonymised per-option
 * COUNT, via activity_exporter_with_options - see that interface's docblock
 * for why counts, never individual answers. This is the same
 * user-generated-content privacy cut as forum posts/assignment submissions:
 * `choice_answers` rows are one person's own choice, not shared/course-level
 * content, so nothing in this class ever reads them without that explicit
 * opt-in, and even then it deliberately never reads ->userid for anything
 * other than counting.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class choice_activity_exporter implements activity_exporter_with_options {
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
     * Builds the payload choice_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @param array $options 'includeanswers' (bool) adds 'answersummary' - see class docblock.
     * @return array
     */
    public function export_with_options(\cm_info $cm, array $options): array {
        global $DB;

        $choice = $DB->get_record('choice', ['id' => $cm->instance], '*', MUST_EXIST);
        [$exportedoptions, $optionindexbyid] = $this->export_options((int) $choice->id);

        $data = [
            'name' => $choice->name,
            'intro' => (string) $choice->intro,
            'introformat' => (int) $choice->introformat,
            'publish' => (int) $choice->publish,
            'showresults' => (int) $choice->showresults,
            'display' => (int) $choice->display,
            'allowupdate' => (int) $choice->allowupdate,
            'allowmultiple' => (int) $choice->allowmultiple,
            'showunanswered' => (int) $choice->showunanswered,
            'includeinactive' => (int) $choice->includeinactive,
            'limitanswers' => (int) $choice->limitanswers,
            'timeopen' => (int) $choice->timeopen,
            'timeclose' => (int) $choice->timeclose,
            'showpreview' => (int) $choice->showpreview,
            'completionsubmit' => (int) $choice->completionsubmit,
            'showavailable' => (int) $choice->showavailable,
            'options' => $exportedoptions,
        ];

        if (!empty($options['includeanswers'])) {
            $data['answersummary'] = $this->export_answer_summary((int) $choice->id, $optionindexbyid, count($exportedoptions));
        }

        return $data;
    }

    /**
     * Exports every option, in order, alongside a sourceoptionid => position
     * lookup for export_answer_summary() to tally counts against - same
     * position-not-id pattern as glossary_activity_exporter's categories,
     * for the same reason (the destination's options get entirely new ids).
     *
     * @param int $choiceid
     * @return array{0: array<int, array{text: string, maxanswers: int}>, 1: array<int, int>}
     */
    protected function export_options(int $choiceid): array {
        global $DB;

        $options = $DB->get_records('choice_options', ['choiceid' => $choiceid], 'id ASC');

        $exported = [];
        $indexbyid = [];
        foreach (array_values($options) as $index => $option) {
            $exported[] = [
                'text' => (string) $option->text,
                'maxanswers' => (int) $option->maxanswers,
            ];
            $indexbyid[(int) $option->id] = $index;
        }

        return [$exported, $indexbyid];
    }

    /**
     * Tallies how many `choice_answers` rows landed on each option, keyed by
     * the same position export_options() used - never reads ->userid for
     * anything but a distinct-respondent count (allowmultiple lets one user
     * occupy several rows), so nothing that could identify a specific
     * respondent ever leaves this method.
     *
     * @param int $choiceid
     * @param array<int, int> $optionindexbyid From export_options().
     * @param int $numoptions
     * @return array{totalresponses: int, options: array<int, int>} 'options' is counts, same order/length
     *                                                                as the 'options' payload key.
     */
    protected function export_answer_summary(int $choiceid, array $optionindexbyid, int $numoptions): array {
        global $DB;

        $counts = array_fill(0, $numoptions, 0);
        $respondents = [];

        $answers = $DB->get_records('choice_answers', ['choiceid' => $choiceid], '', 'id, userid, optionid');
        foreach ($answers as $answer) {
            $respondents[(int) $answer->userid] = true;
            $index = $optionindexbyid[(int) $answer->optionid] ?? null;
            if ($index !== null) {
                $counts[$index]++;
            }
        }

        $totalresponses = count($respondents);

        return [
            'totalresponses' => $totalresponses,
            // Null (not the real counts, and not zeros either - a fabricated
            // "everyone got 0" would be actively misleading), below
            // MIN_RESPONDENTS_FOR_BREAKDOWN - see that constant's docblock.
            'options' => $totalresponses >= self::MIN_RESPONDENTS_FOR_BREAKDOWN ? $counts : null,
        ];
    }
}
