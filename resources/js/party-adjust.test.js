import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import PartyAdjust from './components/PartyAdjust.vue';

/*
 * Setting or changing the files a payment already saved is for.
 *
 * The server checks every line again, against the files read as if this
 * payment had never been typed; checked here is that the screen asks for them
 * that way, starts with what the payment is for now, and only lets Save go
 * when something has changed and the files are on screen to be sent.
 */

const BILLS = [
    { id: 50, fileNo: 'F-00050', vehicle: 'BR01JB8140', works: 'TR', received: '01-08-2026', charged: 3000, returned: 0, adjusted: 0, open: 3000, due: 0, editUrl: '/admin/file/edit/50' },
    { id: 57, fileNo: 'F-00057', vehicle: 'BR01DN2536', works: 'HPA', received: '01-09-2026', charged: 5000, returned: 0, adjusted: 0, open: 5000, due: 5000, editUrl: '/admin/file/edit/57' },
];

const mounted = [];
let fetched;
let reply;

beforeEach(() => {
    fetched = [];
    reply = () => Promise.resolve({ ok: true, json: () => Promise.resolve({ bills: BILLS, covered: 1 }) });

    vi.stubGlobal('fetch', vi.fn((url) => {
        fetched.push(url);

        return reply(url);
    }));
});

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    vi.unstubAllGlobals();
});

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(PartyAdjust, {
        action: '/admin/party/adjust/31',
        csrf: 'test-token',
        label: 'Customer',
        statementUrl: '/admin/party/statement/7',
        billsUrl: '/admin/party/bills/__ID__',
        party: { id: 7, name: 'Arman Qadri', mobile: '9835230001' },
        entry: { id: 31, date: '10-09-2026', side: 'Cr', amount: 3000, mode: 'UPI', reference: 'UTR1', particular: 'Payment' },
        current: { 50: '3000.00' },
        currentLines: [{ id: 50, fileNo: 'F-00050', vehicle: 'BR01JB8140', amount: 3000, why: null }],
        initialAlloc: { 50: '3000.00' },
        drawn: 'print-of-what-was-drawn',
        history: null,
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const settle = async () => {
    for (let i = 0; i < 4; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
        await nextTick();
    }
};

async function type(host, selector, value) {
    const input = host.querySelector(selector);
    input.value = String(value);
    input.dispatchEvent(new window.Event('input'));
    await nextTick();
}

const box = (fileNo) => `input[aria-label="Amount against ${fileNo}"]`;
const save = (host) => [...host.querySelectorAll('button[type="submit"]')].find((b) => b.textContent.includes('Save'));
const posted = (host) => Object.fromEntries([...new FormData(host.querySelector('form')).entries()]);

describe('what it asks for', () => {
    it('asks for the files as if this payment had never been typed', async () => {
        mount();
        await settle();

        expect(fetched).toEqual(['/admin/party/bills/7?all=1&except=31']);
    });

    it('starts with what the payment is for now, covered file and all', async () => {
        const host = mount();
        await settle();

        // F-00050 is covered by money on account, but this payment's amount is on it.
        expect(host.querySelector(box('F-00050')).value).toBe('3000.00');
        expect(posted(host)['alloc[50][amount]']).toBe('3000.00');
    });
});

describe('when it can be saved', () => {
    it('not while nothing has changed', async () => {
        const host = mount();
        await settle();

        expect(save(host).disabled).toBe(true);
        expect(host.textContent).toContain('Nothing changed yet.');

        await type(host, box('F-00050'), '1000');
        await type(host, box('F-00057'), '2000');

        expect(save(host).disabled).toBe(false);
        expect(posted(host)['alloc[57][amount]']).toBe('2000');
    });

    it('not before the files are on screen, or every adjustment would be let go', async () => {
        reply = () => new Promise(() => {});
        const host = mount({ current: {}, initialAlloc: { 57: '1000' } });
        await settle();

        expect(save(host).disabled).toBe(true);
    });

    it('not when the files could not be loaded — and says so once, truly', async () => {
        reply = () => Promise.resolve({ ok: false, status: 500 });
        const host = mount({ current: {}, initialAlloc: { 57: '1000' } });
        await settle();

        expect(save(host).disabled).toBe(true);
        expect(host.textContent).toContain('nothing can be changed now');
        expect(host.textContent).not.toContain('can still be saved');
        expect(host.textContent.split('could not be loaded').length - 1).toBe(1);
    });

    it('not for more than the payment', async () => {
        const host = mount();
        await settle();

        await type(host, box('F-00057'), '1000');

        expect(save(host).disabled).toBe(true);
        expect(host.textContent).toContain('more than the payment');
    });

    it('once, however many times it is pressed', async () => {
        const host = mount();
        await settle();
        await type(host, box('F-00050'), '1000');

        const first = new window.Event('submit', { cancelable: true });
        const second = new window.Event('submit', { cancelable: true });

        host.querySelector('form').dispatchEvent(first);
        host.querySelector('form').dispatchEvent(second);
        await nextTick();

        expect(first.defaultPrevented).toBe(false);
        expect(second.defaultPrevented).toBe(true);
        expect(save(host).disabled).toBe(true);
    });
});

describe('the covered files', () => {
    it('offers no toggle for a covered file already drawn with its line', async () => {
        const host = mount();
        await settle();

        expect(host.querySelector('.adjust__toggle')).toBe(null);
    });

    it('says a vendor payment is money paid, a customer\'s money received', async () => {
        const vendor = mount({ current: {}, currentLines: [], initialAlloc: {}, entry: { id: 31, date: '10-09-2026', side: 'Dr', amount: 3000, mode: '', reference: '', particular: 'Paid' } });
        const customer = mount({ current: {}, currentLines: [], initialAlloc: {} });
        await settle();

        expect(vendor.textContent).toContain('covered by money paid before this payment');
        expect(customer.textContent).toContain('covered by money received before this payment');
    });
});

describe('what it posts besides the files', () => {
    it('posts back what the page was drawn from', async () => {
        const host = mount();
        await settle();

        expect(posted(host).drawn).toBe('print-of-what-was-drawn');
    });

    it('says when its files were last changed', async () => {
        const host = mount({ history: 'Files changed 21-09-2026 by Ravi — was F-00061 3,000.00' });
        await settle();

        expect(host.textContent).toContain('Files changed 21-09-2026 by Ravi');
    });
});

describe('a line on a file no longer open', () => {
    const cancelled = {
        current: { 99: '500.00' },
        currentLines: [{ id: 99, fileNo: 'F-00099', vehicle: 'BR01ZZ0099', amount: 500, why: 'Not charged to this customer now — cancelled, or moved.' }],
        initialAlloc: { 99: '500.00' },
    };

    it('is drawn, kept and posted — never taken off by the page', async () => {
        const host = mount(cancelled);
        await settle();

        expect(host.textContent).toContain('F-00099');
        expect(host.textContent).toContain('Not charged to this customer now');
        expect(host.textContent).not.toContain('no longer open, and has been taken off');
        expect(posted(host)['alloc[99][amount]']).toBe('500.00');
        expect(save(host).disabled).toBe(true);
    });

    it('can be lowered or let go, not raised', async () => {
        const host = mount(cancelled);
        await settle();

        await type(host, box('F-00099'), '200');
        expect(save(host).disabled).toBe(false);

        await type(host, box('F-00099'), '');
        expect(save(host).disabled).toBe(false);
        expect(posted(host)['alloc[99][amount]']).toBeUndefined();

        await type(host, box('F-00099'), '600');
        expect(save(host).disabled).toBe(true);
        expect(host.textContent).toContain('can be kept or lowered, not raised');
    });

    it('Clear empties it too; Fill oldest first leaves it and does not give it twice', async () => {
        const host = mount({
            ...cancelled,
            current: { 50: '2500.00', 99: '500.00' },
            currentLines: [{ id: 50, fileNo: 'F-00050', vehicle: '', amount: 2500, why: null }, cancelled.currentLines[0]],
            initialAlloc: { 50: '2500.00', 99: '500.00' },
        });
        await settle();

        const button = (label) => [...host.querySelectorAll('button')].find((b) => b.textContent.trim().includes(label));

        button('Fill oldest first').click();
        await nextTick();

        // 3,000 less the 500 kept goes on the one file still due.
        expect(posted(host)['alloc[99][amount]']).toBe('500.00');
        expect(posted(host)['alloc[57][amount]']).toBe('2500.00');
        expect(host.textContent).not.toContain('more than the payment');

        button('Clear').click();
        await nextTick();

        expect(posted(host)['alloc[99][amount]']).toBeUndefined();
        expect(posted(host)['alloc[57][amount]']).toBeUndefined();
    });

    it('counts toward the payment, so the files cannot come to more than it', async () => {
        const host = mount({ ...cancelled, entry: { id: 31, date: '10-09-2026', side: 'Cr', amount: 3000, mode: '', reference: '', particular: 'Payment' } });
        await settle();

        await type(host, box('F-00057'), '2600');

        expect(save(host).disabled).toBe(true);
        expect(host.textContent).toContain('more than the payment');
    });
});

it('lets a line stay above what is open when the payment has it already', async () => {
    const tight = [{ ...BILLS[1], open: 2000, due: 2000 }];
    reply = () => Promise.resolve({ ok: true, json: () => Promise.resolve({ bills: tight, covered: 0 }) });

    const host = mount({
        entry: { id: 31, date: '10-09-2026', side: 'Cr', amount: 9000, mode: '', reference: '', particular: 'Payment' },
        current: { 57: '5000.00' },
        currentLines: [{ id: 57, fileNo: 'F-00057', vehicle: 'BR01DN2536', amount: 5000, why: null }],
        initialAlloc: { 57: '5000.00' },
    });
    await settle();

    expect(host.textContent).toContain('This payment has');
    expect(host.textContent).not.toContain('more than is open');

    await type(host, box('F-00057'), '5000.50');
    expect(host.textContent).toContain('more than is open');
});

it('Reset puts back what the page loaded with', async () => {
    const host = mount();
    await settle();

    await type(host, box('F-00050'), '100');
    host.querySelector('form').dispatchEvent(new window.Event('reset', { cancelable: true }));
    await settle();

    expect(host.querySelector(box('F-00050')).value).toBe('3000.00');
    expect(save(host).disabled).toBe(true);
});
