/*
 * Screens without a page load.
 *
 * A link click fetches the next page, takes its main region and its sidebar out
 * of the response, and puts them in place of the current ones. The URL, the
 * back button and bookmarks all behave exactly as they did, because they are
 * still real pages at real URLs — the browser is simply not thrown away and
 * rebuilt between them.
 *
 * This is deliberately not client-side routing. Routing would mean every
 * screen's filters, tiles and headings — two thousand lines of Blade that work
 * and are verified — becoming components first. This gets the same result for
 * the reader at a fraction of the risk, and leaves the server rendering the
 * pages it already renders.
 *
 * The whole thing degrades to ordinary navigation. Anything unexpected — a
 * non-HTML response, a redirect somewhere unexpected, a fetch that fails, a
 * page whose shape it does not recognise — hands the click back to the browser.
 * A navigation that fails is a page load, never a dead link.
 */
import { mount, unmountDetached } from './mounts';

// The region a screen occupies, and the chrome that has to follow it: the
// sidebar carries the active-menu state, which is decided per page.
const REGIONS = ['#main', '.sidebar'];

/** Page styles already added, so a screen visited twice does not add them twice. */
const seenStyles = new Set();

let inFlight = null;

/**
 * Whether this is a click we should handle rather than the browser.
 *
 * Everything here is a case where taking over would change what the user asked
 * for: another tab, a download, a different site, or a modified click that has
 * its own meaning.
 */
function isPlainNavigation(event, link) {
    return (
        link &&
        link.href &&
        !event.defaultPrevented &&
        event.button === 0 &&
        !event.metaKey &&
        !event.ctrlKey &&
        !event.shiftKey &&
        !event.altKey &&
        !link.target &&
        !link.hasAttribute('download') &&
        !link.dataset.noSwap &&
        link.origin === window.location.origin &&
        // An in-page anchor is the browser's job.
        !(link.pathname === window.location.pathname && link.hash)
    );
}

/**
 * Adds any page-specific styles the incoming screen brought with it.
 *
 * They are never removed. Each is scoped to markup that only exists on its own
 * screen, so leaving them costs nothing, and removing them would make a screen
 * flash unstyled on the way back to it. The set is bounded by the number of
 * screens.
 */
function adoptStyles(doc) {
    doc.head.querySelectorAll('style').forEach((style) => {
        const text = style.textContent.trim();

        if (!text || seenStyles.has(text)) {
            return;
        }

        seenStyles.add(text);

        const copy = document.createElement('style');
        copy.textContent = text;
        copy.dataset.navStyle = '1';
        document.head.appendChild(copy);
    });
}

/**
 * Puts the incoming screen in place of the current one.
 *
 * Returns false if the response does not look like one of our pages, which
 * sends the caller back to a normal page load rather than leaving a half-
 * replaced screen on screen.
 */
function swap(html, url) {
    const doc = new DOMParser().parseFromString(html, 'text/html');

    if (!doc.querySelector('#main')) {
        return false;
    }

    adoptStyles(doc);

    for (const selector of REGIONS) {
        const incoming = doc.querySelector(selector);
        const current = document.querySelector(selector);

        if (incoming && current) {
            current.innerHTML = incoming.innerHTML;
        }
    }

    document.title = doc.title;

    // Anything that left the document takes its listeners with it; anything
    // that arrived gets mounted.
    unmountDetached();
    mount(document.querySelector('#main') ?? document);

    /*
     * The calendars, and anything else bound at load. datepicker.js listens for
     * this because DOMContentLoaded now fires once for the whole session rather
     * than once per screen.
     */
    document.dispatchEvent(new CustomEvent('acinfo:content', { detail: { url } }));

    // A new screen starts at the top, the way a page load would.
    window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });

    // The off-canvas sidebar stays open across a swap otherwise, covering the
    // screen the reader just asked for.
    document.body.classList.remove('toggle-sidebar');

    return true;
}

/**
 * Fetches a URL and swaps it in, falling back to a real navigation on anything
 * unexpected.
 */
async function visit(url, { push = true } = {}) {
    // A second click while the first is still loading wins, as it would in a
    // browser: the earlier one is abandoned rather than racing to overwrite.
    if (inFlight) {
        inFlight.abort();
    }

    const controller = new AbortController();
    inFlight = controller;

    document.dispatchEvent(new CustomEvent('acinfo:visit-start'));

    try {
        const response = await fetch(url, {
            headers: {
                // Not a JSON request: the pages are still wanted as pages. This
                // only marks the request as a swap for anything that cares.
                'X-Requested-With': 'fetch',
                Accept: 'text/html',
            },
            credentials: 'same-origin',
            redirect: 'follow',
            signal: controller.signal,
        });

        const type = response.headers.get('content-type') ?? '';

        if (!response.ok || !type.includes('text/html')) {
            window.location.href = url;

            return;
        }

        // A redirect that landed somewhere else — a login page, most likely —
        // is not something to paste into the current shell.
        const landed = response.url || url;

        if (new URL(landed, window.location.origin).origin !== window.location.origin) {
            window.location.href = landed;

            return;
        }

        const html = await response.text();

        if (!swap(html, landed)) {
            window.location.href = landed;

            return;
        }

        if (push) {
            window.history.pushState({ swap: true }, '', landed);
        }
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        // Offline, blocked, or anything else: let the browser do it properly,
        // including showing its own error page.
        window.location.href = url;
    } finally {
        if (inFlight === controller) {
            inFlight = null;
        }

        document.dispatchEvent(new CustomEvent('acinfo:visit-end'));
    }
}

/**
 * Whether the server has this switched on.
 *
 * Read per click rather than once at startup. The built assets ship in the
 * repository and deploy by git pull, so turning this off has to be one env
 * change and a cache clear — and checking here means the listeners can be
 * registered unconditionally, which is one less thing whose behaviour depends
 * on what the page happened to say when the bundle first ran.
 */
function enabled() {
    return document.documentElement.dataset.swapNav === '1';
}


document.addEventListener('click', (event) => {
    const link = event.target.closest('a');

    if (!enabled() || !isPlainNavigation(event, link)) {
        return;
    }

    event.preventDefault();
    visit(link.href);
});

/*
 * The filter forms — every one of them a GET — go through the same path, so
 * applying a date range or a status behaves like following a link.
 *
 * Forms that POST are left alone entirely. They move money, they are validated
 * on the server, and they redirect on success; there is nothing to gain from
 * intercepting them and a great deal to be careful about.
 */
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (
        !enabled() ||
        event.defaultPrevented ||
        form.method.toLowerCase() !== 'get' ||
        form.dataset.noSwap ||
        new URL(form.action, window.location.origin).origin !== window.location.origin
    ) {
        return;
    }

    const query = new URLSearchParams(new FormData(form)).toString();
    const url = form.action.split('?')[0] + (query ? `?${query}` : '');

    event.preventDefault();
    visit(url);
});

/*
 * Back and forward. Only for entries this put there — a state we did not set
 * belongs to a real page load, and replacing its contents would show the reader
 * one screen at another screen's URL.
 */
window.addEventListener('popstate', (event) => {
    if (enabled() && event.state?.swap) {
        visit(window.location.href, { push: false });
    }
});

// So the first entry is ours too, and going back to it swaps rather than
// reloading a page the browser may have dropped from its cache.
window.history.replaceState({ swap: true }, '', window.location.href);
