import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import CollectionList from './components/CollectionList.vue';
import { balanceMessage } from './customerShare';

/*
 * The Collection List's Remind: the statement's balance reminder, on the
 * customer's own chat, from a row — opened, never sent.
 */

const mounted = [];

afterEach(() => {
    vi.restoreAllMocks();

    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const COLUMNS = [
    { key: 'customer', label: 'Customer', type: 'link', linkTo: 'statement_url', note: 'setoff_note', sub: 'inactive_note' },
    { key: 'owes', label: 'Owes', type: 'money' },
    { key: 'remind', label: 'Remind', type: 'action', icon: 'bi-whatsapp', class: 'cl-remind', sortable: false, searchable: false, exportable: false },
];

const ROWS = [
    { id: 1, customer: 'Arman Qadri', statement_url: '/s/1', setoff_note: 'You owe them 1,400.00 as a vendor', inactive_note: '', whatsapp: '98352 30001', owes: 12500, remind: 'Remind' },
    { id: 2, customer: 'Rakesh Madhubani', statement_url: '/s/2', setoff_note: '', inactive_note: '', whatsapp: '0612 222 333', owes: 800, remind: 'Remind' },
];

function mount() {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(CollectionList, { columns: COLUMNS, rows: ROWS, todayLabel: '23-09-2026', perPage: 100 });
    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const remind = (host) => [...host.querySelectorAll('.cl-remind button')];

describe('Remind on the Collection List', () => {
    it('is a button on every row, which submits nothing', () => {
        const buttons = remind(mount());

        expect(buttons).toHaveLength(2);
        buttons.forEach((button) => expect(button.getAttribute('type')).toBe('button'));
    });

    it('opens that customer\'s chat with their balance reminder and nothing else', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);

        remind(mount())[0].click();
        await nextTick();

        const [url, target] = open.mock.calls[0];
        const text = decodeURIComponent(url.split('text=')[1]);

        expect(url.startsWith('https://wa.me/919835230001?text=')).toBe(true);
        expect(text).toBe(balanceMessage('Arman Qadri', 12500, '23-09-2026'));
        // What the office sees beside the name is the office's alone.
        expect(text).not.toContain('vendor');
        expect(target).toBe('_blank');
    });

    it('lets the office pick the chat when the number is no mobile', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);

        remind(mount())[1].click();
        await nextTick();

        expect(open.mock.calls[0][0].startsWith('https://wa.me/?text=')).toBe(true);
    });
});
