import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import FileForm from './components/FileForm.vue';

/*
 * Adding a work to a file that already exists.
 *
 * Papers turn up later for another job on the same vehicle. The rows are a form
 * until the file is saved, so what matters here is that they post under the
 * names the controller reads, that the same work is never offered twice, and
 * that a finished file is not offered the option at all.
 */

const mounted = [];

const WORK_TYPES = [
    { id: 1, label: 'HPT — 2,500.00', rate: '2500.00' },
    { id: 2, label: 'TR — 3,000.00', rate: '3000.00' },
    { id: 3, label: 'HPA', rate: null },
];

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const { values = {}, items = [], ...rest } = overrides;

    const app = createApp(FileForm, {
        action: '/admin/file/edit/1',
        csrf: 'test-token',
        indexUrl: '/admin/files',
        isEdit: true,
        statuses: {
            in_office: 'In Office',
            file_dispatch: 'File Dispatch',
            approval_done: 'Approval Done',
            paper_returned: 'Paper Returned to Customer',
            cancelled: 'Cancelled',
        },
        workTypes: WORK_TYPES,
        customers: [{ id: 7, label: 'Car4Sales (9000000000)', balance: 0 }],
        vendors: [{ id: 6, label: 'Test vendor (9000000001)', balance: 0 }],
        values: {
            file_no: 'F-00061',
            status: 'in_office',
            work_type_id: 1,
            customer_id: 7,
            customer_amount: '2000',
            registration_no: 'BR06PA1805',
            ...values,
        },
        receivedDateField: '<input type="hidden" name="received_date" value="2026-08-01">',
        vendorDateField: '<input type="hidden" name="vendor_date" value="">',
        refundPlaceholder: '0.00',
        screenshotUrl: '',
        items,
        alreadyPosted: { customerId: 7, vendorId: null, customer: 2000, vendor: 0 },
        timeline: [],
        returnedKey: 'paper_returned',
        approvedKey: 'approval_done',
        cancelledKey: 'cancelled',
        errors: {},
        ...rest,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const TWO_WORKS = [
    { id: 11, work_type_id: 1, work_type: 'HPT', customer_amount: 2000, vendor_amount: '', status: 'in_office', status_label: 'In Office', screenshot_url: null, approved_on: null },
    { id: 12, work_type_id: 2, work_type: 'TR', customer_amount: 3000, vendor_amount: '', status: 'in_office', status_label: 'In Office', screenshot_url: null, approved_on: null },
];

const addButton = (host) => host.querySelector('.wf-works__add button');

const optionsOn = (select) =>
    [...select.options].map((o) => o.textContent.trim()).filter((t) => t !== 'Select work');

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

describe('adding a work to a file', () => {
    it('is offered on a file of one work, whose own work is in the boxes above', () => {
        const host = mount();

        expect(addButton(host)).not.toBe(null);
        // No panel table: there is only one work and the form above is it.
        expect(host.querySelector('.wf-works__table')).toBe(null);
    });

    it('is offered on a folder of several, beneath the ones it has', () => {
        const host = mount({ items: TWO_WORKS });

        expect(addButton(host)).not.toBe(null);
        expect(host.querySelectorAll('.wf-works__table tbody tr').length).toBe(2);
    });

    /*
     * The names are the contract with the server. A work added under any other
     * name is one the controller never sees.
     */
    it('posts what it adds under the names the server reads', async () => {
        const host = mount();

        addButton(host).click();
        await nextTick();

        expect(host.querySelector('[name="new_works[0][work_type_id]"]')).not.toBe(null);
        expect(host.querySelector('[name="new_works[0][amount]"]')).not.toBe(null);
        expect(host.querySelector('[name="new_works[0][vendor_amount]"]')).not.toBe(null);

        addButton(host).click();
        await nextTick();

        expect(host.querySelector('[name="new_works[1][work_type_id]"]')).not.toBe(null);
    });

    it('never offers a work the file already has', async () => {
        const host = mount({ items: TWO_WORKS });

        addButton(host).click();
        await nextTick();

        // HPT and TR are on the file; only HPA is left.
        expect(optionsOn(host.querySelector('[name="new_works[0][work_type_id]"]'))).toEqual(['HPA']);
    });

    it('counts a one-work file by the boxes above it', async () => {
        const host = mount({ values: { work_type_id: 2 } });

        addButton(host).click();
        await nextTick();

        // TR is the file's own work, so it is not offered again.
        expect(optionsOn(host.querySelector('[name="new_works[0][work_type_id]"]')))
            .toEqual(['HPT — 2,500.00', 'HPA']);
    });

    it('offers the standard rate without overwriting a price typed', async () => {
        const host = mount();

        addButton(host).click();
        await nextTick();

        const type = host.querySelector('[name="new_works[0][work_type_id]"]');
        const amount = host.querySelector('[name="new_works[0][amount]"]');

        type.value = '2';
        type.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(amount.value).toBe('3000.00');

        amount.value = '4000';
        amount.dispatchEvent(new window.Event('input'));
        type.value = '3';
        type.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(amount.value).toBe('4000');
    });

    /*
     * A row added by mistake is taken off again. It has not been saved, so
     * there is nothing to cancel and no reason to ask for one.
     */
    it('takes a row off again before it is saved', async () => {
        const host = mount();

        addButton(host).click();
        await nextTick();
        expect(host.querySelectorAll('.wf-new-work').length).toBe(1);

        host.querySelector('.wf-new-work__remove').click();
        await nextTick();

        expect(host.querySelectorAll('.wf-new-work').length).toBe(0);
        expect(host.querySelector('[name="new_works[0][work_type_id]"]')).toBe(null);
    });

    it('stops once every work is on the file', async () => {
        const host = mount();

        // One in the boxes above, and three types in all.
        addButton(host).click();
        addButton(host).click();
        await nextTick();

        expect(host.querySelectorAll('.wf-new-work').length).toBe(2);
        expect(addButton(host).disabled).toBe(true);
    });

    /*
     * A file that is approved, returned or cancelled is finished. Papers
     * arriving after that are a new file, and the screen says so rather than
     * letting the save be refused.
     */
    it('is not offered on a file that is finished', async () => {
        for (const status of ['approval_done', 'paper_returned', 'cancelled']) {
            const host = mount({ values: { status } });

            expect(addButton(host).disabled, `a ${status} file`).toBe(true);
            expect(host.querySelector('.wf-works__add').textContent)
                .toContain('Papers for more work are a new file');

            mounted.pop().app.unmount();
        }
    });
});
