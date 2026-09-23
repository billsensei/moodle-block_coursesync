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
 * Handles mod_bigbluebuttonbn: a BigBlueButton room.
 *
 * The room is set up afresh on this site's BigBlueButton server, as a new room
 * would be: bigbluebuttonbn_add_instance() gives it its own meeting id and its
 * own moderator, viewer and guest passwords. None of the source's travel -
 * they are secrets, and two courses must never share a live room. Neither do
 * recordings, which live on the source's server against the source's meeting.
 * What travels is how the room is set up: its type, welcome message, schedule,
 * recording and lock settings, and its preloaded presentation.
 *
 * The participant list says who joins as moderator and who as viewer. Rules
 * for everyone, and for roles, come across, a role matched by its short name
 * since role ids are this site's own. Rules naming a person cannot - that
 * person is on the other site - and are left out and counted. A list left
 * empty falls back to this site's default, as it does for any room.
 *
 * A dial-in number (voice bridge) belongs to one room on one server, so the
 * copy has none, and says so if the original had one.
 *
 * BigBlueButton is switched off in a new Moodle until an administrator enables
 * it, which includes accepting its data processing terms. On a site where it
 * is off, the room is refused rather than created where it cannot run.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bigbluebuttonbn_handler extends activity_handler {
    /** @var int Participant rules left out on the way in, because they named a person or an unknown role. */
    protected int $droppedrules = 0;

    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'bigbluebuttonbn';
    }

    /**
     * The presentation preloaded into the room.
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'presentation', 'itemid' => 0],
        ];
    }

    /**
     * SOURCE SIDE. How the room is set up; never its meeting id or passwords.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the bigbluebuttonbn table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        global $DB;

        $settings = [];

        foreach (self::carried_fields() as $field => $default) {
            $settings[$field] = (string) ($instance->$field ?? $default);
        }

        $settings['welcome'] = (string) ($instance->welcome ?? '');
        $settings['scalename'] = self::scale_name((int) ($instance->grade ?? 0));
        $settings['hadvoicebridge'] = empty($instance->voicebridge) ? '0' : '1';

        // Role ids are this site's own; the short name is what can be matched.
        $rules = json_decode((string) ($instance->participants ?? ''), true);
        $roles = $DB->get_records_menu('role', null, '', 'id, shortname');
        $portable = [];

        foreach (is_array($rules) ? $rules : [] as $rule) {
            $type = (string) ($rule['selectiontype'] ?? '');

            if ($type === 'role') {
                $rule['selectionid'] = (string) ($roles[(int) ($rule['selectionid'] ?? 0)] ?? '');
            }

            $portable[] = [
                'selectiontype' => $type,
                'selectionid' => (string) ($rule['selectionid'] ?? ''),
                'role' => (string) ($rule['role'] ?? ''),
            ];
        }

        $settings['participants'] = json_encode($portable);

        return $settings;
    }

    /**
     * DESTINATION SIDE. Set the room up on this site's server.
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

        require_once($CFG->dirroot . '/mod/bigbluebuttonbn/lib.php');

        $sectionnum = $this->target_section($course, $payload);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);

        foreach (self::carried_fields() as $field => $default) {
            $data->$field = $payload->setting_int($field, $default);
        }

        $known = [
            \mod_bigbluebuttonbn\instance::TYPE_ALL,
            \mod_bigbluebuttonbn\instance::TYPE_ROOM_ONLY,
            \mod_bigbluebuttonbn\instance::TYPE_RECORDING_ONLY,
        ];
        $data->type = in_array($data->type, $known, true) ? $data->type : \mod_bigbluebuttonbn\instance::TYPE_ALL;
        $data->welcome = clean_param($payload->setting('welcome'), PARAM_TEXT);
        $data->participants = $this->local_participants($payload->setting('participants', '[]'));
        $data->grade = self::resolve_scale($payload->setting_int('grade', 0), $payload->setting('scalename'));
        $data->voicebridge = 0;
        $data->instance = 0;
        $data->coursemodule = $cmid;

        // Written afterwards, like every activity's files; see post_files().
        $data->presentation = '';

        $instanceid = \bigbluebuttonbn_add_instance($data);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * DESTINATION SIDE. Point the room at the presentation that has arrived,
     * as bigbluebuttonbn_add_instance() does for an uploaded one.
     *
     * @param \stdClass $cm the course module
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    public function post_files(\stdClass $cm, activity_payload $payload): void {
        global $DB;

        $files = get_file_storage()->get_area_files(
            \context_module::instance($cm->id)->id,
            'mod_bigbluebuttonbn',
            'presentation',
            0,
            'itemid, filepath, filename',
            false
        );

        if (count($files) === 1) {
            $DB->set_field('bigbluebuttonbn', 'presentation', '/' . reset($files)->get_filename(), ['id' => $cm->instance]);
        }
    }

    /**
     * Refuse a room on a site that has BigBlueButton switched off.
     *
     * @param activity_payload $payload what the source site sent
     * @return string|null a message key, or null if the payload is usable
     */
    public function check_payload(activity_payload $payload): ?string {
        $problem = parent::check_payload($payload);

        if ($problem !== null) {
            return $problem;
        }

        return isset(\core\plugininfo\mod::get_enabled_plugins()['bigbluebuttonbn']) ? null : 'errorbbbnotenabled';
    }

    /**
     * What a teacher should know about the copy.
     *
     * @param activity_payload $payload what the source site sent
     * @return array
     */
    public function notes(activity_payload $payload): array {
        $notes = ['syncbbbfreshroom'];

        if ($this->droppedrules > 0) {
            $notes[] = ['syncbbbparticipantsdropped', $this->droppedrules];
        }

        if ($payload->setting_int('hadvoicebridge', 0)) {
            $notes[] = 'syncbbbnovoicebridge';
        }

        return $notes;
    }

    /**
     * Was a grading scale dropped because this site does not have it?
     *
     * @param activity_payload $payload what the source site sent
     * @return bool
     */
    public function lost_scale(activity_payload $payload): bool {
        return self::scale_was_dropped($payload, [['grade', 'scalename']]);
    }

    /**
     * The source's participant rules, in this site's terms.
     *
     * "Everyone" rules stay. A role rule stays if this site has a role of that
     * short name, now pointing at it. A rule naming a person does not: that
     * person is on the other site. Nor does anything unrecognised.
     *
     * @param string $json as export_settings() sent it
     * @return string this site's participants JSON
     */
    protected function local_participants(string $json): string {
        global $DB;

        $rules = json_decode($json, true);
        $local = [];

        foreach (is_array($rules) ? $rules : [] as $rule) {
            $type = (string) ($rule['selectiontype'] ?? '');
            $role = (string) ($rule['role'] ?? '');

            if (!in_array($role, ['viewer', 'moderator'], true)) {
                $this->droppedrules++;

                continue;
            }

            if ($type === 'all') {
                $local[] = ['selectiontype' => 'all', 'selectionid' => 'all', 'role' => $role];

                continue;
            }

            $shortname = clean_param((string) ($rule['selectionid'] ?? ''), PARAM_ALPHANUMEXT);
            $roleid = $type === 'role' ? $DB->get_field('role', 'id', ['shortname' => $shortname]) : false;

            if (!$roleid) {
                $this->droppedrules++;

                continue;
            }

            $local[] = ['selectiontype' => 'role', 'selectionid' => (string) $roleid, 'role' => $role];
        }

        return json_encode($local);
    }

    /**
     * The settings that are carried, and what to assume without them.
     *
     * @return array<string, int> field name => default
     */
    protected static function carried_fields(): array {
        return [
            'type' => 0,
            'wait' => 0,
            'record' => 1,
            'recordallfromstart' => 0,
            'recordhidebutton' => 0,
            'openingtime' => 0,
            'closingtime' => 0,
            'userlimit' => 0,
            'recordings_html' => 0,
            'recordings_deleted' => 1,
            'recordings_imported' => 0,
            'recordings_preview' => 1,
            'clienttype' => 0,
            'muteonstart' => 0,
            'disablecam' => 0,
            'disablemic' => 0,
            'disableprivatechat' => 0,
            'disablepublicchat' => 0,
            'disablenote' => 0,
            'hideuserlist' => 0,
            'completionattendance' => 0,
            'completionengagementchats' => 0,
            'completionengagementtalks' => 0,
            'completionengagementraisehand' => 0,
            'completionengagementpollvotes' => 0,
            'completionengagementemojis' => 0,
            'guestallowed' => 0,
            'mustapproveuser' => 1,
            'showpresentation' => 1,
            'grade' => 0,
        ];
    }
}
