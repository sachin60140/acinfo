import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import CustomerReturn from './components/CustomerReturn.vue';
import GiveToVendor from './components/GiveToVendor.vue';
import VendorReturn from './components/VendorReturn.vue';

/*
 * Give to Vendor, Take Back and Return to Customer, pressed twice.
 *
 * Found in the health check of 2026-10-07: a double click sent the batch twice,
 * and the second post, read while the first was still saving, took the
 * vendor's credit or its reversal off their statement — or, on Return to
 * Customer, ended on a server error with the return already saved. The server
 * makes a second post wait and refuses it now; these screens stop it being
 * sent at all. One press, one post.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

function mount(component, props) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(component, props);
    app.mount(host);
    mounted.push({ app, host });

    return host;
}

// A folder with one transfer, ticked, going to a vendor already chosen.
const give = () => mount(GiveToVendor, {
    files: [{
        id: 1,
        file_no: 'F-0001',
        registration_no: 'BR06GG1401',
        received_date: '14-08-2026',
        description: '',
        customer: 'Car4Sales',
        customer_amount: 3000,
        papers: 'ready',
        papers_note: null,
        papers_url: '/admin/file/1/papers',
        items: [{
            id: 11, work_type_id: 2, work_type: 'TR', customer_amount: 3000, vendor_rate: 1800,
            state: 'here', status_label: 'In Office', vendor: null, vendor_date: null,
            vendor_amount: null, papers: 'ready', papers_pending: null, kept_on: null,
        }],
    }],
    vendors: [{ id: 6, name: 'Sharma Ji', mobile: '9835630000', current_balance: 0 }],
    action: '/admin/file/assign',
    keepUrl: '/admin/file/keep-in-house',
    csrf: 'token',
    cancelUrl: '/admin/files',
    vendorId: 6,
    vendorDate: '2026-09-08',
    vendorDateDisplay: '08-09-2026',
    pickedFiles: [1],
    pickedJobs: [11],
    oldAmounts: { 11: '1800.00' },
});

// One vendor's row, ticked, with a reason given.
const takeBack = () => mount(VendorReturn, {
    files: [{
        id: 12, key: '12:5', vendor_id: 5, file_no: 'F-00012', registration_no: 'BR01AB1234',
        vendor: 'Sharma', vendor_date: '12-09-2026', days_out: '6 days', work_type: 'HPT',
        description: '', customer: 'Car4Sales', vendor_amount: 1000,
    }],
    action: '/admin/file/vendor-return',
    csrf: 'token',
    cancelUrl: '/admin/files',
    returnedOn: '2026-09-18',
    remark: 'Could not do it',
    pickedIds: ['12:5'],
});

// One file, ticked, with a reason given.
const returnToCustomer = () => mount(CustomerReturn, {
    files: [{
        id: 7, file_no: 'F-0007', received_date: '01-09-2026', registration_no: 'BR01CD5678',
        work_type: 'TR', description: '', customer: 'Car4Sales', status: 'in_office',
        status_label: 'In Office', customer_amount: 3000,
    }],
    action: '/admin/file/customer-return',
    csrf: 'token',
    cancelUrl: '/admin/files',
    returnedOn: '2026-09-18',
    oldRemark: 'Customer took the papers back',
    oldFiles: ['7'],
});

const submit = (host) => {
    const event = new window.Event('submit', { cancelable: true });
    host.querySelector('form').dispatchEvent(event);

    return event;
};

const buttons = (host) => [...host.querySelectorAll('button[type="submit"]')];

const broughtBack = async () => {
    const shown = new window.Event('pageshow');
    Object.defineProperty(shown, 'persisted', { value: true });
    window.dispatchEvent(shown);
    await nextTick();
};

describe.each([
    ['Give to Vendor', give, 2],
    ['Take Files Back', takeBack, 1],
    ['Return to Customer', returnToCustomer, 1],
])('pressing %s twice', (label, open, count) => {
    it('sends the form once', () => {
        const host = open();

        const first = submit(host);
        const second = submit(host);

        expect(first.defaultPrevented).toBe(false);
        expect(second.defaultPrevented).toBe(true);
    });

    it('greys the buttons out while it saves', async () => {
        const host = open();

        // Ready to press before anything is sent, or the test proves nothing.
        expect(buttons(host)).toHaveLength(count);
        expect(buttons(host).every((button) => ! button.disabled)).toBe(true);

        submit(host);
        await nextTick();

        expect(buttons(host).every((button) => button.disabled)).toBe(true);
    });

    it('lets it be pressed again on a page brought back with Back', async () => {
        const host = open();

        submit(host);
        await nextTick();
        await broughtBack();

        expect(buttons(host).every((button) => ! button.disabled)).toBe(true);
        expect(submit(host).defaultPrevented).toBe(false);
    });
});
