import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { createApp, nextTick } from 'vue';
import GiveToVendor from './components/GiveToVendor.vue';
import FileForm from './components/FileForm.vue';

/*
 * The calendar on a screen Vue draws.
 *
 * Eight components render a date box, and the page they sit on sends none of
 * them: the server HTML for Give to Vendor contains no js-datefield at all. So
 * the script that turns a box into a calendar has to find markup that did not
 * exist when the page was parsed — and nothing tested that it did.
 */

const SOURCE = readFileSync('public/assets/js/datepicker.js', 'utf8');

beforeAll(() => {
    // eslint-disable-next-line no-new-func
    new Function(SOURCE).call(window);
});

const mounted = [];

function mountScreen(component, props) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(component, props);
    app.mount(host);

    mounted.push({ app, host });

    return host;
}

const popup = () => document.querySelector('.dp-popup');

/*
 * What the browser does after a module script has mounted everything: the page
 * finishes, and the classic script bound to that moment runs.
 */
const pageFinishes = () => document.dispatchEvent(new window.Event('DOMContentLoaded'));

afterEach(() => {
    document.body.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    document.body.innerHTML = '';
});

const giveToVendorProps = {
    files: [],
    vendors: [{ id: 6, name: 'Test vendor', mobile: '9000000000', current_balance: 0 }],
    action: '/admin/file/assign',
    csrf: 'test-token',
    cancelUrl: '/admin/files',
    vendorId: '',
    vendorDate: '2026-08-22',
    vendorDateDisplay: '22-08-2026',
    remark: '',
    pickedFiles: [],
    oldAmounts: {},
    rateHistory: [],
};

describe('the calendar on a Vue screen', () => {
    it('binds a date box the server never sent', async () => {
        const host = mountScreen(GiveToVendor, giveToVendorProps);
        await nextTick();

        const box = host.querySelector('.js-datefield');

        expect(box, 'the screen draws a date box').not.toBe(null);
        expect(document.querySelector('#vendor_date'), 'and the hidden field it writes to').not.toBe(null);

        // Mounted first, then the page finishes — which is the order a module
        // script and DOMContentLoaded actually happen in.
        pageFinishes();

        box.dispatchEvent(new window.Event('focus'));

        expect(popup(), 'the calendar opens').not.toBe(null);
    });

    it('writes the day picked into the field the server reads', async () => {
        const host = mountScreen(GiveToVendor, giveToVendorProps);
        await nextTick();
        pageFinishes();

        const box = host.querySelector('.js-datefield');
        box.dispatchEvent(new window.Event('focus'));

        const day = [...popup().querySelectorAll('.dp-day')].find((b) => b.dataset.iso.endsWith('-11'));
        day.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

        expect(box.value).toMatch(/^11-\d{2}-\d{4}$/);
        expect(document.querySelector('#vendor_date').value).toMatch(/^\d{4}-\d{2}-11$/);
    });

    /*
     * The file screen puts its two date boxes in as server-rendered markup that
     * Vue inserts. Both have to work, and they must not write into each other.
     */
    it('binds both boxes on the file screen, each to its own hidden field', async () => {
        const host = mountScreen(FileForm, {
            action: '/admin/file/edit/1',
            csrf: 'test-token',
            indexUrl: '/admin/files',
            isEdit: true,
            statuses: { in_office: 'In Office', approval_done: 'Approval Done', paper_returned: 'Paper Returned', cancelled: 'Cancelled' },
            workTypes: [{ id: 1, label: 'HPT', rate: null }],
            customers: [{ id: 7, label: 'Car4Sales', balance: 0 }],
            vendors: [{ id: 6, label: 'Test vendor', balance: 0 }],
            values: { file_no: 'F-1', status: 'in_office', work_type_id: 1, customer_id: 7, customer_amount: '1000' },
            receivedDateField: '<div><input type="text" class="js-datefield" id="received_date_display" data-target="received_date" value="01-08-2026"><input type="hidden" id="received_date" name="received_date" value="2026-08-01"></div>',
            vendorDateField: '<div><input type="text" class="js-datefield" id="vendor_date_display" data-target="vendor_date" value=""><input type="hidden" id="vendor_date" name="vendor_date" value=""></div>',
            refundPlaceholder: '0.00',
            screenshotUrl: '',
            items: [],
            alreadyPosted: { customerId: 7, vendorId: null, customer: 1000, vendor: 0 },
            timeline: [],
            returnedKey: 'paper_returned',
            approvedKey: 'approval_done',
            cancelledKey: 'cancelled',
            errors: {},
        });

        await nextTick();
        pageFinishes();

        const boxes = [...host.querySelectorAll('.js-datefield')];

        expect(boxes.length).toBe(2);

        // The second one, to prove it is not only the first that gets bound.
        const vendorBox = host.querySelector('#vendor_date_display');

        vendorBox.dispatchEvent(new window.Event('focus'));
        expect(popup(), 'the second box opens a calendar too').not.toBe(null);

        const day = [...popup().querySelectorAll('.dp-day')].find((b) => b.dataset.iso.endsWith('-09'));
        day.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

        expect(document.querySelector('#vendor_date').value).toMatch(/^\d{4}-\d{2}-09$/);
        // And the other box is untouched: two calendars, two hidden fields.
        expect(document.querySelector('#received_date').value).toBe('2026-08-01');
    });

    /*
     * A calendar left open when its screen goes has to let go of it. Holding the
     * reference makes open() refuse to open the next one for the same field —
     * the box is clicked, nothing happens, and there is nothing to see.
     */
    it('opens again after a screen carrying an open calendar is replaced', async () => {
        const first = mountScreen(GiveToVendor, giveToVendorProps);
        await nextTick();
        pageFinishes();

        first.querySelector('.js-datefield').dispatchEvent(new window.Event('focus'));
        expect(popup()).not.toBe(null);

        const abandoned = popup();

        // The screen goes while its calendar is still open.
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();

        const second = mountScreen(GiveToVendor, giveToVendorProps);
        await nextTick();
        document.dispatchEvent(new window.Event('acinfo:content'));

        second.querySelector('.js-datefield').dispatchEvent(new window.Event('focus'));

        expect(popup(), 'the new screen gets a calendar').not.toBe(null);
        expect(popup(), 'and it is not the one the old screen left behind').not.toBe(abandoned);
        expect(document.querySelectorAll('.dp-popup').length, 'and only one').toBe(1);
    });
});
