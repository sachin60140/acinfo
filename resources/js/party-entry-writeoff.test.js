import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import PartyEntry from './components/PartyEntry.vue';

/*
 * Writing a difference off, on the Entry screen.
 *
 * The customer owes 5,000, pays 4,950, and the 50 is given up rather than left
 * on their statement. The server checks every rule again; what is checked here
 * is that the screen offers it only where it applies, asks why instead of
 * particulars, and will not send one that is over the office's limit or is not
 * wholly against the bill it closes.
 */

const PARTY = { id: 7, name: 'Arman Qadri', mobile: '9835230001', current_balance: 50 };

const BILLS = [
    { id: 50, fileNo: 'F-00050', vehicle: 'BR01JB8140', works: 'TR', received: '01-08-2026', charged: 5000, returned: 0, adjusted: 4950, open: 50, due: 50, ahead: 0, editUrl: '#' },
];

const mounted = [];

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ bills: BILLS, covered: 0 }),
    })));
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

    const app = createApp(PartyEntry, {
        action: '/admin/party/entry/customer',
        csrf: 'test-token',
        label: 'Customer',
        indexUrl: '/admin/parties/customer',
        statementUrl: '/admin/party/statement/__ID__',
        parties: [PARTY],
        paymentModes: ['Cash', 'UPI'],
        dateField: '<input type="hidden" name="txn_date" value="2026-09-20">',
        initial: { party_id: '', entry_type: 'debit', amount: '', payment_mode: '', ref_no: '', particular: '' },
        adjustable: true,
        paymentSide: 'credit',
        billsUrl: '/admin/party/bills/__ID__',
        initialAlloc: {},
        writeOffCap: 500,
        limitsUrl: '/admin/setup/limits',
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

async function pick(host, side = 'credit') {
    const select = host.querySelector('select[name="party_id"]');
    select.value = '7';
    select.dispatchEvent(new window.Event('change'));
    host.querySelector(`input[name="entry_type"][value="${side}"]`).click();
    await settle();
}

async function type(host, selector, value) {
    const input = host.querySelector(selector);
    input.value = String(value);
    input.dispatchEvent(new window.Event('input'));
    await nextTick();
}

const tick = (host) => host.querySelector('input[name="entry_kind"]');
const save = (host) => [...host.querySelectorAll('button[type="submit"]')].find((b) => b.textContent.includes('Save Entry'));
const posted = (host) => Object.fromEntries([...new FormData(host.querySelector('form')).entries()]);

describe('where it is offered', () => {
    it('on a payment, not on a charge', async () => {
        const host = mount();

        await pick(host, 'debit');
        expect(tick(host)).toBe(null);

        host.querySelector('input[name="entry_type"][value="credit"]').click();
        await settle();
        expect(tick(host)).not.toBe(null);
    });

    it('not at all while the office has set no limit', async () => {
        const host = mount({ writeOffCap: 0 });

        await pick(host);

        expect(tick(host)).toBe(null);
    });

    it('says the limit, and where to change it', async () => {
        const host = mount();
        await pick(host);

        expect(host.querySelector('.entry-writeoff').textContent).toContain('500.00');
        expect(host.querySelector('.entry-writeoff a').getAttribute('href')).toBe('/admin/setup/limits');
    });
});

describe('what it asks for', () => {
    it('asks why instead of particulars, and no payment mode', async () => {
        const host = mount();
        await pick(host);

        expect(host.querySelector('textarea[name="particular"]')).not.toBe(null);

        tick(host).click();
        await nextTick();

        expect(host.querySelector('textarea[name="particular"]')).toBe(null);
        expect(host.querySelector('select[name="payment_mode"]')).toBe(null);
        expect(host.querySelector('textarea[name="reason"]')).not.toBe(null);
        expect(host.textContent).toContain('The customer\'s statement says only Discount');
    });

    it('posts the kind, the reason and the bill it closes', async () => {
        const host = mount();
        await pick(host);

        tick(host).click();
        await nextTick();

        await type(host, 'input[name="amount"]', '50');
        await type(host, 'textarea[name="reason"]', 'Rounded off');
        await type(host, 'input[aria-label="Amount against F-00050"]', '50');

        const sent = posted(host);

        expect(sent.entry_kind).toBe('writeoff');
        expect(sent.reason).toBe('Rounded off');
        expect(sent['alloc[50][amount]']).toBe('50');
        expect(sent.particular).toBeUndefined();
        expect(save(host).disabled).toBe(false);
    });
});

describe('what it refuses before Save', () => {
    it('more than the office\'s limit', async () => {
        const host = mount();
        await pick(host);
        tick(host).click();
        await nextTick();

        await type(host, 'input[name="amount"]', '501');

        expect(save(host).disabled).toBe(true);
        expect(host.textContent).toContain('At most 500.00 can be written off at one time');
    });

    it('one that is not wholly against the bill it closes', async () => {
        const host = mount();
        await pick(host);
        tick(host).click();
        await nextTick();

        await type(host, 'input[name="amount"]', '50');

        expect(save(host).disabled).toBe(true);
        expect(host.textContent).toContain('Put the whole 50.00 against the bill it closes');

        await type(host, 'input[aria-label="Amount against F-00050"]', '50');

        expect(save(host).disabled).toBe(false);
    });

    /* A payment is not a write-off: it may still be left on account. */
    it('and asks none of that of an ordinary payment', async () => {
        const host = mount();
        await pick(host);

        await type(host, 'input[name="amount"]', '5000');

        expect(save(host).disabled).toBe(false);
    });
});

it('Reset puts the tick and the reason back as they were', async () => {
    const host = mount();
    await pick(host);

    tick(host).click();
    await nextTick();
    await type(host, 'textarea[name="reason"]', 'Rounded off');

    host.querySelector('form').dispatchEvent(new window.Event('reset', { cancelable: true }));
    await settle();

    expect(host.querySelector('textarea[name="reason"]')).toBe(null);
    expect(host.querySelector('textarea[name="particular"]')).not.toBe(null);
});
