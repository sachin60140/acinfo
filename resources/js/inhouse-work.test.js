import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import InHouseWork from './components/InHouseWork.vue';

/*
 * Moving the office's own work along from In-house Work.
 *
 * The dialog is the Work Report's and is tested there; what is checked here is
 * the list's side of it — each row opens on its own one work, and the save
 * posts to Update Status and comes back to this list.
 */

const mounted = [];

const row = (id, over = {}) => ({
    id,
    file_id: id * 10,
    file_no: `F-000${id}`,
    registration_no: `BR01IH${1000 + id}`,
    customer: 'Arman Qadri',
    party_name: 'Arman Qadri',
    work: 'TR',
    received: '01-09-2026',
    status: 'In Office',
    status_key: 'in_office',
    charged: 3000,
    update: 'Update',
    items: [{ id, work_type: 'TR', status: 'in_office', status_label: 'In Office', approved_on_iso: null, has_screenshot: false }],
    ...over,
});

const ROWS = [
    row(51),
    row(52, { work: 'HPA', items: [{ id: 52, work_type: 'HPA', status: 'under_verification', status_label: 'Under Verification', approved_on_iso: null, has_screenshot: false }] }),
];

const COLUMNS = [
    { key: 'file_no', label: 'File No.' },
    { key: 'work', label: 'Work' },
    { key: 'status', label: 'Status', type: 'badge' },
    { key: 'update', label: 'Update', type: 'action', sortable: false, searchable: false, exportable: false },
];

function mount() {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(InHouseWork, {
        columns: COLUMNS,
        rows: ROWS,
        action: '/admin/file/status',
        csrf: 'test-token',
        returnTo: '/admin/file/in-house',
        jobStatuses: { in_office: 'In Office', under_verification: 'Under Verification', approval_done: 'Approval Done', cancelled: 'Cancelled' },
        approvedKey: 'approval_done',
        reasonKeys: ['cancelled', 'paper_returned'],
        today: '2026-09-21',
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    document.body.innerHTML = '';
    document.body.style.overflow = '';
});

// The dialog is teleported to the body, so it is never inside the host.
const panel = () => document.querySelector('.wu__panel');
const updateButtons = (host) => [...host.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Update');

describe('updating from In-house Work', () => {
    it('offers Update on every row, and opens nothing until one is pressed', () => {
        const host = mount();

        expect(updateButtons(host)).toHaveLength(2);
        expect(panel()).toBe(null);
    });

    it('opens on that row\'s one work, and only that one', async () => {
        const host = mount();

        updateButtons(host)[1].click();
        await nextTick();

        expect(panel().textContent).toContain('F-00052');
        expect(document.querySelector('[name="statuses[52]"]')).not.toBe(null);
        expect(document.querySelector('[name="statuses[51]"]')).toBe(null);
    });

    it('posts to Update Status and asks to come back to this list', async () => {
        const host = mount();

        updateButtons(host)[0].click();
        await nextTick();

        const form = panel().closest('form') ?? panel().querySelector('form');

        expect(form.getAttribute('action')).toBe('/admin/file/status');
        expect(document.querySelector('[name="return_to"]').value).toBe('/admin/file/in-house');
        expect(document.querySelector('[name="_token"]').value).toBe('test-token');
    });
});
