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

namespace block_coursesync\external;

use block_coursesync\local\handler\handler_registry;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\util as external_util;

/**
 * Returns everything needed to rebuild one activity on another site.
 *
 * The return structure is deliberately the same for every activity type: a
 * fixed envelope plus a bag of name/value settings. That means adding support
 * for a new activity type never changes this function - see
 * block_coursesync\local\handler\activity_handler for how a type plugs in.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_activity extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id on this site.', VALUE_REQUIRED),
        ]);
    }

    /**
     * Gather the payload for one activity.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB;

        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = $DB->get_record('course_modules', ['id' => $cmid]);

        if (!$cm || $cm->deletioninprogress) {
            throw new \moodle_exception('erroractivitynotfound', 'block_coursesync');
        }

        // Our own permission first, so a misconfigured sync account is told
        // about the sync permission rather than about enrolment.
        //
        // The context validated is the course, not the activity. Validating the
        // activity would also require the sync account to be allowed to *view*
        // that particular activity, and the capability for that differs by type:
        // a plain unenrolled account can read a page or a book but not an
        // assignment, a quiz or a wiki. That would have made which types can be
        // synced depend on a list of capabilities nobody would think to grant,
        // and would have broken again with every type added.
        //
        // What authorises this call is block/coursesync:sync on the course, held
        // by an account an administrator set up for exactly this. That is also
        // what block_coursesync_get_modified_activities has always checked, so
        // an activity that is offered by the listing can now actually be read.
        // Copying an activity that is hidden from students is deliberate: the
        // payload carries its visibility, and the copy is hidden here too.
        $context = \context_module::instance($cm->id);
        $coursecontext = \context_course::instance($cm->course);
        require_capability('block/coursesync:sync', $coursecontext);
        self::validate_context($coursecontext);

        $modinfo = get_fast_modinfo($cm->course);
        $cminfo = $modinfo->get_cm($cm->id);

        $handler = handler_registry::get($cminfo->modname);

        if ($handler === null) {
            // The source site can export more types than this destination asked
            // for, but not types nobody has written a handler for.
            throw new \moodle_exception('errorunsupportedtype', 'block_coursesync', '', $cminfo->modname);
        }

        $instance = $DB->get_record($cminfo->modname, ['id' => $cminfo->instance], '*', MUST_EXIST);

        $settings = [];

        foreach ($handler->export_settings($cminfo, $instance) as $name => $value) {
            $settings[] = ['name' => $name, 'value' => (string) $value];
        }

        $children = [];

        foreach ($handler->export_children($cminfo, $instance) as $child) {
            $fields = [];

            foreach ($child['fields'] ?? [] as $name => $value) {
                $fields[] = ['name' => (string) $name, 'value' => (string) $value];
            }

            $children[] = [
                'type' => (string) ($child['type'] ?? ''),
                'sortorder' => (int) ($child['sortorder'] ?? 0),
                'fields' => $fields,
            ];
        }

        $files = self::list_files($context, $cminfo->modname, $handler->file_areas());

        return [
            'cmid' => (int) $cminfo->id,
            'modname' => $cminfo->modname,
            'name' => external_util::format_string($cminfo->name, $coursecontext, true),
            'idnumber' => (string) $cminfo->idnumber,
            'sectionnum' => (int) $cminfo->sectionnum,
            'visible' => (bool) $cminfo->visible,
            'intro' => (string) ($instance->intro ?? ''),
            'introformat' => (int) ($instance->introformat ?? FORMAT_HTML),
            'timemodified' => (int) ($instance->timemodified ?? $cminfo->added),
            'settings' => $settings,
            'children' => $children,
            'files' => $files,
        ];
    }

    /**
     * List what is in the file areas the handler declared.
     *
     * Only metadata is returned. Content is fetched separately, a chunk at a
     * time, by block_coursesync_get_activity_file.
     *
     * @param \context_module $context the activity's context
     * @param string $modname the activity type
     * @param array[] $areas what the handler declared, each with its component
     * @return array[]
     */
    protected static function list_files(\context_module $context, string $modname, array $areas): array {
        $fs = get_file_storage();
        $files = [];

        foreach ($areas as $area) {
            $filearea = (string) ($area['filearea'] ?? '');

            if ($filearea === '') {
                continue;
            }

            // An area keyed by child records - a book's chapters - has a file
            // under each of their ids, so false asks for all of them at once.
            $itemid = !empty($area['anyitemid']) ? false : (int) ($area['itemid'] ?? 0);

            $component = (string) ($area['component'] ?? 'mod_' . $modname);
            $stored = $fs->get_area_files($context->id, $component, $filearea, $itemid, 'sortorder', false);

            foreach ($stored as $file) {
                $files[] = [
                    'component' => $component,
                    'filearea' => $filearea,
                    'itemid' => (int) $file->get_itemid(),
                    'filepath' => $file->get_filepath(),
                    'filename' => $file->get_filename(),
                    'filesize' => (int) $file->get_filesize(),
                    'mimetype' => (string) $file->get_mimetype(),
                    'sortorder' => (int) $file->get_sortorder(),
                    'timemodified' => (int) $file->get_timemodified(),
                    'contenthash' => $file->get_contenthash(),
                ];
            }
        }

        return $files;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module id on the source site.'),
            'modname' => new external_value(PARAM_PLUGIN, 'Activity type.'),
            'name' => new external_value(PARAM_TEXT, 'Activity name.'),
            'idnumber' => new external_value(PARAM_RAW, 'ID number on the source site, often empty.'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number the activity sits in.'),
            'visible' => new external_value(PARAM_BOOL, 'Whether the activity is visible on the source site.'),
            'intro' => new external_value(PARAM_RAW, 'Activity description.'),
            'introformat' => new external_value(PARAM_INT, 'Format of the description.'),
            'timemodified' => new external_value(PARAM_INT, 'When the activity was last modified.'),
            'settings' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'Setting name.'),
                    'value' => new external_value(PARAM_RAW, 'Setting value, as a string.'),
                ]),
                'Settings particular to this activity type.'
            ),
            'children' => new external_multiple_structure(
                new external_single_structure([
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'What kind of child record this is.'),
                    'sortorder' => new external_value(PARAM_INT, 'Order among children of the same kind.'),
                    'fields' => new external_multiple_structure(
                        new external_single_structure([
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'Field name.'),
                            'value' => new external_value(PARAM_RAW, 'Field value, as a string.'),
                        ]),
                        'The record\'s fields.'
                    ),
                ]),
                'Records belonging to this activity, such as a book\'s chapters.'
            ),
            'files' => new external_multiple_structure(
                new external_single_structure([
                    'component' => new external_value(
                        PARAM_COMPONENT,
                        'Component the file area belongs to: the activity, or one of its subplugins.',
                        VALUE_OPTIONAL
                    ),
                    'filearea' => new external_value(PARAM_AREA, 'File area within the activity.'),
                    'itemid' => new external_value(PARAM_INT, 'Item id within the file area.'),
                    'filepath' => new external_value(PARAM_PATH, 'Path within the file area.'),
                    'filename' => new external_value(PARAM_FILE, 'File name.'),
                    'filesize' => new external_value(PARAM_INT, 'Size in bytes.'),
                    'mimetype' => new external_value(PARAM_RAW, 'MIME type.'),
                    'sortorder' => new external_value(PARAM_INT, 'Sort order; 1 marks the main file.'),
                    'timemodified' => new external_value(PARAM_INT, 'When the file was last modified.'),
                    'contenthash' => new external_value(PARAM_ALPHANUM, 'SHA1 of the content, for checking what arrives.'),
                ]),
                'Files belonging to this activity. Content is fetched separately.'
            ),
        ]);
    }
}
