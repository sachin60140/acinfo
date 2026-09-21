import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import PartyEntry from './components/PartyEntry.vue';
import { receiptMessage } from './customerShare';

/*
 * Adjusting a payment against files, on the Entry screen.
 *
 * The server checks every line again and refuses what does not fit; what is
 * checked here is that the screen offers it only where it applies, posts it
 * under the names the server reads, and says what is wrong before Save is
 * pressed rather than after a round trip that costs the reader their typing.
 */

const PARTIES = [
    { id: 7, name: 'Arman Qadri', mobile: '9835230001', current_balance: 8000 },
    { id: 9, name: 'Rakesh Madhubani', mobile: '9431000002', current_balance: 2000 },
];

const BILLS = {
    7: [
        { id: 50, fileNo: 'F-00050', vehicle: 'BR01JB8140', works: 'TR', received: '01-08-2026', charged: 3000, returned: 0, adjusted: 0, open: 3000, due: 3000, editUrl: '/admin/file/edit/50' },
        { id: 57, fileNo: 'F-00057', vehicle: 'BR01DN2536', works: 'HPA', received: '01-09-2026', charged: 5000, returned: 0, adjusted: 0, open: 5000, due: 5000, editUrl: '/admin/file/edit/57' },
    ],
    9: [
        { id: 61, fileNo: 'F-00061', vehicle: 'BR32RK7000', works: 'TR', received: '05-09-2026', charged: 2000, returned: 0, adjusted: 0, open: 2000, due: 2000, editUrl: '/admin/file/edit/61' },
    ],
};

const mounted = [];
let fetched;

function answer(partyId, delay = 0, all = false) {
    const bills = (BILLS[partyId] ?? []).filter((bill) => all || bill.due > 0.005);
    const covered = (BILLS[partyId] ?? []).filter((bill) => bill.due <= 0.005 && bill.open > 0.005).length;

    return new Promise((resolve) => setTimeout(() => resolve({
        ok: true,
        json: () => Promise.resolve({ bills, covered }),
    }), delay));
}

beforeEach(() => {
    fetched = [];

    vi.stubGlobal('fetch', vi.fn((url) => {
        fetched.push(url);
        const id = Number(String(url).split('?')[0].split('/').pop());

        return answer(id, 0, String(url).includes('all=1'));
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
        parties: PARTIES,
        paymentModes: ['Cash', 'UPI'],
        dateField: '<input type="hidden" name="txn_date" value="2026-09-21">',
        initial: { party_id: '', entry_type: 'debit', amount: '', payment_mode: '', ref_no: '', particular: '' },
        adjustable: true,
        paymentSide: 'credit',
        billsUrl: '/admin/party/bills/__ID__',
        initialAlloc: {},
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

async function pick(host, partyId, side = 'credit') {
    const select = host.querySelector('select[name="party_id"]');
    select.value = String(partyId);
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

const section = (host) => host.querySelector('.adjust');
const box = (fileNo) => `input[aria-label="Amount against ${fileNo}"]`;
const saveButton = (host) => [...host.querySelectorAll('button[type="submit"]')].find((b) => b.textContent.includes('Save Entry'));

describe('where it is offered', () => {
    it('is not offered on a charge, only on a payment', async () => {
        const host = mount();

        await pick(host, 7, 'debit');
        expect(section(host)).toBe(null);

        host.querySelector('input[name="entry_type"][value="credit"]').click();
        await settle();
        expect(section(host)).not.toBe(null);
    });

    it('asks for that party\'s open files', async () => {
        const host = mount();

        await pick(host, 7);

        expect(fetched.at(-1)).toBe('/admin/party/bills/7?all=1');
        expect(section(host).textContent).toContain('F-00050');
        expect(section(host).textContent).toContain('F-00057');
    });

    it('is not offered before the database has what it needs', async () => {
        const host = mount({ adjustable: false });

        await pick(host, 7);

        expect(section(host)).toBe(null);
        expect(fetched).toHaveLength(0);
    });
});

describe('what it posts', () => {
    it('posts each file\'s amount under the names the server reads', async () => {
        const host = mount();
        await pick(host, 7);

        await type(host, box('F-00057'), '5000');

        expect(host.querySelector('input[name="alloc[57][work_file_id]"]').value).toBe('57');
        expect(host.querySelector('input[name="alloc[57][amount]"]').value).toBe('5000');
    });

    /* Found in review: every empty box was posted, and a limit on the count
       refused every payment from a party with more than two hundred files. */
    it('posts nothing for a file left empty', async () => {
        const host = mount();
        await pick(host, 7);
        await type(host, box('F-00057'), '5000');

        expect(host.querySelector('input[name="alloc[50][amount]"]')).toBe(null);
        expect(host.querySelector('input[name="alloc[50][work_file_id]"]')).toBe(null);
        expect(host.querySelectorAll('input[name^="alloc["]')).toHaveLength(2);
    });

    it('fills oldest first from the amount of the payment', async () => {
        const host = mount();
        await pick(host, 7);
        await type(host, 'input[name="amount"]', '4000');

        [...section(host).querySelectorAll('button')].find((b) => b.textContent.includes('Fill oldest first')).click();
        await nextTick();

        expect(host.querySelector('input[name="alloc[50][amount]"]').value).toBe('3000.00');
        expect(host.querySelector('input[name="alloc[57][amount]"]').value).toBe('1000.00');
        expect(section(host).querySelector('.adjust__foot').textContent).toContain('On account');
    });

    it('fills a file in full with one press', async () => {
        const host = mount();
        await pick(host, 7);

        [...section(host).querySelectorAll('.adjust__row')][1].querySelector('button').click();
        await nextTick();

        expect(host.querySelector('input[name="alloc[57][amount]"]').value).toBe('5000.00');
    });
});

describe('what it refuses before Save', () => {
    it('will not save files adding up to more than the payment', async () => {
        const host = mount();
        await pick(host, 7);
        await type(host, 'input[name="amount"]', '4000');
        await type(host, box('F-00057'), '5000');

        expect(section(host).textContent).toContain('more than the payment');
        expect(saveButton(host).disabled).toBe(true);
    });

    it('will not save more against a file than is open on it', async () => {
        const host = mount();
        await pick(host, 7);
        await type(host, 'input[name="amount"]', '9000');
        await type(host, box('F-00050'), '3500');

        expect(section(host).textContent).toContain('F-00050: more than is open on the file');
        expect(saveButton(host).disabled).toBe(true);
    });

    it('saves once the amounts fit', async () => {
        const host = mount();
        await pick(host, 7);
        await type(host, 'input[name="amount"]', '5000');
        await type(host, box('F-00057'), '5000');

        expect(saveButton(host).disabled).toBe(false);
    });
});

describe('changing party', () => {
    it('drops what was typed against the last party\'s files', async () => {
        const host = mount();
        await pick(host, 7);
        await type(host, box('F-00057'), '5000');

        await pick(host, 9);

        expect(host.querySelector(box('F-00057'))).toBe(null);
        expect(section(host).textContent).toContain('F-00061');
    });

    /* A slow answer for a party since changed must not be shown under the new one. */
    it('throws away a slow answer for a party no longer picked', async () => {
        vi.stubGlobal('fetch', vi.fn((url) => {
            const id = Number(String(url).split('?')[0].split('/').pop());

            return answer(id, id === 7 ? 30 : 0);
        }));

        const host = mount();

        const select = host.querySelector('select[name="party_id"]');
        host.querySelector('input[name="entry_type"][value="credit"]').click();
        select.value = '7';
        select.dispatchEvent(new window.Event('change'));
        await nextTick();
        select.value = '9';
        select.dispatchEvent(new window.Event('change'));

        await new Promise((resolve) => setTimeout(resolve, 60));
        await settle();

        expect(section(host).textContent).toContain('F-00061');
        expect(section(host).textContent).not.toContain('F-00050');
    });
});

it('puts back the amounts of a save the server refused', async () => {
    const host = mount({
        initial: { party_id: '7', entry_type: 'credit', amount: '5000', payment_mode: 'UPI', ref_no: '', particular: 'Paid' },
        initialAlloc: { 57: '5000' },
    });
    await settle();

    expect(host.querySelector('input[name="alloc[57][amount]"]').value).toBe('5000');
});

/* Found in review: Reset after picking another party lost the amounts it had just put back. */
it('Reset puts the refused amounts back even after another party was picked', async () => {
    const host = mount({
        initial: { party_id: '7', entry_type: 'credit', amount: '5000', payment_mode: 'UPI', ref_no: '', particular: 'Paid' },
        initialAlloc: { 57: '5000' },
    });
    await settle();

    await pick(host, 9);
    host.querySelector('form').dispatchEvent(new window.Event('reset', { cancelable: true }));
    await settle();

    expect(host.querySelector('select[name="party_id"]').value).toBe('7');
    expect(host.querySelector('input[name="alloc[57][amount]"]').value).toBe('5000');
});

/* Found in review: filled against what was open, it put the payment on files
   money on account had already paid, and the receipt named them. */
it('fills oldest first against what is still due, not what is open', async () => {
    BILLS[8] = [
        { id: 70, fileNo: 'F-00070', vehicle: 'BR01OLD001', works: 'TR', received: '01-01-2026', charged: 3000, returned: 0, adjusted: 0, open: 3000, due: 0, editUrl: '/admin/file/edit/70' },
        { id: 71, fileNo: 'F-00071', vehicle: 'BR01NEW002', works: 'TR', received: '01-03-2026', charged: 5000, returned: 0, adjusted: 0, open: 5000, due: 5000, editUrl: '/admin/file/edit/71' },
    ];

    const host = mount({ parties: [...PARTIES, { id: 8, name: 'Covered Co', mobile: '9835230008', current_balance: 5000 }] });
    await pick(host, 8);
    host.querySelector('.adjust__toggle input').click();
    await settle();
    await type(host, 'input[name="amount"]', '5000');

    [...section(host).querySelectorAll('button')].find((b) => b.textContent.includes('Fill oldest first')).click();
    await nextTick();

    expect(host.querySelector('input[name="alloc[70][amount]"]')).toBe(null);
    expect(host.querySelector('input[name="alloc[71][amount]"]').value).toBe('5000.00');
});

describe('the receipt', () => {
    it('says which files the payment was for', () => {
        const text = receiptMessage({
            name: 'Arman Qadri',
            amount: 8000,
            dateLabel: '21-09-2026',
            mode: 'UPI',
            balance: 0,
            todayLabel: '21-09-2026',
            against: [
                { label: 'BR01JB8140 (TR)', amount: 3000 },
                { label: 'BR01DN2536 (HPA)', amount: 5000 },
            ],
        });

        expect(text).toContain('Against:');
        expect(text).toContain('• BR01JB8140 (TR) — ₹3,000.00');
        expect(text).toContain('• BR01DN2536 (HPA) — ₹5,000.00');
    });

    it('says nothing of files when it was not adjusted', () => {
        expect(receiptMessage({ name: 'Arman Qadri', amount: 8000, balance: 0 })).not.toContain('Against');
    });
});

/*
 * Found in the second review: an amount against a covered file — put back by
 * a refused save, or left when the list was narrowed — was neither shown nor
 * sent, and the payment went on account without a word.
 */
describe('an amount typed against a covered file', () => {
    const COVERED = [
        { id: 70, fileNo: 'F-00070', vehicle: 'BR01OLD001', works: 'TR', received: '01-01-2026', charged: 3000, returned: 0, adjusted: 0, open: 3000, due: 0, ahead: 0, editUrl: '/admin/file/edit/70' },
        { id: 71, fileNo: 'F-00071', vehicle: 'BR01NEW002', works: 'TR', received: '01-03-2026', charged: 5000, returned: 0, adjusted: 0, open: 5000, due: 5000, ahead: 0, editUrl: '/admin/file/edit/71' },
    ];
    const COVERED_CO = { id: 8, name: 'Covered Co', mobile: '9835230008', current_balance: 5000 };

    it('is shown and sent when a refused save puts it back', async () => {
        BILLS[8] = COVERED;

        const host = mount({
            parties: [...PARTIES, COVERED_CO],
            initial: { party_id: '8', entry_type: 'credit', amount: '5000', payment_mode: 'UPI', ref_no: '', particular: 'Paid' },
            initialAlloc: { 70: '3000', 71: '2000' },
        });
        await settle();

        expect(host.querySelector('input[name="alloc[70][amount]"]').value).toBe('3000');
        expect(host.querySelector('input[name="alloc[71][amount]"]').value).toBe('2000');
        expect(section(host).querySelector('.adjust__foot').textContent).toContain('5,000.00');
    });

    it('brings the covered files back rather than hide it when the list is narrowed', async () => {
        BILLS[8] = COVERED;

        const host = mount({ parties: [...PARTIES, COVERED_CO] });
        await pick(host, 8);

        const toggle = () => host.querySelector('.adjust__toggle input');
        toggle().click();
        await settle();
        await type(host, box('F-00070'), '3000');

        toggle().click();
        await settle();

        // Narrowed, and the file holding an amount is still drawn and still sent.
        expect(toggle().checked).toBe(false);
        expect(section(host).textContent).toContain('F-00070');
        expect(host.querySelector('input[name="alloc[70][amount]"]').value).toBe('3000');
    });
});

/*
 * Found in the second review: a bill typed into the ledger with no file takes
 * its turn in the queue, and Fill oldest first stepped past it.
 */
it('steps over bills with no file that come first, as the ledger would', async () => {
    BILLS[10] = [
        { id: 80, fileNo: 'F-00080', vehicle: 'BR01LOS001', works: 'TR', received: '05-08-2026', charged: 4000, returned: 0, adjusted: 0, open: 4000, due: 4000, ahead: 4000, editUrl: '/admin/file/edit/80' },
        { id: 81, fileNo: 'F-00081', vehicle: 'BR01LOS002', works: 'TR', received: '06-08-2026', charged: 3000, returned: 0, adjusted: 0, open: 3000, due: 3000, ahead: 4000, editUrl: '/admin/file/edit/81' },
    ];

    const host = mount({ parties: [...PARTIES, { id: 10, name: 'Loose Bill Co', mobile: '9835230010', current_balance: 11000 }] });
    await pick(host, 10);
    await type(host, 'input[name="amount"]', '6000');

    [...section(host).querySelectorAll('button')].find((b) => b.textContent.includes('Fill oldest first')).click();
    await nextTick();

    // 4,000 goes to the bill with no file first; 2,000 is left for F-00080.
    expect(host.querySelector('input[name="alloc[80][amount]"]').value).toBe('2000.00');
    expect(host.querySelector('input[name="alloc[81][amount]"]')).toBe(null);
});

/* Found in the third review: two ways the covered-files toggle still misled. */
describe('the covered files after a refused save', () => {
    const COVERED_BILLS = [
        { id: 70, fileNo: 'F-00070', vehicle: 'BR01OLD001', works: 'TR', received: '01-01-2026', charged: 3000, returned: 0, adjusted: 0, open: 3000, due: 0, ahead: 0, editUrl: '/admin/file/edit/70' },
        { id: 71, fileNo: 'F-00071', vehicle: 'BR01NEW002', works: 'TR', received: '01-03-2026', charged: 5000, returned: 0, adjusted: 0, open: 5000, due: 5000, ahead: 0, editUrl: '/admin/file/edit/71' },
    ];
    const CO = { id: 8, name: 'Covered Co', mobile: '9835230008', current_balance: 5000 };

    it('Clear, narrow, then Reset: the amount put back is drawn and sent', async () => {
        BILLS[8] = COVERED_BILLS;

        const host = mount({
            parties: [...PARTIES, CO],
            initial: { party_id: '8', entry_type: 'credit', amount: '5000', payment_mode: 'UPI', ref_no: '', particular: 'Paid' },
            initialAlloc: { 70: '3000', 71: '2000' },
        });
        await settle();

        [...section(host).querySelectorAll('button')].find((b) => b.textContent.trim() === 'Clear').click();
        await settle();

        const toggle = host.querySelector('.adjust__toggle input');
        if (toggle.checked) {
            toggle.click();
            await settle();
        }

        host.querySelector('form').dispatchEvent(new window.Event('reset', { cancelable: true }));
        await settle();

        expect(host.querySelector('input[name="alloc[70][amount]"]').value).toBe('3000');
        expect(host.querySelector('input[name="alloc[71][amount]"]').value).toBe('2000');
        expect(section(host).querySelector('.adjust__foot').textContent).toContain('On account 0.00');
    });

    it('offers no toggle when there are no covered files', async () => {
        const host = mount({
            initial: { party_id: '7', entry_type: 'credit', amount: '5000', payment_mode: 'UPI', ref_no: '', particular: 'Paid' },
            initialAlloc: { 57: '5000' },
        });
        await settle();

        expect(host.querySelector('.adjust__toggle')).toBe(null);
        expect(section(host).textContent).not.toContain('Also show 0');
    });
});

/* Found in the fourth review. */
describe('editing a covered file\'s amount', () => {
    const COVERED_BILLS = [
        { id: 70, fileNo: 'F-00070', vehicle: 'BR01OLD001', works: 'TR', received: '01-01-2026', charged: 3000, returned: 0, adjusted: 0, open: 3000, due: 0, ahead: 0, editUrl: '/admin/file/edit/70' },
        { id: 71, fileNo: 'F-00071', vehicle: 'BR01NEW002', works: 'TR', received: '01-03-2026', charged: 5000, returned: 0, adjusted: 0, open: 5000, due: 5000, ahead: 0, editUrl: '/admin/file/edit/71' },
    ];
    const CO = { id: 8, name: 'Covered Co', mobile: '9835230008', current_balance: 5000 };

    it('keeps the row while its box is emptied and retyped', async () => {
        BILLS[8] = COVERED_BILLS;

        const host = mount({
            parties: [...PARTIES, CO],
            initial: { party_id: '8', entry_type: 'credit', amount: '5000', payment_mode: 'UPI', ref_no: '', particular: 'Paid' },
            initialAlloc: { 70: '3000', 71: '2000' },
        });
        await settle();

        await type(host, box('F-00070'), '');
        expect(host.querySelector(box('F-00070'))).not.toBe(null);

        await type(host, box('F-00070'), '2500');
        expect(host.querySelector('input[name="alloc[70][amount]"]').value).toBe('2500');
    });

    it('counts only what is posted, so a negative hidden nowhere skews the totals', async () => {
        BILLS[8] = COVERED_BILLS;

        const host = mount({ parties: [...PARTIES, CO] });
        await pick(host, 8);
        await type(host, 'input[name="amount"]', '3000');

        const toggle = () => host.querySelector('.adjust__toggle input');
        toggle().click();
        await settle();
        await type(host, box('F-00070'), '-500');
        await type(host, box('F-00071'), '3400');
        toggle().click();
        await settle();

        // Still drawn, so the browser's own check refuses the negative...
        expect(host.querySelector(box('F-00070'))).not.toBe(null);
        // ...and the totals are what is posted: 3,400 against a 3,000 payment.
        expect(section(host).textContent).toContain('more than the payment');
        expect(saveButton(host).disabled).toBe(true);
    });
});
