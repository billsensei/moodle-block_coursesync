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
 * Exports a mod_assign instance's settings for the source side.
 *
 * Settings only, same scope as every other exporter in this plugin: no
 * student submissions, grades, or feedback ever leave the source site - only
 * the assignment's own configuration, i.e. what a teacher would set up on
 * mod_form.php before any student sees it.
 *
 * That configuration includes which submission/feedback sub-plugins are
 * enabled and their own settings (assign_plugin_config), because
 * assign_activity_handler needs to know that to avoid creating an
 * assignment students can't submit anything to (see that class's docblock).
 * Only the two most common submission types (onlinetext, file) and the
 * default feedback type (comments) are exported - see
 * export_plugin_config()'s $fields map, which is the single source of truth
 * for which sub-plugin settings this pair of classes understands. A source
 * assignment using any other sub-plugin (group, offline, ...) simply has
 * that sub-plugin's settings left unexported; the handler leaves it
 * disabled on the destination, same as a teacher who never ticked its box.
 *
 * The "activity" rich-content field (interactive content embedded in the
 * assignment page itself, distinct from the intro) and its file attachments
 * are deliberately out of scope, same rationale as forum's discussions/
 * posts: this is activity-level settings, not embedded content.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_activity_exporter implements activity_exporter {
    /**
     * subtype => plugin => [config names], mirrored by
     * assign_activity_handler::PLUGIN_CONFIG_FIELDS - the only sub-plugin
     * settings either side of this pair reads or writes.
     */
    protected const PLUGIN_CONFIG_FIELDS = [
        'assignsubmission' => [
            'onlinetext' => ['wordlimit', 'wordlimitenabled'],
            'file' => ['maxfilesubmissions', 'maxsubmissionsizebytes', 'filetypeslist'],
        ],
        'assignfeedback' => [
            'comments' => ['commentinline'],
        ],
    ];

    /**
     * Builds the payload assign_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $DB;

        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);

        return [
            'name' => $assign->name,
            'intro' => (string) $assign->intro,
            'introformat' => (int) $assign->introformat,
            'alwaysshowdescription' => (int) $assign->alwaysshowdescription,
            'duedate' => (int) $assign->duedate,
            'allowsubmissionsfromdate' => (int) $assign->allowsubmissionsfromdate,
            'cutoffdate' => (int) $assign->cutoffdate,
            'gradingduedate' => (int) $assign->gradingduedate,
            'grade' => (int) $assign->grade,
            'timelimit' => (int) $assign->timelimit,
            'submissiondrafts' => (int) $assign->submissiondrafts,
            'requiresubmissionstatement' => (int) $assign->requiresubmissionstatement,
            'sendnotifications' => (int) $assign->sendnotifications,
            'sendlatenotifications' => (int) $assign->sendlatenotifications,
            'sendstudentnotifications' => (int) $assign->sendstudentnotifications,
            'completionsubmit' => (int) $assign->completionsubmit,
            'teamsubmission' => (int) $assign->teamsubmission,
            'requireallteammemberssubmit' => (int) $assign->requireallteammemberssubmit,
            'blindmarking' => (int) $assign->blindmarking,
            'hidegrader' => (int) $assign->hidegrader,
            'attemptreopenmethod' => (string) $assign->attemptreopenmethod,
            'maxattempts' => (int) $assign->maxattempts,
            'markingworkflow' => (int) $assign->markingworkflow,
            'markingallocation' => (int) $assign->markingallocation,
            'markinganonymous' => (int) $assign->markinganonymous,
            'preventsubmissionnotingroup' => (int) $assign->preventsubmissionnotingroup,
            'submissionattachments' => (int) $assign->submissionattachments,
            'gradepenalty' => (int) $assign->gradepenalty,
        ] + $this->export_plugin_config((int) $assign->id);
    }

    /**
     * Reads whether each known submission/feedback sub-plugin is enabled,
     * plus its own settings, straight out of assign_plugin_config - the
     * same generic (assignment, subtype, plugin, name) => value store every
     * assign sub-plugin's own save_settings()/get_config() reads and writes.
     *
     * @param int $assignid
     * @return array Flat map of "{subtype}_{plugin}_{name}" => value (string|null).
     */
    protected function export_plugin_config(int $assignid): array {
        global $DB;

        $config = [];
        foreach (self::PLUGIN_CONFIG_FIELDS as $subtype => $plugins) {
            foreach ($plugins as $plugin => $names) {
                $enabled = $DB->get_field('assign_plugin_config', 'value', [
                    'assignment' => $assignid,
                    'subtype' => $subtype,
                    'plugin' => $plugin,
                    'name' => 'enabled',
                ]);
                $config["{$subtype}_{$plugin}_enabled"] = $enabled === false ? 0 : (int) $enabled;

                foreach ($names as $name) {
                    $value = $DB->get_field('assign_plugin_config', 'value', [
                        'assignment' => $assignid,
                        'subtype' => $subtype,
                        'plugin' => $plugin,
                        'name' => $name,
                    ]);
                    $config["{$subtype}_{$plugin}_{$name}"] = $value === false ? null : $value;
                }
            }
        }

        return $config;
    }
}
