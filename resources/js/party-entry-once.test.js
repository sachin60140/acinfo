import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import PartyEntry from './components/PartyEntry.vue';

/*
 * Save Entry pressed twice.
 *
 * A double click, or Enter pressed twice in the amount box, sent the form
 * twice and both were saved: the payment twice on the statement, a set-off
 * cleared twice on both accounts. The screen now sends one press; the server
 * saves one page's entry once whatever reaches it, by the token the page
 * posts with it (PartyController::savedBefore()).
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(PartyEntry, {
        action: '/admin/party/entry/customer',
        csrf: 'test-token',
        label: 'Customer',
        indexUrl: '/admin/parties/customer',
        statementUrl: '/admin/party/statement/__ID__',
        parties: [{ id: 7, name: 'Arman Qadri', mobile: '9835230001', current_balance: 5000 }],
        paymentModes: ['Cash', 'UPI'],
        dateField: '<input type="hidden" name="txn_date" value="2026-09-20">',
        initial: { party_id: '7', entry_type: 'credit', amount: '2000', payment_mode: 'UPI', ref_no: '', particular: 'Payment' },
        paymentSide: 'credit',
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const form = (host) => host.querySelector('form');
const save = (host) => [...host.querySelectorAll('button[type="submit"]')].find((b) => b.textContent.includes('Save Entry'));
const posted = (host) => Object.fromEntries([...new FormData(form(host)).entries()]);

describe('pressing Save Entry twice', () => {
    it('sends the form once', () => {
        const host = mount();

        const first = new window.Event('submit', { cancelable: true });
        const second = new window.Event('submit', { cancelable: true });

        form(host).dispatchEvent(first);
        form(host).dispatchEvent(second);

        expect(first.defaultPrevented).toBe(false);
        expect(second.defaultPrevented).toBe(true);
    });

    it('greys the button out while it saves', async () => {
        const host = mount();

        expect(save(host).disabled).toBe(false);

        form(host).dispatchEvent(new window.Event('submit', { cancelable: true }));
        await nextTick();

        expect(save(host).disabled).toBe(true);
    });

    it('lets it be pressed again on a page brought back with Back', async () => {
        const host = mount();

        form(host).dispatchEvent(new window.Event('submit', { cancelable: true }));
        await nextTick();

        const shown = new window.Event('pageshow');
        Object.defineProperty(shown, 'persisted', { value: true });
        window.dispatchEvent(shown);
        await nextTick();

        expect(save(host).disabled).toBe(false);

        const again = new window.Event('submit', { cancelable: true });
        form(host).dispatchEvent(again);

        expect(again.defaultPrevented).toBe(false);
    });
});

describe('the page\'s own token', () => {
    it('is posted with the entry, the same on every press of one page', async () => {
        const host = mount();
        const before = posted(host).once;

        form(host).dispatchEvent(new window.Event('submit', { cancelable: true }));
        await nextTick();

        expect(before).toMatch(/^[0-9a-f]{32}$/);
        expect(posted(host).once).toBe(before);
    });

    it('is another on another page, where the same payment typed again is a second payment', () => {
        expect(posted(mount()).once).not.toBe(posted(mount()).once);
    });
});
