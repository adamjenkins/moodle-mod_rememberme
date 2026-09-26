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
 * Sortable report tables.
 *
 * Every report renders all of its rows on one page, so sorting happens here
 * rather than by reloading. Each column heading becomes a button, and the
 * heading carries aria-sort, which is what a screen reader announces. The
 * buttons are added by this script rather than the template, so without
 * JavaScript the headings stay plain text instead of controls that do nothing.
 *
 * A cell is ordered by its data-sort attribute when it has one, which the
 * templates set to the raw value behind a formatted one: a date rather than
 * its wording, 1.5 rather than "1,50" in a language that writes it so. Cells
 * with no value sort last whichever way the column is sorted.
 *
 * @module     mod_rememberme/sortable_table
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    table: 'table[data-sortable="1"]',
};

/**
 * The value a cell sorts by.
 *
 * @param {HTMLTableRowElement} row The row.
 * @param {Number} index The column index.
 * @returns {String} The value, empty when there is none.
 */
const valueOf = (row, index) => {
    const cell = row.cells[index];
    if (!cell) {
        return '';
    }
    if (cell.dataset.sort !== undefined) {
        return cell.dataset.sort.trim();
    }
    return cell.textContent.replace(/\s+/g, ' ').trim();
};

/**
 * Whether a value reads as a number, which decides how a column compares.
 *
 * @param {String} value The value.
 * @returns {Boolean} True for a finite number.
 */
const isNumber = (value) => value !== '' && Number.isFinite(Number(value));

/**
 * Sort a table's body by one column.
 *
 * The sort is stable, so rows that tie keep the order they had, which is the
 * server's order until another column is sorted.
 *
 * @param {HTMLTableElement} table The table.
 * @param {Number} index The column index.
 * @param {String} direction Either ascending or descending.
 */
export const sortTable = (table, index, direction) => {
    const body = table.tBodies[0];
    if (!body) {
        return;
    }
    const rows = Array.from(body.rows);
    const values = rows.map((row) => valueOf(row, index));
    const numeric = values.every((value) => value === '' || isNumber(value));
    const collator = new Intl.Collator(document.documentElement.lang || undefined, {numeric: true, sensitivity: 'base'});
    const sign = direction === 'descending' ? -1 : 1;

    const order = rows.map((row, position) => ({row, value: values[position], position}));
    order.sort((a, b) => {
        // Empty cells go last in both directions: they are absent, not small.
        if (a.value === '' || b.value === '') {
            if (a.value === b.value) {
                return a.position - b.position;
            }
            return a.value === '' ? 1 : -1;
        }
        const compared = numeric ? Number(a.value) - Number(b.value) : collator.compare(a.value, b.value);
        return compared === 0 ? a.position - b.position : sign * compared;
    });

    order.forEach(({row}) => body.appendChild(row));

    const headings = table.tHead ? Array.from(table.tHead.rows[0].cells) : [];
    headings.forEach((heading, position) => {
        if (position === index) {
            heading.setAttribute('aria-sort', direction);
        } else {
            heading.removeAttribute('aria-sort');
        }
    });
};

/**
 * Turn one table's headings into sort buttons.
 *
 * @param {HTMLTableElement} table The table.
 */
const enhance = (table) => {
    if (!table.tHead || !table.tHead.rows.length || table.dataset.sortableReady) {
        return;
    }
    table.dataset.sortableReady = '1';

    Array.from(table.tHead.rows[0].cells).forEach((heading, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-link p-0 text-reset fw-bold text-start mod_rememberme-sortbutton';
        while (heading.firstChild) {
            button.appendChild(heading.firstChild);
        }
        const indicator = document.createElement('span');
        indicator.className = 'mod_rememberme-sortindicator ms-1';
        indicator.setAttribute('aria-hidden', 'true');
        button.appendChild(indicator);
        heading.appendChild(button);

        button.addEventListener('click', () => {
            // The first click on a column sorts it ascending, a click on the
            // column already sorted reverses it.
            const current = heading.getAttribute('aria-sort');
            sortTable(table, index, current === 'ascending' ? 'descending' : 'ascending');
        });
    });
};

/**
 * Make every sortable table on the page sortable.
 */
export const init = () => {
    document.querySelectorAll(SELECTORS.table).forEach(enhance);
};
