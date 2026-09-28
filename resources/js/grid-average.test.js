import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import DataGrid from './components/DataGrid.vue';

/*
 * A column averaged rather than summed — Approval Time's days, per party and
 * overall. A blank is a figure nobody could work out, and is left out of the
 * mean rather than counted as nought.
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
    { key: 'days', label: 'Days Taken', type: 'count' },
];

async function mount(rows) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(DataGrid, {
        columns: COLUMNS,
        rows,
        groupBy: 'party_id',
        groupLabel: 'party_name',
        totals: { days: 'avg' },
        perPage: 100,
    });
    app.mount(host);
    mounted.push({ app, host });
    await nextTick();

    return host;
}

const lastCell = (tr) => tr.querySelectorAll('td')[1].textContent.trim();

describe('an averaged column', () => {
    it('averages each band, and all of them at the foot', async () => {
        const host = await mount([
            { id: 1, party_id: 1, party_name: 'Arman Works', file_no: 'F-1', days: 4 },
            { id: 2, party_id: 1, party_name: 'Arman Works', file_no: 'F-2', days: 7 },
            { id: 3, party_id: 2, party_name: 'Chandan Motors', file_no: 'F-3', days: 20 },
        ]);

        const subtotals = [...host.querySelectorAll('.grid__subtotal')].map(lastCell);

        expect(subtotals).toEqual(['avg 5.5', 'avg 20.0']);
        expect(lastCell(host.querySelector('tfoot tr'))).toBe('avg 10.3');
    });

    it('leaves out a blank rather than counting it as nothing', async () => {
        const host = await mount([
            { id: 1, party_id: 1, party_name: 'Arman Works', file_no: 'F-1', days: 6 },
            { id: 2, party_id: 1, party_name: 'Arman Works', file_no: 'F-2', days: null },
        ]);

        expect(lastCell(host.querySelector('tfoot tr'))).toBe('avg 6.0');
    });

    it('says nothing when there is nothing to average', async () => {
        const host = await mount([
            { id: 1, party_id: 1, party_name: 'Arman Works', file_no: 'F-1', days: null },
        ]);

        expect(lastCell(host.querySelector('tfoot tr'))).toBe('—');
    });
});
