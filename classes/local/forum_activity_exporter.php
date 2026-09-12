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
 * Exports a mod_forum instance's settings for the source side.
 *
 * Activity-level settings only, per Phase 5's scope - no discussions or
 * posts. type is still exported as-is (forum_activity_handler is what
 * refuses to act on a "news" one - see that class).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_activity_exporter implements activity_exporter {
    /**
     * Builds the payload forum_activity_handler::create_from_remote_data() expects.
     *
     * @param \cm_info $cm
     * @return array
     */
    public function export(\cm_info $cm): array {
        global $DB;

        $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);

        return [
            'type' => $forum->type,
            'name' => $forum->name,
            'intro' => (string) $forum->intro,
            'introformat' => (int) $forum->introformat,
            'duedate' => (int) $forum->duedate,
            'cutoffdate' => (int) $forum->cutoffdate,
            'assessed' => (int) $forum->assessed,
            'assesstimestart' => (int) $forum->assesstimestart,
            'assesstimefinish' => (int) $forum->assesstimefinish,
            'scale' => (int) $forum->scale,
            'grade_forum' => (int) $forum->grade_forum,
            'grade_forum_notify' => (int) $forum->grade_forum_notify,
            'maxbytes' => (int) $forum->maxbytes,
            'maxattachments' => (int) $forum->maxattachments,
            'forcesubscribe' => (int) $forum->forcesubscribe,
            'trackingtype' => (int) $forum->trackingtype,
            'rsstype' => (int) $forum->rsstype,
            'rssarticles' => (int) $forum->rssarticles,
            'warnafter' => (int) $forum->warnafter,
            'blockafter' => (int) $forum->blockafter,
            'blockperiod' => (int) $forum->blockperiod,
            'completiondiscussions' => (int) $forum->completiondiscussions,
            'completionreplies' => (int) $forum->completionreplies,
            'completionposts' => (int) $forum->completionposts,
            'displaywordcount' => (int) $forum->displaywordcount,
            'lockdiscussionafter' => (int) $forum->lockdiscussionafter,
            'showimmediately' => (int) ($forum->showimmediately ?? 0),
        ];
    }
}
