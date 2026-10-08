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

function mount(files = FILES, extra = {}) {
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
        ...extra,
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

/*
 * A vendor's name finds their work, not the office's beside it. Asked for by
 * the owner on 2026-09-28: the folder's heading names every vendor on it now
 * ("Sharma + in-house"), and matched against that, "sharma tr" found the
 * office's own TR on a folder Sharma had part of.
 */
describe('searching for a vendor on a folder shared with the office', () => {
    const SHARED = [{
        ...FILES[1],
        id: 3,
        file_no: 'F-00077',
        vendor: 'Sharma + in-house',
        // What Give to Vendor writes: it names the vendor on the folder's history.
        last_remark: 'HPT given to Sharma',
        items: [
            { ...FILES[1].items[0], id: 31, work_type: 'HPT', vendor: 'Sharma' },
            { ...FILES[1].items[1], id: 32, work_type: 'TR', vendor: null },
        ],
    }];

    it('finds only the work that is theirs', async () => {
        const host = mount(SHARED);

        await search(host, 'sharma hpt');
        expect(host.querySelector('.board__search .ui-hint').textContent.trim()).toBe('1 of 2 works.');

        await search(host, 'sharma tr');
        expect(host.querySelector('.board__search .ui-hint').textContent.trim()).toBe('Nothing here matches that.');
    });
});

/*
 * Paper Returned to Customer gives the charge back, so the server refuses it
 * without a reason just as it refuses a cancellation. Found in the health
 * check: the board asked a reason only of Cancelled, let a return with no
 * remark through, and the server sent the whole board back.
 */
describe('a return to the customer', () => {
    const RETURNABLE = [{
        ...FILES[0],
        statuses: { ...FILES[0].statuses, paper_returned: 'Paper Returned to Customer' },
    }];

    const choose = async (host, id, status) => {
        const select = host.querySelector(`select[name="statuses[${id}]"]`);
        select.value = status;
        select.dispatchEvent(new window.Event('change'));
        await nextTick();
    };

    const save = (host) => host.querySelector('button[type="submit"]');

    it('needs a reason before the board can be saved, as a cancellation does', async () => {
        const host = mount(RETURNABLE);

        await choose(host, 11, 'paper_returned');

        expect(save(host).disabled).toBe(true);
        expect(host.querySelector('.ui-card__foot').textContent).toContain('1 needs a reason');
        expect(host.querySelector('input[name="remarks[11]"]').getAttribute('placeholder')).toBe('A reason is required');

        const remark = host.querySelector('input[name="remarks[11]"]');
        remark.value = 'Customer took the papers to another agent';
        remark.dispatchEvent(new window.Event('input'));
        await nextTick();

        expect(save(host).disabled).toBe(false);
    });

    it('asks it of whatever the server says needs one', async () => {
        const host = mount(RETURNABLE, { reasonKeys: ['cancelled'] });

        await choose(host, 11, 'paper_returned');

        expect(save(host).disabled).toBe(false);
    });

    /*
     * The finding's own case, from the other side: a return the server
     * refused for want of a reason comes back still chosen, with the board
     * asking for the reason — not drawn fresh with the choice gone.
     */
    it('comes back from a refusal still chosen, asking for its reason', () => {
        const host = mount(RETURNABLE, {
            restore: {
                statuses: { 11: 'paper_returned' },
                was: { 11: 'file_dispatch' },
                remarks: { 11: '' },
                approved_on: {},
                was_approved_on: {},
                reason: 'Cancelling work, or returning its papers to the customer, changes the customer\'s balance, so it needs a reason.',
            },
        });

        expect(host.querySelector('select[name="statuses[11]"]').value).toBe('paper_returned');
        expect(save(host).disabled).toBe(true);
        expect(host.querySelector('.ui-card__foot').textContent).toContain('1 needs a reason');
    });
});

/*
 * Back from a save the server refused, the board used to be drawn fresh: every
 * status chosen, approval date and remark typed in that sitting was gone. What
 * the refused save held is put back where that is safe, by the same rules as
 * the Update dialog on the Work Report.
 */
describe('after a save the server refused', () => {
    const back = (over = {}) => ({
        statuses: { 11: 'approval_done', 21: 'file_dispatch', 22: 'cancelled' },
        was: { 11: 'file_dispatch', 21: 'file_dispatch', 22: 'file_dispatch' },
        remarks: { 11: '', 21: 'Handed to the runner', 22: 'Buyer backed out' },
        approved_on: { 11: '2026-08-19' },
        was_approved_on: { 11: '' },
        reason: 'Approval Done needs a screenshot. Attach one for: F-00031 · DRC',
        ...over,
    });

    const field = (host, name) => host.querySelector(`[name="${name}"]`);

    it('puts back the choices, the approval date and the remarks', () => {
        const host = mount(FILES, { restore: back() });

        expect(field(host, 'statuses[11]').value).toBe('approval_done');
        expect(field(host, 'approved_on[11]').value).toBe('2026-08-19');
        expect(field(host, 'remarks[21]').value).toBe('Handed to the runner');
        expect(field(host, 'statuses[22]').value).toBe('cancelled');
        expect(field(host, 'remarks[22]').value).toBe('Buyer backed out');

        // What was drawn is still what is posted as drawn.
        expect(field(host, 'was[11]').value).toBe('file_dispatch');

        const note = host.querySelector('.board__restored').textContent;
        expect(note).toContain('What you typed has been put back');
        // A file cannot be handed back to an upload box.
        expect(note).toContain('Attach any screenshot again');

        // The approval still wants its screenshot, so the board says so.
        expect(host.querySelector('.ui-card__foot').textContent).toContain('1 needs a screenshot');
    });

    /*
     * Refused because a colleague moved it: the choice was made against a
     * status that is no longer true, and filling it back in would send the
     * work straight back. Said on the work, so it is decided again.
     */
    it('does not put back a status chosen against one that has changed since', () => {
        const host = mount(FILES, { restore: back({ was: { 11: 'in_office', 21: 'file_dispatch', 22: 'file_dispatch' } }) });

        expect(field(host, 'statuses[11]').value).toBe('file_dispatch');
        expect(field(host, 'approved_on[11]')).toBe(null);

        const row = field(host, 'statuses[11]').closest('tr');
        expect(row.textContent).toContain('This work is now File Dispatch');
        expect(row.textContent).toContain('your choice of Approval Done was not put back');

        expect(host.querySelector('.board__restored').textContent).toContain('Some of what you typed has been put back');

        // The rest of the sitting is still put back.
        expect(field(host, 'statuses[22]').value).toBe('cancelled');
    });

    /*
     * A corrected approval date comes back only over the date it was
     * corrected from. One a colleague has changed since is theirs.
     */
    it('puts back a corrected approval date only over the one it corrected', () => {
        const approved = JSON.parse(JSON.stringify(FILES));
        approved[0].items[0].status = 'approval_done';
        approved[0].items[0].approved_on = '20-08-2026';
        approved[0].items[0].approved_on_value = '2026-08-20';
        approved[0].items[0].approved_on_iso = '2026-08-20';

        const same = back({
            statuses: { 11: 'approval_done' },
            was: { 11: 'approval_done' },
            remarks: {},
            approved_on: { 11: '2026-08-18' },
            was_approved_on: { 11: '2026-08-20' },
        });

        let host = mount(approved, { restore: same });
        expect(field(host, 'approved_on[11]').value).toBe('2026-08-18');

        host = mount(approved, { restore: { ...same, was_approved_on: { 11: '2026-08-15' } } });

        // The box shows the date as it is stored now, and sends nothing.
        expect(host.querySelector('input[type="date"]').value).toBe('2026-08-20');
        expect(field(host, 'approved_on[11]')).toBe(null);
    });

    it('puts back nothing when nothing was refused', () => {
        const host = mount(FILES, { restore: null });

        expect(field(host, 'statuses[11]').value).toBe('file_dispatch');
        expect(host.querySelector('.board__restored')).toBe(null);
        expect(host.querySelector('.ui-card__foot').textContent).toContain('No changes yet');
    });

    /*
     * A work that is not on this board — moved off this tab since — is simply
     * not there to put back; the server's message names it.
     */
    it('ignores works that are not on the board', () => {
        const host = mount(FILES, { restore: back({ statuses: { 99: 'cancelled' }, was: { 99: 'in_office' }, remarks: { 99: 'Gone' } }) });

        expect(host.querySelector('.board__restored')).toBe(null);
        expect(field(host, 'statuses[99]')).toBe(null);
    });
});

/*
 * "View the one on file", for an approval kept as a PDF.
 *
 * The address is a route with no extension, so the preview cannot tell a PDF
 * from it: drawn as an image, it would not load, and the office was told the
 * evidence had been removed from the server. The server says which it is.
 */
describe('an approval kept as a PDF', () => {
    it('opens in the viewer', async () => {
        const host = mount([{
            ...FILES[0],
            status: 'approval_done',
            items: [{
                ...FILES[0].items[0],
                status: 'approval_done',
                has_screenshot: true,
                screenshot_url: '/admin/file/1/approval/11',
                screenshot_is_pdf: true,
                approved_on: '21-08-2026',
                approved_on_iso: '2026-08-21',
            }],
        }]);

        [...host.querySelectorAll('a')]
            .find((a) => a.textContent.includes('View the one on file'))
            .dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
        await nextTick();

        expect(document.querySelector('.preview__frame').getAttribute('src')).toBe('/admin/file/1/approval/11');
        expect(document.querySelector('.preview__image')).toBe(null);
    });
});
