import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import PartyEntry from './components/PartyEntry.vue';
import { receiptMessage } from './customerShare';

/*
 * Setting a customer off against their own vendor account, on the Entry
 * screen.
 *
 * The dealer owes 5,000 as a customer and is owed 3,000 as a vendor. The
 * server checks every rule again under a lock; what is checked here is that
 * the screen offers it only for accounts the office linked, says how much can
 * go, keeps each account's files under its own name, and will not send one
 * that is more than either side owes.
 */

const DEALER = { id: 7, name: 'Arman Qadri', mobile: '9835230001', current_balance: 5000 };
const OTHER = { id: 8, name: 'Nobody Linked', mobile: '9835230002', current_balance: 900 };

// The dealer's vendor account: the office owes it 3,000 (Cr).
const LINKED = { 7: { id: 9, name: 'Arman Works', balance: -3000, active: true } };

const BILLS = {
    7: [{ id: 50, fileNo: 'F-00050', vehicle: 'BR01JB8140', works: 'TR', received: '01-08-2026', charged: 5000, returned: 0, adjusted: 0, open: 5000, due: 5000, ahead: 0, editUrl: '#' }],
    9: [{ id: 60, fileNo: 'F-00060', vehicle: 'BR06XY1111', works: 'HPA', received: '02-08-2026', charged: 3000, returned: 0, adjusted: 0, open: 3000, due: 3000, ahead: 0, editUrl: '#' }],
};

const mounted = [];

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn((url) => {
        const id = Number(String(url).match(/bills\/(\d+)/)?.[1]);

        return Promise.resolve({ ok: true, json: () => Promise.resolve({ bills: BILLS[id] ?? [], covered: 0 }) });
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

    const app = createApp(PartyEntry, {
        action: '/admin/party/entry/customer',
        csrf: 'test-token',
        label: 'Customer',
        indexUrl: '/admin/parties/customer',
        statementUrl: '/admin/party/statement/__ID__',
        parties: [DEALER, OTHER],
        paymentModes: ['Cash', 'UPI'],
        dateField: '<input type="hidden" name="txn_date" value="2026-09-20">',
        initial: { party_id: '', entry_type: 'debit', amount: '', payment_mode: '', ref_no: '', particular: '', entry_kind: '', reason: '' },
        adjustable: true,
        paymentSide: 'credit',
        billsUrl: '/admin/party/bills/__ID__',
        initialAlloc: {},
        writeOffCap: 500,
        limitsUrl: '/admin/setup/limits',
        settable: true,
        counterparts: LINKED,
        initialCounterAlloc: {},
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

async function pick(host, id = '7', side = 'credit') {
    const select = host.querySelector('select[name="party_id"]');
    select.value = id;
    select.dispatchEvent(new window.Event('change'));
    host.querySelector(`input[name="entry_type"][value="${side}"]`).click();
    await settle();
}

async function type(host, selector, value) {
    const input = host.querySelector(selector);
    input.value = String(value);
    input.dispatchEvent(new window.Event('input'));
    await settle();
}

const setOffTick = (host) => host.querySelector('input[name="entry_kind"][value="setoff"]');
const writeOffTick = (host) => host.querySelector('input[name="entry_kind"][value="writeoff"]');
const save = (host) => [...host.querySelectorAll('button[type="submit"]')].find((b) => b.textContent.includes('Save Entry'));
const posted = (host) => [...new FormData(host.querySelector('form')).entries()];
const postedObject = (host) => Object.fromEntries(posted(host));

async function tickSetOff(host) {
    setOffTick(host).click();
    await settle();
}

describe('where it is offered', () => {
    it('for a customer the office linked, on a payment', async () => {
        const host = mount();

        await pick(host);

        expect(setOffTick(host)).not.toBe(null);
        expect(host.textContent).toContain('Arman Works');
    });

    it('not on a charge', async () => {
        const host = mount();

        await pick(host, '7', 'debit');

        expect(setOffTick(host)).toBe(null);
    });

    it('not for a customer with no linked account', async () => {
        const host = mount();

        await pick(host, '8');

        expect(setOffTick(host)).toBe(null);
    });

    it('not while the linked account is inactive', async () => {
        const host = mount({ counterparts: { 7: { ...LINKED[7], active: false } } });

        await pick(host);

        expect(setOffTick(host)).toBe(null);
    });

    it('not before the database has what it needs', async () => {
        const host = mount({ settable: false });

        await pick(host);

        expect(setOffTick(host)).toBe(null);
    });

    it('says what each side stands at, and the most that can go', async () => {
        const host = mount();

        await pick(host);

        expect(host.textContent).toContain('owes 5,000.00 as a customer');
        expect(host.textContent).toContain('we owe 3,000.00 as a vendor');
        expect(host.textContent).toContain('at most 3,000.00 can be set off');
    });
});

describe('what it posts', () => {
    it('the kind and the account it was drawn for, and none of a payment\'s fields', async () => {
        const host = mount();
        await pick(host);
        await type(host, '#amount', 2000);

        await tickSetOff(host);

        const form = postedObject(host);
        expect(form.entry_kind).toBe('setoff');
        expect(form.counterpart_id).toBe('9');
        expect(form).not.toHaveProperty('payment_mode');
        expect(form).not.toHaveProperty('ref_no');
        expect(form).not.toHaveProperty('particular');

        // A remark, optional.
        const remark = host.querySelector('textarea[name="reason"]');
        expect(remark).not.toBe(null);
        expect(remark.required).toBe(false);
        expect(save(host).disabled).toBe(false);
    });

    it('each account\'s files under its own name, fetched for that account', async () => {
        const host = mount();
        await pick(host);
        await type(host, '#amount', 2000);
        await tickSetOff(host);

        expect(fetch.mock.calls.some(([url]) => String(url).includes('/bills/9'))).toBe(true);

        const boxes = [...host.querySelectorAll('.adjust input[type="number"]')];
        expect(boxes).toHaveLength(2);

        boxes[0].value = '2000';
        boxes[0].dispatchEvent(new window.Event('input'));
        boxes[1].value = '1500';
        boxes[1].dispatchEvent(new window.Event('input'));
        await settle();

        const form = postedObject(host);
        expect(form['alloc[50][amount]']).toBe('2000');
        expect(form['counter_alloc[60][amount]']).toBe('1500');
        expect(form).not.toHaveProperty('alloc[60][amount]');
        expect(form).not.toHaveProperty('counter_alloc[50][amount]');
    });

    it('write off and set off are one or the other', async () => {
        const host = mount();
        await pick(host);
        await type(host, '#amount', 50);

        writeOffTick(host).click();
        await settle();
        expect(postedObject(host).entry_kind).toBe('writeoff');

        await tickSetOff(host);
        expect(writeOffTick(host).checked).toBe(false);
        expect(posted(host).filter(([name]) => name === 'entry_kind')).toEqual([['entry_kind', 'setoff']]);
    });
});

describe('what it will not send', () => {
    it('more than either side owes', async () => {
        const host = mount();
        await pick(host);
        await type(host, '#amount', 3500);
        await tickSetOff(host);

        expect(save(host).disabled).toBe(true);
        expect(host.textContent).toContain('At most 3,000.00 can be set off');

        await type(host, '#amount', 3000);
        expect(save(host).disabled).toBe(false);
    });

    it('anything, when one side owes nothing', async () => {
        const host = mount({ parties: [{ ...DEALER, current_balance: 0 }, OTHER] });
        await pick(host);
        await type(host, '#amount', 100);
        await tickSetOff(host);

        expect(save(host).disabled).toBe(true);
        expect(host.textContent).toContain('owes nothing as a customer');
    });

    /*
     * The tick goes with the side, even where nothing can be written off.
     * Found in writing it: the one watch there was cleared the kind only when
     * a write-off stopped being possible, which with no limit set is never.
     */
    it('a set-off left ticked after switching to a charge', async () => {
        const host = mount({ writeOffCap: 0 });
        await pick(host);
        await type(host, '#amount', 1000);
        await tickSetOff(host);

        host.querySelector('input[name="entry_type"][value="debit"]').click();
        await settle();

        expect(posted(host).some(([name]) => name === 'entry_kind')).toBe(false);
        expect(host.querySelector('textarea[name="particular"]')).not.toBe(null);

        // Back on a payment, it is a payment until ticked again.
        host.querySelector('input[name="entry_type"][value="credit"]').click();
        await settle();

        expect(setOffTick(host).checked).toBe(false);
        expect(posted(host).some(([name]) => name === 'entry_kind')).toBe(false);
    });
});

describe('from the vendor account', () => {
    it('the same limit, read the other way round', async () => {
        const host = mount({
            action: '/admin/party/entry/vendor',
            label: 'Vendor',
            paymentSide: 'debit',
            writeOffCap: 0,
            parties: [{ id: 9, name: 'Arman Works', mobile: '9835230001', current_balance: -3000 }],
            counterparts: { 9: { id: 7, name: 'Arman Qadri', balance: 1200, active: true } },
            initial: { party_id: '', entry_type: 'credit', amount: '', payment_mode: '', ref_no: '', particular: '', entry_kind: '', reason: '' },
        });

        await pick(host, '9', 'debit');
        await type(host, '#amount', 1500);
        await tickSetOff(host);

        // The customer owes only 1,200: that is the most.
        expect(host.textContent).toContain('at most 1,200.00 can be set off');
        expect(save(host).disabled).toBe(true);

        await type(host, '#amount', 1200);
        expect(save(host).disabled).toBe(false);
        expect(postedObject(host).counterpart_id).toBe('7');
        // The customer's account moves the other way: 1,200 Dr to nothing.
        expect(host.textContent).toContain('Arman Qadri on 0.00');
    });
});

describe('Reset', () => {
    it('puts back a refused save\'s amounts on both accounts', async () => {
        const host = mount({
            initial: { party_id: '7', entry_type: 'credit', amount: '2000', payment_mode: '', ref_no: '', particular: '', entry_kind: 'setoff', reason: '' },
            initialAlloc: { 50: '2000' },
            initialCounterAlloc: { 60: '1500' },
        });
        await settle();

        expect(postedObject(host)['counter_alloc[60][amount]']).toBe('1500');

        const boxes = [...host.querySelectorAll('.adjust input[type="number"]')];
        boxes[1].value = '';
        boxes[1].dispatchEvent(new window.Event('input'));
        await settle();
        expect(postedObject(host)).not.toHaveProperty('counter_alloc[60][amount]');

        host.querySelector('form').dispatchEvent(new window.Event('reset'));
        await settle();

        expect(postedObject(host)['counter_alloc[60][amount]']).toBe('1500');
        expect(postedObject(host).entry_kind).toBe('setoff');
    });
});

describe('the message the customer is offered', () => {
    const base = { name: 'Arman Qadri', amount: 3000, dateLabel: '20-09-2026', balance: 2000, todayLabel: '23-09-2026' };

    it('says what it is, in the owner\'s words', () => {
        const text = receiptMessage({ ...base, kind: 'setoff', against: [{ label: 'BR01JB8140 (TR)', amount: 3000 }] });

        expect(text).toContain('*Account adjusted — Arman Qadri*');
        expect(text).toContain('₹3,000.00 adjusted against payment due to you on 20-09-2026');
        expect(text).toContain('• BR01JB8140 (TR) — ₹3,000.00');
        expect(text).toContain('Balance due as of 23-09-2026: ₹2,000.00');
    });

    it('never that anything was received, nor a mode, nor a reference', () => {
        const text = receiptMessage({ ...base, kind: 'setoff', mode: 'Set-off', reference: 'Arman Works' });

        expect(text).not.toMatch(/received/i);
        expect(text).not.toContain('Set-off');
        expect(text).not.toContain('Arman Works');
        expect(text).not.toMatch(/vendor/i);
    });

    it('a payment is still a payment', () => {
        expect(receiptMessage({ ...base, mode: 'UPI' })).toContain('*Payment received — Arman Qadri*');
    });
});
