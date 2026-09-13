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
 * File handling shared by every question_exporter/question_handler pair
 * (Phase 10 - quiz support) - the question-bank equivalent of sanitizer.php
 * being shared by every activity_handler.
 *
 * A question's rich-text fields (questiontext, generalfeedback, an answer's
 * own text, its feedback, combined feedback, a match subquestion, ...) can
 * each have their own embedded files, stored in the 'question' component
 * (or 'qtype_essay' for essay's graderinfo) under a filearea/itemid specific
 * to that field. Two different mechanisms are used to get exported files
 * back into the right place, because {@see \question_type::save_question()}
 * only supports one of them for questiontext/generalfeedback:
 *
 * - questiontext and generalfeedback: {@see \question_type::save_question()}
 *   itself (not the qtype-specific save_question_options() below it) moves
 *   these two fields' files, and it ONLY does that via a *draft area* itemid
 *   (`file_save_draft_area_files()`) - there is no "give me raw file bytes"
 *   fallback at this level. store_in_draft_area() below stashes the exported
 *   files in a fresh draft area owned by the current user (the teacher
 *   running "Sync now"), the same pattern h5pactivity_activity_handler uses
 *   for its package file.
 * - every other rich field (an answer's text/feedback, combined feedback,
 *   a match subquestion's text, essay's graderinfo, ...): these go through
 *   {@see \question_type::import_or_save_files()} inside each qtype's own
 *   save_question_options(), which additionally accepts a 'files' key -
 *   raw {name, encoding, content} records copied straight into their final
 *   file area, no draft area needed. This is the same mechanism Moodle's own
 *   question XML import format uses. to_import_files() below builds that
 *   shape.
 *
 * The exported wire shape is deliberately the same {filename, contentbase64}
 * pair resource_activity_exporter and h5pactivity_activity_exporter already
 * use - only the destination-side re-assembly differs per field.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_file_helper {
    /**
     * Exports every file in one (component, filearea, itemid) file area.
     *
     * @param int $contextid
     * @param string $component e.g. 'question' or 'qtype_essay'.
     * @param string $filearea e.g. 'questiontext', 'answer', 'subquestion'.
     * @param int $itemid Usually a question id or an answer/subquestion id.
     * @return array<int, array{filename: string, contentbase64: string}>
     */
    public static function export_files(int $contextid, string $component, string $filearea, int $itemid): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, $component, $filearea, $itemid, 'sortorder', false);

        $exported = [];
        foreach ($files as $file) {
            $exported[] = [
                'filename' => $file->get_filename(),
                'contentbase64' => base64_encode($file->get_content()),
            ];
        }

        return $exported;
    }

    /**
     * Stashes exported files in a fresh draft area for the current user, for
     * use as questiontext/generalfeedback's own ['itemid' => ...] - the only
     * mechanism save_question() itself understands for those two fields (see
     * class docblock). Left falsy (0) when there's nothing to store: the
     * caller only reaches for a draft area's itemid when it's non-empty (see
     * every activity_handler's own $data->files-style fields), so 0 quietly
     * results in no files being attached, matching h5pactivity_activity_handler's
     * "leave the trigger falsy to skip it" pattern.
     *
     * @param array<int, array{filename: string, contentbase64: string}> $files
     * @return int A draft itemid with the files in it, or 0 if $files is empty.
     */
    public static function store_in_draft_area(array $files): int {
        if (empty($files)) {
            return 0;
        }

        global $USER;

        $usercontext = \context_user::instance($USER->id);
        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();

        foreach ($files as $filedata) {
            $content = base64_decode($filedata['contentbase64'] ?? '', true);
            if ($content === false) {
                // Malformed payload for this one file - skip it rather than
                // fail the whole question, same as resource_activity_handler
                // does per-file.
                continue;
            }

            $fs->create_file_from_string([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => sanitizer::filename($filedata['filename'] ?? 'file'),
            ], $content);
        }

        return $draftitemid;
    }

    /**
     * Builds the 'files' list {@see \question_type::import_or_save_files()}
     * expects for every rich field EXCEPT questiontext/generalfeedback (see
     * class docblock) - each entry needs object properties (->name,
     * ->encoding, ->content), not array keys, per decode_file()'s own access.
     *
     * @param array<int, array{filename: string, contentbase64: string}> $files
     * @return array<int, \stdClass>
     */
    public static function to_import_files(array $files): array {
        $imported = [];
        foreach ($files as $filedata) {
            if (base64_decode($filedata['contentbase64'] ?? '', true) === false) {
                // Malformed payload for this one file - skip it, same as store_in_draft_area().
                continue;
            }

            $imported[] = (object) [
                'name' => sanitizer::filename($filedata['filename'] ?? 'file'),
                'encoding' => 'base64',
                'content' => $filedata['contentbase64'],
            ];
        }

        return $imported;
    }

    /**
     * Builds the ['text' => ..., 'format' => ..., 'files' => ...] shape
     * every non-questiontext/generalfeedback rich field needs on $form.
     *
     * @param mixed $text
     * @param mixed $format
     * @param array $files From export_files().
     * @return array{text: string, format: int, files: array<int, \stdClass>}
     */
    public static function richfield($text, $format, array $files): array {
        return [
            'text' => sanitizer::html($text),
            'format' => sanitizer::textformat($format),
            'files' => self::to_import_files($files),
        ];
    }
}
