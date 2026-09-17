import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import GiveToVendor from './components/GiveToVendor.vue';

/*
 * Give to Vendor, once papers have to be ready first.
 *
 * A file whose papers are not checked, or are still pending, says so under its
 * number with a link to its checklist. It can still be ticked — but then it
 * asks for a reason and the batch cannot be sent without one. And Select all
 * never sweeps such a file in: sending one early is a decision made one file
 * at a time.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const FILE = (id, papers, note = null) => ({
    id,
    file_no: `F-000${id}`,
    registration_no: `BR01AB${1000 + id}`,
    received_date: '07-09-2026',
    description: '',
    customer: 'Car4Sales',
    customer_amount: 5000,
    papers,
    papers_note: note,
    papers_url: `/admin/file/${id}/papers`,
    items: [{ id: id * 10, work_type_id: 1, work_type: 'TR', customer_amount: 5000, vendor_rate: 3000 }],
});

const FILES = [
    FILE(1, 'ready'),
    FILE(2, 'pending', 'Papers pending: Form 30'),
    FILE(3, 'to_check', 'Papers not checked yet'),
];

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(GiveToVendor, {
        files: FILES,
        vendors: [{ id: 6, name: 'Dabloo Ji', mobile: '9835630000', current_balance: 0 }],
        action: '/admin/file/assign',
        csrf: 'token',
        cancelUrl: '/admin/files',
        vendorId: 6,
        vendorDate: '2026-09-08',
        vendorDateDisplay: '08-09-2026',
        pickedFiles: [],
        oldAmounts: {},
        rateHistory: [],
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const rowOf = (host, id) => host.querySelector(`input[name="files[]"][value="${id}"]`).closest('tr');
const reason = (host, id) => host.querySelector(`input[name="overrides[${id}]"]`);
const submit = (host) => host.querySelector('button[type="submit"]');
const ticked = (host) => [...host.querySelectorAll('input[name="files[]"]')].filter((b) => b.checked).map((b) => Number(b.value)).sort();
const selectAll = (host) => [...host.querySelectorAll('input[type="checkbox"]')].find((el) => ! el.closest('.give-pick'));

async function tick(host, id) {
    rowOf(host, id).querySelector('input[name="files[]"]').click();
    await nextTick();
}

describe('papers before dispatch', () => {
    it('says why a file is not ready, and links to its checklist', () => {
        const host = mount();

        const pending = rowOf(host, 2).querySelector('.give-papers a');
        expect(pending.textContent).toBe('Papers pending: Form 30');
        expect(pending.getAttribute('href')).toBe('/admin/file/2/papers');

        expect(rowOf(host, 3).querySelector('.give-papers').textContent).toContain('Papers not checked yet');
        expect(rowOf(host, 1).querySelector('.give-papers')).toBeNull();
    });

    it('sends a ready file with no questions', async () => {
        const host = mount();

        await tick(host, 1);

        expect(reason(host, 1)).toBeNull();
        expect(submit(host).disabled).toBe(false);
    });

    it('asks for a reason once an unready file is ticked, and waits for one', async () => {
        const host = mount();

        expect(reason(host, 2)).toBeNull();

        await tick(host, 2);

        expect(reason(host, 2)).not.toBeNull();
        expect(submit(host).disabled).toBe(true);
        expect(host.querySelector('.ui-card__foot').textContent).toContain('needs a reason');

        reason(host, 2).value = 'RTO will take it at verification';
        reason(host, 2).dispatchEvent(new Event('input'));
        await nextTick();

        expect(submit(host).disabled).toBe(false);
    });

    it('stops posting the reason when the file is unticked', async () => {
        const host = mount();

        await tick(host, 2);
        await tick(host, 2);

        expect(reason(host, 2)).toBeNull();
        expect(submit(host).disabled).toBe(false);
    });

    it('keeps a reason given before a bounced save', () => {
        const host = mount({ pickedFiles: [2], oldOverrides: { 2: 'Kept reason' } });

        expect(reason(host, 2).value).toBe('Kept reason');
    });

    it('never sweeps an unready file in with Select all', async () => {
        const host = mount();

        selectAll(host).click();
        await nextTick();

        expect(ticked(host)).toEqual([1]);
        expect(submit(host).disabled).toBe(false);
    });

    it('treats a file with no paper state at all as ready', async () => {
        const host = mount({ files: [{ ...FILE(4, undefined), papers: undefined }] });

        await tick(host, 4);

        expect(reason(host, 4)).toBeNull();
    });
});
