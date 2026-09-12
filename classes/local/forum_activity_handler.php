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
 * Recreates a mod_forum instance - activity-level settings only, no
 * discussions/posts - from forum_activity_exporter's payload.
 *
 * ONE DELIBERATE CARVE-OUT: a "news" (Announcements) forum is refused, not
 * created. Every course already gets its own on creation, forum_add_instance()
 * has no "only one news forum per course" guard of its own (that rule
 * actually lives in mod_form.php validation, which this bypasses like
 * every other handler bypasses its module's form) - so pulling a remote
 * "news" forum would silently leave the destination course with two
 * Announcements forums, which is a real, structurally-confusing state, not
 * a cosmetic one. Rather than work around that quietly (e.g. downgrading
 * its type to "general"), it's surfaced as a clear failure instead - see
 * REMOTE_SETUP.md for the full note.
 *
 * Same instance-setting quirk as label: forum_add_instance() does NOT set
 * course_modules.instance itself - that's done explicitly below.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_activity_handler implements activity_handler {
    /**
     * Creates the forum course module and instance.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param array $data Payload from forum_activity_exporter::export().
     * @param string $idnumber
     * @return int The new course module id.
     * @throws \moodle_exception If $data['type'] is 'news'.
     */
    public function create_from_remote_data(int $courseid, int $sectionnum, array $data, string $idnumber): int {
        $type = $data['type'] ?? 'general';
        if ($type === 'news') {
            throw new \moodle_exception('forumnewsnotsynced', 'block_coursesync');
        }
        if (!in_array($type, ['general', 'eachuser', 'single', 'qanda', 'blog'], true)) {
            // Not a type mod_forum itself recognises - store the safe default
            // rather than an arbitrary remote-supplied string in a column
            // forum's own code branches on (see forum_add_instance()'s
            // handling of type == 'single', for one).
            $type = 'general';
        }

        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/forum/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'forum'], MUST_EXIST);

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

        $forumdata = new \stdClass();
        $forumdata->course = $courseid;
        $forumdata->coursemodule = $cmid;
        // Core's forum_grade_item_update() (called by forum_add_instance())
        // reads ->cmidnumber directly with no isset() guard - always present
        // on a real form submission. Mirrors the course_modules idnumber,
        // same as every other mod_form's "custom idnumber" field would.
        $forumdata->cmidnumber = $idnumber;
        $forumdata->type = $type;
        $forumdata->name = sanitizer::text($data['name'] ?? '');
        $forumdata->intro = sanitizer::html($data['intro'] ?? '');
        $forumdata->introformat = sanitizer::textformat($data['introformat'] ?? FORMAT_HTML);
        $forumdata->duedate = sanitizer::integer($data['duedate'] ?? 0);
        $forumdata->cutoffdate = sanitizer::integer($data['cutoffdate'] ?? 0);
        $forumdata->assessed = sanitizer::integer($data['assessed'] ?? 0);
        $forumdata->assesstimestart = sanitizer::integer($data['assesstimestart'] ?? 0);
        $forumdata->assesstimefinish = sanitizer::integer($data['assesstimefinish'] ?? 0);
        $forumdata->scale = sanitizer::integer($data['scale'] ?? 0);
        $forumdata->grade_forum = sanitizer::integer($data['grade_forum'] ?? 0);
        $forumdata->grade_forum_notify = sanitizer::integer($data['grade_forum_notify'] ?? 0);
        $forumdata->maxbytes = sanitizer::integer($data['maxbytes'] ?? 0);
        $forumdata->maxattachments = sanitizer::integer($data['maxattachments'] ?? 1, 1);
        $forumdata->forcesubscribe = sanitizer::integer($data['forcesubscribe'] ?? 0);
        $forumdata->trackingtype = sanitizer::integer($data['trackingtype'] ?? 1, 1);
        $forumdata->rsstype = sanitizer::integer($data['rsstype'] ?? 0);
        $forumdata->rssarticles = sanitizer::integer($data['rssarticles'] ?? 0);
        $forumdata->warnafter = sanitizer::integer($data['warnafter'] ?? 0);
        $forumdata->blockafter = sanitizer::integer($data['blockafter'] ?? 0);
        $forumdata->blockperiod = sanitizer::integer($data['blockperiod'] ?? 0);
        $forumdata->completiondiscussions = sanitizer::integer($data['completiondiscussions'] ?? 0);
        $forumdata->completionreplies = sanitizer::integer($data['completionreplies'] ?? 0);
        $forumdata->completionposts = sanitizer::integer($data['completionposts'] ?? 0);
        $forumdata->displaywordcount = sanitizer::integer($data['displaywordcount'] ?? 0);
        $forumdata->lockdiscussionafter = sanitizer::integer($data['lockdiscussionafter'] ?? 0);
        $forumdata->showimmediately = sanitizer::integer($data['showimmediately'] ?? 0);

        $instanceid = forum_add_instance($forumdata, null);
        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        course_add_cm_to_section($courseid, $cmid, $sectionnum, null, 'forum');
        rebuild_course_cache($courseid, true);

        return $cmid;
    }
}
