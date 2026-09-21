import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import FileForm from './components/FileForm.vue';

/*
 * The file form, in four cards instead of one.
 *
 * It used to put the fields, the works, the expenses and the documents in a
 * single card and ask the operator to scroll past all four to reach the one
 * they came for. Now each is its own card, and the three that most visits never
 * touch fold away to a line that says what is inside.
 *
 * What is tested here is the part that could go wrong quietly: a folded section
 * must still post, and a file with documents must not look like a file with
 * none just because the section is shut.
 */

const mounted = [];

const EXPENSE_TYPES = [{ id: 3, label: 'Challan' }, { id: 4, label: 'Notary' }];

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(FileForm, {
        action: '/admin/file/edit/1',
        csrf: 'test-token',
        indexUrl: '/admin/files',
        isEdit: true,
        statuses: { in_office: 'In Office', approval_done: 'Approval Done', paper_returned: 'Returned', cancelled: 'Cancelled' },
        workTypes: [{ id: 1, label: 'HPT', rate: null }, { id: 2, label: 'TR', rate: null }],
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
        expenses: [],
        expenseTypes: EXPENSE_TYPES,
        documents: [],
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const titles = (host) => [...host.querySelectorAll('.sect__title')].map((el) => el.textContent.trim());

const section = (host, title) =>
    [...host.querySelectorAll('.sect')].find((el) => el.querySelector('.sect__title')?.textContent.trim() === title);

const shut = (host, title) => section(host, title).classList.contains('is-shut');
const summaryOf = (host, title) => section(host, title).querySelector('.sect__summary')?.textContent ?? null;
const toggleOf = (host, title) => section(host, title).querySelector('.sect__toggle');

const EXPENSE = { id: 9, expense_type_id: 3, amount: '450', remark: 'RTO challan', spent_on: '2026-09-02' };
const DOC = { id: 5, name: 'RC scan', arrived: 'rc.pdf', uploaded: '02-09-2026', size: '210 KB', url: '/docs/5' };

beforeEach(() => {
    localStorage.clear();
});

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    localStorage.clear();
});

describe('the form in cards', () => {
    it('gives the works, the expenses and the documents a card each', () => {
        expect(titles(mount())).toEqual(['Works on This File', 'Expenses on this file', 'Documents']);
    });

    /* Receiving a file has no works to correct, nothing paid out and no PDFs. */
    it('offers none of them while a file is being received', () => {
        expect(titles(mount({ isEdit: false }))).toEqual([]);
    });

    it('saves once for the whole form, not once per card', () => {
        const host = mount();

        expect(host.querySelectorAll('button[type="submit"]')).toHaveLength(1);
        expect(host.querySelector('.wf-save')).not.toBeNull();
    });
});

describe('what a folded card says about itself', () => {
    it('folds the expenses away on a file with none', () => {
        const host = mount();

        expect(shut(host, 'Expenses on this file')).toBe(true);
        expect(summaryOf(host, 'Expenses on this file')).toBe('nothing paid out');
    });

    it('opens the expenses on a file that has some', () => {
        const host = mount({ expenses: [EXPENSE] });

        expect(shut(host, 'Expenses on this file')).toBe(false);
    });

    it('counts what it is holding once it is folded', async () => {
        const host = mount({ expenses: [EXPENSE] });

        await click(toggleOf(host, 'Expenses on this file'));

        expect(summaryOf(host, 'Expenses on this file')).toContain('1 expense');
        expect(summaryOf(host, 'Expenses on this file')).toContain('450');
    });

    /* A file with three PDFs must not read like a file with none. */
    it('says how many documents are on a file it has folded away', async () => {
        const host = mount({ documents: [DOC] });

        await click(toggleOf(host, 'Documents'));

        expect(summaryOf(host, 'Documents')).toBe('1 PDF');
    });

    it('folds the documents away on a file with none', () => {
        const host = mount();

        expect(shut(host, 'Documents')).toBe(true);
        expect(summaryOf(host, 'Documents')).toBe('nothing uploaded');
    });

    it('counts the works on the file', async () => {
        const host = mount({
            items: [
                { id: 11, work_type_id: 1, work_type: 'HPT', customer_amount: '1000', vendor_amount: null },
                { id: 12, work_type_id: 2, work_type: 'TR', customer_amount: '1000', vendor_amount: null },
            ],
        });

        await click(toggleOf(host, 'Works on This File'));

        expect(summaryOf(host, 'Works on This File')).toBe('2 works');
    });
});

/*
 * On a file of several works the charge and the vendor's rate are each work's,
 * and the figures at the top are their sums. Found in use: with the works
 * folded away, the vendor's figure could not be typed into and nothing said
 * where it could.
 */
describe('the totals at the top lead to the works', () => {
    const TWO = {
        values: { file_no: 'F-00061', status: 'in_office', work_type_id: 1, customer_id: 7, customer_amount: '7500', vendor_id: 3, vendor_amount: '4000' },
        vendors: [{ id: 3, label: 'Parwez Ji Muzaffarpur' }],
        items: [
            { id: 11, work_type_id: 1, work_type: 'HPT', customer_amount: '5000', vendor_amount: '4000', has_vendor: true },
            { id: 12, work_type_id: 2, work_type: 'TR', customer_amount: '2500', vendor_amount: null, has_vendor: true },
        ],
    };

    const button = (host, label) => [...host.querySelectorAll('button')].find((b) => b.textContent.trim() === label);

    beforeEach(() => {
        localStorage.setItem('acinfo.section.file.works', 'shut');
    });

    it('opens the works and puts the cursor in the rate still to agree', async () => {
        const host = mount(TWO);

        expect(shut(host, 'Works on This File')).toBe(true);
        expect(host.textContent).toContain('1 still without a rate');

        await click(button(host, 'Set rates'));

        expect(shut(host, 'Works on This File')).toBe(false);
        expect(document.activeElement?.getAttribute('name')).toBe('items[12][vendor_amount]');
    });

    it('goes to the charges from the charge', async () => {
        const host = mount(TWO);

        await click([...host.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Change per work')[0]);

        expect(shut(host, 'Works on This File')).toBe(false);
        expect(document.activeElement?.getAttribute('name')).toBe('items[11][customer_amount]');
    });

    it('leaves the fold the reader chose for their next visit', async () => {
        const host = mount(TWO);

        await click(button(host, 'Set rates'));

        expect(localStorage.getItem('acinfo.section.file.works')).toBe('shut');
    });

    it('does not count a work the office is doing itself as still without a rate', () => {
        const host = mount({
            ...TWO,
            items: [TWO.items[0], { ...TWO.items[1], has_vendor: false, in_house: true }],
        });

        expect(host.textContent).not.toContain('still without a rate');
        expect(button(host, 'Set rates')).toBeUndefined();
    });
});

describe('a folded card still posts', () => {
    it('keeps an expense on the form after it is folded away', async () => {
        const host = mount({ expenses: [EXPENSE] });

        await click(toggleOf(host, 'Expenses on this file'));

        const amount = host.querySelector('input[name="expenses[9][amount]"]');

        expect(amount).not.toBeNull();
        expect(amount.value).toBe('450');
        expect(amount.disabled).toBe(false);
    });

    it('keeps a document name on the form after it is folded away', async () => {
        const host = mount({ documents: [DOC] });

        await click(toggleOf(host, 'Documents'));

        const name = host.querySelector('input[name="document_names[5]"]');

        expect(name).not.toBeNull();
        expect(name.value).toBe('RC scan');
    });
});

describe('a card that has something to answer opens itself', () => {
    /*
     * A picked PDF is named from its own filename, so picking one asks nothing.
     * Clearing that name does: it is refused on save, and the warning saying so
     * is inside this card.
     */
    it('will not fold away a PDF left without a name', async () => {
        const host = mount();

        const picker = host.querySelector('.wf-docs__pick input[type="file"]');

        Object.defineProperty(picker, 'files', {
            configurable: true,
            value: [new File(['%PDF-1.4'], 'scan.pdf', { type: 'application/pdf' })],
        });
        picker.dispatchEvent(new Event('change'));
        await nextTick();

        const name = host.querySelector('.wf-docs__pick input[type="text"]');
        expect(name.value).toBe('scan');

        name.value = '';
        name.dispatchEvent(new Event('input'));
        await nextTick();

        expect(toggleOf(host, 'Documents').disabled).toBe(true);

        await click(toggleOf(host, 'Documents'));

        expect(shut(host, 'Documents')).toBe(false);
    });

    it('opens the expenses when the server refused one', () => {
        const host = mount({ errors: { 'expenses[9][amount]': 'An expense needs an amount.' } });

        expect(shut(host, 'Expenses on this file')).toBe(false);
        expect(toggleOf(host, 'Expenses on this file').disabled).toBe(true);
    });
});

async function click(el) {
    el.click();
    await nextTick();
}
