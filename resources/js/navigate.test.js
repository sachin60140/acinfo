/*
 * Navigation without page loads, exercised.
 *
 * This is the one part of the application that cannot be reached from the PHP
 * suite: it only exists in a browser, and its failures are the kind that appear
 * on the second or third navigation rather than the first — a screen swapped in
 * with no components started, a calendar bound twice, an overlay that never
 * goes down. So it gets a document to run against and a fetch it can be lied
 * to by.
 *
 * The falling-back matters more than the swapping. A navigation that goes wrong
 * has to become an ordinary page load, because the alternative is a link that
 * does nothing at all — and on a ledger, a button that silently does nothing is
 * worse than one that reloads.
 */
import { beforeEach, describe, expect, test, vi } from 'vitest';

/** A page of the shape the layout renders. */
function page({ title = 'Screen', main = '<p>content</p>', sidebar = '<a href="/admin/files">Files</a>', style = '' } = {}) {
    return `<!doctype html><html data-swap-nav="1"><head><title>${title}</title>${style}</head>
        <body><aside id="sidebar" class="sidebar">${sidebar}</aside>
        <main id="main" class="main">${main}</main></body></html>`;
}

function htmlResponse(body, { url = 'http://localhost/admin/files', ok = true } = {}) {
    return {
        ok,
        url,
        headers: { get: () => 'text/html; charset=UTF-8' },
        text: async () => body,
    };
}

/**
 * Loads navigate.js fresh against a document, with its collaborators stubbed.
 *
 * Re-imported per test because the module registers its listeners once, on the
 * document that exists when it is evaluated.
 */
async function load({ enabled = true } = {}) {
    document.documentElement.dataset.swapNav = enabled ? '1' : '0';
    document.body.innerHTML = '<aside id="sidebar" class="sidebar"><a href="/admin/files">Files</a></aside><main id="main" class="main"><p>first</p></main>';

    const mount = vi.fn();
    const unmountDetached = vi.fn();

    vi.resetModules();
    vi.doMock('./mounts', () => ({ mount, unmountDetached }));

    await import('./navigate.js');

    return { mount, unmountDetached };
}

beforeEach(() => {
    vi.restoreAllMocks();
    document.head.innerHTML = '';
    window.history.replaceState({}, '', 'http://localhost/admin/dashboard');
});

describe('following a link', () => {
    test('puts the next screen in place of the current one', async () => {
        const { mount, unmountDetached } = await load();

        vi.stubGlobal('fetch', vi.fn(async () => htmlResponse(page({ title: 'Files', main: '<p>the files</p>' }))));

        const link = document.createElement('a');
        link.href = 'http://localhost/admin/files';
        document.querySelector('#main').appendChild(link);
        link.click();

        await vi.waitFor(() => expect(document.querySelector('#main').innerHTML).toContain('the files'));

        expect(document.title).toBe('Files');
        expect(window.location.pathname).toBe('/admin/files');

        // The screen that left is taken down; the one that arrived is started.
        expect(unmountDetached).toHaveBeenCalled();
        expect(mount).toHaveBeenCalled();
    });

    test('replaces the sidebar too, so the active menu follows the screen', async () => {
        await load();

        vi.stubGlobal('fetch', vi.fn(async () => htmlResponse(page({ sidebar: '<a class="active" href="/admin/files">Files</a>' }))));

        const link = document.createElement('a');
        link.href = 'http://localhost/admin/files';
        document.body.appendChild(link);
        link.click();

        await vi.waitFor(() => expect(document.querySelector('.sidebar').innerHTML).toContain('active'));
    });

    test('tells the page a screen arrived, so calendars can rebind', async () => {
        await load();

        const heard = vi.fn();
        document.addEventListener('acinfo:content', heard);

        vi.stubGlobal('fetch', vi.fn(async () => htmlResponse(page())));

        const link = document.createElement('a');
        link.href = 'http://localhost/admin/files';
        document.body.appendChild(link);
        link.click();

        await vi.waitFor(() => expect(heard).toHaveBeenCalled());
    });

    test('carries a screen’s own styles across with it', async () => {
        await load();

        vi.stubGlobal('fetch', vi.fn(async () => htmlResponse(page({ style: '<style>.report-filter { color: red }</style>' }))));

        const link = document.createElement('a');
        link.href = 'http://localhost/admin/reports/files';
        document.body.appendChild(link);
        link.click();

        await vi.waitFor(() => expect(document.head.innerHTML).toContain('.report-filter'));
    });
});

describe('leaving it to the browser', () => {
    /*
     * Each of these is a click whose meaning would change if it were taken over.
     * The assertion is that no request is made at all — the browser is left to
     * do exactly what the user asked for.
     */
    test.each([
        ['a new tab', (link) => { link.target = '_blank'; }],
        ['a download', (link) => { link.setAttribute('download', ''); }],
        ['another site', (link) => { link.href = 'https://example.com/x'; }],
        ['an opt-out', (link) => { link.dataset.noSwap = '1'; }],
    ])('does not take over %s', async (_name, prepare) => {
        await load();

        const fetched = vi.fn();
        vi.stubGlobal('fetch', fetched);

        const link = document.createElement('a');
        link.href = 'http://localhost/admin/files';
        prepare(link);
        document.body.appendChild(link);
        link.click();

        await new Promise((resolve) => setTimeout(resolve, 10));
        expect(fetched).not.toHaveBeenCalled();
    });

    test('does nothing at all when the server has it switched off', async () => {
        await load({ enabled: false });

        const fetched = vi.fn();
        vi.stubGlobal('fetch', fetched);

        const link = document.createElement('a');
        link.href = 'http://localhost/admin/files';
        document.body.appendChild(link);
        link.click();

        await new Promise((resolve) => setTimeout(resolve, 10));
        expect(fetched).not.toHaveBeenCalled();
    });
});

describe('when a visit goes wrong', () => {
    /*
     * The important property: it becomes a page load. A link that quietly does
     * nothing is worse than one that reloads, and on these screens it would be
     * mistaken for a save that did not happen.
     */
    async function expectsFallback(respond) {
        await load();

        const assign = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { origin: 'http://localhost', pathname: '/admin/dashboard', href: '', assign },
        });

        vi.stubGlobal('fetch', respond);

        const link = document.createElement('a');
        link.href = 'http://localhost/admin/files';
        document.body.appendChild(link);
        link.click();

        await vi.waitFor(() => expect(window.location.href).toContain('/admin/files'));
    }

    test('a failed request becomes a normal page load', async () => {
        await expectsFallback(vi.fn(async () => { throw new Error('offline'); }));
    });

    test('a response that is not a page becomes a normal page load', async () => {
        await expectsFallback(vi.fn(async () => ({
            ok: true,
            url: 'http://localhost/admin/files',
            headers: { get: () => 'application/json' },
            text: async () => '{}',
        })));
    });

    test('a page of an unfamiliar shape becomes a normal page load', async () => {
        await expectsFallback(vi.fn(async () => htmlResponse('<!doctype html><html><body>no main here</body></html>')));
    });

    test('an error page becomes a normal page load', async () => {
        await expectsFallback(vi.fn(async () => htmlResponse(page(), { ok: false })));
    });
});

describe('filter forms', () => {
    test('applying a filter swaps rather than reloading', async () => {
        await load();

        const fetched = vi.fn(async () => htmlResponse(page({ main: '<p>filtered</p>' })));
        vi.stubGlobal('fetch', fetched);

        const form = document.createElement('form');
        form.method = 'get';
        form.action = 'http://localhost/admin/files';
        form.innerHTML = '<input name="status" value="open">';
        document.querySelector('#main').appendChild(form);
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => expect(fetched).toHaveBeenCalled());
        expect(fetched.mock.calls[0][0]).toBe('http://localhost/admin/files?status=open');
    });

    test('a form that posts is left alone entirely', async () => {
        await load();

        const fetched = vi.fn();
        vi.stubGlobal('fetch', fetched);

        const form = document.createElement('form');
        form.method = 'post';
        form.action = 'http://localhost/admin/payment';
        document.querySelector('#main').appendChild(form);
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));

        await new Promise((resolve) => setTimeout(resolve, 10));
        expect(fetched).not.toHaveBeenCalled();
    });
});
