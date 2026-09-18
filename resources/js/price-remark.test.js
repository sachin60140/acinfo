import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import FileForm from './components/FileForm.vue';

/*
 * The reason a price moved, asked where the price is.
 *
 * The server refuses the save without one, so what is being held here is that
 * nobody reaches that refusal by surprise: the box appears beside the money the
 * moment an agreed figure is retyped, and the save waits for it.
 *
 * Pricing a file for the first time asks nothing. A box that fired on every
 * blank would be filled with a full stop and stop being read.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const HPT = { id: 1, label: 'HPT', rate: null };
const TR = { id: 2, label: 'TR', rate: null };

function mount({ values = {}, items = [], errors = {} } = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(FileForm, {
        action: '/admin/file/edit/1',
        csrf: 'test-token',
        indexUrl: '/admin/files',
        isEdit: true,
        statuses: { in_office: 'In Office', approval_done: 'Approval Done', paper_returned: 'Returned', cancelled: 'Cancelled' },
        workTypes: [HPT, TR],
        customers: [{ id: 7, label: 'Car4Sales', balance: 0 }],
        vendors: [{ id: 9, label: 'Sharma Agency', balance: 0 }],
        values: {
            file_no: 'F-00061',
            status: 'in_office',
            work_type_id: 1,
            customer_id: 7,
            customer_amount: '5000',
            vendor_id: 9,
            vendor_amount: '3000',
            ...values,
        },
        receivedDateField: '<input type="hidden" name="received_date" value="2026-08-01">',
        vendorDateField: '<input type="hidden" name="vendor_date" value="2026-08-02">',
        refundPlaceholder: '0.00',
        screenshotUrl: '',
        items,
        alreadyPosted: { customerId: 7, vendorId: 9, customer: 5000, vendor: 3000 },
        timeline: [],
        returnedKey: 'paper_returned',
        approvedKey: 'approval_done',
        cancelledKey: 'cancelled',
        pendencyKey: 'paper_pendency',
        errors,
        documents: [],
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const card = (host) => host.querySelector('.wf-why');
const save = (host) => [...host.querySelectorAll('button[type="submit"]')].pop();
const box = (host, name) => host.querySelector(`[name="${name}"]`);

async function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await nextTick();
}

/** A folder of two works, priced. */
const twoWorks = [
    { id: 11, work_type_id: 1, work_type: 'HPT', customer_amount: '5000.00', vendor_amount: '3000.00', status: 'in_office', status_label: 'In Office' },
    { id: 12, work_type_id: 2, work_type: 'TR', customer_amount: '2000.00', vendor_amount: '1000.00', status: 'in_office', status_label: 'In Office' },
];

describe('a file priced in the boxes above the table', () => {
    it('says nothing until an agreed price is retyped', async () => {
        const host = mount();

        expect(card(host)).toBeNull();
        expect(save(host).disabled).toBe(false);

        await type(box(host, 'customer_amount'), '6000');

        expect(card(host)).not.toBeNull();
        expect(card(host).textContent).toContain('the charge');
        expect(save(host).disabled).toBe(true);
    });

    it('asks about the vendor rate too', async () => {
        const host = mount();

        await type(box(host, 'vendor_amount'), '2500');

        expect(card(host).textContent).toContain('the vendor rate');
        expect(save(host).disabled).toBe(true);
    });

    it('lets the file be saved once the reason is typed', async () => {
        const host = mount();

        await type(box(host, 'customer_amount'), '6000');
        await type(box(host, 'price_remark'), 'Customer agreed it on the phone');

        expect(save(host).disabled).toBe(false);
    });

    it('goes away when the price is put back', async () => {
        const host = mount();

        await type(box(host, 'customer_amount'), '6000');
        await type(box(host, 'customer_amount'), '5000');

        expect(card(host)).toBeNull();
        expect(save(host).disabled).toBe(false);
    });

    it('is not asked when the file is being priced for the first time', async () => {
        const host = mount({ values: { customer_amount: '0', vendor_amount: '' } });

        await type(box(host, 'customer_amount'), '4000');
        await type(box(host, 'vendor_amount'), '3000');

        expect(card(host)).toBeNull();
        expect(save(host).disabled).toBe(false);
    });

    /* Clearing a rate the vendor had agreed is changing it, not un-pricing it. */
    it('is asked when a rate is emptied', async () => {
        const host = mount();

        await type(box(host, 'vendor_amount'), '');

        expect(card(host)).not.toBeNull();
    });
});

describe('a folder of several works', () => {
    it('names the work whose price moved', async () => {
        const host = mount({ items: twoWorks });

        await type(box(host, 'items[12][customer_amount]'), '2500');

        expect(card(host).textContent).toContain('TR charged');
        expect(card(host).textContent).not.toContain('HPT');
        expect(save(host).disabled).toBe(true);
    });

    it('names each one when two move', async () => {
        const host = mount({ items: twoWorks });

        await type(box(host, 'items[11][vendor_amount]'), '3200');
        await type(box(host, 'items[12][customer_amount]'), '2500');

        expect(card(host).textContent).toContain('HPT vendor rate');
        expect(card(host).textContent).toContain('TR charged');
    });

    /* A work being taken off the file is not a price being changed. */
    it('says nothing about work that is being removed', async () => {
        const host = mount({ items: twoWorks });

        await type(box(host, 'items[12][customer_amount]'), '2500');
        expect(card(host)).not.toBeNull();

        const row = [...host.querySelectorAll('tbody tr')][1];
        row.querySelector('.wf-row-btn, button[type="button"]').click();
        await nextTick();

        expect(card(host)).toBeNull();
    });
});

describe('the reason itself', () => {
    it('is posted under its own name and says who it is for', async () => {
        const host = mount();

        await type(box(host, 'customer_amount'), '6000');

        const input = box(host, 'price_remark');

        expect(input).not.toBeNull();
        expect(input.getAttribute('maxlength')).toBe('200');
        expect(card(host).textContent).toContain('customer never sees it');
    });
});

describe('coming back from a refusal', () => {
    /*
     * The screen is filled from what was typed, so the price on it and the price
     * it is compared against are by then the same figure. Without the server's
     * word for it, the box the refusal asks for would not be on the page.
     */
    it('still offers the box, with what the server said', async () => {
        const host = mount({
            values: { customer_amount: '6000' },
            errors: { price_remark: 'Say why the price is changing.' },
        });

        expect(card(host)).not.toBeNull();
        expect(card(host).textContent).toContain('Say why the price is changing.');
        expect(save(host).disabled).toBe(true);

        await type(box(host, 'price_remark'), 'Agreed on the phone');

        expect(save(host).disabled).toBe(false);
    });
});

describe('finding the box', () => {
    /*
     * The button bar is stuck to the bottom of the screen and the box is at the
     * end of a long form, so Save going dead is the first thing the office
     * notices. The sentence beside it is what takes them to the box.
     */
    it('is one click from the button bar', async () => {
        const host = mount();

        await type(box(host, 'customer_amount'), '6000');

        const jump = host.querySelector('.wf-why__jump');

        expect(jump).not.toBeNull();

        jump.click();
        await nextTick();

        expect(host.ownerDocument.activeElement).toBe(box(host, 'price_remark'));
    });

    it('says nothing in the bar once the reason is there', async () => {
        const host = mount();

        await type(box(host, 'customer_amount'), '6000');
        await type(box(host, 'price_remark'), 'Agreed on the phone');

        expect(host.querySelector('.wf-why__jump')).toBeNull();
    });
});
