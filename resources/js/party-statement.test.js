import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import PartyStatement from './components/PartyStatement.vue';

/*
 * Taking back an entry from its statement.
 *
 * The server decides what can be reversed and refuses the rest; checked here is
 * that the screen offers it only where it applies, asks why before it will
 * post, and posts to that entry with which of the two it is.
 */

const mounted = [];

const COLUMNS = [
    { key: 'id', label: '#' },
    { key: 'txn_date', label: 'Txn Date' },
    { key: 'particular', label: 'Particulars', note: 'office_note' },
    { key: 'debit', label: 'Debit', type: 'money' },
    { key: 'credit', label: 'Credit', type: 'money' },
    { key: 'change', label: 'Change', type: 'action', onlyIf: 'change', sortable: false, searchable: false, exportable: false },
];

const row = (id, over = {}) => ({
    id,
    txn_date: '10-09-2026',
    particular: 'Payment received',
    payment_mode: 'UPI',
    debit: null,
    credit: 5000,
    against: null,
    change: 'Change',
    row_state: null,
    office_note: null,
    ...over,
});

const ROWS = [
    row(11),
    row(12, { particular: 'TR - BR01AB1234', debit: 3000, credit: null, change: null }),
    row(13, { change: null, row_state: 'is-reversed', office_note: 'Reversed by #14 on 21-09-2026' }),
    row(14, { particular: 'Reversal of entry #13 of 10-09-2026', debit: 5000, credit: null, change: null, row_state: 'is-reversal', office_note: 'Why: Duplicate' }),
];

function mount() {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(PartyStatement, {
        columns: COLUMNS,
        rows: ROWS,
        sortable: false,
        rowClass: 'row_state',
        action: '/admin/party/reverse/__ID__',
        csrf: 'test-token',
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

    document.body.innerHTML = '';
});

const changeButtons = (host) => [...host.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Change');
const dialog = () => document.querySelector('.ps-dialog__panel');
const submit = (label) => [...dialog().querySelectorAll('button[type="submit"]')].find((b) => b.textContent.includes(label));

describe('what can be changed', () => {
    it('offers Change only on an entry typed by hand, not reversed, not a reversal', () => {
        const host = mount();

        expect(changeButtons(host)).toHaveLength(1);
        expect(changeButtons(host)[0].closest('tr').textContent).toContain('11');
    });

    it('draws a reversed entry and its reversal apart from the rest', () => {
        const host = mount();
        const rows = [...host.querySelectorAll('tbody tr')];

        expect(rows.find((tr) => tr.textContent.includes('Reversed by #14')).classList).toContain('is-reversed');
        expect(rows.find((tr) => tr.textContent.includes('Why: Duplicate')).classList).toContain('is-reversal');
    });
});

describe('the Change dialog', () => {
    it('posts to that entry, and asks why before it will', async () => {
        const host = mount();

        changeButtons(host)[0].click();
        await nextTick();

        expect(dialog().getAttribute('action')).toBe('/admin/party/reverse/11');
        expect(dialog().querySelector('input[name="_token"]').value).toBe('test-token');
        expect(submit('Reverse').disabled).toBe(true);

        const why = dialog().querySelector('textarea[name="reason"]');
        why.value = 'Typed for the wrong customer';
        why.dispatchEvent(new window.Event('input'));
        await nextTick();

        expect(submit('Reverse').disabled).toBe(false);
        expect(submit('enter it again').disabled).toBe(false);
    });

    it('says which of the two it is', async () => {
        const host = mount();

        changeButtons(host)[0].click();
        await nextTick();

        expect(submit('Reverse and enter it again').getAttribute('name')).toBe('correct');
        expect(submit('Reverse and enter it again').value).toBe('1');
        expect([...dialog().querySelectorAll('button[type="submit"]')].find((b) => b.textContent.trim().endsWith('Reverse')).value).toBe('0');
    });

    it('keeps the office note under its own class, for print to leave out', () => {
        const host = mount();
        const note = [...host.querySelectorAll('.ui-sub')].find((d) => d.textContent.includes('Why: Duplicate'));

        expect(note.classList).toContain('grid__cellnote');
    });

    it('posts once however many times it is pressed', async () => {
        const host = mount();

        changeButtons(host)[0].click();
        await nextTick();

        const why = dialog().querySelector('textarea[name="reason"]');
        why.value = 'Duplicate';
        why.dispatchEvent(new window.Event('input'));
        await nextTick();

        const first = new window.Event('submit', { cancelable: true });
        const second = new window.Event('submit', { cancelable: true });

        dialog().dispatchEvent(first);
        dialog().dispatchEvent(second);
        await nextTick();

        expect(first.defaultPrevented).toBe(false);
        expect(second.defaultPrevented).toBe(true);
        expect(submit('Reverse and enter it again').disabled).toBe(true);
    });

    it('closes on Escape wherever focus is, and goes back to its button', async () => {
        const host = mount();
        const button = changeButtons(host)[0];

        button.focus();
        button.click();
        await nextTick();

        document.activeElement.blur();
        document.body.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await nextTick();

        expect(dialog()).toBe(null);
        expect(document.activeElement).toBe(button);
    });

    it('closes without posting anything', async () => {
        const host = mount();

        changeButtons(host)[0].click();
        await nextTick();

        [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Cancel').click();
        await nextTick();

        expect(dialog()).toBe(null);
    });
});
