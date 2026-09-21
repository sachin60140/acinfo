import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import StatusBoard from './components/StatusBoard.vue';

/*
 * Finding one file on a board of thirty.
 *
 * The chips narrow by status, work type and vendor; none of them answers "the
 * customer is on the phone about BR06CL5310". The dangerous part is what
 * happens to a change the search then hides — a row taken out of the page takes
 * its inputs with it, and the change would simply not be saved.
 */

const mounted = [];

const FILES = [
    {
        id: 1,
        file_no: 'F-00031',
        registration_no: 'BR07BA1145',
        customer: 'Arman Qadri',
        vendor: 'Amit Ji Darbhanag',
        status: 'file_dispatch',
        status_label: 'File Dispatch',
        received_date: '12-08-2026',
        edit_url: '/admin/file/edit/1',
        last_remark: 'Given to Amit Ji Darbhanag',
        works: 1,
        settled: 0,
        statuses: { in_office: 'In Office', file_dispatch: 'File Dispatch', approval_done: 'Approval Done', cancelled: 'Cancelled' },
        items: [{
            id: 11,
            work_type: 'DRC',
            customer_amount: 2500,
            status: 'file_dispatch',
            has_screenshot: false,
            screenshot_url: null,
            approved_on: null,
            approved_on_value: '2026-08-21',
        }],
    },
    {
        id: 2,
        file_no: 'F-00010',
        registration_no: 'BR06CL5310',
        customer: 'Car4Sales',
        vendor: 'Dabloo Ji Muzaffarpur',
        status: 'file_dispatch',
        status_label: 'File Dispatch',
        received_date: '14-08-2026',
        edit_url: '/admin/file/edit/2',
        last_remark: null,
        works: 2,
        settled: 0,
        statuses: { in_office: 'In Office', file_dispatch: 'File Dispatch', approval_done: 'Approval Done', cancelled: 'Cancelled' },
        items: [
            {
                id: 21,
                work_type: 'HPT',
                customer_amount: 3000,
                status: 'file_dispatch',
                has_screenshot: false,
                screenshot_url: null,
                approved_on: null,
                approved_on_value: '2026-08-21',
            },
            {
                id: 22,
                work_type: 'TR',
                customer_amount: 2600,
                status: 'file_dispatch',
                has_screenshot: false,
                screenshot_url: null,
                approved_on: null,
                approved_on_value: '2026-08-21',
            },
        ],
    },
];

function mount(files = FILES) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(StatusBoard, {
        files,
        statuses: { in_office: 'In Office', file_dispatch: 'File Dispatch', approval_done: 'Approval Done', cancelled: 'Cancelled' },
        action: '/admin/file/status',
        csrf: 'test-token',
        resetUrl: '/admin/file/status',
        approvedKey: 'approval_done',
        cancelledKey: 'cancelled',
        today: '2026-08-21',
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

// A row the browser would actually draw.
const visibleRows = (host) =>
    [...host.querySelectorAll('tbody tr')].filter((tr) => tr.style.display !== 'none');

const search = async (host, text) => {
    const box = host.querySelector('.board__find input');
    box.value = text;
    box.dispatchEvent(new window.Event('input'));
    await nextTick();
};

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

describe('finding work on the board', () => {
    it('shows every work until something is typed', () => {
        const host = mount();

        // Three works, each under a heading of its own file.
        expect(host.querySelectorAll('.rcv-work, .board__file').length).toBeGreaterThan(0);
        expect(visibleRows(host).length).toBe(5);
        expect(host.querySelector('.board__search .ui-hint').textContent.trim()).toBe('3 works.');
    });

    it('finds a file by its number plate', async () => {
        const host = mount();

        await search(host, 'BR06CL5310');

        const rows = visibleRows(host);

        // One heading and the two works under it.
        expect(rows.length).toBe(3);
        expect(host.querySelector('.board__search .ui-hint').textContent.trim()).toBe('2 of 3 works.');
        expect(rows.map((tr) => tr.textContent).join(' ')).not.toContain('BR07BA1145');
    });

    it('finds by vendor, by customer and by work', async () => {
        const host = mount();

        for (const [term, expected] of [['dabloo', 2], ['arman', 1], ['DRC', 1], ['F-00031', 1]]) {
            await search(host, term);

            expect(
                host.querySelector('.board__search .ui-hint').textContent.trim(),
                `searching "${term}"`
            ).toBe(`${expected} of 3 works.`);
        }
    });

    /*
     * Two words narrow rather than widen: "dabloo tr" is that vendor's transfer
     * work, not everything of either.
     */
    it('narrows on each word typed', async () => {
        const host = mount();

        await search(host, 'dabloo');
        expect(host.querySelector('.board__search .ui-hint').textContent.trim()).toBe('2 of 3 works.');

        await search(host, 'dabloo tr');
        expect(host.querySelector('.board__search .ui-hint').textContent.trim()).toBe('1 of 3 works.');
    });

    it('says so plainly when nothing matches', async () => {
        const host = mount();

        await search(host, 'nothing like this');

        expect(visibleRows(host).length).toBe(0);
        expect(host.querySelector('.board__search .ui-hint').textContent.trim())
            .toBe('Nothing here matches that.');
    });

    /*
     * The heading belongs to the first work of a file that is on screen. Hiding
     * the first one would otherwise leave the rest of the folder with nothing
     * above it saying which file they are.
     */
    it('keeps a heading over whatever is left of a folder', async () => {
        const host = mount();

        await search(host, 'TR');

        const headings = [...host.querySelectorAll('.board__file')]
            .filter((tr) => tr.style.display !== 'none');

        expect(headings.length).toBe(1);
        expect(headings[0].textContent).toContain('F-00010');
    });

    /*
     * The important one. A change made, then searched past, is still on the
     * form and still saved — so the row is hidden, never removed, and the
     * footer says how many are out of sight.
     */
    it('keeps a change that the search hides, and says it is keeping it', async () => {
        const host = mount();

        const select = host.querySelector('[name="statuses[11]"]');
        select.value = 'in_office';
        select.dispatchEvent(new window.Event('change'));
        await nextTick();

        await search(host, 'BR06CL5310');

        // Out of sight, still on the form, still going to be saved.
        expect(host.querySelector('[name="statuses[11]"]')).not.toBe(null);
        expect(host.querySelector('[name="statuses[11]"]').value).toBe('in_office');

        expect(host.querySelector('.ui-card__foot .ui-hint').textContent)
            .toContain('1 not shown by the search — it is still saved');
    });
});

/*
 * What each work said when the board was drawn goes with every save, so the
 * server can leave alone a row a colleague has moved on since and refuse a
 * change made against a status that is no longer true.
 */
describe('saving from a board that may be out of date', () => {
    it('posts, for every work, the status it showed when drawn', () => {
        const host = mount();

        const was = Object.fromEntries(
            [...host.querySelectorAll('input[type="hidden"][name^="was["]')].map((input) => [input.name, input.value])
        );

        expect(was).toEqual({ 'was[11]': 'file_dispatch', 'was[21]': 'file_dispatch', 'was[22]': 'file_dispatch' });
    });

    it('posts the approval date it drew for an approved work, and none for the rest', () => {
        const approved = JSON.parse(JSON.stringify(FILES));
        approved[0].items[0].status = 'approval_done';
        approved[0].items[0].approved_on = '20-08-2026';
        approved[0].items[0].approved_on_value = '2026-08-20';
        approved[0].items[0].approved_on_iso = '2026-08-20';

        const host = mount(approved);

        expect(host.querySelector('input[name="was_approved_on[11]"]').value).toBe('2026-08-20');
        // Not approved when drawn: the date box holds today as a suggestion, which is not a date the work had.
        expect(host.querySelector('input[name="was_approved_on[21]"]').value).toBe('');
    });

    it('sends no approval date from an approved row nobody touched', () => {
        const approved = JSON.parse(JSON.stringify(FILES));
        approved[0].items[0].status = 'approval_done';
        approved[0].items[0].approved_on_value = '2026-08-21';
        approved[0].items[0].approved_on_iso = null;

        const host = mount(approved);

        // The box is drawn, filled with a suggestion, and is not sent.
        expect(host.querySelector('input[type="date"]')).not.toBe(null);
        expect(host.querySelector('input[name="approved_on[11]"]')).toBe(null);
    });

    it('sends the approval date once it is changed', async () => {
        const approved = JSON.parse(JSON.stringify(FILES));
        approved[0].items[0].status = 'approval_done';
        approved[0].items[0].approved_on_value = '2026-08-20';
        approved[0].items[0].approved_on_iso = '2026-08-20';

        const host = mount(approved);

        const box = host.querySelector('input[type="date"]');
        box.value = '2026-08-18';
        box.dispatchEvent(new window.Event('input'));
        await nextTick();

        expect(host.querySelector('input[name="approved_on[11]"]').value).toBe('2026-08-18');
    });

    it('sends the approval date for a work being approved now', async () => {
        const host = mount();

        const select = host.querySelector('select[name="statuses[11]"]');
        select.value = 'approval_done';
        select.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(host.querySelector('input[name="approved_on[11]"]').value).toBe('2026-08-21');
    });

    it('posts no date for an approval that never had one, whatever the box suggests', () => {
        const approved = JSON.parse(JSON.stringify(FILES));
        approved[0].items[0].status = 'approval_done';
        approved[0].items[0].approved_on_value = '2026-08-21';
        approved[0].items[0].approved_on_iso = null;

        const host = mount(approved);

        expect(host.querySelector('input[name="was_approved_on[11]"]').value).toBe('');
    });

    it('keeps posting the drawn status after a different one is chosen', async () => {
        const host = mount();

        const select = host.querySelector('select[name="statuses[11]"]');
        select.value = 'in_office';
        select.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(host.querySelector('input[name="was[11]"]').value).toBe('file_dispatch');
        expect(host.querySelector('select[name="statuses[11]"]').value).toBe('in_office');
    });
});
