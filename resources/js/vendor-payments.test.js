import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import VendorPayments from './components/VendorPayments.vue';

/*
 * Vendor Payments, banded by vendor: each band says what they are owed and
 * offers the two things to do about it. Nothing on it goes to a vendor.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const band = (id, name, owed, extra = {}) => ({
    vendor_id: id,
    vendor: name,
    vendor_owed: owed,
    statement_url: `/admin/party/statement/${id}`,
    pay_url: `/admin/party/entry/vendor?party_id=${id}&pay=1`,
    setoff_note: '',
    ...extra,
});

const ROWS = [
    { ...band(4, 'Arman Works', 1300), id: '4-f1', bill: 'F-101', bill_url: '/f/1', vehicle: 'BR01AB1234', works: 'TR', given: '01-08-2026', given_raw: '2026-08-01', days_text: '53 days', state: 'Finished', finished: 500, due: 500 },
    { ...band(4, 'Arman Works', 1300), id: '4-f2', bill: 'F-102', bill_url: '/f/2', vehicle: 'BR01AB5678', works: 'HP', given: '01-09-2026', given_raw: '2026-09-01', days_text: '22 days', state: 'File Dispatch', finished: 0, due: 800 },
    { ...band(9, 'Chandan Motors', 250, { setoff_note: 'Their customer account owes you 350.00 — set it off first' }), id: '9-e7', bill: 'Opening Balance', bill_url: null, vehicle: '', works: '', given: '10-09-2026', given_raw: '2026-09-10', days_text: '13 days', state: '', finished: 0, due: 250 },
];

const COLUMNS = [
    { key: 'bill', label: 'Bill', type: 'link', linkTo: 'bill_url', sub: 'vehicle' },
    { key: 'given', label: 'Given', sortBy: 'given_raw', sub: 'days_text' },
    { key: 'due', label: 'Owed', type: 'money' },
    { key: 'vendor', label: 'Vendor', exportOnly: true },
];

async function mount() {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(VendorPayments, { columns: COLUMNS, rows: ROWS, groupBy: 'vendor_id', groupLabel: 'vendor', totals: { due: 'sum' }, perPage: 100 });
    app.mount(host);
    mounted.push({ app, host });
    await nextTick();

    return host;
}

describe('a vendor\'s band', () => {
    it('names them, in the order they came, with what they are owed', async () => {
        const host = await mount();

        expect([...host.querySelectorAll('.vp-band__label')].map((el) => el.textContent)).toEqual(['Arman Works', 'Chandan Motors']);
        expect(host.querySelector('.vp-band__owed').textContent).toContain('1,300.00 owed');
    });

    it('records a payment to them, and opens their statement', async () => {
        const host = await mount();
        const first = host.querySelector('.vp-band');

        expect(first.querySelector('.vp-band__pay').getAttribute('href')).toBe('/admin/party/entry/vendor?party_id=4&pay=1');
        expect([...first.querySelectorAll('a')].some((a) => a.getAttribute('href') === '/admin/party/statement/4')).toBe(true);
    });

    it('says when their customer account should be set off first', async () => {
        const host = await mount();
        const [arman, chandan] = host.querySelectorAll('.vp-band');

        expect(arman.querySelector('.vp-band__note')).toBe(null);
        expect(chandan.querySelector('.vp-band__note').textContent).toContain('set it off first');
    });

    it('sends nothing: no WhatsApp anywhere', async () => {
        const host = await mount();

        expect(host.innerHTML).not.toContain('wa.me');
        expect(host.querySelector('.bi-whatsapp')).toBe(null);
    });
});
