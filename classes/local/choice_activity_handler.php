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
 * Recreates a mod_choice instance AND its options from
 * choice_activity_exporter's payload.
 *
 * `choice_add_instance()` (mod/choice/lib.php) takes the option texts and
 * per-option limits as plain `->option[]`/`->limit[]` arrays and creates
 * the `choice_options` rows itself - unlike glossary/wiki, this handler
 * never inserts into that table directly. It does NOT set
 * course_modules.instance itself, though - same quirk as url/label/forum/
 * assign/glossary, handled the same explicit way.
 *
 * If the payload carries an 'answersummary' (only present when the pulling
 * block instance's config_includeanswers checkbox was on for this sync -
 * see choice_activity_exporter's docblock), it's rendered as a clearly
 * labelled, read-only block APPENDED TO THE INTRO - never written into
 * `choice_answers` itself. Writing synthetic rows into that table would
 * misrepresent real submissions (they'd have to be attributed to some
 * userid, and there is no source-to-destination user mapping to attribute
 * them to correctly - see activity_exporter_with_options's docblock), so
 * this activity's own intro/description is the only honest place for
 * "here's what people answered on the source site" to live: visible to a
 * teacher, edited or removed like any other text, and never mistaken for
 * a live response a student could still change.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class choice_activity_handler implements activity_handler {
    /**
     * Creates the choice course module, instance, and options.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from choice_activity_exporter::export_with_options().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/choice/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'choice'], MUST_EXIST);

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

        $choicedata = $this->build_choice_data($cmid, $idnumber, $data);
        $choiceid = choice_add_instance($choicedata);
        // This call does NOT set course_modules.instance for $cmid - done explicitly below.
        $DB->set_field('course_modules', 'instance', $choiceid, ['id' => $cmid]);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'choice');
        rebuild_course_cache($courseid, true);

        return $cmid;
    }

    /**
     * Builds the choice row's own settings, including the option/limit
     * arrays choice_add_instance() itself turns into `choice_options` rows.
     *
     * @param int $cmid
     * @param string $idnumber
     * @param array $data
     * @return \stdClass
     */
    protected function build_choice_data(int $cmid, string $idnumber, array $data): \stdClass {
        $choice = new \stdClass();
        $choice->coursemodule = $cmid;
        // Mirrors the course_modules idnumber - choice_set_events() (called
        // by choice_add_instance()) reads ->cmidnumber the same way every
        // other handler's *_add_instance() call in this plugin does.
        $choice->cmidnumber = $idnumber;
        $choice->name = sanitizer::text($data['name'] ?? '');
        $choice->intro = $this->build_intro($data);
        $choice->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $choice->publish = sanitizer::integer($data['publish'] ?? 0);
        $choice->showresults = sanitizer::integer($data['showresults'] ?? 0);
        $choice->display = sanitizer::integer($data['display'] ?? 0);
        $choice->allowupdate = sanitizer::integer($data['allowupdate'] ?? 0);
        $choice->allowmultiple = sanitizer::integer($data['allowmultiple'] ?? 0);
        $choice->showunanswered = sanitizer::integer($data['showunanswered'] ?? 0);
        $choice->includeinactive = sanitizer::integer($data['includeinactive'] ?? 1, 1);
        $choice->limitanswers = sanitizer::integer($data['limitanswers'] ?? 0);
        $choice->timeopen = sanitizer::integer($data['timeopen'] ?? 0);
        $choice->timeclose = sanitizer::integer($data['timeclose'] ?? 0);
        $choice->showpreview = sanitizer::integer($data['showpreview'] ?? 0);
        $choice->completionsubmit = sanitizer::integer($data['completionsubmit'] ?? 0);
        $choice->showavailable = sanitizer::integer($data['showavailable'] ?? 0);

        $choice->option = [];
        $choice->limit = [];
        foreach ($data['options'] ?? [] as $option) {
            $choice->option[] = sanitizer::text($option['text'] ?? '');
            $choice->limit[] = sanitizer::integer($option['maxanswers'] ?? 0);
        }

        return $choice;
    }

    /**
     * Sanitizes the intro, then appends the answer summary block (if any) -
     * see class docblock for why this, not `choice_answers`, is where it
     * lives. Built AFTER sanitizing the source intro so the summary's own
     * escaped, plugin-generated markup can't be stripped by the same
     * clean_text() pass that has to run on remote-sourced HTML.
     *
     * @param array $data
     * @return string
     */
    protected function build_intro(array $data): string {
        $intro = sanitizer::html($data['intro'] ?? '');

        if (empty($data['answersummary'])) {
            return $intro;
        }

        $summary = $data['answersummary'];
        $options = $data['options'] ?? [];
        // Null (not the exporter's real counts) when the source had fewer
        // than MIN_RESPONDENTS_FOR_BREAKDOWN respondents - see
        // choice_activity_exporter::export_answer_summary().
        $counts = $summary['options'] ?? null;

        $heading = get_string('answersummaryheading', 'block_coursesync', userdate(time()));
        $total = get_string('answersummarytotal', 'block_coursesync', sanitizer::integer($summary['totalresponses'] ?? 0));

        $block = \html_writer::empty_tag('hr') .
            \html_writer::tag('p', \html_writer::tag('em', $heading)) .
            \html_writer::tag('p', $total);

        if ($counts !== null) {
            $rows = [];
            foreach ($options as $index => $option) {
                $a = (object) [
                    'text' => s(sanitizer::text($option['text'] ?? '')),
                    'count' => sanitizer::integer($counts[$index] ?? 0),
                ];
                $rows[] = \html_writer::tag('li', get_string('answersummaryoptionline', 'block_coursesync', $a));
            }
            $block .= \html_writer::tag('ul', implode('', $rows));
        } else {
            // Too few respondents to show a breakdown without risking
            // identifying one of them - see
            // activity_exporter_with_options::MIN_RESPONDENTS_FOR_BREAKDOWN.
            $block .= \html_writer::tag('p', \html_writer::tag('em', get_string(
                'answersummarybreakdownsuppressed',
                'block_coursesync',
                activity_exporter_with_options::MIN_RESPONDENTS_FOR_BREAKDOWN
            )));
        }

        return $intro . $block;
    }
}
