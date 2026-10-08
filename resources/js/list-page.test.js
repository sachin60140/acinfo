import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import DataGrid from './components/DataGrid.vue';
import WorkReport from './components/WorkReport.vue';

/*
 * The totals under a page of a longer list.
 *
 * All Work Files and the Work Report are sent the newest 500 files of finished
 * work and a link to the older ones (see ListPage). A bare "Total" under that
 * page, or under a party's band on it, reads as the whole list's — or the
 * whole of that party's — so the server names the totals for what they
 * cover, and the grid says it on every totals row.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const COLUMNS = [
    { key: 'file_no', label: 'File No.' },
    { key: 'billed', label: 'Billed', type: 'money' },
];

const ROWS = [
    { id: 1, party_id: 1, party_name: 'Customer One', file_no: 'F-1', billed: 1000 },
    { id: 2, party_id: 1, party_name: 'Customer One', file_no: 'F-2', billed: 2000 },
    { id: 3, party_id: 2, party_name: 'Customer Two', file_no: 'F-3', billed: 500 },
];

async function mount(component, extra = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(component, {
        columns: COLUMNS,
        rows: ROWS,
        groupBy: 'party_id',
        groupLabel: 'party_name',
        totals: { billed: 'sum' },
        perPage: 100,
        ...extra,
    });
    app.mount(host);
    mounted.push({ app, host });
    await nextTick();

    return host;
}

const label = (tr) => tr.querySelectorAll('td')[0].textContent.trim();

async function search(host, text) {
    const box = host.querySelector('input[type="search"]');
    box.value = text;
    box.dispatchEvent(new window.Event('input'));
    await nextTick();
}

describe('a page of a longer list', () => {
    it('names every totals row for what it covers', async () => {
        const host = await mount(DataGrid, { totalLabel: 'Total of those shown' });

        expect([...host.querySelectorAll('.grid__subtotal')].map(label))
            .toEqual(['Total of those shown', 'Total of those shown']);
        expect(label(host.querySelector('tfoot tr'))).toBe('Total of those shown');

        await search(host, 'F-1');

        expect(label(host.querySelector('tfoot tr'))).toBe('Total of those shown (filtered)');
    });

    it('is named so through the Work Report too', async () => {
        const host = await mount(WorkReport, { totalLabel: 'Total of those shown' });

        expect(label(host.querySelector('.grid__subtotal'))).toBe('Total of those shown');
        expect(label(host.querySelector('tfoot tr'))).toBe('Total of those shown');
    });
});

describe('a list on one page', () => {
    it('still calls its totals Total', async () => {
        const host = await mount(DataGrid);

        expect(label(host.querySelector('.grid__subtotal'))).toBe('Total');
        expect(label(host.querySelector('tfoot tr'))).toBe('Total');

        await search(host, 'F-1');

        expect(label(host.querySelector('tfoot tr'))).toBe('Total (filtered)');
    });
});

/*
 * An export of such a page.
 *
 * Found in review: the PDF, the print and the files exported from a page of a
 * longer list held that page alone under the list's own heading, with nothing
 * on them to say so — a customer's Work Report printed as all their files over
 * the newest 500 of 1,200. The server now hands the grid a heading that says
 * which page it is (ListPage::heading()), apart from the title: the title is
 * what the open column bands are remembered by, and must stay put page to page.
 */
describe('an export of a page of a longer list', () => {
    const TITLE = 'Work Files';
    const EXPORT = 'Work Files — newest 500 of 1,200 files';

    const button = (host, text) => [...host.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(text));
    const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

    afterEach(() => {
        vi.restoreAllMocks();
        delete window.pdfMake;
        localStorage.clear();
    });

    it('heads the print sheet with which page it is', async () => {
        const written = [];
        vi.spyOn(window, 'open').mockReturnValue({
            document: { write: (html) => written.push(html), close() {} },
            focus() {},
            print() {},
        });

        const host = await mount(DataGrid, { title: TITLE, exportTitle: EXPORT });
        button(host, 'Print').click();

        expect(written.join('')).toContain('<h1>Work Files — newest 500 of 1,200 files</h1>');
        expect(written.join('')).toContain('<title>Work Files — newest 500 of 1,200 files</title>');
    });

    it('heads the PDF with it, and names the PDF by it', async () => {
        let made = null;
        let named = null;

        // The library arrives as soon as it is asked for, and builds nothing.
        vi.spyOn(document.head, 'appendChild').mockImplementation((tag) => {
            setTimeout(() => tag.onload());

            return tag;
        });
        window.pdfMake = {
            createPdf: (doc) => {
                made = doc;

                return { download: (name) => { named = name; } };
            },
        };

        const host = await mount(DataGrid, { title: TITLE, exportTitle: EXPORT });
        button(host, 'PDF').click();
        await settle();
        await settle();
        await settle();

        expect(made?.content[0].text).toBe(EXPORT);
        expect(named).toBe('Work-Files-newest-500-of-1-200-files.pdf');
    });

    it('names the spreadsheet by it', async () => {
        const named = [];
        const created = URL.createObjectURL;
        const revoked = URL.revokeObjectURL;
        URL.createObjectURL = () => 'blob:export';
        URL.revokeObjectURL = () => {};
        vi.spyOn(window.HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
            named.push(this.download);
        });

        try {
            const host = await mount(DataGrid, { title: TITLE, exportTitle: EXPORT });
            button(host, 'CSV').click();
        } finally {
            URL.createObjectURL = created;
            URL.revokeObjectURL = revoked;
        }

        expect(named).toEqual(['Work-Files-newest-500-of-1-200-files.csv']);
    });

    it('leaves the column bands remembered by the title', async () => {
        localStorage.setItem('acinfo.grid.Work Files.groups', JSON.stringify(['money']));

        const host = await mount(DataGrid, {
            title: TITLE,
            exportTitle: EXPORT,
            columns: [...COLUMNS, { key: 'cost', label: 'Cost', type: 'money', group: 'money' }],
            groups: [{ key: 'money', label: 'Cost & margin' }],
        });

        expect([...host.querySelectorAll('thead th')].map((th) => th.textContent.trim())).toContain('Cost');
    });

    it('is headed by the title when the whole list is on the page', async () => {
        const written = [];
        vi.spyOn(window, 'open').mockReturnValue({
            document: { write: (html) => written.push(html), close() {} },
            focus() {},
            print() {},
        });

        const host = await mount(DataGrid, { title: TITLE });
        button(host, 'Print').click();

        expect(written.join('')).toContain('<h1>Work Files</h1>');
    });

    it('is handed through the Work Report to its grid', async () => {
        const written = [];
        vi.spyOn(window, 'open').mockReturnValue({
            document: { write: (html) => written.push(html), close() {} },
            focus() {},
            print() {},
        });

        const host = await mount(WorkReport, { title: 'Customer-wise Work Report', exportTitle: 'Customer-wise Work Report — newest 500 of 1,200 files' });
        button(host, 'Print').click();

        expect(written.join('')).toContain('<h1>Customer-wise Work Report — newest 500 of 1,200 files</h1>');
    });
});
