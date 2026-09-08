import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import DataGrid from './components/DataGrid.vue';

/*
 * What a statement carries when it leaves the screen.
 *
 * The opening balance was drawn above the table, in a card of its own, so no
 * export ever saw it — a printed statement began at its first transaction with
 * a Balance column counting up from a figure that appeared nowhere on the page.
 * The reader is left to work out what it started from, which is the one thing a
 * statement exists to tell them.
 */

const mounted = [];

const COLUMNS = [
    { key: 'txn_date', label: 'Date' },
    { key: 'particular', label: 'Particulars' },
    { key: 'debit', label: 'Debit', type: 'money' },
    { key: 'credit', label: 'Credit', type: 'money' },
    { key: 'balance', label: 'Balance', type: 'balance' },
];

const ROWS = [
    { txn_date: '05-09-2026', particular: 'Cash Paid to arman', debit: null, credit: 10000, balance: -11000 },
    { txn_date: '07-09-2026', particular: 'HPT+TR - BR07AK2575', debit: 7000, credit: null, balance: 6500 },
];

const OPENING = [{ txn_date: '01-09-2026', particular: 'Opening Balance', debit: null, credit: null, balance: -1000 }];
const CLOSING = [{ txn_date: '30-09-2026', particular: 'Closing Balance', debit: 7000, credit: 10000, balance: 6500 }];

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(DataGrid, {
        title: 'Rakesh JI Madhubani Statement',
        columns: COLUMNS,
        rows: ROWS,
        lead: OPENING,
        tail: CLOSING,
        sortable: false,
        totals: { debit: 'sum', credit: 'sum' },
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const button = (host, label) =>
    [...host.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(label));

const bodyText = (host) => [...host.querySelectorAll('tbody tr')].map((tr) => tr.textContent);

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    vi.restoreAllMocks();
});

describe('the opening balance on a statement', () => {
    it('is the first row of the table', () => {
        const host = mount();

        expect(bodyText(host)[0]).toContain('Opening Balance');
        expect(bodyText(host)[0]).toContain('1,000.00');
    });

    it('is the last row that is the closing balance', () => {
        const host = mount();

        const rows = bodyText(host);

        expect(rows[rows.length - 1]).toContain('Closing Balance');
    });

    /*
     * The point of the whole change. Copy builds its text from the same rows
     * every other export does, so proving it here proves CSV, Excel, PDF and
     * Print as well — they share exportRows().
     */
    it('is carried into what the reader copies out', async () => {
        const written = [];

        Object.assign(navigator, {
            clipboard: { writeText: (text) => { written.push(text); return Promise.resolve(); } },
        });

        const host = mount();

        button(host, 'Copy').click();
        await nextTick();
        await Promise.resolve();

        expect(written.length, 'something was copied').toBe(1);

        const [text] = written;
        const lines = text.trim().split('\n');

        // Header, opening, two entries, closing.
        expect(lines.length).toBe(5);
        expect(lines[1]).toContain('Opening Balance');
        expect(lines[4]).toContain('Closing Balance');
    });

    it('survives a search that hides every entry', async () => {
        const host = mount();

        const search = host.querySelector('input[type="search"], .grid__search input');

        search.value = 'nothing matches this';
        search.dispatchEvent(new window.Event('input'));
        await nextTick();

        const rows = bodyText(host);

        // A balance brought forward is not a search result, and a statement
        // filtered down to nothing still started somewhere.
        expect(rows.some((r) => r.includes('Opening Balance'))).toBe(true);
        expect(rows.some((r) => r.includes('Cash Paid to arman'))).toBe(false);
    });

    it('is not counted as a transaction in the totals', () => {
        const host = mount();

        const foot = host.querySelector('tfoot').textContent;

        // 7,000 debit from the one entry — not 14,000 with the closing row
        // counted again.
        expect(foot).toContain('7,000.00');
        expect(foot).not.toContain('14,000.00');
    });

    it('says nothing extra on a grid that was given none', () => {
        const host = mount({ lead: [], tail: [] });

        const rows = bodyText(host);

        expect(rows.some((r) => r.includes('Opening Balance'))).toBe(false);
        expect(rows.length).toBe(2);
    });

    /*
     * A period a party had no dealings in is not an empty statement: it has a
     * balance brought forward, which is exactly what somebody asking for that
     * period wants to know.
     */
    it('shows a period with no entries as a balance rather than as nothing', () => {
        const host = mount({ rows: [] });

        expect(host.querySelector('.ui-empty')).toBe(null);
        expect(bodyText(host)[0]).toContain('Opening Balance');
    });
});
