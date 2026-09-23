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

namespace block_coursesync\local\handler;

use block_coursesync\activity_payload;
/**
 * Handles mod_feedback, including its questions.
 *
 * The questions are what a feedback activity is, so they come across. The
 * answers people gave do not: those are the responses of the people in that
 * course, and an anonymous response in particular is not something to copy
 * anywhere.
 *
 * A question may depend on the answer to another question, and that dependency
 * is stored as the other question's id. Ids are local to the site they came
 * from, so the questions are created first and the dependencies fixed in a
 * second pass, once every id is known - a question can depend on one that comes
 * after it in the list as easily as before.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_handler extends activity_handler {
    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'feedback';
    }

    /**
     * A feedback's own files: those in the page shown after submitting, and
     * those embedded in its items - a label item is all text, and its images
     * are filed under the item's own id.
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'page_after_submit', 'itemid' => 0],
            ['filearea' => 'item', 'anyitemid' => true],
        ];
    }

    /**
     * DESTINATION SIDE. An item's files go under the item created here for it.
     *
     * @param activity_payload $payload
     * @param array $file
     * @param \stdClass $cm
     * @return int|null
     */
    public function map_file_itemid(activity_payload $payload, array $file, \stdClass $cm): ?int {
        if (($file['filearea'] ?? '') === 'item') {
            return $this->local_id('item', (int) ($file['itemid'] ?? 0));
        }

        return 0;
    }

    /**
     * SOURCE SIDE. How the feedback is set up.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the feedback table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        $settings['site_after_submit'] = (string) ($instance->site_after_submit ?? '');
        $settings['page_after_submit'] = (string) ($instance->page_after_submit ?? '');

        return $settings;
    }

    /**
     * SOURCE SIDE. The questions.
     *
     * The question's own id travels because it is what a dependency between two
     * questions is expressed in; it is a handle for matching them up again, not
     * something this site stores.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the feedback table
     * @return array[]
     */
    public function export_children(\cm_info $cm, \stdClass $instance): array {
        global $DB;

        $children = [];

        foreach ($DB->get_records('feedback_item', ['feedback' => $instance->id], 'position ASC') as $item) {
            $children[] = [
                'type' => 'item',
                'sortorder' => (int) $item->position,
                'fields' => [
                    'remoteid' => (int) $item->id,
                    'name' => (string) $item->name,
                    'label' => (string) $item->label,
                    'presentation' => (string) $item->presentation,
                    'typ' => (string) $item->typ,
                    'hasvalue' => (int) $item->hasvalue,
                    'position' => (int) $item->position,
                    'required' => (int) $item->required,
                    'dependitem' => (int) $item->dependitem,
                    'dependvalue' => (string) $item->dependvalue,
                    'options' => (string) $item->options,
                ],
            ];
        }

        return $children;
    }

    /**
     * DESTINATION SIDE. Build the feedback, and its questions, in a local course.
     *
     * @param \stdClass $course the destination course
     * @param activity_payload $payload what the source site sent
     * @param string $idnumber the ID number that marks this as synced
     * @return \stdClass the new course_modules record
     */
    public function create_from_remote_data(
        \stdClass $course,
        activity_payload $payload,
        string $idnumber
    ): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/feedback/lib.php');

        $sectionnum = $this->target_section($course, $payload);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        // Where to send people after they submit. It is rendered as a link, so
        // it is held to PARAM_URL for the same reason mod_url's address is.
        $data->site_after_submit = $payload->setting_url('site_after_submit');

        $format = $payload->setting_int('page_after_submitformat', FORMAT_HTML);
        $data->page_after_submit = $payload->setting_html('page_after_submit', $format);
        $data->page_after_submitformat = $format;
        // This is read by feedback_add_instance() without being checked for,
        // and a zero item id means "no draft area to move anything from".
        $data->page_after_submit_editor = [
            'text' => $data->page_after_submit,
            'format' => $format,
            'itemid' => 0,
        ];

        $instanceid = \feedback_add_instance($data);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        // A feedback whose questions did not all arrive is worse than none: the
        // next run would see it as already synced and never come back to it.
        try {
            $this->create_items((int) $instanceid, $payload);
        } catch (\Throwable $e) {
            $DB->delete_records('feedback_item', ['feedback' => $instanceid]);
            $DB->delete_records('feedback', ['id' => $instanceid]);
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * Recreate the questions, then fix the dependencies between them.
     *
     * @param int $feedbackid the feedback on this site
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    protected function create_items(int $feedbackid, activity_payload $payload): void {
        global $DB;

        $position = 0;
        $dependencies = [];

        foreach ($payload->children('item') as $item) {
            $type = activity_payload::child_field($item, 'typ');

            // A question of a type this site does not have could not be
            // answered or even displayed, so it is left out rather than stored.
            if (!self::item_type_is_installed($type)) {
                continue;
            }

            $record = (object) [
                'feedback' => $feedbackid,
                // A template is a stored set of questions belonging to the other
                // site. The copy is its own, not a use of a template here.
                'template' => 0,
                'name' => clean_param(activity_payload::child_field($item, 'name'), PARAM_TEXT),
                'label' => clean_param(activity_payload::child_field($item, 'label'), PARAM_TEXT),
                'presentation' => self::clean_presentation($type, $item),
                'typ' => $type,
                'hasvalue' => activity_payload::child_int($item, 'hasvalue', 0) ? 1 : 0,
                // Renumbered from one rather than trusted, so a gap or a repeat
                // on the source cannot produce questions in an unusable order.
                'position' => ++$position,
                'required' => activity_payload::child_int($item, 'required', 0) ? 1 : 0,
                'dependitem' => 0,
                'dependvalue' => clean_param(
                    activity_payload::child_field($item, 'dependvalue'),
                    PARAM_TEXT
                ),
                'options' => clean_param(activity_payload::child_field($item, 'options'), PARAM_TEXT),
            ];

            $localid = (int) $DB->insert_record('feedback_item', $record);
            $remoteid = activity_payload::child_int($item, 'remoteid', 0);

            $this->remember_id('item', $remoteid, $localid);

            $dependson = activity_payload::child_int($item, 'dependitem', 0);

            if ($dependson > 0) {
                $dependencies[$localid] = $dependson;
            }
        }

        // Second pass, now every question has an id here. A question that
        // depended on one that did not arrive is left depending on nothing,
        // which shows it always rather than hiding it behind a question that
        // is not there.
        foreach ($dependencies as $localid => $remotedependson) {
            $DB->set_field(
                'feedback_item',
                'dependitem',
                $this->mapped_id('item', $remotedependson),
                ['id' => $localid]
            );
        }
    }

    /**
     * Say that the copy arrived without anybody's responses.
     *
     * @param activity_payload $payload what the source site sent
     * @return string[] message keys to show against this activity
     */
    public function notes(activity_payload $payload): array {
        return ['syncfeedbacknoresponses'];
    }

    /**
     * Clean a question's presentation, which means different things per type.
     *
     * This field is whatever the question type needs it to be, and the two
     * shapes it takes cannot be cleaned the same way.
     *
     * A label's presentation is a block of HTML written in an editor, and
     * mod_feedback renders it with cleaning explicitly turned off, so anything
     * left in it runs in the reader's browser. It has to be cleaned here,
     * because nothing downstream will.
     *
     * Every other type packs structure into the field - a multiple choice keeps
     * its options separated by "|" after a marker like "r>>>>>". Running that
     * through clean_text() turns the marker into "r&gt;&gt;..." and the question
     * stops working, so those are stripped of markup instead, which leaves the
     * separators alone. Their contents reach the page through format_string(),
     * which escapes.
     *
     * @param string $type the question type
     * @param array $item the child record from the payload
     * @return string
     */
    protected static function clean_presentation(string $type, array $item): string {
        if ($type === 'label') {
            return activity_payload::child_html($item, 'presentation', FORMAT_HTML);
        }

        return clean_param(activity_payload::child_field($item, 'presentation'), PARAM_NOTAGS);
    }

    /**
     * Is that kind of question installed on this site?
     *
     * @param string $type the question type, for example 'textfield'
     * @return bool
     */
    protected static function item_type_is_installed(string $type): bool {
        global $CFG;

        if ($type === '' || $type !== clean_param($type, PARAM_ALPHA)) {
            return false;
        }

        // A page break is a real row in the questions table but is not one of
        // the question types under mod/feedback/item, so it is named here.
        if ($type === 'pagebreak') {
            return true;
        }

        require_once($CFG->dirroot . '/mod/feedback/lib.php');

        // Question types here are plain directories rather than a plugin type,
        // so this is mod_feedback's own list of them.
        return in_array($type, \feedback_load_feedback_items(), true);
    }

    /**
     * The feedback settings that are carried, and what to assume without them.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'anonymous' => 1,
            'email_notification' => 1,
            'multiple_submit' => 1,
            'autonumbering' => 1,
            'page_after_submitformat' => FORMAT_HTML,
            'publish_stats' => 0,
            'timeopen' => 0,
            'timeclose' => 0,
            'completionsubmit' => 0,
        ];
    }
}
