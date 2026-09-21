import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import BalanceReminder from './components/BalanceReminder.vue';
import CustomerReceipt from './components/CustomerReceipt.vue';
import UncollectedReport from './components/UncollectedReport.vue';
import { balanceMessage, readyMessage, receiptMessage } from './customerShare';

/*
 * Reminding a customer of finished work and money owed, on WhatsApp.
 *
 * Read for three things: that the longest owed comes first, that the total is
 * the rows added up, and that nothing leaves the office that the customer is
 * not to know — which vendor did the work, and anything the office wrote down
 * for itself.
 */

const row = (id, over = {}) => ({
    id,
    file_no: `F-000${id}`,
    registration_no: `BR01XX${1000 + id}`,
    works: 'TR',
    customer: 'Arman Qadri',
    customer_id: 7,
    customer_mobile: '9835230001',
    finished: '10-09-2026',
    finished_raw: '2026-09-10',
    handed_over: 'With the office',
    handed_over_raw: '',
    charged: 3000,
    outstanding: 3000,
    ...over,
});

const ARMAN = [
    row(55, { registration_no: 'BR01DN2536', finished: '15-09-2026', finished_raw: '2026-09-15', outstanding: 1500 }),
    row(1, { registration_no: 'BR01DD1100', works: 'HPT, TR', finished: '16-08-2026', finished_raw: '2026-08-16' }),
];

describe('the work-ready message', () => {
    it('puts the work finished longest ago at the top', () => {
        const text = readyMessage('Arman Qadri', ARMAN, '21-09-2026');

        expect(text.indexOf('BR01DD1100')).toBeLessThan(text.indexOf('BR01DN2536'));
        expect(text).toContain('1. *BR01DD1100 — HPT, TR*');
    });

    it('says whose it is, how many, and as of when', () => {
        const lines = readyMessage('Arman Qadri', ARMAN, '21-09-2026').split('\n');

        expect(lines[0]).toBe('*Your work is ready — Arman Qadri*');
        expect(lines[1]).toBe('2 files · as of 21-09-2026');
    });

    it('says what is due on each file and in all', () => {
        const text = readyMessage('Arman Qadri', ARMAN);

        expect(text).toContain('F-00055 · completed 15-09-2026 · ₹1,500.00 due');
        expect(text).toContain('*Total due: ₹4,500.00*');
    });

    it('asks them to collect papers only where the office still has them', () => {
        const handedBack = readyMessage('Arman Qadri', ARMAN.map((r) => ({ ...r, handed_over_raw: '2026-09-18' })));

        expect(readyMessage('Arman Qadri', ARMAN)).toContain('Papers ready to collect');
        expect(handedBack).not.toContain('Papers ready to collect');
        expect(handedBack).toContain('Please clear the balance at your convenience.');
    });

    /* The report can be asked for everything owing, work still running too. */
    it('does not call unfinished work ready', () => {
        const text = readyMessage('Arman Qadri', [
            ...ARMAN,
            row(60, { registration_no: 'BR01RUN001', finished: null, finished_raw: null }),
        ]);

        expect(text.split('\n')[0]).toBe('*Payment due — Arman Qadri*');
        expect(text).toContain('F-00060 · in progress');
        // And it goes last, after everything that did finish.
        expect(text.indexOf('BR01RUN001')).toBeGreaterThan(text.indexOf('BR01DN2536'));
    });

    /*
     * The lines that must never move. A customer is never told who does the
     * office's work, and a note the office wrote for itself never leaves it.
     */
    it('never names a vendor or reads an office note, whatever the row carries', () => {
        const text = readyMessage('Arman Qadri', ARMAN.map((r) => ({
            ...r,
            vendor: 'Suman Ji Patna',
            party_name: 'Suman Ji Patna',
            office_note: 'Chase Suman, he is slow',
            cost: 2200,
        })));

        expect(text).not.toContain('Suman');
        expect(text).not.toContain('slow');
        expect(text).not.toMatch(/2,?200/);
    });

    it('says nothing at all when there is nothing to send', () => {
        expect(readyMessage('Arman Qadri', [])).toBe('');
    });
});

describe('the balance reminder', () => {
    it('says the balance and the day', () => {
        const text = balanceMessage('Arman Qadri', 12500, '21-09-2026');

        expect(text.split('\n')[0]).toBe('*Balance reminder — Arman Qadri*');
        expect(text).toContain('₹12,500.00 due as of 21-09-2026');
    });

    it('is never written about a settled account, or one in credit', () => {
        expect(balanceMessage('Arman Qadri', 0)).toBe('');
        expect(balanceMessage('Arman Qadri', -500)).toBe('');
    });
});

// ------------------------------------------------------------------ the screens

const mounted = [];

function mount(component, props) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(component, props);
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

    vi.restoreAllMocks();
});

const COLUMNS = [
    { key: 'file_no', label: 'File No.' },
    { key: 'registration_no', label: 'Vehicle' },
    { key: 'customer', label: 'Customer' },
    { key: 'outstanding', label: 'Outstanding', type: 'money' },
];

const RAKESH = [
    row(70, { customer: 'Rakesh Madhubani', customer_id: 9, customer_mobile: '9431000002', registration_no: 'BR32RK7000' }),
];

const report = () => mount(UncollectedReport, {
    columns: COLUMNS,
    rows: [...ARMAN, ...RAKESH],
    groupBy: 'customer_id',
    groupLabel: 'customer',
    todayLabel: '21-09-2026',
    perPage: 100,
});

const sendButtons = (host) => [...host.querySelectorAll('.wa-share__send')];

describe('on Not Yet Collected', () => {
    it('offers each customer their own message from their own band', () => {
        const host = report();

        expect([...host.querySelectorAll('.uc-band__label')].map((el) => el.textContent))
            .toEqual(['Arman Qadri', 'Rakesh Madhubani']);
        expect(sendButtons(host)).toHaveLength(2);
    });

    it('opens a chat on that customer with only their files, and sends nothing itself', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const host = report();

        sendButtons(host)[1].click();
        await nextTick();

        const [url, target] = open.mock.calls[0];
        const text = decodeURIComponent(url.split('text=')[1]);

        expect(url.startsWith('https://wa.me/919431000002?text=')).toBe(true);
        expect(text).toContain('BR32RK7000');
        expect(text).not.toContain('BR01DD1100');
        expect(target).toBe('_blank');
    });

    it('never submits anything on the way to WhatsApp', () => {
        for (const button of report().querySelectorAll('.wa-share button')) {
            expect(button.getAttribute('type')).toBe('button');
        }
    });
});

describe('on a customer statement', () => {
    it('opens a chat on the customer with the balance filled in', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const host = mount(BalanceReminder, { name: 'Arman Qadri', mobile: '98352 30001', balance: 12500, todayLabel: '21-09-2026' });

        expect(host.textContent).toContain('12,500.00 owing');

        host.querySelector('.wa-share__send').click();
        await nextTick();

        const url = open.mock.calls[0][0];

        expect(url.startsWith('https://wa.me/919835230001?text=')).toBe(true);
        expect(decodeURIComponent(url)).toContain('₹12,500.00 due as of 21-09-2026');
    });

    it('says whose number it opens before anything is clicked', () => {
        const host = mount(BalanceReminder, { name: 'Arman Qadri', mobile: '9835230001', balance: 100 });

        expect(host.querySelector('.wa-share__send').getAttribute('title')).toContain('+91 98352 30001');
    });

    it('shows nothing for a customer who owes nothing', () => {
        const host = mount(BalanceReminder, { name: 'Arman Qadri', mobile: '9835230001', balance: 0 });

        expect(host.querySelector('.balance-reminder')).toBeNull();
    });
});

// ------------------------------------------------------------------ receipts

const PAID = { name: 'Arman Qadri', mobile: '9835230001', amount: 5000, dateLabel: '21-09-2026', mode: 'UPI', reference: '412345678901', balance: 2500, todayLabel: '21-09-2026' };

describe('the payment receipt', () => {
    it('says what was received, when, how, and their reference', () => {
        const lines = receiptMessage(PAID).split('\n');

        expect(lines[0]).toBe('*Payment received — Arman Qadri*');
        expect(lines[1]).toBe('₹5,000.00 received on 21-09-2026 (UPI)');
        expect(lines[2]).toBe('Ref: 412345678901');
    });

    it('says where the account stands after it, whichever way that is', () => {
        expect(receiptMessage(PAID)).toContain('Balance due as of 21-09-2026: ₹2,500.00');
        expect(receiptMessage({ ...PAID, balance: 0 })).toContain('Your account is fully settled as of 21-09-2026.');
        expect(receiptMessage({ ...PAID, balance: -500 })).toContain('Paid in advance as of 21-09-2026: ₹500.00');
    });

    /* A payment booked with last month's date, and today's balance beside it. */
    it('dates the balance, so a backdated payment is not read as last month\'s balance', () => {
        const text = receiptMessage({ ...PAID, dateLabel: '01-08-2026' });

        expect(text).toContain('received on 01-08-2026');
        expect(text).toContain('Balance due as of 21-09-2026');
    });

    it('leaves out a reference nobody wrote down', () => {
        expect(receiptMessage({ ...PAID, reference: '  ' })).not.toContain('Ref:');
    });

    /* The particular is the office's own description of the entry. */
    it('never reads the particular, whatever it is handed', () => {
        const text = receiptMessage({ ...PAID, particular: 'Paid late again, watch him', office_note: 'slow payer' });

        expect(text).not.toContain('watch him');
        expect(text).not.toContain('slow payer');
    });

    it('says nothing about no money', () => {
        expect(receiptMessage({ ...PAID, amount: 0 })).toBe('');
    });
});

describe('after a payment is saved', () => {
    it('opens a chat on the customer with the receipt filled in, and sends nothing itself', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const host = mount(CustomerReceipt, PAID);

        expect(host.textContent).toContain('5,000.00 received from Arman Qadri');

        host.querySelector('.wa-share__send').click();
        await nextTick();

        const url = open.mock.calls[0][0];

        expect(url.startsWith('https://wa.me/919835230001?text=')).toBe(true);
        expect(decodeURIComponent(url)).toContain('*Payment received — Arman Qadri*');
        expect(host.querySelector('.wa-share__send').textContent).toContain('Send receipt on WhatsApp');
    });
});
