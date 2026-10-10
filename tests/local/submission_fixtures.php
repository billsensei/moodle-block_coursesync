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
 * Real assignment submissions for tests, made the way a student makes them.
 *
 * They go through mod_assign's own save_submission(), so the rows and files
 * in the tests are the ones a real site holds, not what a test thinks they
 * look like.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait submission_fixtures {
    /**
     * An assignment that takes both online text and files.
     *
     * @param \stdClass $course
     * @param array $record extra generator fields
     * @param array $options generator options, such as an idnumber
     * @return \stdClass the module record
     */
    protected function assignment(\stdClass $course, array $record = [], array $options = []): \stdClass {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        return $this->getDataGenerator()->create_module('assign', array_merge([
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 5,
            'assignsubmission_file_maxsizebytes' => 0,
            'grade' => 100,
            // Handed in as soon as it is saved, unless a test says otherwise.
            'submissiondrafts' => 0,
        ], $record), $options);
    }

    /**
     * The assign object for a module.
     *
     * @param \stdClass $module
     * @return \assign
     */
    protected function assign_object(\stdClass $module): \assign {
        return new \assign(\context_module::instance($module->cmid), null, null);
    }

    /**
     * A student hands work in.
     *
     * @param \stdClass $student
     * @param \stdClass $module
     * @param string|null $text online text, or null for none
     * @param string[] $files file name => content
     * @return void
     */
    protected function submit(\stdClass $student, \stdClass $module, ?string $text, array $files = []): void {
        global $USER;

        $previous = $USER;
        $this->setUser($student);

        $data = (object) ['userid' => $student->id];

        // The online text plugin expects its editor even when nothing is typed.
        $data->onlinetext_editor = [
            'itemid' => file_get_unused_draft_itemid(),
            'text' => $text ?? '',
            'format' => FORMAT_HTML,
        ];

        if ($files) {
            $draftid = file_get_unused_draft_itemid();

            foreach ($files as $name => $content) {
                get_file_storage()->create_file_from_string([
                    'contextid' => \context_user::instance($student->id)->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $draftid,
                    'filepath' => '/',
                    'filename' => $name,
                ], $content);
            }

            $data->files_filemanager = $draftid;
        }

        $notices = [];
        $this->assign_object($module)->save_submission($data, $notices);

        $this->setUser($previous);
    }

    /**
     * The stored files of a student's submission in one area, by name.
     *
     * @param \stdClass $module
     * @param int $userid
     * @param string $area
     * @return string[] file name => content
     */
    protected function submission_files(\stdClass $module, int $userid, string $area): array {
        global $DB;

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $module->id,
            'userid' => $userid,
            'latest' => 1,
        ], '*', MUST_EXIST);
        $found = [];

        foreach (
            get_file_storage()->get_area_files(
                \context_module::instance($module->cmid)->id,
                submissions::AREAS[$area],
                $area,
                $submission->id,
                'filename',
                false
            ) as $file
        ) {
            $found[$file->get_filename()] = $file->get_content();
        }

        return $found;
    }
}
