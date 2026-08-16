/*
 * What goes into an exported file.
 *
 * Pulled out of the grid so it can be tested. These decide which columns leave,
 * which rows leave, and what a figure looks like when it gets there — and every
 * one of those has been wrong at least once. A column silently missing from a
 * spreadsheet is not visible in the application at all: the screen looks
 * correct, the file is opened somewhere else, and the discrepancy is found in a
 * meeting rather than by anyone who could fix it.
 *
 * Nothing here touches the DOM or a library. The grid does the fetching and the
 * saving; this decides what is in the file.
 */

/** Column types whose values are figures rather than text. */
const NUMERIC = ['money', 'balance', 'count'];

export const isNumeric = (column) => NUMERIC.includes(column.type);

/**
 * The columns that reach a file.
 *
 * Hidden columns are internal — the report bands on a party id nobody should
 * ever see in a spreadsheet. Columns marked unexportable are labels rather than
 * data, like a cell reading "Edit".
 */
export function exportColumns(columns) {
    return columns.filter((column) => column.exportable !== false && ! column.hidden);
}

/**
 * A cell as text, written the way the screen writes it.
 *
 * Used for the header, for CSV, for the PDF and for the clipboard — everything
 * a person reads rather than calculates with.
 */
export function cellText(row, column, { money, balance }) {
    const raw = row[column.key];

    if (raw === null || raw === undefined || raw === '') {
        return '';
    }

    if (column.type === 'money') return money(raw);
    if (column.type === 'balance') return balance(raw);

    return String(raw);
}

/**
 * A cell for a spreadsheet: figures as numbers.
 *
 * A spreadsheet is opened to add things up. Writing "1,23,456.00" into the cell
 * makes it text — the column will not sum, sort or filter, and the reader has
 * to retype it. Only the spreadsheet needs this; CSV, PDF and print are read
 * rather than calculated, and the grouped form is easier on the eye there.
 *
 * Empty stays empty. Number(null) is 0, and a zero written into a cell the
 * screen leaves blank is a figure that was never incurred.
 */
export function cellValue(row, column, formatters) {
    if (! isNumeric(column)) {
        return cellText(row, column, formatters);
    }

    const raw = row[column.key];

    if (raw === null || raw === undefined || raw === '') {
        return '';
    }

    const parsed = Number(raw);

    return Number.isFinite(parsed) ? parsed : cellText(row, column, formatters);
}

/** The header row: the labels a reader sees on screen. */
export function exportHeader(columns) {
    return exportColumns(columns).map((column) => column.label);
}

/**
 * Every exported row, as text.
 *
 * Takes the rows it is given — which the grid passes as the filtered, sorted
 * set rather than the visible page. Exporting page 1 of 8 after someone has
 * searched for a party is the kind of quiet wrongness nobody notices until it
 * matters.
 */
export function exportRows(columns, rows, formatters) {
    const cols = exportColumns(columns);

    return rows.map((row) => cols.map((column) => cellText(row, column, formatters)));
}

/** Every exported row, with figures as numbers, for the spreadsheet. */
export function exportValues(columns, rows, formatters) {
    const cols = exportColumns(columns);

    return rows.map((row) => cols.map((column) => cellValue(row, column, formatters)));
}

/**
 * One CSV field.
 *
 * Quoted always. A grouped figure carries a comma, so quoting is not optional
 * here — an unquoted 1,200.00 becomes two columns and shifts every field after
 * it by one.
 */
export function csvCell(value) {
    return `"${String(value).replace(/"/g, '""')}"`;
}

/**
 * The whole CSV, with a byte-order mark.
 *
 * The BOM is what makes Excel read the file as UTF-8 rather than mangling
 * anything outside ASCII. CRLF line endings for the same reason.
 */
export function toCsv(columns, rows, formatters) {
    const lines = [exportHeader(columns), ...exportRows(columns, rows, formatters)];

    return '﻿' + lines.map((line) => line.map(csvCell).join(',')).join('\r\n');
}

/** Tab-separated, for pasting straight into a sheet. */
export function toClipboard(columns, rows, formatters) {
    return [exportHeader(columns), ...exportRows(columns, rows, formatters)]
        .map((line) => line.join('\t'))
        .join('\n');
}

/** A filename that survives every filesystem. */
export function fileName(title, extension) {
    return `${String(title).replace(/[^\w-]+/g, '-')}.${extension}`;
}
