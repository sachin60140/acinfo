import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import DataGrid from './components/DataGrid.vue';
import StatusBoard from './components/StatusBoard.vue';
import VendorReturn from './components/VendorReturn.vue';
import { exportHeader, exportRows } from './exports';

/*
 * The day a file went out, and how long it has been out.
 *
 * Three things the office asked for: the days beside the date, the newest
 * dispatch first, and the date in the files that come out of the exports.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

function mount(component, props) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(component, props);
    app.mount(host);
    mounted.push({ app, host });

    return host;
}

// The files list's columns, as WorkFileController::filesScreen builds them.
const COLUMNS = [
    { key: 'file_no', label: 'File No.' },
    { key: 'received', label: 'Received', sortBy: 'received_raw' },
    { key: 'dispatched', label: 'Dispatched', sortBy: 'dispatched_raw', sortDesc: true, sub: 'days_out' },
    { key: 'status', label: 'Status' },
];

const ROWS = [
    { id: 1, file_no: 'F-00001', received: '01-09-2026', received_raw: '2026-09-01', dispatched: '02-09-2026', dispatched_raw: '2026-09-02', days_out: '16 days', status: 'File Dispatch' },
    { id: 2, file_no: 'F-00002', received: '10-09-2026', received_raw: '2026-09-10', dispatched: '12-09-2026', dispatched_raw: '2026-09-12', days_out: '6 days', status: 'File Dispatch' },
    { id: 3, file_no: 'F-00003', received: '15-09-2026', received_raw: '2026-09-15', dispatched: null, dispatched_raw: null, days_out: null, status: 'In Office' },
];

const heading = (host, label) => [...host.querySelectorAll('thead th')].find((th) => th.textContent.trim().startsWith(label));
const column = (host, label) => {
    const index = [...host.querySelectorAll('thead th')].indexOf(heading(host, label));

    return [...host.querySelectorAll('tbody tr')].map((tr) => tr.children[index].textContent.replace(/\s+/g, ' ').trim());
};

describe('the dispatch date on a grid', () => {
    it('shows the days under the date, and nothing for a file never sent out', () => {
        const host = mount(DataGrid, { columns: COLUMNS, rows: ROWS });

        // The days are their own line under the date, so the cell text runs together.
        const cells = column(host, 'Dispatched');

        expect(cells[0]).toContain('02-09-2026');
        expect(cells[0]).toContain('16 days');
        expect(cells[1]).toContain('6 days');
        expect(cells[2]).toBe('—');
    });

    /*
     * Ascending would open on the oldest dispatch, which is the opposite of the
     * question. Every other column still opens upwards.
     */
    it('opens newest first, and leaves other columns opening upwards', async () => {
        const host = mount(DataGrid, { columns: COLUMNS, rows: ROWS });

        heading(host, 'Dispatched').click();
        await nextTick();

        expect(column(host, 'File No.')[0]).toBe('F-00002');

        heading(host, 'Received').click();
        await nextTick();

        expect(column(host, 'File No.')[0]).toBe('F-00001');
    });

    it('turns round on a second click, like any other column', async () => {
        const host = mount(DataGrid, { columns: COLUMNS, rows: ROWS });

        heading(host, 'Dispatched').click();
        await nextTick();
        heading(host, 'Dispatched').click();
        await nextTick();

        // Ascending now: the file with no dispatch date sorts before the dates.
        expect(column(host, 'File No.')[2]).toBe('F-00002');
    });

    it('puts the date in the exports', () => {
        expect(exportHeader(COLUMNS)).toContain('Dispatched');

        const rows = exportRows(COLUMNS, ROWS, {});

        expect(rows[0]).toContain('02-09-2026');
        expect(rows[1]).toContain('12-09-2026');
    });
});

describe('the screens that are lists of dispatched work', () => {
    it('says how long the vendor has had each file', () => {
        const host = mount(VendorReturn, {
            files: [{
                id: 1, file_no: 'F-00050', registration_no: 'BR01JB8140', vendor: 'Suman Ji Patna',
                vendor_date: '12-09-2026', days_out: '6 days', work_type: 'TR', description: '',
                customer: 'Car4Sales', vendor_amount: 3000,
            }],
            action: '/admin/file/vendor-return',
            csrf: 'token',
            cancelUrl: '/admin/files',
            returnedOn: '2026-09-18',
            oldRemark: '',
            oldFiles: [],
            oldAmounts: {},
        });

        const cell = [...host.querySelectorAll('td')].find((td) => td.dataset.label === 'Given On');

        expect(cell.textContent).toContain('12-09-2026');
        expect(cell.textContent).toContain('6 days out');
    });

    it('says it in the status board heading too', () => {
        const host = mount(StatusBoard, {
            files: [{
                id: 1, file_no: 'F-00050', status: 'file_dispatch', status_label: 'File Dispatch',
                registration_no: 'BR01JB8140', received_date: '07-09-2026',
                dispatched: '12-09-2026', days_out: '6 days',
                customer: 'Car4Sales', vendor: 'Suman Ji Patna', customer_amount: 5000,
                statuses: { file_dispatch: 'File Dispatch', approval_done: 'Approval Done' },
                works: 1, settled: 0, edit_url: '/admin/file/edit/1', papers_url: '/admin/file/1/papers',
                items: [{ id: 11, work_type: 'TR', status: 'file_dispatch', customer_amount: 5000, approved_on: null, approved_on_value: null }],
            }],
            statuses: { file_dispatch: 'File Dispatch', approval_done: 'Approval Done' },
            action: '/admin/file/status',
            csrf: 'token',
            resetUrl: '/admin/file/status',
            approvedKey: 'approval_done',
            cancelledKey: 'cancelled',
            today: '2026-09-18',
        });

        expect(host.querySelector('.board__file-line').textContent.replace(/\s+/g, ' '))
            .toContain('Dispatched 12-09-2026 · 6 days out');
    });
});
