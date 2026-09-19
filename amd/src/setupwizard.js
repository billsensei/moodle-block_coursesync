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
 * Checks the remote site and course from the block configuration form.
 *
 * @module     block_coursesync/setupwizard
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getString} from 'core/str';

const SELECTORS = {
    TEST_BUTTON: '[data-action="test-connection"]',
    VALIDATE_BUTTON: '[data-action="validate-course"]',
    RESULT: '[data-region="result"]',
};

/**
 * Reads the trimmed value of a named field in the configuration form.
 *
 * @param {HTMLFormElement} form The form being edited.
 * @param {string} name The field name.
 * @returns {string} The value, or an empty string if the field is absent.
 */
const fieldValue = (form, name) => {
    const field = form.querySelector(`[name="${name}"]`);

    return field ? field.value.trim() : '';
};

/**
 * Replaces the result area with a single message.
 *
 * @param {HTMLElement} result The result container.
 * @param {string} message The message to show.
 * @param {string} type A bootstrap alert variant, such as success or danger.
 */
const showMessage = (result, message, type) => {
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} mb-0`;
    alert.setAttribute('role', 'alert');
    alert.textContent = message;

    result.innerHTML = '';
    result.appendChild(alert);
};

/**
 * Picks the alert variant that matches a response.
 *
 * @param {object} response The web service response.
 * @returns {string} A bootstrap alert variant.
 */
const variantFor = (response) => {
    if (!response.status) {
        return 'danger';
    }

    return response.complete === false ? 'warning' : 'success';
};

/**
 * Calls one of the plugin's AJAX checks and reports the outcome.
 *
 * @param {HTMLButtonElement} button The button that was pressed.
 * @param {HTMLElement} result The result container.
 * @param {string} methodname The web service function to call.
 * @param {object} args Arguments for the web service function.
 * @param {string} pendingkey Language string shown while the call is in flight.
 * @returns {Promise<void>}
 */
const runCheck = async(button, result, methodname, args, pendingkey) => {
    button.disabled = true;
    showMessage(result, await getString(pendingkey, 'block_coursesync'), 'secondary');

    try {
        const response = await Ajax.call([{methodname, args}])[0];
        showMessage(result, response.message, variantFor(response));
    } catch (error) {
        showMessage(result, error.message || await getString('error:unexpected', 'block_coursesync'), 'danger');
    } finally {
        button.disabled = false;
    }
};

/**
 * Wires up the check buttons in a block configuration form.
 *
 * @param {string} uniqid Identifier tying the markup to this call.
 * @param {number} blockid The block instance being configured.
 */
export const init = (uniqid, blockid) => {
    const region = document.querySelector(`[data-region="block_coursesync/connection-actions"][data-uniqid="${uniqid}"]`);
    if (!region) {
        return;
    }

    const form = region.closest('form');
    const result = region.querySelector(SELECTORS.RESULT);
    if (!form || !result) {
        return;
    }

    region.querySelector(SELECTORS.TEST_BUTTON).addEventListener('click', (event) => {
        runCheck(event.target, result, 'block_coursesync_test_connection', {
            blockid,
            remoteurl: fieldValue(form, 'config_remoteurl'),
            token: fieldValue(form, 'config_token'),
        }, 'testingconnection');
    });

    region.querySelector(SELECTORS.VALIDATE_BUTTON).addEventListener('click', (event) => {
        runCheck(event.target, result, 'block_coursesync_validate_course', {
            blockid,
            remoteurl: fieldValue(form, 'config_remoteurl'),
            remotecourse: fieldValue(form, 'config_remotecourse'),
            token: fieldValue(form, 'config_token'),
        }, 'validatingcourse');
    });
};
