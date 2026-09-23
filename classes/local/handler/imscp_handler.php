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
 * Handles mod_imscp: an IMS content package.
 *
 * Like a SCORM, only the package travels. mod_imscp keeps each uploaded
 * package in its 'backup' area under that upload's revision number, unpacks
 * it into 'content' under the same number, and stores the table of contents
 * it parsed from the manifest. A source that kept old revisions ("keep old
 * packages") has several; only the current one is sent on, and it becomes
 * revision 1 here, as a first upload would. post_files() then unpacks and
 * parses it exactly as imscp_add_instance() does for an upload, and refuses a
 * copy whose manifest gave no table of contents to open.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imscp_handler extends activity_handler {
    /** @var int The revision a copied package becomes here: a first upload's. */
    protected const LOCAL_REVISION = 1;

    /**
     * The activity type this handler is responsible for.
     *
     * @return string
     */
    public static function get_modname(): string {
        return 'imscp';
    }

    /**
     * The uploaded packages, one per revision. Only the current revision's
     * is kept on arrival; see map_file_itemid().
     *
     * @return array[]
     */
    public function get_file_areas(): array {
        return [
            ['filearea' => 'backup', 'anyitemid' => true],
        ];
    }

    /**
     * SOURCE SIDE. Which revision is current, so the right package is kept.
     *
     * @param \cm_info $cm the course module on the source site
     * @param \stdClass $instance the row from the imscp table
     * @return array
     */
    public function export_settings(\cm_info $cm, \stdClass $instance): array {
        return [
            'revision' => (string) (int) ($instance->revision ?? 1),
            'keepold' => (string) (int) ($instance->keepold ?? -1),
        ];
    }

    /**
     * DESTINATION SIDE. Build the activity; post_files() unpacks the package.
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

        require_once($CFG->dirroot . '/mod/imscp/lib.php');

        $sectionnum = $this->resolve_section($course, $payload->sectionnum);
        $cmid = $this->create_course_module($course, $payload, $idnumber);

        $data = $this->make_instance_data($course, $cmid, $payload, $idnumber);
        $data->keepold = $payload->setting_int('keepold', -1);

        // No draft area to move a package from: it arrives afterwards.
        $data->package = 0;

        $instanceid = \imscp_add_instance($data, null);

        if (!$instanceid) {
            $DB->delete_records('course_modules', ['id' => $cmid]);

            throw new \moodle_exception('errorcreatefailed', 'block_coursesync');
        }

        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);

        return $this->finish_creation($course, $cmid, $sectionnum);
    }

    /**
     * DESTINATION SIDE. Keep only the current revision's package, as this
     * site's first revision; leave any older ones behind.
     *
     * @param activity_payload $payload
     * @param array $file
     * @param \stdClass $cm
     * @return int|null
     */
    public function map_file_itemid(activity_payload $payload, array $file, \stdClass $cm): ?int {
        if (($file['filearea'] ?? '') !== 'backup') {
            return 0;
        }

        return (int) ($file['itemid'] ?? 0) === $payload->setting_int('revision', 1) ? self::LOCAL_REVISION : null;
    }

    /**
     * DESTINATION SIDE. Unpack the package and read its table of contents,
     * as imscp_add_instance() does for an upload, and refuse a copy with
     * nothing to open.
     *
     * An exception here is caught by the syncer, which takes the half-made
     * activity back out and reports the failure.
     *
     * @param \stdClass $cm the course module
     * @param activity_payload $payload what the source site sent
     * @return void
     */
    public function post_files(\stdClass $cm, activity_payload $payload): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/imscp/locallib.php');

        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_imscp', 'backup', self::LOCAL_REVISION, 'id', false);
        $package = reset($files);

        if (!$package) {
            throw new \moodle_exception('errornopackage', 'block_coursesync');
        }

        $imscp = $DB->get_record('imscp', ['id' => $cm->instance], '*', MUST_EXIST);

        $fs->delete_area_files($context->id, 'mod_imscp', 'content', self::LOCAL_REVISION);
        $package->extract_to_storage(
            get_file_packer('application/zip'),
            $context->id,
            'mod_imscp',
            'content',
            self::LOCAL_REVISION,
            '/'
        );

        $structure = \imscp_parse_structure($imscp, $context);

        // What opening it needs: a table of contents (view.php refuses to
        // show a package without one), whose first page is really here.
        if (!is_array($structure) || !self::first_page_is_here($structure, $context)) {
            throw new \moodle_exception('errorpackagenotdeployed', 'block_coursesync');
        }

        $DB->set_field('imscp', 'structure', serialize($structure), ['id' => $imscp->id]);
    }

    /**
     * Does the first page the table of contents links to exist?
     *
     * Depth first, because an entry can be a heading with no page of its own.
     * A page on the web is taken as it is; one in the package has to have
     * been unpacked here.
     *
     * @param array $items as imscp_parse_structure() returns them
     * @param \context $context the new activity's context
     * @return bool
     */
    protected static function first_page_is_here(array $items, \context $context): bool {
        foreach ($items as $item) {
            $href = (string) ($item['href'] ?? '');

            if ($href === '') {
                if (self::first_page_is_here($item['subitems'] ?? [], $context)) {
                    return true;
                }

                continue;
            }

            if (preg_match('|^https?://|', $href)) {
                return true;
            }

            $path = '/' . ltrim(rawurldecode(strtok($href, '#?')), '/');
            $dir = dirname($path) === '/' ? '/' : dirname($path) . '/';

            return (bool) get_file_storage()->get_file(
                $context->id,
                'mod_imscp',
                'content',
                self::LOCAL_REVISION,
                $dir,
                basename($path)
            );
        }

        return false;
    }

    /**
     * Refuse an activity whose current package did not come with it.
     *
     * @param activity_payload $payload what the source site sent
     * @return string|null a message key, or null if the payload is usable
     */
    public function check_payload(activity_payload $payload): ?string {
        $problem = parent::check_payload($payload);

        if ($problem !== null) {
            return $problem;
        }

        return $payload->has_file_in('backup', $payload->setting_int('revision', 1)) ? null : 'errornopackage';
    }
}
