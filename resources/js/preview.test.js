import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick, ref } from 'vue';
import FilePreview from './components/FilePreview.vue';
import DataGrid from './components/DataGrid.vue';
import Loader from './components/Loader.vue';
import { exportHeader, exportRows } from './exports';

/*
 * The approval document opens over the page it was clicked on.
 *
 * Checking a screenshot is a glance — is this the right vehicle, is the date
 * the one on the file — and it happens part way down a list that is filtered
 * and scrolled. Following the link threw that away. These assert the dialog
 * appears, that the link is not followed, and that there is a way back out,
 * none of which the PHP suite can see.
 */

const mounted = [];

function mount(component, props) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(component, props);
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

    // Teleported nodes are removed by unmount; the scroll lock is the
    // component's own doing and has to have been undone.
    document.body.style.overflow = '';
});

describe('the approval document', () => {
    it('is shown when there is one, and not before', async () => {
        const src = ref(null);

        mount({
            components: { FilePreview },
            setup: () => ({ src }),
            template: '<FilePreview :src="src" @close="src = null" />',
        });

        expect(document.querySelector('.preview')).toBe(null);

        src.value = '/uploads/approval/file-1.png';
        await nextTick();

        expect(document.querySelector('.preview')).not.toBe(null);
        expect(document.querySelector('.preview__image').getAttribute('src'))
            .toBe('/uploads/approval/file-1.png');
    });

    it('gives a PDF the browser viewer rather than an img that cannot show it', async () => {
        mount(FilePreview, { src: '/uploads/approval/file-2.pdf' });
        await nextTick();

        expect(document.querySelector('.preview__frame')).not.toBe(null);
        expect(document.querySelector('.preview__image')).toBe(null);
    });

    it('closes on Escape, so the keyboard is not a dead end', async () => {
        const src = ref('/uploads/approval/file-3.png');

        mount({
            components: { FilePreview },
            setup: () => ({ src }),
            template: '<FilePreview :src="src" @close="src = null" />',
        });

        await nextTick();
        expect(document.querySelector('.preview')).not.toBe(null);

        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();

        expect(document.querySelector('.preview')).toBe(null);
    });

    it('closes on the button, and gives the page its scrolling back', async () => {
        const src = ref('/uploads/approval/file-4.png');

        mount({
            components: { FilePreview },
            setup: () => ({ src }),
            template: '<FilePreview :src="src" @close="src = null" />',
        });

        await nextTick();
        expect(document.body.style.overflow).toBe('hidden');

        document.querySelector('.preview__close').click();
        await nextTick();

        expect(document.querySelector('.preview')).toBe(null);
        expect(document.body.style.overflow).toBe('');
    });

    /*
     * A document that will not load says so. An empty box reads as the page
     * having broken, and the operator cannot tell whether the file is missing
     * or the screen is.
     */
    it('says so when the document will not load', async () => {
        mount(FilePreview, { src: '/uploads/approval/gone.png' });
        await nextTick();

        document.querySelector('.preview__image').dispatchEvent(new window.Event('error'));
        await nextTick();

        expect(document.querySelector('.preview__missing')).not.toBe(null);
        expect(document.querySelector('.preview__missing').textContent)
            .toContain('could not be loaded');
    });
});

describe('the files list', () => {
    const columns = [
        { key: 'file_no', label: 'File No.' },
        {
            key: 'status',
            label: 'Status',
            type: 'badge',
            sub: 'screenshot',
            subLinkTo: 'screenshot_url',
        },
    ];

    const rows = [{
        id: 1,
        file_no: 'F-16028',
        status: 'Partly Approved',
        status_key: 'partly_approved',
        screenshot: 'Approval screenshot on file',
        screenshot_url: '/uploads/approval/f-16028.png',
    }];

    it('opens the screenshot in place instead of following the link', async () => {
        const host = mount(DataGrid, { columns, rows, title: 'Work Files' });
        await nextTick();

        const link = [...host.querySelectorAll('a')]
            .find((a) => a.textContent.includes('Approval screenshot on file'));

        expect(link).toBeTruthy();

        const click = new window.MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(click);
        await nextTick();

        // Not followed: jsdom would report a navigation, and on the real page
        // the reader would lose the filter and the scroll position.
        expect(click.defaultPrevented).toBe(true);

        expect(document.querySelector('.preview__image').getAttribute('src'))
            .toBe('/uploads/approval/f-16028.png');
    });

    it('still names the document it is about', async () => {
        const host = mount(DataGrid, { columns, rows, title: 'Work Files' });
        await nextTick();

        [...host.querySelectorAll('a')]
            .find((a) => a.textContent.includes('Approval screenshot on file'))
            .dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

        await nextTick();

        expect(document.querySelector('.preview__title').textContent.trim())
            .toBe('Approval screenshot on file');
    });
});

/*
 * The loading overlay and a link the page handles itself.
 *
 * The overlay is raised on any click heading for another page, and taken down
 * when that page arrives. A click the page cancels never goes anywhere, so
 * nothing ever took it down again: opening the approval screenshot left the
 * spinner sitting over the list, and closing the dialog revealed it still
 * there.
 */
describe('the loading overlay', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function clickLink(onClick) {
        const link = document.createElement('a');
        link.href = '/admin/files';
        link.textContent = 'Somewhere';

        if (onClick) {
            link.addEventListener('click', onClick);
        }

        document.body.appendChild(link);
        link.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

        return link;
    }

    it('stays down for a click the page cancels', async () => {
        mount(Loader, {});
        await nextTick();

        const link = clickLink((event) => event.preventDefault());

        vi.advanceTimersByTime(1000);
        await nextTick();

        expect(document.querySelector('.app-loader')).toBe(null);

        link.remove();
    });

    it('still comes up for a click that really is going somewhere', async () => {
        mount(Loader, {});
        await nextTick();

        const link = clickLink(null);

        vi.advanceTimersByTime(1000);
        await nextTick();

        expect(document.querySelector('.app-loader')).not.toBe(null);

        link.remove();
    });
});

/*
 * Detail a spreadsheet can use and a screen has no room for.
 *
 * "Partly Approved" says a folder's works disagree and never which way. In
 * Excel the questions are "every file with a transfer still pending" and
 * "everything approved last week", and neither can be asked of a sentence in a
 * status cell — they need a column each.
 */
describe('export-only columns', () => {
    const columns = [
        { key: 'file_no', label: 'File No.' },
        { key: 'status', label: 'Status', type: 'badge' },
        { key: 'works_done', label: 'Approved Works', exportOnly: true },
        { key: 'works_approved_on', label: 'Approved On', exportOnly: true },
        { key: 'works_pending', label: 'Pending Works', exportOnly: true },
        { key: 'action', label: 'Action', exportable: false },
    ];

    const rows = [{
        id: 1,
        file_no: 'F-16028',
        status: 'Partly Approved',
        status_key: 'partly_approved',
        works_done: 'HPA',
        works_approved_on: '21-08-2026',
        works_pending: 'HPT, TR',
        action: 'Edit',
    }];

    it('are kept out of the table', async () => {
        const host = mount(DataGrid, { columns, rows, title: 'Work Files' });
        await nextTick();

        const headings = [...host.querySelectorAll('thead th')].map((th) => th.textContent.trim());

        expect(headings).toContain('Status');
        expect(headings).not.toContain('Approved Works');
        expect(headings).not.toContain('Pending Works');

        // And the body keeps the same cell count the header promises.
        expect(host.querySelectorAll('tbody tr td').length).toBe(headings.length);
    });

    it('are written to the export, in order, beside the status they explain', () => {
        const header = exportHeader(columns);
        const body = exportRows(columns, rows, { money: String, balance: String });

        expect(header).toEqual(['File No.', 'Status', 'Approved Works', 'Approved On', 'Pending Works']);
        expect(body[0]).toEqual(['F-16028', 'Partly Approved', 'HPA', '21-08-2026', 'HPT, TR']);
    });
});
