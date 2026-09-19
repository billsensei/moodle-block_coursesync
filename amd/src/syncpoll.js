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

/**
 * Watches a running sync and refreshes the page once it finishes.
 *
 * @module     block_coursesync/syncpoll
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/** @var {number} How long to wait between checks, in milliseconds. */
const INTERVAL = 5000;

/** @var {number} Give up after this many checks, so a stuck run does not poll forever. */
const MAX_CHECKS = 60;

/**
 * Starts watching a queued run.
 *
 * The block is rendered server side, so rather than rebuilding it here the page
 * is reloaded once the run is done, which shows the new counts and any conflicts.
 *
 * @param {string} uniqid Identifier tying this call to the markup it belongs to.
 * @param {number} blockid The block instance being watched.
 */
export const init = (uniqid, blockid) => {
    const region = document.querySelector(`[data-region="block_coursesync/status"][data-uniqid="${uniqid}"]`);
    if (!region) {
        return;
    }

    let checks = 0;

    const check = async() => {
        checks++;
        if (checks > MAX_CHECKS) {
            return;
        }

        try {
            const status = await Ajax.call([{methodname: 'block_coursesync_sync_status', args: {blockid}}])[0];
            if (!status.running) {
                window.location.reload();
                return;
            }
        } catch (error) {
            // The run may well have finished; stop polling and let the next page load report.
            return;
        }

        window.setTimeout(check, INTERVAL);
    };

    window.setTimeout(check, INTERVAL);
};
