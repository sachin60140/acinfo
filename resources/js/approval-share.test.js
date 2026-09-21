import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import ApprovalShare from './components/ApprovalShare.vue';
import { approvalMessage } from './customerShare';

/*
 * Telling a customer their work was approved, on WhatsApp.
 *
 * Read for what it says — which work, on what day, what is still in progress,
 * whether the papers can be collected, what is owed — and for what it never
 * says: which vendor did the work, or anything the office wrote for itself.
 */

const NOTICE = {
    id: 57,
    fileNo: 'F-00057',
    vehicle: 'BR01JB8140',
    customer: 'Arman Qadri',
    mobile: '9835230001',
    works: [{ work: 'TR', on: '18-09-2026' }],
    pending: [],
    papersReady: true,
    balance: 2500,
};

describe('the approval message', () => {
    it('says whose it is, which vehicle, and what came through on what day', () => {
        const lines = approvalMessage(NOTICE).split('\n');

        expect(lines[0]).toBe('*Work approved — Arman Qadri*');
        expect(lines[1]).toBe('BR01JB8140 · F-00057');
        expect(lines).toContain('✓ TR approved on 18-09-2026');
    });

    it('asks them to collect the papers and says what is owed', () => {
        const text = approvalMessage(NOTICE, '21-09-2026');

        expect(text).toContain('Your papers are ready to collect.');
        expect(text).toContain('Total balance on your account as of 21-09-2026: ₹2,500.00');
    });

    /* Found in review: a bare "Balance due" under one vehicle reads as that vehicle's price. */
    it('says the balance is the whole account, not this file', () => {
        const text = approvalMessage({ ...NOTICE, balance: 23500 }, '21-09-2026');

        expect(text).toContain('on your account');
        expect(text).not.toMatch(/^Balance due/m);
    });

    it('names what is still in progress, and does not call the papers ready then', () => {
        const text = approvalMessage({ ...NOTICE, pending: ['HPA'], papersReady: false });

        expect(text).toContain('Still in progress: HPA');
        expect(text).not.toContain('ready to collect');
    });

    it('says nothing about a balance that is paid', () => {
        expect(approvalMessage({ ...NOTICE, balance: 0 })).not.toContain('balance');
        expect(approvalMessage({ ...NOTICE, balance: -300 })).not.toContain('balance');
    });

    it('never names a vendor or reads a remark, whatever the notice carries', () => {
        const text = approvalMessage({ ...NOTICE, vendor: 'Suman Ji Patna', remark: 'Chased Suman, slow again', office_note: 'x' });

        expect(text).not.toContain('Suman');
        expect(text).not.toContain('slow again');
    });

    it('says nothing when nothing was approved', () => {
        expect(approvalMessage({ ...NOTICE, works: [] })).toBe('');
    });
});

// ------------------------------------------------------------------ the bar

const mounted = [];

function mount(props) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(ApprovalShare, props);
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

describe('after an approval is saved', () => {
    const RAKESH = { ...NOTICE, id: 58, fileNo: 'F-00058', vehicle: 'BR32RK7000', customer: 'Rakesh Madhubani', mobile: '9431000002' };

    it('offers one message per file approved, each to its own customer', () => {
        const host = mount({ files: [NOTICE, RAKESH] });

        const rows = [...host.querySelectorAll('.approval-share__row')];

        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('F-00057');
        expect(rows[1].textContent).toContain('Rakesh Madhubani');
    });

    it('opens a chat on that customer with the message filled in, and sends nothing itself', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const host = mount({ files: [NOTICE, RAKESH] });

        host.querySelectorAll('.wa-share__send')[1].click();
        await nextTick();

        const [url, target] = open.mock.calls[0];

        expect(url.startsWith('https://wa.me/919431000002?text=')).toBe(true);
        expect(decodeURIComponent(url)).toContain('*Work approved — Rakesh Madhubani*');
        expect(target).toBe('_blank');
    });

    it('never submits anything on the way to WhatsApp', () => {
        for (const button of mount({ files: [NOTICE] }).querySelectorAll('button')) {
            expect(button.getAttribute('type')).toBe('button');
        }
    });
});
