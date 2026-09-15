import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import DataGrid from './components/DataGrid.vue';

/*
 * A grid with more columns than the page is wide.
 *
 * The table is width:100%, so a column that will not fit does not push the
 * table wider — it takes the space out of every other column. The files list
 * reached thirteen columns and a customer called "Kuwy Technology Service Pvt
 * Ltd" wrapped onto four lines, which made the row four lines tall and left the
 * figures beside it floating in the middle of it.
 *
 * So a wide grid is allowed to be wide and scroll inside its own wrapper. Only
 * above the width where it is still a table: below that every row is a card,
 * and a minimum width there would restore exactly the sideways scroll the cards
 * exist to avoid.
 */

const mounted = [];

const column = (key) => ({ key, label: key });

function mount(count) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const columns = Array.from({ length: count }, (_, i) => column(`c${i}`));
    const row = Object.fromEntries(columns.map((c, i) => [c.key, `value ${i}`]));

    const app = createApp(DataGrid, { columns, rows: [row], title: 'Test' });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const table = (host) => host.querySelector('table');

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

describe('a grid with many columns', () => {
    it('is left alone when it comfortably fits', () => {
        expect(table(mount(5)).classList.contains('grid__table--wide')).toBe(false);
    });

    it('is given room once there are too many columns to squeeze', () => {
        expect(table(mount(13)).classList.contains('grid__table--wide')).toBe(true);
    });

    it('turns at nine, so the eight-column listings are untouched', () => {
        expect(table(mount(8)).classList.contains('grid__table--wide')).toBe(false);
        expect(table(mount(9)).classList.contains('grid__table--wide')).toBe(true);
    });

    /*
     * Hidden and export-only columns are not on the page, so they must not
     * count towards the width the page needs. The files list carries four of
     * them and would otherwise be called wide on the strength of columns
     * nobody can see.
     */
    it('counts the columns that are drawn, not the ones that are carried', async () => {
        const host = document.createElement('div');
        document.body.appendChild(host);

        const columns = [
            ...Array.from({ length: 5 }, (_, i) => column(`shown${i}`)),
            ...Array.from({ length: 6 }, (_, i) => ({ ...column(`hidden${i}`), hidden: true })),
        ];

        const app = createApp(DataGrid, {
            columns,
            rows: [Object.fromEntries(columns.map((c) => [c.key, 'x']))],
            title: 'Test',
        });

        app.mount(host);
        mounted.push({ app, host });
        await nextTick();

        expect(host.querySelectorAll('thead th').length).toBe(5);
        expect(table(host).classList.contains('grid__table--wide')).toBe(false);
    });

    /* The wrapper is what scrolls. The page must never. */
    it('scrolls inside its own wrapper', () => {
        const host = mount(13);

        expect(host.querySelector('.ui-table-wrap')).not.toBe(null);
        expect(table(host).closest('.ui-table-wrap')).not.toBe(null);
    });
});
