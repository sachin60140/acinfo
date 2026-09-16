import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import FileForm from './components/FileForm.vue';

/*
 * Naming the PDFs on a file.
 *
 * The customer is offered every document on the file, from a list, so each one
 * needs a name a person gave it. What can go wrong is here rather than on the
 * server: a name that lands against the wrong file after a row is removed, a
 * suggested name that overwrites one already typed, or a form with no spare row
 * so the next PDF takes two clicks.
 */

const mounted = [];

function mount(documents = []) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(FileForm, {
        action: '/admin/file/edit/1',
        csrf: 'test-token',
        indexUrl: '/admin/files',
        isEdit: true,
        statuses: { in_office: 'In Office', approval_done: 'Approval Done', paper_returned: 'Returned', cancelled: 'Cancelled' },
        workTypes: [{ id: 1, label: 'HPT', rate: null }],
        customers: [{ id: 7, label: 'Car4Sales', balance: 0 }],
        vendors: [],
        values: { file_no: 'F-00061', status: 'in_office', work_type_id: 1, customer_id: 7, customer_amount: '2000' },
        receivedDateField: '<input type="hidden" name="received_date" value="2026-08-01">',
        vendorDateField: '<input type="hidden" name="vendor_date" value="">',
        refundPlaceholder: '0.00',
        screenshotUrl: '',
        items: [],
        alreadyPosted: { customerId: 7, vendorId: null, customer: 2000, vendor: 0 },
        timeline: [],
        returnedKey: 'paper_returned',
        approvedKey: 'approval_done',
        cancelledKey: 'cancelled',
        errors: {},
        documents,
    });

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
});

const rows = (host) => [...host.querySelectorAll('.wf-docs__pick')];
const fileIn = (row) => row.querySelector('input[type="file"]');
const nameIn = (row) => row.querySelector('.wf-docs__title');

/** What a browser does when a PDF is picked: the input now holds it. */
async function pick(input, filename) {
    Object.defineProperty(input, 'files', {
        configurable: true,
        value: [new File(['%PDF-1.4'], filename, { type: 'application/pdf' })],
    });
    input.dispatchEvent(new Event('change'));
    await nextTick();
}

async function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await nextTick();
}

describe('adding PDFs to a file', () => {
    it('offers one empty row to start', () => {
        const host = mount();

        expect(rows(host)).toHaveLength(1);
        expect(nameIn(rows(host)[0]).value).toBe('');
    });

    it('suggests a name from the file, readable rather than as the scanner wrote it', async () => {
        const host = mount();

        await pick(fileIn(rows(host)[0]), 'Form-34_signed.PDF');

        expect(nameIn(rows(host)[0]).value).toBe('Form 34 signed');
    });

    it('never overwrites a name already typed', async () => {
        const host = mount();
        const row = rows(host)[0];

        await type(nameIn(row), 'RC');
        await pick(fileIn(row), 'scan_00123.pdf');

        expect(nameIn(row).value).toBe('RC');
    });

    it('keeps a spare row at the foot, so the next PDF is one click away', async () => {
        const host = mount();

        await pick(fileIn(rows(host)[0]), 'a.pdf');
        expect(rows(host)).toHaveLength(2);

        await pick(fileIn(rows(host)[1]), 'b.pdf');
        expect(rows(host)).toHaveLength(3);

        // Picking again in a row that is not the last adds nothing.
        await pick(fileIn(rows(host)[0]), 'c.pdf');
        expect(rows(host)).toHaveLength(3);
    });

    /*
     * The reason the rows are keyed rather than numbered by position. A row
     * taken out of the middle must not slide the names after it onto the
     * wrong files — each row's file and name post under the same key.
     */
    it('posts each name beside its own file, even after a row in the middle goes', async () => {
        const host = mount();

        await pick(fileIn(rows(host)[0]), 'rc.pdf');
        await pick(fileIn(rows(host)[1]), 'wrong.pdf');
        await pick(fileIn(rows(host)[2]), 'noc.pdf');

        rows(host)[1].querySelector('button').click();
        await nextTick();

        const left = rows(host).filter((row) => nameIn(row).value);

        expect(left.map((row) => nameIn(row).value)).toEqual(['rc', 'noc']);

        for (const row of left) {
            const key = fileIn(row).name.match(/^documents\[(\d+)\]\[file\]$/)[1];

            expect(nameIn(row).name).toBe(`documents[${key}][title]`);
        }

        // And the removed file is off the page, so it is not submitted.
        expect([...host.querySelectorAll('.wf-docs__pick input[type="file"]')]).toHaveLength(3);
    });

    it('says so, in place, when a PDF has no name', async () => {
        const host = mount();
        const row = rows(host)[0];

        await pick(fileIn(row), 'x.pdf');
        await type(nameIn(row), '   ');

        expect(nameIn(row).classList.contains('is-invalid')).toBe(true);
        expect(host.querySelector('.wf-docs__warn')).not.toBeNull();
    });

    it('offers no remove button on the empty spare row', () => {
        const host = mount();

        expect(rows(host)[0].querySelector('button')).toBeNull();
    });
});

describe('the PDFs already on the file', () => {
    const DOCS = [
        { id: 41, name: 'Form 29', arrived: 'scan_2.pdf', size: '240 KB', uploaded: '12-09-2026', url: '/admin/file/1/document/41' },
        { id: 40, name: 'scan_1', arrived: 'scan_1.pdf', size: '120 KB', uploaded: '11-09-2026', url: '/admin/file/1/document/40' },
    ];

    it('can each be renamed where they stand', () => {
        const host = mount(DOCS);

        const boxes = [...host.querySelectorAll('input[name^="document_names["]')];

        expect(boxes.map((box) => [box.name, box.value])).toEqual([
            ['document_names[41]', 'Form 29'],
            ['document_names[40]', 'scan_1'],
        ]);
    });

    it('still say what the scanner called them, where that differs from the name', () => {
        const host = mount(DOCS);

        const meta = [...host.querySelectorAll('.wf-docs__row .wf-docs__meta')].map((el) => el.textContent);

        expect(meta[0]).toContain('scan_2.pdf');
        // "scan_1" and "scan_1.pdf" are the same thing said twice; once is enough.
        expect(meta[1]).not.toContain('scan_1.pdf');
    });

    it('stop sending a new name once marked to be taken off', async () => {
        const host = mount(DOCS);
        const first = host.querySelector('.wf-docs__row');

        first.querySelector('button').click();
        await nextTick();

        expect(first.querySelector('input[name="document_names[41]"]').disabled).toBe(true);
        expect(first.querySelector('input[name="remove_documents[]"]').value).toBe('41');
    });
});
