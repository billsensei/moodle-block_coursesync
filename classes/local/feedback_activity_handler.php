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
 * Recreates a mod_feedback instance AND its items from
 * feedback_activity_exporter's payload.
 *
 * Items are inserted directly into `feedback_item` (in a first pass, with
 * dependitem left at 0) rather than through any mod_feedback API - there
 * isn't a public "add one item" function to go through; core's OWN restore
 * code does exactly the same direct insert (see
 * mod/feedback/backup/moodle2/restore_feedback_stepslib.php's
 * process_feedback_item()), which is why this is a safe pattern to follow
 * rather than novel. A second pass (set_dependencies()) then resolves each
 * item's dependitemindex (a position in the exported list - see the
 * exporter's docblock) to the matching new item's real id, mirroring
 * restore's own after_execute() step for the same field.
 *
 * `feedback_add_instance()` does NOT set course_modules.instance itself -
 * same quirk as url/label/forum/assign/glossary/choice, handled the same
 * explicit way.
 *
 * If the payload carries an 'answersummary' (only when the pulling block
 * instance's config_includeanswers was on for this sync), it's rendered as
 * a read-only block appended to the intro, never written into
 * `feedback_completed`/`feedback_value` - same reasoning as
 * choice_activity_handler's own answersummary handling: there's no
 * source-to-destination user to attribute a synthetic response to, so the
 * activity's own intro is the only honest place for it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_activity_handler implements activity_handler {
    /** @var string[] Every typ mod_feedback's own item/ subdirectories (plus 'pagebreak', a special case with no directory of its own - see lib.php's feedback_create_item_pagebreak()) recognise. */
    protected const KNOWN_TYPES = [
        'info', 'label', 'textfield', 'textarea', 'multichoice', 'multichoicerated', 'numeric', 'captcha', 'pagebreak',
    ];

    /**
     * Creates the feedback course module, instance, and items.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from feedback_activity_exporter::export_with_options().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/feedback/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'feedback'], MUST_EXIST);

        $newcm = new \stdClass();
        $newcm->course = $courseid;
        $newcm->module = $moduleid;
        $newcm->instance = 0;
        $newcm->section = 0;
        $newcm->idnumber = $idnumber;
        $newcm->visible = 1;
        $newcm->visibleold = 1;
        $newcm->visibleoncoursepage = 1;
        $newcm->groupmode = 0;
        $newcm->groupingid = 0;
        $newcm->completion = 0;
        $newcm->showdescription = 0;

        $cmid = add_course_module($newcm);

        $feedbackdata = $this->build_feedback_data($courseid, $cmid, $data);
        $feedbackid = feedback_add_instance($feedbackdata);
        // This call does NOT set course_modules.instance for $cmid - done explicitly below.
        $DB->set_field('course_modules', 'instance', $feedbackid, ['id' => $cmid]);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'feedback');
        rebuild_course_cache($courseid, true);

        $this->create_items((int) $feedbackid, $data['items'] ?? []);

        return $cmid;
    }

    /**
     * Builds the feedback row's own settings.
     *
     * @param int $courseid
     * @param int $cmid
     * @param array $data
     * @return \stdClass
     */
    protected function build_feedback_data(int $courseid, int $cmid, array $data): \stdClass {
        $feedback = new \stdClass();
        $feedback->course = $courseid;
        $feedback->coursemodule = $cmid;
        $feedback->name = sanitizer::text($data['name'] ?? '');
        $feedback->intro = $this->build_intro($data);
        $feedback->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $feedback->anonymous = sanitizer::integer($data['anonymous'] ?? 1, 1);
        $feedback->email_notification = sanitizer::integer($data['email_notification'] ?? 1, 1);
        $feedback->multiple_submit = sanitizer::integer($data['multiple_submit'] ?? 1, 1);
        $feedback->autonumbering = sanitizer::integer($data['autonumbering'] ?? 1, 1);
        $feedback->site_after_submit = sanitizer::url($data['site_after_submit'] ?? '');
        $feedback->page_after_submit = sanitizer::html($data['page_after_submit'] ?? '');
        $feedback->page_after_submitformat = sanitizer::textformat($data['page_after_submitformat'] ?? FORMAT_HTML);
        // Feedback_add_instance() only rebuilds ->page_after_submit from this
        // editor array when its itemid is truthy (a real draft area with
        // embedded files to move) - itemid 0 leaves the plain string field
        // above as the stored value, which is exactly what's wanted here
        // (no item-level files are synced - see the exporter's docblock).
        $feedback->page_after_submit_editor = [
            'itemid' => 0,
            'text' => $feedback->page_after_submit,
            'format' => $feedback->page_after_submitformat,
        ];
        $feedback->publish_stats = sanitizer::integer($data['publish_stats'] ?? 0);
        $feedback->timeopen = sanitizer::integer($data['timeopen'] ?? 0);
        $feedback->timeclose = sanitizer::integer($data['timeclose'] ?? 0);
        $feedback->completionsubmit = sanitizer::integer($data['completionsubmit'] ?? 0);

        return $feedback;
    }

    /**
     * Sanitizes the intro, then appends the answer summary block (if any) -
     * see class docblock for why this, not feedback_completed/feedback_value,
     * is where it lives.
     *
     * @param array $data
     * @return string
     */
    protected function build_intro(array $data): string {
        $intro = sanitizer::html($data['intro'] ?? '');

        if (empty($data['answersummary'])) {
            return $intro;
        }

        $heading = get_string('answersummaryheading', 'block_coursesync', userdate(time()));
        $total = get_string(
            'answersummarytotal',
            'block_coursesync',
            sanitizer::integer($data['answersummary']['totalresponses'] ?? 0)
        );

        $itemlines = [];
        foreach ($data['items'] ?? [] as $itemdata) {
            $line = $this->build_item_summary_line($itemdata);
            if ($line !== null) {
                $itemlines[] = \html_writer::tag('li', $line);
            }
        }

        $block = \html_writer::empty_tag('hr') .
            \html_writer::tag('p', \html_writer::tag('em', $heading)) .
            \html_writer::tag('p', $total) .
            (!empty($itemlines) ? \html_writer::tag('ul', implode('', $itemlines)) : '');

        return $intro . $block;
    }

    /**
     * Builds one item's line in the answer summary, or null for an item
     * with no answersummary (includeanswers was off, or - impossible in
     * practice, since the exporter always attaches one when on - a legacy
     * payload) or one that never carries a value (hasvalue = 0: info,
     * label, pagebreak, captcha).
     *
     * @param array $itemdata
     * @return string|null Escaped HTML, ready to wrap in <li>.
     */
    protected function build_item_summary_line(array $itemdata): ?string {
        if (empty($itemdata['hasvalue']) || !isset($itemdata['answersummary'])) {
            return null;
        }

        $summary = $itemdata['answersummary'];
        $label = sanitizer::text($itemdata['name'] ?? '') ?: sanitizer::text($itemdata['label'] ?? '');

        $line = s($label !== '' ? $label : get_string('answersummaryunnameditem', 'block_coursesync')) . ': ' . get_string(
            'answersummaryitemresponses',
            'block_coursesync',
            sanitizer::integer($summary['responsecount'] ?? 0)
        );

        if (!empty($summary['optioncounts'])) {
            $parts = [];
            foreach ($summary['optioncounts'] as $option) {
                $a = (object) [
                    'text' => s(sanitizer::text($option['text'] ?? '')),
                    'count' => sanitizer::integer($option['count'] ?? 0),
                ];
                $parts[] = get_string('answersummaryoptionline', 'block_coursesync', $a);
            }
            if (!empty($parts)) {
                $line .= ' (' . implode(', ', $parts) . ')';
            }
        } else if (isset($summary['average'])) {
            $a = (object) [
                'average' => round(sanitizer::float($summary['average'] ?? 0), 2),
                'min' => sanitizer::float($summary['min'] ?? 0),
                'max' => sanitizer::float($summary['max'] ?? 0),
            ];
            $line .= ' (' . get_string('answersummaryitemaverage', 'block_coursesync', $a) . ')';
        }

        return $line;
    }

    /**
     * Inserts every item (first pass, dependitem left at 0), then resolves
     * dependitemindex to a real id in a second pass - see class docblock.
     *
     * @param int $feedbackid
     * @param array $items From feedback_activity_exporter::export_items().
     */
    protected function create_items(int $feedbackid, array $items): void {
        global $DB;

        $newids = [];
        foreach (array_values($items) as $index => $itemdata) {
            $newitem = new \stdClass();
            $newitem->feedback = $feedbackid;
            $newitem->template = 0;
            $newitem->name = sanitizer::text($itemdata['name'] ?? '');
            // Note: feedback_item.label is char(255), unlike most *.name
            // columns (char(1333)) - see sanitizer::text()'s docblock.
            $newitem->label = sanitizer::text($itemdata['label'] ?? '', 255);
            $newitem->presentation = sanitizer::structured($itemdata['presentation'] ?? '');
            $sourcetyp = (string) ($itemdata['typ'] ?? '');
            $newitem->typ = $this->sanitize_typ($sourcetyp);
            // Only trust the source's own hasvalue when typ itself was
            // trusted as-is - a fallback to 'label' means the source's typ
            // was unrecognised, so hasvalue is forced to match label's
            // normal shape (never counted as an answerable item) rather
            // than carrying over a flag that made sense for a type this
            // handler just refused to store.
            $newitem->hasvalue = ($newitem->typ === $sourcetyp) ? sanitizer::integer($itemdata['hasvalue'] ?? 0) : 0;
            $newitem->position = $index + 1;
            $newitem->required = sanitizer::integer($itemdata['required'] ?? 0);
            $newitem->dependitem = 0;
            $newitem->dependvalue = sanitizer::structured($itemdata['dependvalue'] ?? '', 255);
            $newitem->options = sanitizer::structured($itemdata['options'] ?? '', 255);

            $newids[$index] = $DB->insert_record('feedback_item', $newitem);
        }

        foreach (array_values($items) as $index => $itemdata) {
            $dependindex = $itemdata['dependitemindex'] ?? -1;
            if ($dependindex >= 0 && isset($newids[$dependindex])) {
                $DB->set_field('feedback_item', 'dependitem', $newids[$dependindex], ['id' => $newids[$index]]);
            }
        }
    }

    /**
     * Validates typ against the known, fixed set mod_feedback itself
     * recognises (never trusts remote data to match what this plugin's own
     * exporter would send) - an unrecognised one falls back to 'label' with
     * hasvalue forced to 0 (see create_items()), the safest "just inert
     * text" shape, same spirit as forum's type/glossary's displayformat
     * fallbacks elsewhere in this plugin.
     *
     * @param mixed $value
     * @return string
     */
    protected function sanitize_typ($value): string {
        $value = (string) $value;

        return in_array($value, self::KNOWN_TYPES, true) ? $value : 'label';
    }
}
