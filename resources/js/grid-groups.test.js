import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import DataGrid from './components/DataGrid.vue';

/*
 * Columns offered rather than shown.
 *
 * The work files list answered every question anybody had ever asked of it at
 * once — fourteen columns — which meant the few that say which file this is and
 * where it has got to were read past a wall of figures nobody wanted that
 * morning. So a column may name a band, and a band is turned on when its
 * question is being asked.
 *
 * The rule the whole thing rests on: a column naming no band is always drawn.
 * A list whose point can be hidden is not simpler, only emptier.
 */

const mounted = [];

const COLUMNS = [
    { key: 'file_no', label: 'File No.' },
    { key: 'customer', label: 'Customer' },
    { key: 'vendor', label: 'Vendor', group: 'dispatch' },
    { key: 'cost', label: 'Cost', type: 'money', group: 'money' },
    { key: 'margin', label: 'Margin', type: 'balance', group: 'money' },
    // Carried for the export and drawn nowhere, band or no band.
    { key: 'works', label: 'Works', exportOnly: true },
];

const GROUPS = [
    { key: 'dispatch', label: 'Vendor & dispatch' },
    { key: 'money', label: 'Cost & margin' },
];

const ROWS = [
    { id: 1, file_no: 'F-00072', customer: 'Rishu ji', vendor: 'In-house', cost: 1200, margin: 800, works: 'TR' },
];

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(DataGrid, {
        title: 'Work Files',
        columns: COLUMNS,
        groups: GROUPS,
        rows: ROWS,
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const headings = (host) => [...host.querySelectorAll('thead th')].map((th) => th.textContent.trim());
const pills = (host) => [...host.querySelectorAll('.grid__group')];
const pill = (host, label) => pills(host).find((b) => b.textContent.trim() === label);

beforeEach(() => {
    localStorage.clear();
});

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    localStorage.clear();
});

describe('columns offered rather than shown', () => {
    it('draws the columns that name no band, and holds back the rest', () => {
        const host = mount();

        expect(headings(host)).toContain('File No.');
        expect(headings(host)).toContain('Customer');

        expect(headings(host)).not.toContain('Vendor');
        expect(headings(host)).not.toContain('Cost');
        expect(headings(host)).not.toContain('Margin');
    });

    it('offers a pill for each band, named for its question', () => {
        const host = mount();

        expect(pills(host).map((b) => b.textContent.trim()))
            .toEqual(['Vendor & dispatch', 'Cost & margin']);
    });

    it('brings a whole band in on one click', async () => {
        const host = mount();

        await click(pill(host, 'Cost & margin'));

        expect(headings(host)).toContain('Cost');
        expect(headings(host)).toContain('Margin');
        // And only that band: the other question was not asked.
        expect(headings(host)).not.toContain('Vendor');
    });

    it('takes the band away again', async () => {
        const host = mount();

        await click(pill(host, 'Cost & margin'));
        await click(pill(host, 'Cost & margin'));

        expect(headings(host)).not.toContain('Cost');
    });

    it('opens a band that says it starts open', () => {
        const host = mount({ groups: [{ key: 'money', label: 'Cost & margin', on: true }] });

        expect(headings(host)).toContain('Cost');
    });

    it('offers nothing when a screen names no bands', () => {
        const host = mount({ groups: [], columns: [{ key: 'file_no', label: 'File No.' }] });

        expect(pills(host)).toHaveLength(0);
        expect(headings(host)).toContain('File No.');
    });

    /* An export-only column is not a band that is shut; it is never drawn. */
    it('never draws an export-only column, whichever bands are open', async () => {
        const host = mount();

        await click(pill(host, 'Cost & margin'));
        await click(pill(host, 'Vendor & dispatch'));

        expect(headings(host)).not.toContain('Works');
    });
});

describe('what the reader chose is remembered', () => {
    it('keeps the bands they opened for the next visit', async () => {
        const first = mount();

        await click(pill(first, 'Cost & margin'));

        expect(headings(mount())).toContain('Cost');
    });

    it('remembers per screen, not for every table at once', async () => {
        const files = mount();

        await click(pill(files, 'Cost & margin'));

        expect(headings(mount({ title: 'Vendor Report' }))).not.toContain('Cost');
    });

    /* A band renamed or dropped should not keep a seat in a stale memory. */
    it('ignores a remembered band the screen no longer offers', () => {
        localStorage.setItem('acinfo.grid.Work Files.groups', JSON.stringify(['retired', 'money']));

        const host = mount();

        expect(headings(host)).toContain('Cost');
        expect(pills(host)).toHaveLength(2);
    });

    it('draws its usual columns when the memory is nonsense', () => {
        localStorage.setItem('acinfo.grid.Work Files.groups', 'not json at all');

        const host = mount();

        expect(headings(host)).toContain('File No.');
        expect(headings(host)).not.toContain('Cost');
    });
});

async function click(el) {
    el.click();
    await nextTick();
}
