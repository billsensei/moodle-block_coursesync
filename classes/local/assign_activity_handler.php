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
 * Recreates a mod_assign instance from assign_activity_exporter's payload.
 *
 * assign_add_instance() (mod/assign/lib.php) delegates to the `assign` class,
 * whose add_instance() loops over EVERY installed submission/feedback
 * sub-plugin and explicitly calls $plugin->disable() for any of them whose
 * "{subtype}_{plugin}_enabled" field isn't set (and truthy) on the data
 * object passed in - see update_plugin_instance() in mod/assign/locallib.php.
 * That means silently omitting those fields doesn't leave sub-plugins at
 * whatever the site's own defaults are, it force-disables all of them,
 * which would create an assignment nobody can submit anything to. This
 * handler avoids that by explicitly setting the enabled flag (0 or 1) for
 * every sub-plugin it knows about (see apply_plugin_config(), matching
 * assign_activity_exporter::PLUGIN_CONFIG_FIELDS's list) - true/false based
 * on what the remote payload says, never left unset.
 *
 * Same instance-setting quirk as url/label/forum: assign::add_instance()
 * does NOT set course_modules.instance itself - that's done explicitly
 * below.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_activity_handler implements activity_handler {
    /** Attempt reopen methods mod_assign itself recognises (see mod/assign/locallib.php). */
    protected const ATTEMPT_REOPEN_METHODS = ['none', 'manual', 'untilpass'];

    /**
     * Creates the assign course module and instance.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from assign_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'assign'], MUST_EXIST);

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

        $assigndata = new \stdClass();
        $assigndata->course = $courseid;
        $assigndata->coursemodule = $cmid;
        $assigndata->name = sanitizer::text($data['name'] ?? '');
        $assigndata->intro = sanitizer::html($data['intro'] ?? '');
        $assigndata->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $assigndata->alwaysshowdescription = sanitizer::integer($data['alwaysshowdescription'] ?? 1, 1);
        $assigndata->duedate = sanitizer::integer($data['duedate'] ?? 0);
        $assigndata->allowsubmissionsfromdate = sanitizer::integer($data['allowsubmissionsfromdate'] ?? 0);
        $assigndata->cutoffdate = sanitizer::integer($data['cutoffdate'] ?? 0);
        $assigndata->gradingduedate = sanitizer::integer($data['gradingduedate'] ?? 0);
        $assigndata->grade = sanitizer::integer($data['grade'] ?? 100, 100);
        $assigndata->timelimit = sanitizer::integer($data['timelimit'] ?? 0);
        $assigndata->submissiondrafts = sanitizer::integer($data['submissiondrafts'] ?? 0);
        $assigndata->requiresubmissionstatement = sanitizer::integer($data['requiresubmissionstatement'] ?? 0);
        $assigndata->sendnotifications = sanitizer::integer($data['sendnotifications'] ?? 0);
        $assigndata->sendlatenotifications = sanitizer::integer($data['sendlatenotifications'] ?? 0);
        $assigndata->sendstudentnotifications = sanitizer::integer($data['sendstudentnotifications'] ?? 1, 1);
        $assigndata->completionsubmit = sanitizer::integer($data['completionsubmit'] ?? 0);
        $assigndata->teamsubmission = sanitizer::integer($data['teamsubmission'] ?? 0);
        $assigndata->requireallteammemberssubmit = sanitizer::integer($data['requireallteammemberssubmit'] ?? 0);
        // Never carried over from the remote site: a groupingid there has no
        // guaranteed match (or even meaning) on the destination course.
        $assigndata->teamsubmissiongroupingid = 0;
        $assigndata->blindmarking = sanitizer::integer($data['blindmarking'] ?? 0);
        $assigndata->hidegrader = sanitizer::integer($data['hidegrader'] ?? 0);
        $assigndata->attemptreopenmethod = $this->sanitize_attemptreopenmethod($data['attemptreopenmethod'] ?? 'untilpass');
        $assigndata->maxattempts = sanitizer::integer($data['maxattempts'] ?? 1, 1);
        $assigndata->markingworkflow = sanitizer::integer($data['markingworkflow'] ?? 0);
        $assigndata->markingallocation = sanitizer::integer($data['markingallocation'] ?? 0);
        $assigndata->markinganonymous = sanitizer::integer($data['markinganonymous'] ?? 0);
        $assigndata->preventsubmissionnotingroup = sanitizer::integer($data['preventsubmissionnotingroup'] ?? 0);
        $assigndata->submissionattachments = sanitizer::integer($data['submissionattachments'] ?? 0);
        $assigndata->gradepenalty = sanitizer::integer($data['gradepenalty'] ?? 0);

        $this->apply_plugin_config($assigndata, $data);

        // This call does NOT set course_modules.instance for $cmid - done explicitly below.
        $instanceid = assign_add_instance($assigndata, null);
        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'assign');
        rebuild_course_cache($courseid, true);

        return $cmid;
    }

    /**
     * Sets every known submission/feedback sub-plugin's "*_enabled" field
     * (always, even when disabled - see class docblock) plus its own
     * settings, straight from the remote payload's flat
     * "{subtype}_{plugin}_{name}" keys (see
     * assign_activity_exporter::PLUGIN_CONFIG_FIELDS).
     *
     * Deliberately NOT a generic loop keyed off that same field-name list:
     * assign_plugin_config's own config "name" (what save_settings() calls
     * it internally, e.g. 'maxfilesubmissions') and the $formdata property
     * assign_add_instance() actually reads (e.g.
     * assignsubmission_file_maxfiles - see
     * mod/assign/submission/file/locallib.php's save_settings()) are two
     * different strings for most of these fields, not the same one under a
     * shared naming convention. Each line below is an explicit, individually
     * verified mapping between the two for exactly that reason.
     *
     * @param \stdClass $assigndata Modified in place.
     * @param array $data Payload from assign_activity_exporter::export().
     */
    protected function apply_plugin_config(\stdClass $assigndata, array $data): void {
        $assigndata->assignsubmission_onlinetext_enabled =
            sanitizer::integer($data['assignsubmission_onlinetext_enabled'] ?? 0);
        $assigndata->assignsubmission_onlinetext_wordlimit =
            sanitizer::integer($data['assignsubmission_onlinetext_wordlimit'] ?? 0);
        $assigndata->assignsubmission_onlinetext_wordlimit_enabled =
            sanitizer::integer($data['assignsubmission_onlinetext_wordlimitenabled'] ?? 0);

        $assigndata->assignsubmission_file_enabled =
            sanitizer::integer($data['assignsubmission_file_enabled'] ?? 0);
        $assigndata->assignsubmission_file_maxfiles =
            sanitizer::integer($data['assignsubmission_file_maxfilesubmissions'] ?? 20, 20);
        $assigndata->assignsubmission_file_maxsizebytes =
            sanitizer::integer($data['assignsubmission_file_maxsubmissionsizebytes'] ?? 0);
        $assigndata->assignsubmission_file_filetypes =
            sanitizer::text($data['assignsubmission_file_filetypeslist'] ?? '');

        $assigndata->assignfeedback_comments_enabled =
            sanitizer::integer($data['assignfeedback_comments_enabled'] ?? 1, 1);
        $assigndata->assignfeedback_comments_commentinline =
            sanitizer::integer($data['assignfeedback_comments_commentinline'] ?? 0);
    }

    /**
     * Validates a remote-supplied attemptreopenmethod against the fixed set
     * mod_assign itself recognises, same approach as forum_activity_handler's
     * type check - falls back to the safe default rather than storing an
     * arbitrary string a later assign page might branch on.
     *
     * @param mixed $value
     * @return string
     */
    protected function sanitize_attemptreopenmethod($value): string {
        $value = (string) $value;

        return in_array($value, self::ATTEMPT_REOPEN_METHODS, true) ? $value : 'untilpass';
    }
}
