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
 * Asks the remote course what it has, and lists what a sync would bring in.
 *
 * @module     block_coursesync/checkupdates
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import {getString} from 'core/str';

const SELECTORS = {
    CHECK_BUTTON: '[data-action="check"]',
    AVAILABLE: '[data-region="available"]',
    SYNC_ALL: '[data-region="syncall"]',
    SELECT_ALL: '[data-action="select-all"]',
    SELECT_NONE: '[data-action="select-none"]',
    CHECKBOX: 'input[type="checkbox"][name="remotecmids[]"]',
};

/** @var {string} The template both this module and the block itself render the list with. */
const TEMPLATE = 'block_coursesync/available_list';

/**
 * Replaces the list with a single line of plain text.
 *
 * @param {HTMLElement} region The list container.
 * @param {string} message The message to show.
 */
const showMessage = (region, message) => {
    const paragraph = document.createElement('p');
    paragraph.className = 'text-muted small mb-2';
    paragraph.textContent = message;

    region.innerHTML = '';
    region.appendChild(paragraph);
};

/**
 * Ticks or clears every activity in the list.
 *
 * @param {HTMLElement} region The list container.
 * @param {boolean} checked Whether the activities should end up chosen.
 */
const setAll = (region, checked) => {
    region.querySelectorAll(SELECTORS.CHECKBOX).forEach((checkbox) => {
        checkbox.checked = checked;
    });
};

/**
 * Shows the whole-course Sync now only while there is no list to choose from.
 *
 * The list carries its own Sync now for the activities that are ticked, and two
 * buttons of the same name doing different things would be a trap.
 *
 * @param {HTMLElement} status The block body.
 * @param {boolean} haslist Whether a list of activities is on show.
 */
const toggleSyncAll = (status, haslist) => {
    const syncall = status.querySelector(SELECTORS.SYNC_ALL);
    if (syncall) {
        syncall.hidden = haslist;
    }
};

/**
 * Checks the remote course and redraws the list with what came back.
 *
 * @param {HTMLButtonElement} button The button that was pressed.
 * @param {HTMLElement} status The block body.
 * @param {HTMLElement} region The list container.
 * @param {number} blockid The block instance being checked.
 * @returns {Promise<void>}
 */
const check = async(button, status, region, blockid) => {
    button.disabled = true;
    showMessage(region, await getString('available:checking', 'block_coursesync'));

    try {
        const context = await Ajax.call([{methodname: 'block_coursesync_check_updates', args: {blockid}}])[0];
        const {html, js} = await Templates.renderForPromise(TEMPLATE, context);

        Templates.replaceNodeContents(region, html, js);
        toggleSyncAll(status, context.hasitems);
    } catch (error) {
        // Anything the check itself could not do comes back in the response and
        // is rendered with the list; reaching here means the request failed.
        showMessage(region, error.message || await getString('error:unexpected', 'block_coursesync'));
    } finally {
        button.disabled = false;
    }
};

/**
 * Wires up the Check now button in one block instance.
 *
 * @param {string} uniqid Identifier tying this call to the markup it belongs to.
 * @param {number} blockid The block instance being shown.
 */
export const init = (uniqid, blockid) => {
    const status = document.querySelector(`[data-region="block_coursesync/status"][data-uniqid="${uniqid}"]`);
    if (!status) {
        return;
    }

    const button = status.querySelector(SELECTORS.CHECK_BUTTON);
    const region = status.querySelector(SELECTORS.AVAILABLE);
    if (!button || !region) {
        return;
    }

    button.addEventListener('click', () => check(button, status, region, blockid));

    // Delegated, because the list inside this region is replaced wholesale
    // every time a check is made.
    region.addEventListener('click', (event) => {
        if (event.target.closest(SELECTORS.SELECT_ALL)) {
            setAll(region, true);
        } else if (event.target.closest(SELECTORS.SELECT_NONE)) {
            setAll(region, false);
        }
    });
};
