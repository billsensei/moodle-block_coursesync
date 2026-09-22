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
 * Select all / select none for the "Choose what to copy" checkbox picker.
 *
 * Only the enabled checkboxes respond - the ones shown for reference
 * (already synced, needs review, not supported) stay disabled and unticked
 * however these buttons are used.
 *
 * @module     block_coursesync/choose
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    FORM: '#coursesync-choose',
    SELECTALL: '[data-action="coursesync-select-all"]',
    SELECTNONE: '[data-action="coursesync-select-none"]',
    ENABLED_CHECKBOX: 'input[type="checkbox"]:not(:disabled)',
};

/**
 * Tick or untick every enabled checkbox in the form.
 *
 * @param {HTMLFormElement} form
 * @param {Boolean} checked
 */
const setAll = (form, checked) => {
    form.querySelectorAll(SELECTORS.ENABLED_CHECKBOX).forEach(checkbox => {
        checkbox.checked = checked;
    });
};

/**
 * Initialise the select all / select none buttons.
 */
export const init = () => {
    const form = document.querySelector(SELECTORS.FORM);

    if (!form) {
        return;
    }

    form.addEventListener('click', e => {
        if (e.target.closest(SELECTORS.SELECTALL)) {
            e.preventDefault();
            setAll(form, true);
        } else if (e.target.closest(SELECTORS.SELECTNONE)) {
            e.preventDefault();
            setAll(form, false);
        }
    });
};
