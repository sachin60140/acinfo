import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import FileForm from './components/FileForm.vue';

/*
 * The in-house box of a folder of one work.
 *
 * Found in the health check: the box lived only in the table a folder of
 * several works has, so a folder of one kept in-house by a wrong press on Give
 * to Vendor could not be offered to a vendor again from any screen. It is now
 * among the folder's own boxes, beside the vendor it is the other answer to.
 *
 * What is held here is what it posts: a nought always, and a one after it when
 * ticked — and nothing at all where it is not on the page, which the save reads
 * as leaving the mark alone.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const ONE_WORK = { id: 11, work_type_id: 1, work_type: 'TR', customer_amount: 3000, vendor_amount: null, status: 'in_office', status_label: 'In Office', in_house: true, has_vendor: false };

function mount({ values = {}, items = [ONE_WORK], ...rest } = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(FileForm, {
        action: '/admin/file/edit/1',
        csrf: 'test-token',
        indexUrl: '/admin/files',
        isEdit: true,
        statuses: { in_office: 'In Office', approval_done: 'Approval Done', paper_returned: 'Returned', cancelled: 'Cancelled' },
        workTypes: [{ id: 1, label: 'TR', rate: null }, { id: 2, label: 'HPA', rate: null }],
        customers: [{ id: 7, label: 'Car4Sales', balance: 0 }],
        vendors: [{ id: 9, label: 'Test vendor', balance: 0 }],
        values: {
            file_no: 'F-00061',
            status: 'in_office',
            work_type_id: 1,
            customer_id: 7,
            customer_amount: '3000',
            vendor_id: '',
            vendor_amount: '',
            in_house: true,
            ...values,
        },
        receivedDateField: '<input type="hidden" name="received_date" value="2026-09-01">',
        vendorDateField: '<input type="hidden" name="vendor_date" value="">',
        refundPlaceholder: '0.00',
        screenshotUrl: '',
        items,
        alreadyPosted: { customerId: 7, vendorId: null, customer: 3000, vendor: 0 },
        timeline: [],
        returnedKey: 'paper_returned',
        approvedKey: 'approval_done',
        cancelledKey: 'cancelled',
        errors: {},
        documents: [],
        ...rest,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const box = (host) => host.querySelector('input[type="checkbox"][name="in_house"]');
const posted = (host) => new FormData(host.querySelector('form')).getAll('in_house');

describe('a folder of one work', () => {
    it('says it is kept in-house, and posts it', () => {
        const host = mount();

        expect(box(host).checked).toBe(true);
        expect(posted(host)).toEqual(['0', '1']);
    });

    it('lets a mistaken Keep in-house be taken back', async () => {
        const host = mount();

        box(host).click();
        await nextTick();

        expect(posted(host)).toEqual(['0']);
        expect(host.textContent).toContain('Give to Vendor stops offering the file');
    });

    it('can be kept in-house from here too', async () => {
        const host = mount({ values: { in_house: false }, items: [{ ...ONE_WORK, in_house: false }] });

        expect(box(host).checked).toBe(false);

        box(host).click();
        await nextTick();

        expect(posted(host)).toEqual(['0', '1']);
    });

    /*
     * Not even with the vendor box emptied: that takes the work back off the
     * vendor when saved, and the work is theirs until then.
     */
    it('has no box while the work is with a vendor', async () => {
        const host = mount({
            values: { vendor_id: 9, vendor_amount: '1800', in_house: false },
            items: [{ ...ONE_WORK, in_house: false, has_vendor: true }],
        });

        expect(host.querySelector('[name="in_house"]')).toBeNull();

        const vendor = host.querySelector('select[name="vendor_id"]');
        vendor.value = '';
        vendor.dispatchEvent(new Event('change'));
        await nextTick();

        expect(host.querySelector('[name="in_house"]')).toBeNull();
    });

    /*
     * A vendor chosen above is the other answer, being given now: the box goes,
     * and with it anything it would have posted.
     */
    it('has no box once a vendor is chosen above', async () => {
        const host = mount();
        const vendor = host.querySelector('select[name="vendor_id"]');

        vendor.value = '9';
        vendor.dispatchEvent(new Event('change'));
        await nextTick();

        expect(host.querySelector('[name="in_house"]')).toBeNull();
        expect(posted(host)).toEqual([]);
    });
});

/*
 * Finished, which is where every folder kept in-house ends up.
 *
 * Found in review: the hint still said a finished folder was listed on In-house
 * Work and would be offered to a vendor again if unticked. Neither list holds
 * finished work, so an operator who believed it unticked the box, found nothing
 * on Give to Vendor, and had wiped the day the work was kept.
 */
describe('a finished folder of one work', () => {
    const hint = (host) => box(host).closest('.ui-field').querySelector('.ui-hint').textContent;

    const finished = (status, inHouse = true) => mount({
        values: { status, in_house: inHouse },
        items: [{ ...ONE_WORK, status, in_house: inHouse }],
    });

    it.each(['approval_done', 'paper_returned', 'cancelled'])('does not promise either list when %s', (status) => {
        const host = finished(status);

        expect(hint(host)).not.toContain('listed on In-house Work');
        expect(hint(host)).not.toContain('offer the file to a vendor again');
        expect(hint(host)).toContain('only takes that record off');
        // Still the box it was, posting what it says.
        expect(posted(host)).toEqual(['0', '1']);
    });

    it('does not say ticking it takes the file off Give to Vendor', () => {
        const host = finished('approval_done', false);

        expect(hint(host)).not.toContain('Give to Vendor stops offering the file');
        expect(hint(host)).toContain('Tick it only to record');
    });

    /*
     * The status in the box above is the one the save writes to the work, so
     * the hint follows it: approved here, the folder leaves In-house Work on
     * this save; reopened, it comes back.
     */
    it('follows the status being saved', async () => {
        const host = mount();
        const status = host.querySelector('select[name="status"]');

        expect(hint(host)).toContain('listed on In-house Work');

        status.value = 'approval_done';
        status.dispatchEvent(new Event('change'));
        await nextTick();

        expect(hint(host)).not.toContain('listed on In-house Work');
        expect(hint(host)).toContain('only takes that record off');

        status.value = 'in_office';
        status.dispatchEvent(new Event('change'));
        await nextTick();

        expect(hint(host)).toContain('listed on In-house Work');
    });
});

describe('elsewhere', () => {
    // Several works say it per work, in their own table.
    it('is not among the boxes of a folder of several works', () => {
        const host = mount({
            values: { in_house: false },
            items: [ONE_WORK, { ...ONE_WORK, id: 12, work_type_id: 2, work_type: 'HPA', in_house: false }],
        });

        expect(posted(host)).toEqual([]);
        expect(host.querySelector('input[name="items[11][in_house]"]').checked).toBe(true);
    });

    it('is not on the receiving screen', () => {
        const host = mount({ isEdit: false, items: [], values: { in_house: false } });

        expect(host.querySelector('[name="in_house"]')).toBeNull();
    });
});
