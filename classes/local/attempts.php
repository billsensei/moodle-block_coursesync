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
 * What both ends of an "every attempt" pull agree on.
 *
 * By default a submission or marks pull describes one attempt per student: the
 * latest one handed in. Asking for every attempt hands out more of a student's
 * history, so it has a switch of its own on the source site, on top of the
 * switches and permissions that already guard submissions and marks.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempts {
    /**
     * What a source says in its ping when it can describe every attempt.
     *
     * The number is the shape of the answer, not a release: it changes when
     * the shape of "attempts" does, so a destination never meets a shape it
     * does not know. Reported only while the source's switch is on.
     */
    public const FEATURE = 'attempts1';

    /** @var string Reported in place of earlier marks that a marking workflow keeps back. */
    public const REASON_WORKFLOW = 'workflowhistory';

    /**
     * Has this site's administrator let other sites read every attempt?
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool) get_config('block_coursesync', 'allowattemptsexport');
    }

    /**
     * Refuse unless this site shares every attempt.
     *
     * Called by every source-side path that names an attempt, after the
     * switches and permissions of submissions or marks themselves.
     *
     * @return void
     * @throws \moodle_exception
     */
    public static function require_export(): void {
        if (!self::enabled()) {
            throw new \moodle_exception('errorattemptsexportdisabled', 'block_coursesync');
        }
    }

    /**
     * A short stand-in for a student's whole history, so a destination can tell
     * that nothing at all has changed without comparing every attempt.
     *
     * @param array[] $attempts the described attempts, each with attemptnumber and fingerprint
     * @return string SHA-1
     */
    public static function history_fingerprint(array $attempts): string {
        $parts = [];

        foreach ($attempts as $attempt) {
            $parts[] = [(int) $attempt['attemptnumber'], (string) $attempt['fingerprint']];
        }

        sort($parts);

        return sha1(json_encode($parts));
    }
}
