import { afterEach, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { createApp } from 'vue';
import DataGrid from './components/DataGrid.vue';

/*
 * Every row lines up with the header.
 *
 * A table column is only as wide as the cells in it, so a row carrying a
 * different number of cells than the header does not render short — it shifts
 * everything after the gap sideways, and the figures end up under the wrong
 * heading. There is no error and nothing looks broken up close: each row is
 * individually fine and the page is quietly wrong.
 *
 * The work files list is the one that grew: thirteen visible columns, four
 * cell types, plus the framing rows and the totals foot. So it is the one
 * worth pinning.
 */

const mounted = [];

// The work files list, as WorkFileController::filesScreen builds it.
const FILES_COLUMNS = [
    { key: 'file_no', label: 'File No.', type: 'link', linkTo: 'edit_url' },
    { key: 'registration_no', label: 'Vehicle' },
    { key: 'received', label: 'Received', sortBy: 'received_raw' },
    { key: 'work_type', label: 'Work Type' },
    { key: 'description', label: 'Details' },
    { key: 'customer', label: 'Customer', type: 'link', linkTo: 'customer_url' },
    { key: 'charged', label: 'Charged', type: 'money', class: 'dr', sub: 'charged_was' },
    { key: 'vendor', label: 'Vendor', type: 'link', linkTo: 'vendor_url' },
    { key: 'cost', label: 'Cost', type: 'money', class: 'cr', sub: 'cost_was' },
    { key: 'expenses', label: 'Expenses', type: 'money', class: 'cr' },
    { key: 'margin', label: 'Margin', type: 'balance', class: 'fw-bold' },
    { key: 'status', label: 'Status', type: 'badge' },
    { key: 'action', label: 'Action', type: 'link', linkTo: 'edit_url' },
    // Carried for the export only, and drawn nowhere.
    { key: 'works_done', label: 'Approved Works', exportOnly: true },
    { key: 'received_raw', label: 'Received Raw', hidden: true },
];

/* An in-house file with nothing priced — the row from the screenshot. */
const BARE_ROW = {
    id: 1,
    file_no: 'F-00072',
    edit_url: '/admin/file/edit/1',
    registration_no: 'BR06CN4873',
    received: '15-09-2026',
    received_raw: '2026-09-15',
    work_type: 'HPT+TR',
    description: null,
    customer: 'Rishu ji',
    customer_url: '/admin/party/statement/5',
    charged: 0,
    charged_was: null,
    vendor: 'In-house',
    // In-house: there is no vendor to link to, so the link cell has no href.
    vendor_url: null,
    cost: null,
    cost_was: null,
    expenses: null,
    margin: null,
    status: 'In Office',
    status_key: 'in_office',
    action: 'Edit',
    works_done: '',
};

const FULL_ROW = {
    ...BARE_ROW,
    id: 2,
    file_no: 'F-00051',
    customer: 'Kuwy Technology Service Pvt Ltd',
    vendor: 'Dabloo Ji Muzaffarpur',
    vendor_url: '/admin/party/statement/6',
    charged: 7000,
    cost: 2950,
    expenses: 450,
    margin: 4050,
};

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(DataGrid, {
        columns: FILES_COLUMNS,
        rows: [BARE_ROW, FULL_ROW],
        title: 'Work Files',
        totals: { charged: 'sum', cost: 'sum', expenses: 'sum', margin: 'sum' },
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const headerCells = (host) => host.querySelectorAll('thead th').length;

// Every row that is meant to line up with the header: the entries, the framing
// rows around them, and the totals. A band heading is deliberately one wide
// cell spanning the lot, so it is counted separately.
const griddedRows = (host) =>
    [...host.querySelectorAll('tbody tr, tfoot tr')].filter((tr) => ! tr.classList.contains('grid__band'));

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

describe('every row lines up with the header', () => {
    it('gives each entry exactly as many cells as there are headings', () => {
        const host = mount();

        const headings = headerCells(host);

        expect(headings, 'thirteen drawn, two carried for the export').toBe(13);

        for (const tr of griddedRows(host)) {
            expect(
                tr.querySelectorAll('td').length,
                `a row has ${tr.querySelectorAll('td').length} cells against ${headings} headings: `
                    + tr.textContent.replace(/\s+/g, ' ').trim().slice(0, 60)
            ).toBe(headings);
        }
    });

    /*
     * The row from the screenshot. Every one of its money columns is null and
     * its vendor has no link — the combination most likely to leave a cell
     * unrendered, because each of those is a branch that can decline to draw.
     */
    it('draws a cell even where the value is nothing', () => {
        const host = mount({ rows: [BARE_ROW] });

        const row = host.querySelector('tbody tr');

        expect(row.querySelectorAll('td').length).toBe(headerCells(host));
    });

    it('draws a cell for a link with nowhere to go', () => {
        const host = mount({ rows: [BARE_ROW] });

        const cells = [...host.querySelector('tbody tr').querySelectorAll('td')];
        const vendor = cells[7];

        // In-house: no href, but the name still has to be in its own column.
        expect(vendor.textContent.trim()).toBe('In-house');
        expect(vendor.querySelector('a')).toBe(null);
    });

    it('keeps the framing rows in step with the header', () => {
        const host = mount({
            lead: [{ ...BARE_ROW, file_no: 'Opening' }],
            tail: [{ ...BARE_ROW, file_no: 'Closing' }],
        });

        for (const tr of griddedRows(host)) {
            expect(tr.querySelectorAll('td').length).toBe(headerCells(host));
        }
    });

    it('keeps the totals row in step with the header', () => {
        const host = mount();

        const foot = host.querySelector('tfoot tr');

        expect(foot, 'there is a totals row').not.toBe(null);
        expect(foot.querySelectorAll('td').length).toBe(headerCells(host));
    });

    /*
     * A figure is read down its column, so the heading has to be aligned the
     * same way the cells under it are. A right-aligned column of money under a
     * left-aligned heading reads as two different columns.
     */
    it('aligns each heading the way the cells beneath it are aligned', () => {
        const host = mount();

        const heads = [...host.querySelectorAll('thead th')];
        const cells = [...host.querySelector('tbody tr').querySelectorAll('td')];

        heads.forEach((th, i) => {
            expect(
                cells[i].classList.contains('num'),
                `"${th.textContent.trim()}" heading and its cells disagree about being numeric`
            ).toBe(th.classList.contains('num'));
        });
    });

    it('marks the money columns as numeric on both the heading and the cell', () => {
        const host = mount();

        const heads = [...host.querySelectorAll('thead th')];
        const cells = [...host.querySelector('tbody tr').querySelectorAll('td')];

        for (const label of ['Charged', 'Cost', 'Expenses', 'Margin']) {
            const i = heads.findIndex((th) => th.textContent.trim().startsWith(label));

            expect(i, `${label} is on the table`).toBeGreaterThan(-1);
            expect(heads[i].classList.contains('num'), `${label} heading is numeric`).toBe(true);
            expect(cells[i].classList.contains('num'), `${label} cells are numeric`).toBe(true);
        }
    });

    /*
     * Where a row is taller than one line, every cell starts at the top.
     *
     * jsdom does no layout, so this reads the rule rather than measuring it —
     * which is the honest test either way, because the value IS the behaviour.
     *
     * Centred, a one-line cell floats in the middle of a four-line row: a
     * customer called "Kuwy Technology Service Pvt Ltd" wraps onto four lines
     * and the status, the figures and the Edit link all drift to the middle of
     * the band, so nothing lines up with the heading it belongs to or with the
     * cell beside it. On a single-line row the two are identical, so top costs
     * nothing and fixes the tall ones.
     */
    it('starts every cell at the top of its row', () => {
        const css = readFileSync('resources/css/app.css', 'utf8');

        const rule = css.match(/\.ui-table tbody td \{([^}]*)\}/);

        expect(rule, '.ui-table tbody td is styled').not.toBe(null);
        expect(rule[1]).toMatch(/vertical-align:\s*top/);
        expect(rule[1], 'centred is what made tall rows drift').not.toMatch(/vertical-align:\s*middle/);
    });

    /* And the ones that are not figures are not right-aligned. */
    it('leaves the words alone', () => {
        const host = mount();

        const heads = [...host.querySelectorAll('thead th')];
        const cells = [...host.querySelector('tbody tr').querySelectorAll('td')];

        for (const label of ['Status', 'Action', 'Customer', 'Vendor']) {
            const i = heads.findIndex((th) => th.textContent.trim().startsWith(label));

            expect(heads[i].classList.contains('num'), `${label} heading is not numeric`).toBe(false);
            expect(cells[i].classList.contains('num'), `${label} cells are not numeric`).toBe(false);
        }
    });
});
