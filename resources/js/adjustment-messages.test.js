import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import CustomerReceipt from './components/CustomerReceipt.vue';
import { receiptMessage } from './customerShare';

/*
 * What a customer is sent when their account changes without their paying:
 * a difference written off, or an entry taken back. In the words their
 * statement already uses — Discount, and which entry was reversed — with
 * nobody else named and never why.
 */

const base = { name: 'Arman Qadri', dateLabel: '20-09-2026', todayLabel: '23-09-2026' };

describe('a write-off', () => {
    const text = receiptMessage({
        ...base,
        kind: 'writeoff',
        amount: 50,
        balance: 0,
        against: [{ label: 'BR01JB8140 (TR)', amount: 50 }],
    });

    it('is said as the Discount their statement shows', () => {
        expect(text).toContain('*Discount — Arman Qadri*');
        expect(text).toContain('₹50.00 discount on 20-09-2026');
        expect(text).toContain('• BR01JB8140 (TR) — ₹50.00');
        expect(text).toContain('Your account is fully settled as of 23-09-2026.');
        expect(text).toContain('Thank you.');
    });

    it('never as money received', () => {
        expect(text).not.toMatch(/received/i);
    });
});

describe('a reversal', () => {
    const text = receiptMessage({
        ...base,
        kind: 'reversal',
        amount: 5000,
        entryNo: 12,
        dateLabel: '10-09-2026',
        reference: '412345678901',
        balance: 7500,
    });

    it('says which entry was taken back, by its number, date and amount', () => {
        expect(text).toContain('*Account corrected — Arman Qadri*');
        expect(text).toContain('Entry #12 of 10-09-2026 for ₹5,000.00 has been reversed.');
    });

    it('gives their own reference, to find it by', () => {
        expect(text).toContain('Ref: 412345678901');
    });

    it('says where they stand now, and no thanks for a correction', () => {
        expect(text).toContain('Balance due as of 23-09-2026: ₹7,500.00');
        expect(text).not.toContain('Thank you');
    });
});

describe('a payment is still a receipt', () => {
    it('as it was', () => {
        const text = receiptMessage({ ...base, amount: 5000, mode: 'UPI', reference: '4123', balance: 2500 });

        expect(text).toContain('*Payment received — Arman Qadri*');
        expect(text).toContain('Ref: 4123');
        expect(text).toContain('Thank you.');
    });
});

describe('the card above the Send button', () => {
    const mounted = [];

    afterEach(() => {
        while (mounted.length) {
            const { app, host } = mounted.pop();
            app.unmount();
            host.remove();
        }
    });

    async function mount(props) {
        const host = document.createElement('div');
        document.body.appendChild(host);
        const app = createApp(CustomerReceipt, { name: 'Arman Qadri', mobile: '9835230001', ...props });
        app.mount(host);
        mounted.push({ app, host });
        await nextTick();

        return host;
    }

    it('says a discount was written off, and offers a message rather than a receipt', async () => {
        const host = await mount({ kind: 'writeoff', amount: 50 });

        expect(host.textContent).toContain('written off for Arman Qadri');
        expect(host.textContent).toContain('Send message on WhatsApp');
        expect(host.textContent).not.toContain('receipt');
    });

    it('says which entry was reversed', async () => {
        const host = await mount({ kind: 'reversal', amount: 5000, entryNo: 12 });

        expect(host.textContent).toContain('Entry #12 reversed for Arman Qadri');
        expect(host.textContent).toContain('Send message on WhatsApp');
    });

    it('still offers a payment its receipt', async () => {
        const host = await mount({ amount: 5000 });

        expect(host.textContent).toContain('received from Arman Qadri');
        expect(host.textContent).toContain('Send receipt on WhatsApp');
    });
});
