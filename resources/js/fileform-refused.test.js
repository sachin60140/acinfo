import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import FileForm from './components/FileForm.vue';

/*
 * An edit save sent back, and the page it comes back to.
 *
 * Found in the health check: a save refused for a PDF over 10 MB came back
 * with only the boxes at the top as typed. The corrected charge on a second
 * work, the work and the expense added and the reason for the price were all
 * gone, and the next save said "updated successfully" without them. The
 * server now hands the page what the refused save posted (typed); what is held
 * here is that the form starts from it, row by row, and still compares a price
 * with what is stored, so the reason typed for it is on the page to be sent.
 *
 * And the sizes: a PDF or a screenshot too large is said when it is picked,
 * with the server's own figure, rather than by a refusal after the upload.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    try {
        localStorage.clear();
    } catch {
        // Nothing remembered.
    }
});

const HPT = { id: 1, label: 'HPT', rate: null };
const TR = { id: 2, label: 'TR', rate: null };
const HPA = { id: 3, label: 'HPA', rate: '1500.00' };

const CHALLAN = { id: 4, label: 'Transfer Challan', amount: 450 };

/** A folder of two works, priced, the transfer still on the desk. */
const TWO_WORKS = [
    { id: 11, work_type_id: 1, work_type: 'HPT', customer_amount: 3000, vendor_amount: null, status: 'in_office', status_label: 'In Office', in_house: false, has_vendor: false },
    { id: 12, work_type_id: 2, work_type: 'TR', customer_amount: 3000, vendor_amount: 2000, status: 'in_office', status_label: 'In Office', in_house: false, has_vendor: true },
];

const EXPENSES = [
    { id: 21, expense_type_id: 4, type: 'Transfer Challan', amount: 300, spent_on: '2026-09-02', remark: null },
    { id: 22, expense_type_id: 4, type: 'Transfer Challan', amount: 120, spent_on: '2026-09-02', remark: null },
];

const DOCUMENTS = [
    { id: 31, name: 'scan_0001', arrived: 'scan_0001.pdf', size: '1 KB', uploaded: '01-09-2026', url: '/admin/file/1/document/31' },
    { id: 32, name: 'scan_0002', arrived: 'scan_0002.pdf', size: '1 KB', uploaded: '01-09-2026', url: '/admin/file/1/document/32' },
];

/** What the server hands back after a refusal; see WorkFileController::typedEdit(). */
const TYPED = {
    items: {
        11: { work_type_id: 1, customer_amount: '3000', vendor_amount: '', in_house: true },
        12: { work_type_id: 2, customer_amount: '3500', vendor_amount: '2000', in_house: false },
    },
    removeWorks: [],
    newWorks: [{ work_type_id: 3, amount: '2500', vendor_amount: '1500' }],
    priceRemark: 'Customer agreed the higher charge',
    expenses: {
        21: { expense_type_id: 4, amount: '350', spent_on: '2026-09-02', remark: 'Corrected' },
    },
    removeExpenses: [22],
    newExpenses: [{ expense_type_id: 4, amount: '450', spent_on: '2026-09-03', remark: 'Transfer challan' }],
    documentNames: { 31: 'RC' },
    removeDocuments: [32],
    newDocuments: [{ key: 3, title: 'Form 29' }],
};

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const { values = {}, ...rest } = overrides;

    const app = createApp(FileForm, {
        action: '/admin/file/edit/1',
        csrf: 'test-token',
        indexUrl: '/admin/files',
        isEdit: true,
        statuses: { in_office: 'In Office', approval_done: 'Approval Done', paper_returned: 'Returned', cancelled: 'Cancelled' },
        workTypes: [HPT, TR, HPA],
        customers: [{ id: 7, label: 'Car4Sales', balance: 0 }],
        vendors: [{ id: 9, label: 'Test vendor', balance: 0 }],
        values: {
            file_no: 'F-00061',
            status: 'in_office',
            work_type_id: 1,
            customer_id: 7,
            customer_amount: '6000',
            vendor_id: '',
            vendor_amount: '',
            ...values,
        },
        priced: { customer_amount: 6000, vendor_amount: null },
        receivedDateField: '<input type="hidden" name="received_date" value="2026-09-01">',
        vendorDateField: '<input type="hidden" name="vendor_date" value="">',
        refundPlaceholder: '0.00',
        screenshotUrl: '',
        items: TWO_WORKS,
        alreadyPosted: { customerId: 7, vendorId: null, customer: 6000, vendor: 0 },
        timeline: [],
        returnedKey: 'paper_returned',
        approvedKey: 'approval_done',
        cancelledKey: 'cancelled',
        errors: {},
        expenses: EXPENSES,
        expenseTypes: [CHALLAN],
        today: '2026-10-07',
        documents: DOCUMENTS,
        pdfMaxKb: 10240,
        screenshotMaxKb: 4096,
        ...rest,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const box = (host, name) => host.querySelector(`[name="${name}"]`);
const save = (host) => [...host.querySelectorAll('button[type="submit"]')].pop();
const docRows = (host) => [...host.querySelectorAll('.wf-docs__pick')];

/**
 * What a browser does when a file is picked: the input now holds it, and its
 * value names it until a script clears it.
 */
async function pick(input, name, size) {
    let value = `C:\\fakepath\\${name}`;

    Object.defineProperty(input, 'files', {
        configurable: true,
        // Only the name and the size are read; no need to hold megabytes.
        value: [{ name, size, type: name.endsWith('.pdf') ? 'application/pdf' : 'image/png' }],
    });
    Object.defineProperty(input, 'value', {
        configurable: true,
        get: () => value,
        set: (now) => {
            value = now;
        },
    });
    input.dispatchEvent(new Event('change'));
    await nextTick();
}

const MB = 1024 * 1024;

describe('a save sent back', () => {
    it('starts every work from what was typed', () => {
        const host = mount({ typed: TYPED });

        expect(box(host, 'items[12][customer_amount]').value).toBe('3500');
        expect(box(host, 'items[12][vendor_amount]').value).toBe('2000');
        expect(box(host, 'items[11][in_house]').checked).toBe(true);
    });

    /*
     * Compared with what is stored, a charge typed and sent back is still a
     * charge moving: the box for its reason is there, holding the reason
     * typed, and the save is not held up asking for it again.
     */
    it('still asks why the price moved, with the reason already typed', () => {
        const host = mount({ typed: TYPED });

        expect(host.querySelector('.wf-why')).not.toBeNull();
        expect(host.querySelector('.wf-why').textContent).toContain('TR charged');
        expect(box(host, 'price_remark').value).toBe('Customer agreed the higher charge');
        expect(save(host).disabled).toBe(false);
    });

    it('brings back the work being added', () => {
        const host = mount({ typed: TYPED });

        expect(box(host, 'new_works[0][work_type_id]').value).toBe('3');
        expect(box(host, 'new_works[0][amount]').value).toBe('2500');
        expect(box(host, 'new_works[0][vendor_amount]').value).toBe('1500');
    });

    it('brings back work marked to come off, still marked', () => {
        const host = mount({ typed: { ...TYPED, removeWorks: [12] } });

        expect(host.querySelector('input[name="remove_works[]"]').value).toBe('12');
        expect(box(host, 'items[12][customer_amount]')).toBeNull();
    });

    it('brings back the expenses: corrected, taken off and added', () => {
        const host = mount({ typed: TYPED });

        expect(box(host, 'expenses[21][amount]').value).toBe('350');
        expect(box(host, 'expenses[21][remark]').value).toBe('Corrected');
        expect(host.querySelector('input[name="remove_expenses[]"]').value).toBe('22');

        expect(box(host, 'new_expenses[0][expense_type_id]').value).toBe('4');
        expect(box(host, 'new_expenses[0][amount]').value).toBe('450');
        expect(box(host, 'new_expenses[0][spent_on]').value).toBe('2026-09-03');
        expect(box(host, 'new_expenses[0][remark]').value).toBe('Transfer challan');
    });

    it('brings back the names typed for the documents, and which were coming off', () => {
        const host = mount({ typed: TYPED });

        expect(box(host, 'document_names[31]').value).toBe('RC');
        expect(host.querySelector('input[name="remove_documents[]"]').value).toBe('32');
    });

    /*
     * The PDF itself cannot come back — no browser lets a page fill a file
     * box — so its name does, in a row of its own, saying the PDF is wanted
     * again. Under the key it was posted with, so the refusal names its row.
     */
    it('brings back the name typed for a new PDF, and asks for the PDF again', () => {
        const host = mount({
            typed: TYPED,
            errors: { 'documents.3.file': 'Each PDF must be 10 MB or smaller.' },
        });

        const row = docRows(host)[0];

        expect(row.querySelector('[name="documents[3][title]"]').value).toBe('Form 29');
        expect(row.querySelector('[name="documents[3][file]"]')).not.toBeNull();
        expect(row.textContent).toContain('Each PDF must be 10 MB or smaller.');
        expect(row.textContent).toContain('Choose the PDF again');

        // A spare row after it, as ever, under a key of its own.
        expect(docRows(host)).toHaveLength(2);
        expect(docRows(host)[1].querySelector('input[type="file"]').name).not.toBe('documents[3][file]');
    });

    /*
     * Refused for something else — a price with no reason — on a file with no
     * documents yet, whose Documents section this browser keeps folded. The
     * name comes back with no PDF beside it, and a name with no PDF is refused
     * as well; folded away, nobody would see why until the next refusal.
     */
    it('holds the documents open while a PDF is wanted again', () => {
        localStorage.setItem('acinfo.section.file.documents', 'shut');

        const host = mount({ typed: TYPED, documents: [], errors: {} });
        const section = [...host.querySelectorAll('.sect')]
            .find((one) => one.querySelector('.sect__title').textContent === 'Documents');

        expect(section.querySelector('.sect__toggle').getAttribute('aria-expanded')).toBe('true');
        expect(docRows(host)[0].textContent).toContain('Choose the PDF again');
    });

    it('stops asking for the PDF once one is chosen', async () => {
        const host = mount({ typed: TYPED, errors: { 'documents.3.file': 'Each PDF must be 10 MB or smaller.' } });

        const row = docRows(host)[0];
        await pick(row.querySelector('input[type="file"]'), 'form-29-small.pdf', 2 * MB);

        expect(row.textContent).not.toContain('Choose the PDF again');
        expect(row.textContent).not.toContain('10 MB or smaller');
        // The name typed is the office's and stays.
        expect(row.querySelector('[name="documents[3][title]"]').value).toBe('Form 29');
    });

    /*
     * A file of one work is priced in the boxes at the top, which come back
     * as typed. Compared with what is stored rather than with themselves, a
     * charge typed and sent back still asks its reason — with the reason
     * typed, so the next save is not refused for the want of it.
     */
    it('on a file of one work, compares the boxes with what is stored', () => {
        const host = mount({
            items: [TWO_WORKS[0]],
            values: { customer_amount: '3600' },
            priced: { customer_amount: 3000, vendor_amount: null },
            typed: { ...TYPED, items: {}, newWorks: [], priceRemark: 'Customer agreed it' },
        });

        expect(host.querySelector('.wf-why')).not.toBeNull();
        expect(host.querySelector('.wf-why').textContent).toContain('the charge');
        expect(box(host, 'price_remark').value).toBe('Customer agreed it');
        expect(save(host).disabled).toBe(false);
    });

    it('starts from the file when nothing was sent back', () => {
        const host = mount();

        expect(box(host, 'items[12][customer_amount]').value).toBe('3000');
        expect(box(host, 'items[11][in_house]').checked).toBe(false);
        expect(host.querySelector('.wf-why')).toBeNull();
        expect(box(host, 'new_works[0][work_type_id]')).toBeNull();
        expect(box(host, 'new_expenses[0][amount]')).toBeNull();
        expect(box(host, 'expenses[21][amount]').value).toBe('300');
        expect(box(host, 'document_names[31]').value).toBe('scan_0001');
        expect(host.querySelector('input[name="remove_expenses[]"]')).toBeNull();
        expect(docRows(host)).toHaveLength(1);
    });
});

describe('a file too large to take', () => {
    it('is said when a PDF is picked, with the figure the save uses', async () => {
        const host = mount();
        const row = docRows(host)[0];
        const input = row.querySelector('input[type="file"]');

        await pick(input, 'scan-11mb.pdf', 11 * MB);

        expect(row.textContent).toContain('scan-11mb.pdf');
        expect(row.textContent).toContain('10 MB or smaller');
        // Not taken: no name suggested from it, no spare row for the next,
        // and nothing counted as being added.
        expect(row.querySelector('.wf-docs__title').value).toBe('');
        expect(docRows(host)).toHaveLength(1);
        expect(host.textContent).not.toContain('will be added when you save');
        expect(input.value).toBe('');
    });

    it('takes a PDF of exactly the limit', async () => {
        const host = mount({ pdfMaxKb: 2048 });
        const row = docRows(host)[0];

        await pick(row.querySelector('input[type="file"]'), 'form-29.pdf', 2048 * 1024);

        expect(row.textContent).not.toContain('or smaller');
        expect(row.querySelector('.wf-docs__title').value).toBe('form 29');
        expect(docRows(host)).toHaveLength(2);
    });

    it('lets a smaller PDF be picked in its place', async () => {
        const host = mount();
        const row = docRows(host)[0];
        const input = row.querySelector('input[type="file"]');

        await pick(input, 'scan-11mb.pdf', 11 * MB);
        await pick(input, 'scan-3mb.pdf', 3 * MB);

        expect(row.textContent).not.toContain('or smaller');
        expect(row.querySelector('.wf-docs__title').value).toBe('scan 3mb');
    });

    it('is said when an approval screenshot is picked', async () => {
        const host = mount({ values: { status: 'approval_done' }, items: [TWO_WORKS[0]], typed: null });
        const input = box(host, 'approval_screenshot');

        await pick(input, 'approval.png', 5 * MB);

        const field = input.closest('.ui-field');

        expect(field.textContent).toContain('approval.png');
        expect(field.textContent).toContain('4 MB or smaller');
        expect(input.value).toBe('');

        await pick(input, 'approval-small.png', 1 * MB);

        expect(field.textContent).not.toContain('4 MB or smaller');
    });
});
