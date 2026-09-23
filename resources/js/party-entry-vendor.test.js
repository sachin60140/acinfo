import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import PartyEntry from './components/PartyEntry.vue';

/*
 * What is typed on a vendor's entry is printed on the vendor's statement,
 * and a vendor is never told a customer's name. The server cuts a customer's
 * name out of it; the screen says so where it is typed.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(PartyEntry, {
        action: '/admin/party/entry/vendor',
        csrf: 'test-token',
        label: 'Vendor',
        indexUrl: '/admin/parties/vendor',
        statementUrl: '/admin/party/statement/__ID__',
        parties: [{ id: 9, name: 'Parwez Works', mobile: '9835230009', current_balance: -3000 }],
        paymentModes: ['Cash', 'UPI'],
        dateField: '<input type="hidden" name="txn_date" value="2026-09-20">',
        initial: { party_id: '9', entry_type: 'debit', amount: '', payment_mode: '', ref_no: '', particular: '' },
        paymentSide: 'debit',
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

describe('the Particulars box', () => {
    it('says on a vendor\'s entry that the vendor reads it, and no customer is named', async () => {
        const host = mount();
        await nextTick();

        const box = host.querySelector('textarea[name="particular"]').closest('.ui-field');
        expect(box.textContent).toContain("Printed on the vendor's statement");
        expect(box.textContent).toContain('customers\' names');
    });

    it('says nothing of the kind on a customer\'s', async () => {
        const host = mount({
            label: 'Customer',
            paymentSide: 'credit',
            parties: [{ id: 7, name: 'Arman Qadri', mobile: '9835230001', current_balance: 500 }],
            initial: { party_id: '7', entry_type: 'credit', amount: '', payment_mode: '', ref_no: '', particular: '' },
        });
        await nextTick();

        const box = host.querySelector('textarea[name="particular"]').closest('.ui-field');
        expect(box.textContent).not.toContain("vendor's statement");
    });
});
