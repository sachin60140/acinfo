import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import GiveToVendor from './components/GiveToVendor.vue';

/*
 * Handing over one work at a time.
 *
 * A folder holding a transfer and a hypothecation addition need not send both
 * to the same person — one agent is quick with transfers, another has the bank
 * contact — so the tick is per work, and the folder's own box above them is a
 * shorthand for all of their ticks at once.
 *
 * The half that has already gone still shows, saying who has it. It is the only
 * way to see, from the row, that the folder in front of you is half empty.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const work = (id, name, over = {}) => ({
    id,
    work_type_id: 2,
    work_type: name,
    customer_amount: 3000,
    vendor_rate: 1800,
    state: 'here',
    status_label: 'File Dispatch',
    vendor: null,
    vendor_date: null,
    vendor_amount: null,
    papers: 'ready',
    papers_pending: null,
    ...over,
});

const file = (id, items, over = {}) => ({
    id,
    file_no: `F-000${id}`,
    registration_no: `BR06GG${1400 + id}`,
    received_date: '14-08-2026',
    description: '',
    customer: 'Car4Sales',
    customer_amount: 5600,
    papers: 'ready',
    papers_note: null,
    papers_url: `/admin/file/${id}/papers`,
    items,
    ...over,
});

// Two works, both still on the desk.
const WHOLE = file(1, [work(11, 'TR'), work(12, 'HPA', { vendor_rate: null })]);

// One work already with somebody, one still here.
const HALF = file(2, [
    work(21, 'TR'),
    work(22, 'HPA', {
        state: 'out',
        vendor: 'Dabloo Ji Muzaffarpur',
        vendor_date: '02-09-2026',
        vendor_amount: 1200,
    }),
]);

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(GiveToVendor, {
        files: [WHOLE, HALF],
        vendors: [{ id: 6, name: 'Sharma Ji', mobile: '9835630000', current_balance: 0 }],
        action: '/admin/file/assign',
        csrf: 'token',
        cancelUrl: '/admin/files',
        vendorId: 6,
        vendorDate: '2026-09-08',
        vendorDateDisplay: '08-09-2026',
        pickedFiles: [],
        pickedJobs: [],
        oldAmounts: {},
        oldOverrides: {},
        rateHistory: [],
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const box = (host, name, value) => host.querySelector(`input[name="${name}"][value="${value}"]`);
const fileBox = (host, id) => box(host, 'files[]', id);
const workBox = (host, id) => box(host, 'jobs[]', id);

const postedFiles = (host) =>
    [...host.querySelectorAll('input[name="files[]"]')].filter((b) => b.checked).map((b) => Number(b.value)).sort();

const postedWork = (host) =>
    [...host.querySelectorAll('input[name="jobs[]"]')].filter((b) => b.checked).map((b) => Number(b.value)).sort();

const amount = (host, id) => host.querySelector(`input[name="amounts[${id}]"]`);
const foot = (host) => host.querySelector('.ui-card__foot').textContent;

async function click(el) {
    el.click();
    await nextTick();
}

describe('giving one work at a time', () => {
    it('posts the work that is going, and the folder it came out of', async () => {
        const host = mount();

        await click(workBox(host, 11));

        expect(postedWork(host)).toEqual([11]);
        // files[] has to name the folder, or the server never looks inside it.
        expect(postedFiles(host)).toEqual([1]);
    });

    it('takes the whole folder from the box above it', async () => {
        const host = mount();

        await click(fileBox(host, 1));

        expect(postedWork(host)).toEqual([11, 12]);
        expect(postedFiles(host)).toEqual([1]);
    });

    it('lets the folder go once its last work is unticked', async () => {
        const host = mount();

        await click(fileBox(host, 1));
        await click(workBox(host, 11));

        expect(postedWork(host)).toEqual([12]);
        expect(postedFiles(host)).toEqual([1], 'the folder left while work on it was still going');

        await click(workBox(host, 12));

        expect(postedWork(host)).toEqual([]);
        expect(postedFiles(host)).toEqual([]);
    });

    it('unticking the folder takes its work with it', async () => {
        const host = mount();

        await click(fileBox(host, 1));
        await click(fileBox(host, 1));

        expect(postedWork(host)).toEqual([]);
        expect(postedFiles(host)).toEqual([]);
    });

    /* The one thing somebody can get wrong without noticing. */
    it('half-ticks the folder while only some of its work is going', async () => {
        const host = mount();

        await click(workBox(host, 11));

        expect(fileBox(host, 1).indeterminate).toBe(true);
        expect(foot(host)).toContain('going in part');

        await click(workBox(host, 12));

        expect(fileBox(host, 1).indeterminate).toBe(false);
        expect(foot(host)).not.toContain('going in part');
    });

    /* Said only where it differs: "1 file, 1 work" tells nobody anything. */
    it('counts the works going out, not the works in the folder', async () => {
        const host = mount();

        await click(fileBox(host, 1));
        await click(workBox(host, 21));

        // Three works on the two folders are here; the fourth is already gone.
        expect(foot(host)).toContain('2 files going out');
        expect(foot(host)).toContain('3 works');

        const only = mount();

        await click(workBox(only, 11));

        expect(foot(only)).toContain('1 file going out');
        expect(foot(only)).not.toContain('1 work,');
    });
});

describe('work that is already with somebody', () => {
    it('offers no tick for it, and says who has it', () => {
        const host = mount();

        expect(workBox(host, 22)).toBeNull();

        const row = fileBox(host, 2).closest('tr');

        expect(row.textContent).toContain('with Dabloo Ji Muzaffarpur');
        expect(row.textContent).toContain('since 02-09-2026');
    });

    it('is never swept in by the folder box above it', async () => {
        const host = mount();

        await click(fileBox(host, 2));

        expect(postedWork(host)).toEqual([21]);
        // Everything still here is going, so the folder reads as whole.
        expect(fileBox(host, 2).indeterminate).toBe(false);
    });

    it('shows what it already costs, with nothing to type into', () => {
        const host = mount();

        expect(amount(host, 22)).toBeNull();
        expect(fileBox(host, 2).closest('tr').textContent).toContain('1,200');
    });
});

describe('the rate follows its own work', () => {
    it('leaves a box disabled until that work is ticked', async () => {
        const host = mount();

        expect(amount(host, 11).disabled).toBe(true);
        expect(amount(host, 12).disabled).toBe(true);

        await click(workBox(host, 11));

        expect(amount(host, 11).disabled).toBe(false);
        // A rate on work that is staying here must not reach the ledger.
        expect(amount(host, 12).disabled).toBe(true);
    });

    it('fills in the usual rate for the work that is ticked, and no other', async () => {
        const host = mount();

        await click(workBox(host, 11));

        expect(amount(host, 11).value).toBe('1800.00');
        expect(amount(host, 12).value).toBe('');
    });

    it('totals only the work going out', async () => {
        const host = mount();

        await click(workBox(host, 11));
        await click(workBox(host, 21));

        // 1800 each, and never the 1200 already agreed with the other vendor.
        expect(host.querySelector('.give-total__value').textContent).toContain('3,600');
    });
});

describe('a bounced save', () => {
    it('comes back ticking the work it was sent with', () => {
        const host = mount({ pickedFiles: [1], pickedJobs: [12] });

        expect(postedWork(host)).toEqual([12]);
        expect(postedFiles(host)).toEqual([1]);
        expect(fileBox(host, 1).indeterminate).toBe(true);
    });
});
