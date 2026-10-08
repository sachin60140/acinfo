import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import PartyEntry from './components/PartyEntry.vue';

/*
 * A balance carried from the old Client Ledger, entered again on Correct.
 *
 * The Collection List dates such a balance from the old book's own charges,
 * found through the client its line names. Entered again, the new line says
 * which client only if the server knows which line it replaces: the screen
 * posts that line's number back, and the server reads the client from it.
 */

const PARTIES = [
    { id: 7, name: 'Arman Qadri', mobile: '9835230001', current_balance: 2500 },
    { id: 8, name: 'Bilal Ansari', mobile: '9835230002', current_balance: 0 },
];

const mounted = [];

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ bills: [], covered: 0 }),
    })));
});

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    vi.unstubAllGlobals();
});

function mount(initial = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(PartyEntry, {
        action: '/admin/party/entry/customer',
        csrf: 'test-token',
        label: 'Customer',
        indexUrl: '/admin/parties/customer',
        statementUrl: '/admin/party/statement/__ID__',
        parties: PARTIES,
        paymentModes: ['Cash', 'UPI'],
        dateField: '<input type="hidden" name="txn_date" value="2026-10-07">',
        initial: {
            party_id: '7',
            entry_type: 'debit',
            amount: '2500',
            payment_mode: '',
            ref_no: '',
            particular: 'Balance brought from old Client Ledger',
            entry_kind: '',
            reason: '',
            corrects: '',
            ...initial,
        },
        adjustable: true,
        paymentSide: 'credit',
        billsUrl: '/admin/party/bills/__ID__',
        initialAlloc: {},
        writeOffCap: 500,
        limitsUrl: '/admin/setup/limits',
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const corrects = (host) => host.querySelector('input[name="corrects"]');

describe('entering a carried balance again', () => {
    it('posts which line it replaces', async () => {
        const host = mount({ corrects: '41' });
        await nextTick();

        expect(corrects(host)).not.toBe(null);
        expect(corrects(host).type).toBe('hidden');
        expect(corrects(host).value).toBe('41');
    });

    it('and still does when it is put on the right customer', async () => {
        const host = mount({ corrects: '41' });
        await nextTick();

        const select = host.querySelector('select[name="party_id"]');
        select.value = '8';
        select.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(corrects(host).value).toBe('41');
    });

    it('posts nothing of the kind on an ordinary entry', async () => {
        const host = mount({ particular: 'Typed by hand' });
        await nextTick();

        expect(corrects(host)).toBe(null);
    });

    it('nor on a write-off, which says why it was given up instead', async () => {
        const host = mount({ corrects: '41', entry_type: 'credit', entry_kind: 'writeoff' });
        await nextTick();

        expect(host.querySelector('input[name="entry_kind"]').checked).toBe(true);
        expect(corrects(host)).toBe(null);
    });
});

/*
 * Found in review: what Correct filled in lasted one page, and the right
 * customer, if they had not been made, was added by leaving it — losing which
 * old client it came from. The screen says what it is entering again, to leave
 * its Particulars, and how to add the customer without losing it.
 */
describe('what the screen says of it', () => {
    const note = (host) => [...host.querySelectorAll('.ui-note')].find((n) => n.textContent.includes('old Client Ledger'));
    const said = (host) => note(host)?.textContent.replace(/\s+/g, ' ');

    it('names the line, says to leave its Particulars, and how to add the right customer', async () => {
        const host = mount({ corrects: '41' });
        await nextTick();

        expect(said(host)).toContain('Entering again entry #41, a balance carried from the old Client Ledger');
        expect(said(host)).toContain('Leave its Particulars as they are');
        expect(said(host)).toContain('add them in another tab, then reload this page');
    });

    it('and says nothing of the kind on an ordinary entry', async () => {
        const host = mount({ particular: 'Typed by hand' });
        await nextTick();

        expect(note(host)).toBeUndefined();
    });
});
