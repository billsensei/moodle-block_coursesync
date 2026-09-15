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
 * Optional capability an activity_exporter can additionally implement: lets
 * the pulling block instance's own config drive extra, opt-in content in the
 * payload, on top of that type's normal export() (Phase 12's
 * config_includeanswers checkbox - see edit_form.php and
 * block_coursesync::sync_now()).
 *
 * Only choice_activity_exporter and feedback_activity_exporter implement
 * this so far. Deliberately a SEPARATE interface rather than a new parameter
 * on activity_exporter::export() itself, so every exporter that has no use
 * for options (page, url, label, resource, forum, assign, h5pactivity, quiz,
 * glossary, wiki) is completely untouched - get_activity_content.php checks
 * `instanceof self` and calls export_with_options() only when it's actually
 * implemented, falling back to plain export() for everything else.
 *
 * 'includeanswers' (bool) is the only option key any exporter recognises
 * today. When true, choice_activity_exporter/feedback_activity_exporter add
 * ANONYMISED AGGREGATE response counts to their payload - never individual
 * per-user answers. That's not just an extra privacy precaution: this
 * plugin has no cross-site user-id mapping anywhere (group subwikis are
 * matched by name only - see wiki_activity_exporter's docblock), so there
 * is no destination user a "who answered what" row could even correctly be
 * attributed to. See choice_activity_exporter's and
 * feedback_activity_exporter's own docblocks for exactly what gets
 * aggregated for each type, and DEVELOPER_NOTES.md's "Choice and Feedback:
 * aggregate answers, never individual ones" section for the full reasoning.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface activity_exporter_with_options extends activity_exporter {
    /**
     * Minimum distinct respondents (choice: distinct choice_answers.userid;
     * feedback: feedback_completed rows overall / feedback_value rows for
     * one item) an answersummary needs before any PER-OPTION breakdown
     * (choice's 'options' counts; feedback's 'optioncounts'/'average'/
     * 'min'/'max') is included at all - below this, only the bare total is
     * included. This isn't extra caution on top of the anonymisation this
     * interface already promises: at a small n (most starkly n=1) a
     * breakdown doesn't approximate anonymity, it fully reconstructs that
     * one respondent's exact answer, which is exactly what
     * config_includeanswers is documented as never doing - see this
     * interface's own docblock and DEVELOPER_NOTES.md's "Choice and
     * Feedback: aggregate answers, never individual ones" section. 5 is a
     * conventional small-cell-suppression threshold (the same order of
     * magnitude used for this in education/census reporting); not
     * configurable today.
     */
    public const MIN_RESPONDENTS_FOR_BREAKDOWN = 5;

    /**
     * Builds this activity's payload, honouring extra sync options.
     *
     * @param \cm_info $cm The course module to export. $cm->modname must be this exporter's type.
     * @param array $options Recognised keys are type-specific; see the implementing class's docblock.
     *                        Today only 'includeanswers' (bool) is recognised by anything.
     * @return array
     */
    public function export_with_options(\cm_info $cm, array $options): array;
}
