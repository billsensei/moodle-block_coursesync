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
 * "Select all" ticks only what is new or changed (data-coursesync-bulk).
 * Ticking something already in the course copies it again, so that is only
 * ever done one row at a time. "Select none" clears everything.
 *
 * Each section heading in the lists is a button that opens and closes that section's rows
 * (they are hidden, not removed, so the form still sends what is ticked), and has a button
 * that ticks the new and changed rows in just that section.
 *
 * Also stops the form being sent twice: a second click on a slow sync would
 * otherwise start a second run, which the server refuses (syncer::run()'s
 * lock) - and it is that refusal the browser would then show.
 *
 * @module     block_coursesync/choose
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    FORM: '#coursesync-choose',
    SELECTALL: '[data-action="coursesync-select-all"]',
    SELECTNONE: '[data-action="coursesync-select-none"]',
    SUBMIT: 'input[type="submit"], button[type="submit"]',
    BULK_CHECKBOX: 'input[type="checkbox"][data-coursesync-bulk]:not(:disabled)',
    ANY_CHECKBOX: 'input[type="checkbox"]:not(:disabled)',
    TOGGLE: '[data-action="coursesync-toggle-section"]',
    SELECTSECTION: '[data-action="coursesync-select-section"]',
    EXPANDALL: '[data-action="coursesync-expand-all"]',
    COLLAPSEALL: '[data-action="coursesync-collapse-all"]',
    SECTIONHEAD: 'tr[data-coursesync-group]',
    GROUPS: 'details.coursesync-group',
};

/**
 * The rows that belong to a section: the ones after its heading row, up to the next heading row.
 *
 * @param {HTMLTableRowElement} head
 * @return {HTMLTableRowElement[]}
 */
const rowsOf = head => {
    const rows = [];

    for (let row = head.nextElementSibling; row && !row.matches(SELECTORS.SECTIONHEAD); row = row.nextElementSibling) {
        rows.push(row);
    }

    return rows;
};

/**
 * Open or close one section. Its rows are only hidden: they stay in the form, so
 * whatever is ticked in a closed section is still sent.
 *
 * @param {HTMLTableRowElement} head
 * @param {Boolean} open
 */
const setSection = (head, open) => {
    rowsOf(head).forEach(row => {
        row.hidden = !open;
    });
    head.querySelector(SELECTORS.TOGGLE).setAttribute('aria-expanded', open ? 'true' : 'false');
};

/**
 * Tick or untick the checkboxes a selector matches in the form.
 *
 * @param {HTMLFormElement} form
 * @param {String} selector
 * @param {Boolean} checked
 */
const setAll = (form, selector, checked) => {
    form.querySelectorAll(selector).forEach(checkbox => {
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
        const toggle = e.target.closest(SELECTORS.TOGGLE);
        const pick = e.target.closest(SELECTORS.SELECTSECTION);

        if (toggle) {
            e.preventDefault();
            const head = toggle.closest(SELECTORS.SECTIONHEAD);
            setSection(head, toggle.getAttribute('aria-expanded') !== 'true');
        } else if (pick) {
            // Like "Select all", only what is plainly new or changed - and only in this section.
            e.preventDefault();
            const head = pick.closest(SELECTORS.SECTIONHEAD);
            rowsOf(head).forEach(row => {
                row.querySelectorAll(SELECTORS.BULK_CHECKBOX).forEach(checkbox => {
                    checkbox.checked = true;
                });
            });
            setSection(head, true);
        } else if (e.target.closest(SELECTORS.EXPANDALL) || e.target.closest(SELECTORS.COLLAPSEALL)) {
            e.preventDefault();
            const open = !!e.target.closest(SELECTORS.EXPANDALL);
            form.querySelectorAll(SELECTORS.SECTIONHEAD).forEach(head => setSection(head, open));

            if (open) {
                // Opening the groups too, so "expand all" shows everything.
                form.querySelectorAll(SELECTORS.GROUPS).forEach(group => {
                    group.open = true;
                });
            }
        } else if (e.target.closest(SELECTORS.SELECTALL)) {
            e.preventDefault();
            setAll(form, SELECTORS.BULK_CHECKBOX, true);
        } else if (e.target.closest(SELECTORS.SELECTNONE)) {
            e.preventDefault();
            setAll(form, SELECTORS.ANY_CHECKBOX, false);
        }
    });

    form.addEventListener('submit', e => {
        if (form.dataset.submitted) {
            e.preventDefault();
            return;
        }

        form.dataset.submitted = '1';
        form.querySelectorAll(SELECTORS.SUBMIT).forEach(button => {
            button.disabled = true;
        });
    });
};
