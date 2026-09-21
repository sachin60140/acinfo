import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import WorkReport from './components/WorkReport.vue';
import { vendorFilesMessage } from './vendorShare';

/*
 * Sending a vendor the files they are holding, from the Work Report.
 *
 * Suman Ji Patna's files as the report showed them on the day this was asked
 * for. The point of the list is chasing, so it is read for two things: that
 * the longest out is at the top, and that nothing leaves the office that the
 * office keeps to itself — whose file it is, and what it was billed at.
 */

const row = (id, over = {}) => ({
    id,
    party_id: 6,
    party_band: 'Vendor — Suman Ji Patna · Ledger balance 11,000.00 Dr',
    party_name: 'Suman Ji Patna',
    party_mobile: '9835230000',
    file_no: `F-000${id}`,
    registration_no: `BR01XX${1000 + id}`,
    work_type: 'HPT+TR',
    counterparty: 'Car4Sales',
    status: 'File Dispatch',
    billed: 11000,
    cost: 10000,
    dispatched: '23-08-2026',
    dispatched_sort: '2026-08-23',
    days_out: '29 days',
    ...over,
});

const SUMAN = [
    row(50, { registration_no: 'BR01JB8140', work_type: 'DRC, HPT+TR+HPA', dispatched: '07-09-2026', dispatched_sort: '2026-09-07', days_out: '14 days', counterparty: 'Rakesh JI Madhubani' }),
    row(1, { registration_no: 'BR01DD1100', dispatched: '16-08-2026', dispatched_sort: '2026-08-16', days_out: '36 days', counterparty: 'Arman Qadri' }),
    row(55, { registration_no: 'BR01DN2536', work_type: 'TR', dispatched: '08-09-2026', dispatched_sort: '2026-09-08', days_out: '13 days', counterparty: 'Arman Qadri' }),
];

describe('the message', () => {
    it('puts the file out longest at the top', () => {
        const text = vendorFilesMessage('Suman Ji Patna', SUMAN, '21-09-2026');

        const order = ['BR01DD1100', 'BR01JB8140', 'BR01DN2536'].map((v) => text.indexOf(v));

        expect(order).toEqual([...order].sort((a, b) => a - b));
        expect(text).toContain('1. *BR01DD1100 — HPT+TR*');
    });

    it('says the vehicle, the work, the day it went and how long it has been', () => {
        const text = vendorFilesMessage('Suman Ji Patna', SUMAN, '21-09-2026');

        expect(text).toContain('*BR01JB8140 — DRC, HPT+TR+HPA*');
        expect(text).toContain('dispatched 07-09-2026');
        expect(text).toContain('14 days');
        expect(text).toContain('F-00050');
    });

    it('says whose list it is, how many, and as of when', () => {
        const text = vendorFilesMessage('Suman Ji Patna', SUMAN, '21-09-2026');

        expect(text.split('\n')[0]).toBe('*Files with you — Suman Ji Patna*');
        expect(text.split('\n')[1]).toBe('3 files · as of 21-09-2026');
    });

    /*
     * The lines that must never move. The office keeps a vendor from learning
     * who its customers are, and what a file was billed at is between the
     * office and the customer.
     */
    it('never names the customer a file came from', () => {
        const text = vendorFilesMessage('Suman Ji Patna', SUMAN);

        expect(text).not.toContain('Arman Qadri');
        expect(text).not.toContain('Rakesh');
        expect(text).not.toContain('Car4Sales');
    });

    it('never says what anything was billed at or cost', () => {
        const text = vendorFilesMessage('Suman Ji Patna', SUMAN);

        expect(text).not.toMatch(/11,?000/);
        expect(text).not.toMatch(/10,?000/);
    });

    it('puts a file with no dispatch date at the bottom rather than the top', () => {
        const text = vendorFilesMessage('Suman Ji Patna', [
            row(9, { registration_no: 'NODATE', dispatched: null, dispatched_sort: null, days_out: null }),
            ...SUMAN,
        ]);

        expect(text.indexOf('NODATE')).toBeGreaterThan(text.indexOf('BR01DN2536'));
    });

    it('says nothing at all when there is nothing to send', () => {
        expect(vendorFilesMessage('Suman Ji Patna', [])).toBe('');
    });
});

// ------------------------------------------------------------------ the report

const mounted = [];

const COLUMNS = [
    { key: 'party_id', label: 'Vendor Id', hidden: true },
    { key: 'party_name', label: 'Vendor', exportOnly: true },
    { key: 'file_no', label: 'File No.' },
    { key: 'registration_no', label: 'Vehicle' },
    { key: 'work_type', label: 'Work Type' },
    { key: 'counterparty', label: 'Received From' },
    { key: 'billed', label: 'Billed', type: 'money' },
];

function mount(over = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(WorkReport, {
        columns: COLUMNS,
        rows: SUMAN,
        groupBy: 'party_id',
        groupLabel: 'party_band',
        partyType: 'vendor',
        todayLabel: '21-09-2026',
        perPage: 50,
        ...over,
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

    vi.restoreAllMocks();
});

const bandButtons = (host) => [...host.querySelectorAll('.wr-band__share button')];
const sendButton = (host) => bandButtons(host).find((b) => b.textContent.includes('WhatsApp'));

describe('on the vendor-wise report', () => {
    it('offers to send each vendor their list from their own band', () => {
        const host = mount();

        expect(host.querySelector('.wr-band__label').textContent).toContain('Suman Ji Patna');
        expect(sendButton(host)).toBeDefined();
    });

    it('opens a chat on the vendor with the list filled in, and sends nothing itself', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const host = mount();

        sendButton(host).click();
        await nextTick();

        const [url, target] = open.mock.calls[0];

        expect(url.startsWith('https://wa.me/919835230000?text=')).toBe(true);
        expect(decodeURIComponent(url.split('text=')[1])).toContain('*Files with you — Suman Ji Patna*');
        expect(target).toBe('_blank');
    });

    it('says whose number it is opening before anything is clicked', () => {
        expect(sendButton(mount()).getAttribute('title')).toContain('+91 98352 30000');
    });

    it('never submits anything on the way to WhatsApp', () => {
        for (const button of bandButtons(mount())) {
            expect(button.getAttribute('type')).toBe('button');
        }
    });

    /* The same guarantee as the message, checked at the point it leaves. */
    it('never sends a customer name, even though the report shows them', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const host = mount();

        expect(host.textContent).toContain('Arman Qadri');

        sendButton(host).click();
        await nextTick();

        expect(decodeURIComponent(open.mock.calls[0][0])).not.toContain('Arman Qadri');
    });
});

describe('on the customer-wise report', () => {
    /*
     * A customer is never told a file went to a vendor. A list of dispatch
     * dates and days out would tell them exactly that, so there is nothing to
     * send from here at all.
     */
    it('offers nothing to send', () => {
        const host = mount({
            partyType: 'customer',
            rows: SUMAN.map((r) => ({ ...r, party_band: 'Customer — Car4Sales', party_name: 'Car4Sales' })),
        });

        expect(host.querySelector('.wr-band__share')).toBeNull();
        // The band still says whose files they are.
        expect(host.querySelector('.wr-band__label').textContent).toContain('Car4Sales');
    });
});
