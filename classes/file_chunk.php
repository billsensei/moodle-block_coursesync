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

namespace block_coursesync;

/**
 * One piece of a file fetched from the remote site.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_chunk {
    /** @var bool Whether the chunk arrived. */
    public readonly bool $success;

    /** @var string|null Language string identifier describing the failure. */
    public readonly ?string $errorkey;

    /** @var string The decoded bytes. */
    public readonly string $content;

    /** @var int How many bytes this chunk holds. */
    public readonly int $returned;

    /** @var bool Whether this chunk reaches the end of the file. */
    public readonly bool $eof;

    /** @var int Total size of the file on the source site. */
    public readonly int $filesize;

    /** @var string SHA1 of the whole file, as the source site holds it. */
    public readonly string $contenthash;

    /**
     * Use the success() and failure() factories instead.
     *
     * @param bool $success
     * @param string|null $errorkey
     * @param string $content
     * @param int $returned
     * @param bool $eof
     * @param int $filesize
     * @param string $contenthash
     */
    protected function __construct(
        bool $success,
        ?string $errorkey,
        string $content,
        int $returned,
        bool $eof,
        int $filesize,
        string $contenthash
    ) {
        $this->success = $success;
        $this->errorkey = $errorkey;
        $this->content = $content;
        $this->returned = $returned;
        $this->eof = $eof;
        $this->filesize = $filesize;
        $this->contenthash = $contenthash;
    }

    /**
     * The chunk arrived.
     *
     * @param string $content the decoded bytes
     * @param int $returned
     * @param bool $eof
     * @param int $filesize
     * @param string $contenthash
     * @return self
     */
    public static function success(
        string $content,
        int $returned,
        bool $eof,
        int $filesize,
        string $contenthash
    ): self {
        return new self(true, null, $content, $returned, $eof, $filesize, $contenthash);
    }

    /**
     * The chunk could not be fetched.
     *
     * @param string $errorkey a language string identifier in block_coursesync
     * @return self
     */
    public static function failure(string $errorkey): self {
        return new self(false, $errorkey, '', 0, true, 0, '');
    }
}
