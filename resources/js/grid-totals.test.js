import { afterEach, describe, expect, it } from 'vitest';
import { createApp } from 'vue';
import DataGrid from './components/DataGrid.vue';

/*
 * A total is written the way its column is written.
 *
 * The footer went through money() for anything that was not a balance, so the
 * profit report's Works column — cells reading 3, 2, 2 and 2 — totalled to
 * "9.00". Nobody has ever done nine-hundredths of a transfer.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

// The profit report, cut by work type, as ReportController::profit builds it.
function mount(rows) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(DataGrid, {
        columns: [
            { key: 'label', label: 'Work Type' },
            { key: 'files', label: 'Works', type: 'count' },
            { key: 'billed', label: 'Billed', type: 'money' },
            { key: 'margin', label: 'Margin', type: 'balance' },
        ],
        rows,
        totals: { files: 'sum', billed: 'sum', margin: 'sum' },
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

/** The footer cell under a heading. */
function footer(host, heading) {
    const headings = [...host.querySelectorAll('thead th')].map((th) => th.textContent.trim());

    return host.querySelectorAll('tfoot td')[headings.indexOf(heading)].textContent.trim();
}

const ROWS = [
    { id: 1, label: 'HPT + TR + HPA', files: 3, billed: 38500, margin: 23000 },
    { id: 2, label: 'TR', files: 2, billed: 8500, margin: 3500 },
    { id: 3, label: 'HPT', files: 2, billed: 6500, margin: 1000 },
    { id: 4, label: 'HPA', files: 2, billed: 5000, margin: 1500 },
    // The counter-expenses line: no work done, so it adds nothing to the count.
    { id: 0, label: 'Counter expenses', files: 0, billed: 0, margin: -2550 },
];

describe('the totals row', () => {
    it('writes a count as a whole number', () => {
        expect(footer(mount(ROWS), 'Works')).toBe('9');
    });

    it('still writes money as money beside it', () => {
        expect(footer(mount(ROWS), 'Billed')).toBe('58,500.00');
    });

    it('still writes a balance with its side', () => {
        expect(footer(mount(ROWS), 'Margin')).toBe('26,450.00 Dr');
    });

    it('writes a count of nothing as 0 rather than a blank', () => {
        expect(footer(mount([{ id: 1, label: 'None', files: 0, billed: 0, margin: 0 }]), 'Works')).toBe('0');
    });
});
