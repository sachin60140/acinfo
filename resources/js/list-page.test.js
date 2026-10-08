import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import DataGrid from './components/DataGrid.vue';
import WorkReport from './components/WorkReport.vue';

/*
 * The totals under a page of a longer list.
 *
 * All Work Files and the Work Report are sent the newest 500 files of finished
 * work and a link to the older ones (see ListPage). A bare "Total" under that
 * page, or under a party's band on it, reads as the whole list's — or the
 * whole of that party's — so the server names the totals for what they
 * cover, and the grid says it on every totals row.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const COLUMNS = [
    { key: 'file_no', label: 'File No.' },
    { key: 'billed', label: 'Billed', type: 'money' },
];

const ROWS = [
    { id: 1, party_id: 1, party_name: 'Customer One', file_no: 'F-1', billed: 1000 },
    { id: 2, party_id: 1, party_name: 'Customer One', file_no: 'F-2', billed: 2000 },
    { id: 3, party_id: 2, party_name: 'Customer Two', file_no: 'F-3', billed: 500 },
];

async function mount(component, extra = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(component, {
        columns: COLUMNS,
        rows: ROWS,
        groupBy: 'party_id',
        groupLabel: 'party_name',
        totals: { billed: 'sum' },
        perPage: 100,
        ...extra,
    });
    app.mount(host);
    mounted.push({ app, host });
    await nextTick();

    return host;
}

const label = (tr) => tr.querySelectorAll('td')[0].textContent.trim();

async function search(host, text) {
    const box = host.querySelector('input[type="search"]');
    box.value = text;
    box.dispatchEvent(new window.Event('input'));
    await nextTick();
}

describe('a page of a longer list', () => {
    it('names every totals row for what it covers', async () => {
        const host = await mount(DataGrid, { totalLabel: 'Total of those shown' });

        expect([...host.querySelectorAll('.grid__subtotal')].map(label))
            .toEqual(['Total of those shown', 'Total of those shown']);
        expect(label(host.querySelector('tfoot tr'))).toBe('Total of those shown');

        await search(host, 'F-1');

        expect(label(host.querySelector('tfoot tr'))).toBe('Total of those shown (filtered)');
    });

    it('is named so through the Work Report too', async () => {
        const host = await mount(WorkReport, { totalLabel: 'Total of those shown' });

        expect(label(host.querySelector('.grid__subtotal'))).toBe('Total of those shown');
        expect(label(host.querySelector('tfoot tr'))).toBe('Total of those shown');
    });
});

describe('a list on one page', () => {
    it('still calls its totals Total', async () => {
        const host = await mount(DataGrid);

        expect(label(host.querySelector('.grid__subtotal'))).toBe('Total');
        expect(label(host.querySelector('tfoot tr'))).toBe('Total');

        await search(host, 'F-1');

        expect(label(host.querySelector('tfoot tr'))).toBe('Total (filtered)');
    });
});
