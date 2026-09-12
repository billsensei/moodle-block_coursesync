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
 * Turns this block's cached state (connection status, course mapping, sync
 * results) into the plain-language lines get_content() and sync.php show
 * the user. Pulled out of block_coursesync itself purely to keep that class
 * focused on the block lifecycle (config save, sync orchestration); this
 * class holds no state of its own beyond what's passed to each method, and
 * also centralises the errorcode -> message mapping that connection status,
 * course mapping status, and sync results all shared (they can all fail for
 * the same reasons: bad token, wrong URL, unreachable, ...).
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_renderer {
    /**
     * The cached connection status line (see block_coursesync::instance_config_save()).
     *
     * laststatussitename is the remote site's own fullname, from its ping
     * response - untrusted, same as $technical below, and escaped the same way.
     *
     * @param \stdClass $config
     * @return string
     */
    public static function status_text(\stdClass $config): string {
        if (empty($config->laststatustime)) {
            return get_string('neverchecked', 'block_coursesync');
        }

        $time = userdate($config->laststatustime);

        if (!empty($config->laststatus)) {
            $a = (object) ['sitename' => s($config->laststatussitename ?? ''), 'time' => $time];
            return get_string('statusok', 'block_coursesync', $a);
        }

        $a = (object) [
            'message' => self::error_message($config->laststatuserrorcode ?? '', $config->laststatustechnical ?? ''),
            'time' => $time,
        ];
        return get_string('statusfail', 'block_coursesync', $a);
    }

    /**
     * The cached course mapping status line (see block_coursesync::resolve_course_mapping()).
     *
     * fullname/shortname are the remote course's own, from check_course's
     * response - untrusted, same as $technical elsewhere in this class.
     *
     * @param \stdClass $config
     * @return string
     */
    public static function coursemapping_text(\stdClass $config): string {
        if (empty($config->remotecourse)) {
            return get_string('coursemappingnone', 'block_coursesync');
        }

        if (!empty($config->coursemappingcourseid)) {
            $a = (object) [
                'fullname' => s($config->coursemappingfullname ?? ''),
                'courseid' => $config->coursemappingcourseid,
                'shortname' => s($config->coursemappingshortname ?? ''),
            ];
            return get_string('coursemappingok', 'block_coursesync', $a);
        }

        $errorcode = $config->coursemappingerrorcode ?? '';
        if ($errorcode === 'unverified') {
            return get_string('coursemappingunverified', 'block_coursesync');
        }
        if (in_array($errorcode, ['coursenotfound', 'coursenotaccessible', 'emptycourseidentifier'], true)) {
            return get_string($errorcode, 'block_coursesync');
        }

        return self::error_message($errorcode, $config->coursemappingtechnical ?? '');
    }

    /**
     * Renders a list of activities (from block_coursesync_get_modified_activities)
     * as a heading + bullet list, or a "nothing found" line if empty.
     *
     * @param array $activities
     * @return string
     */
    public static function activities_list_html(array $activities): string {
        if (empty($activities)) {
            return \html_writer::tag('p', get_string('previewnone', 'block_coursesync'));
        }

        $items = '';
        foreach ($activities as $activity) {
            $a = (object) [
                'name' => s($activity['name'] ?? ''),
                'modname' => s($activity['modname'] ?? ''),
                'time' => userdate((int) ($activity['timemodified'] ?? 0)),
            ];
            $items .= \html_writer::tag('li', get_string('previewitem', 'block_coursesync', $a));
        }

        $heading = \html_writer::tag('p', get_string('previewheading', 'block_coursesync', count($activities)));

        return $heading . \html_writer::tag('ul', $items);
    }

    /**
     * One plain-language line summarising a sync_runner::run() result.
     *
     * @param array $result
     * @return string
     */
    public static function sync_result_text(array $result): string {
        if (!$result['success']) {
            return self::sync_failure_text($result);
        }

        $a = (object) [
            'created' => count($result['created']),
            'conflicts' => count($result['conflicts']),
            'unsupported' => count($result['unsupported']),
            'failed' => count($result['failed']),
        ];

        if (!$a->created && !$a->conflicts && !$a->unsupported && !$a->failed) {
            return get_string('syncnothingtodo', 'block_coursesync');
        }

        return get_string('syncsummary', 'block_coursesync', $a);
    }

    /**
     * The failure-message half of sync_result_text().
     *
     * @param array $result
     * @return string
     */
    protected static function sync_failure_text(array $result): string {
        $errorcode = $result['errorcode'];
        if (in_array($errorcode, ['syncnotconfigured', 'syncnocoursemapping', 'syncnocourse'], true)) {
            return get_string($errorcode, 'block_coursesync');
        }

        return self::error_message($errorcode, $result['technical'] ?? '');
    }

    /**
     * Maps a remote_client errorcode to a plain-language message.
     *
     * The default case's $technical is untrusted: it's the remote site's own
     * exception message, so a malicious or compromised remote could put
     * anything in it - HTML included. It's escaped with s() here, once, at
     * the point it enters a string that later gets written straight into
     * HTML (get_content(), history_renderer, and sync.php's redirect()
     * notification all do this without escaping again - notification
     * messages in particular are rendered as-is, unescaped, by core).
     *
     * @param string $errorcode
     * @param string $technical Untrusted - the remote site's own message text.
     * @return string
     */
    protected static function error_message(string $errorcode, string $technical): string {
        switch ($errorcode) {
            case 'badtoken':
                return get_string('error_badtoken', 'block_coursesync');
            case 'wrongurl':
                return get_string('error_wrongurl', 'block_coursesync');
            case 'unreachable':
                return get_string('error_unreachable', 'block_coursesync');
            case 'privaterange':
                return get_string('err_remoteurl_privaterange', 'block_coursesync');
            default:
                return get_string('error_remote', 'block_coursesync', s($technical));
        }
    }
}
