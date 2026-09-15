import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import WorkReport from './components/WorkReport.vue';

/*
 * Moving a file along from the report it was noticed on.
 *
 * The dialog posts to the status screen's own controller with that screen's
 * field names, so nothing about the rules lives here. What does live here is
 * the shape of what gets posted — the wrong key and the save lands on another
 * work, or on none — and refusing to submit what the server is going to refuse
 * anyway, since a rejected post comes back with the typed remark gone.
 */

const mounted = [];

const ROWS = [
    {
        id: 1,
        party_id: 7,
        party_band: 'Customer — Kuwy',
        party_name: 'Kuwy Technology Service Pvt Ltd',
        file_no: 'F-00044',
        registration_no: 'BR11BU1926',
        received: '03-09-2026',
        work_type: 'HPA',
        status: 'File Dispatch',
        status_key: 'file_dispatch',
        remark: 'Form 34 received',
        update: 'Update',
        items: [
            {
                id: 11,
                work_type: 'HPA',
                status: 'file_dispatch',
                status_label: 'File Dispatch',
                approved_on_iso: null,
                has_screenshot: false,
            },
        ],
    },
    {
        id: 2,
        party_id: 7,
        party_band: 'Customer — Kuwy',
        party_name: 'Kuwy Technology Service Pvt Ltd',
        file_no: 'F-00061',
        registration_no: 'BR05AS6323',
        received: '15-09-2026',
        work_type: 'HPT+TR+HPA',
        status: 'Paper Pendency',
        status_key: 'paper_pendency',
        remark: 'PUC Fail',
        update: 'Update',
        items: [
            { id: 21, work_type: 'HPT', status: 'paper_pendency', status_label: 'Paper Pendency', approved_on_iso: null, has_screenshot: false },
            { id: 22, work_type: 'TR', status: 'in_office', status_label: 'In Office', approved_on_iso: null, has_screenshot: true },
        ],
    },
    // A folder with nothing under it: no works, so nothing to move.
    {
        id: 3,
        party_id: 7,
        party_band: 'Customer — Kuwy',
        party_name: 'Kuwy Technology Service Pvt Ltd',
        file_no: 'F-00099',
        registration_no: 'BR06ZZ0001',
        received: '15-09-2026',
        work_type: 'HPT',
        status: 'In Office',
        status_key: 'in_office',
        remark: '',
        update: null,
        items: [],
    },
];

const COLUMNS = [
    { key: 'file_no', label: 'File No.' },
    { key: 'registration_no', label: 'Vehicle' },
    { key: 'status', label: 'Status', type: 'badge' },
    { key: 'remark', label: 'Remarks' },
    { key: 'update', label: 'Update', type: 'action', sortable: false, searchable: false, exportable: false },
];

const STATUSES = {
    in_office: 'In Office',
    paper_pendency: 'Paper Pendency',
    file_dispatch: 'File Dispatch',
    under_verification: 'Under Verification',
    approval_done: 'Approval Done',
    paper_returned: 'Paper Returned to Customer',
    cancelled: 'Cancelled',
};

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(WorkReport, {
        columns: COLUMNS,
        rows: ROWS,
        title: 'Customer-wise Work Report',
        sortable: false,
        action: '/admin/file/status',
        csrf: 'test-token',
        returnTo: '/admin/reports/files?party_id=7',
        jobStatuses: STATUSES,
        approvedKey: 'approval_done',
        reasonKeys: ['cancelled', 'paper_returned'],
        today: '2026-09-15',
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

// The dialog is teleported to the body, so it is never inside the host.
const panel = () => document.querySelector('.wu__panel');

const updateButtons = (host) =>
    [...host.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Update');

async function openRow(host, i) {
    updateButtons(host)[i].click();
    await nextTick();

    return panel();
}

const field = (name) => document.querySelector(`[name="${name}"]`);

const saveButton = () =>
    [...panel().querySelectorAll('button')].find((b) => b.textContent.trim().startsWith('Save'));

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    document.body.innerHTML = '';
    document.body.style.overflow = '';
});

describe('the update dialog on the work report', () => {
    it('is closed until a row asks for it', () => {
        mount();

        expect(panel()).toBe(null);
    });

    it('offers a button only on folders that have works to move', () => {
        const host = mount();

        // Three rows, two with works.
        expect(updateButtons(host).length).toBe(2);
    });

    it('opens over the report naming the file it is about', async () => {
        const host = mount();

        await openRow(host, 0);

        expect(panel()).not.toBe(null);
        expect(panel().textContent).toContain('F-00044');
        expect(panel().textContent).toContain('BR11BU1926');
        expect(panel().textContent).toContain('Kuwy Technology Service Pvt Ltd');
    });

    /*
     * Statuses belong to works, not folders: a transfer can be approved on
     * Tuesday with the hypothecation addition still pending on Friday. A dialog
     * with one select for a folder of three would move all of them together.
     */
    it('gives every work on the folder its own row', async () => {
        const host = mount();

        await openRow(host, 1);

        expect(panel().querySelectorAll('.wu__work').length).toBe(2);
        expect(field('statuses[21]')).not.toBe(null);
        expect(field('statuses[22]')).not.toBe(null);
    });

    it('starts each work on the status it is already at', async () => {
        const host = mount();

        await openRow(host, 1);

        expect(field('statuses[21]').value).toBe('paper_pendency');
        expect(field('statuses[22]').value).toBe('in_office');
    });

    /*
     * The field names are the status screen's, keyed by work. A different
     * spelling posts nothing the server reads, and the save silently does
     * nothing at all.
     */
    it('posts under the names the status screen already reads', async () => {
        const host = mount();

        await openRow(host, 0);

        const form = panel().querySelector('form');

        expect(form.getAttribute('action')).toBe('/admin/file/status');
        expect(form.getAttribute('method').toLowerCase()).toBe('post');
        // Multipart, or the screenshot an approval needs never arrives.
        expect(form.getAttribute('enctype')).toBe('multipart/form-data');

        expect(field('_token').value).toBe('test-token');
        expect(field('return_to').value).toBe('/admin/reports/files?party_id=7');
        expect(field('statuses[11]')).not.toBe(null);
        expect(field('remarks[11]')).not.toBe(null);
    });

    it('will not save when nothing has been changed', async () => {
        const host = mount();

        await openRow(host, 0);

        expect(saveButton().disabled).toBe(true);
        expect(panel().textContent).toContain('Nothing changed yet');
    });

    it('saves once a remark is typed, without moving anything', async () => {
        const host = mount();

        await openRow(host, 0);

        const remark = field('remarks[11]');
        remark.value = 'Chased the RTO today';
        remark.dispatchEvent(new window.Event('input'));
        await nextTick();

        expect(saveButton().disabled).toBe(false);
        expect(panel().textContent).toContain('1 remark added');
    });

    /*
     * The server refuses these without a reason. Saying so while the box is
     * still on screen saves the reader losing what they typed to a rule they
     * could have been told about.
     */
    it('holds the save until a cancellation says why', async () => {
        const host = mount();

        await openRow(host, 0);

        const status = field('statuses[11]');
        status.value = 'cancelled';
        status.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(saveButton().disabled, 'cancelled with no reason').toBe(true);
        expect(panel().textContent).toContain('needs a reason');

        const remark = field('remarks[11]');
        remark.value = 'Customer took the papers back';
        remark.dispatchEvent(new window.Event('input'));
        await nextTick();

        expect(saveButton().disabled).toBe(false);
    });

    /*
     * An approval is a thing that happened on a day with a document to show for
     * it. Both are asked for here rather than refused after the round trip.
     */
    it('asks for the date and the document when a work is approved', async () => {
        const host = mount();

        await openRow(host, 0);

        expect(field('approved_on[11]'), 'not asked for until it is needed').toBe(null);

        const status = field('statuses[11]');
        status.value = 'approval_done';
        status.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(field('approved_on[11]')).not.toBe(null);
        expect(field('screenshots[11]')).not.toBe(null);
        expect(field('approved_on[11]').getAttribute('max'), 'no approval in the future').toBe('2026-09-15');
    });

    it('says a document is already on file rather than demanding another', async () => {
        const host = mount();

        await openRow(host, 1);

        const status = field('statuses[22]');
        status.value = 'approval_done';
        status.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(panel().textContent).toContain('already on file');
    });

    it('closes on the button and on Escape', async () => {
        const host = mount();

        await openRow(host, 0);

        [...panel().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Cancel').click();
        await nextTick();
        expect(panel()).toBe(null);

        await openRow(host, 0);
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(panel()).toBe(null);
    });

    /* The page behind must not scroll under the dialog, and must get it back. */
    it('gives the page its scrolling back', async () => {
        const host = mount();

        await openRow(host, 0);
        expect(document.body.style.overflow).toBe('hidden');

        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();

        expect(document.body.style.overflow).toBe('');
    });

    /*
     * Opening a second file must not leave the first one's typing behind. The
     * dialog is one component reused, so the state has to be rebuilt each time.
     */
    it('forgets what was typed about the last file', async () => {
        const host = mount();

        await openRow(host, 0);

        const remark = field('remarks[11]');
        remark.value = 'Something about F-00044';
        remark.dispatchEvent(new window.Event('input'));
        await nextTick();

        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();

        await openRow(host, 1);
        expect(field('remarks[21]').value).toBe('');

        // And back again: the first file starts clean too.
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        await openRow(host, 0);

        expect(field('remarks[11]').value).toBe('');
    });
});
