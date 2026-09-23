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

use block_coursesync\activity_payload;
use block_coursesync\local\handler\activity_handler;
use block_coursesync\remote_client;
use core\http_client;

/**
 * Brings an activity's files across and stores them locally.
 *
 * Files arrive a chunk at a time from block_coursesync_get_activity_file. Each
 * one is checked against the SHA1 the source reported before it is stored, so a
 * truncated or corrupted transfer is refused rather than written.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_sync {
    /** @var int How much to ask for at a time, in bytes. */
    const CHUNK = 524288;

    /** @var int Refuse to keep reading past this many chunks for one file. */
    const MAX_CHUNKS = 2048;

    /**
     * Copy every file in a payload into a newly created activity.
     *
     * @param \stdClass $cm the course module just created on this site
     * @param activity_handler $handler the handler for this activity type
     * @param activity_payload $payload what the source site sent
     * @param string $baseurl the remote site
     * @param string $token the remote site's token
     * @param http_client|null $client injected only by tests
     * @return int how many files were stored
     * @throws \moodle_exception if any file cannot be fetched or fails its check
     */
    public static function copy_files(
        \stdClass $cm,
        activity_handler $handler,
        activity_payload $payload,
        string $baseurl,
        string $token,
        ?http_client $client = null
    ): int {
        $fs = get_file_storage();
        $context = \context_module::instance($cm->id);
        $modname = $handler::get_modname();
        $stored = 0;

        foreach ($payload->files as $file) {
            if (($file['component'] ?? '') === '') {
                $file['component'] = 'mod_' . $modname;
            }

            // The source names where a file goes, and this site decides
            // whether that is somewhere it lets files go. Only an area this
            // site's own handler declares - never whatever component and area
            // a source cares to name.
            if (!$handler->declares_file_area($file['component'], (string) $file['filearea'])) {
                continue;
            }

            // Files that hang off child records have to be pointed at the ones
            // this site created; a file with nowhere to go is left behind
            // rather than stored somewhere arbitrary.
            $itemid = $handler->local_file_itemid($payload, $file, $cm);

            if ($itemid === null) {
                continue;
            }

            // Named only when it is not the activity's own, so a source
            // running an older version - which lists only the activity's own
            // areas, and does not know the parameter - is never sent it.
            $remotecomponent = $file['component'] === 'mod_' . $modname ? '' : $file['component'];
            $content = self::fetch($baseurl, $token, $payload->cmid, $file, $client, $remotecomponent);

            $record = (object) [
                'contextid' => $context->id,
                'component' => $file['component'],
                'filearea' => (string) $file['filearea'],
                'itemid' => $itemid,
                'filepath' => (string) $file['filepath'],
                'filename' => (string) $file['filename'],
                'timecreated' => time(),
                'timemodified' => (int) ($file['timemodified'] ?? time()),
                'sortorder' => (int) ($file['sortorder'] ?? 0),
            ];

            // A repeated sync should not trip over a file that is already here.
            $existing = $fs->get_file(
                $record->contextid,
                $record->component,
                $record->filearea,
                $record->itemid,
                $record->filepath,
                $record->filename
            );

            if ($existing) {
                $existing->delete();
            }

            $fs->create_file_from_string($record, $content);
            $stored++;
        }

        return $stored;
    }

    /**
     * Read one file from the source site, a chunk at a time.
     *
     * @param string $baseurl
     * @param string $token
     * @param int $remotecmid course module id on the source site
     * @param array $file the file metadata from the payload
     * @param http_client|null $client injected only by tests
     * @param string $component the file area's component, empty for the activity's own
     * @return string the whole file
     * @throws \moodle_exception if the transfer fails or the content does not match
     */
    protected static function fetch(
        string $baseurl,
        string $token,
        int $remotecmid,
        array $file,
        ?http_client $client = null,
        string $component = ''
    ): string {
        $content = '';
        $offset = 0;
        $chunks = 0;

        do {
            if ($chunks++ >= self::MAX_CHUNKS) {
                throw new \moodle_exception('errorfiletoobig', 'block_coursesync');
            }

            $chunk = remote_client::get_activity_file(
                $baseurl,
                $token,
                $remotecmid,
                (string) $file['filearea'],
                (int) $file['itemid'],
                (string) $file['filepath'],
                (string) $file['filename'],
                $offset,
                self::CHUNK,
                $client,
                $component
            );

            if (!$chunk->success) {
                throw new \moodle_exception($chunk->errorkey, 'block_coursesync');
            }

            $content .= $chunk->content;
            $offset += $chunk->returned;

            // A chunk that returns nothing without reaching the end would spin
            // forever, so treat it as a failed transfer.
            if ($chunk->returned === 0 && !$chunk->eof) {
                throw new \moodle_exception('errorfiletransfer', 'block_coursesync');
            }
        } while (!$chunk->eof);

        $expected = (string) ($file['contenthash'] ?? '');

        if ($expected !== '' && sha1($content) !== $expected) {
            // Moodle stores a file's SHA1 as its content hash, so this compares
            // what arrived against what the source site actually holds.
            throw new \moodle_exception('errorfilecorrupt', 'block_coursesync');
        }

        return $content;
    }
}
