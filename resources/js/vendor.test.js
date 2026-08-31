import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import GiveToVendor from './components/GiveToVendor.vue';

/*
 * What this work has been paid before, where its rate is being agreed.
 *
 * The question at the counter is always the same — what did we pay for a
 * transfer last time, and to whom — and the answer used to be on another screen
 * behind a filter.
 */

const mounted = [];

const FILES = [
    {
        id: 1,
        file_no: 'F-00009',
        registration_no: 'BR06GG1408',
        received_date: '14-08-2026',
        description: '',
        customer: 'Car4Sales',
        customer_amount: 5600,
        items: [
            { id: 11, work_type_id: 2, work_type: 'TR', customer_amount: 3000, vendor_rate: 1800 },
            { id: 12, work_type_id: 3, work_type: 'HPA', customer_amount: 2600, vendor_rate: null },
        ],
    },
];

const RATE_HISTORY = [
    { work_type_id: 2, rto: 'BR06', rates: [
        { file_no: 'F-00007', vendor_date: '2026-08-14', registration_no: 'BR06CL5310', vendor: 'Dabloo Ji Muzaffarpur', amount: 1800, charged: 3000 },
        { file_no: 'F-00004', vendor_date: '2026-08-02', registration_no: 'BR07BA1145', vendor: 'Amit Ji Darbhanag', amount: 1600, charged: 2800 },
    ] },
    // The same work at a different office, which must not be offered here.
    { work_type_id: 2, rto: 'BR01', rates: [
        { file_no: 'F-00002', vendor_date: '2026-08-01', registration_no: 'BR01DD1234', vendor: 'Someone Else', amount: 9999, charged: 12000 },
    ] },
];

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(GiveToVendor, {
        files: FILES,
        vendors: [{ id: 6, name: 'Dabloo Ji Muzaffarpur', mobile: '9835630000', current_balance: -24200 }],
        action: '/admin/file/assign',
        csrf: 'test-token',
        cancelUrl: '/admin/files',
        vendorId: '',
        vendorDate: '2026-08-22',
        vendorDateDisplay: '22-08-2026',
        remark: '',
        pickedFiles: [],
        oldAmounts: {},
        rateHistory: RATE_HISTORY,
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const openers = (host) => [...host.querySelectorAll('.give-past__open')];

const visiblePanels = (host) =>
    [...host.querySelectorAll('tr')].filter(
        (tr) => tr.querySelector('.give-past') && tr.style.display !== 'none'
    );

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

describe('what this work was paid before', () => {
    it('says the last rate beside the box where the next one is typed', () => {
        const host = mount();

        expect(openers(host).length).toBe(1);
        expect(openers(host)[0].textContent).toContain('1,800.00');
    });

    it('says so plainly when a work has never been given out', () => {
        const host = mount();

        // HPA has no history, so it says that rather than showing nothing.
        expect(host.querySelectorAll('.give-past__none').length).toBe(1);
        expect(host.querySelector('.give-past__none').textContent).toContain('no earlier rate');
    });

    it('opens the last five, with the vendor and what was charged beside each', async () => {
        const host = mount();

        expect(visiblePanels(host).length).toBe(0);

        openers(host)[0].click();
        await nextTick();

        const panel = visiblePanels(host)[0];

        expect(panel).toBeTruthy();
        expect(panel.querySelectorAll('.give-past__table tbody tr').length).toBe(2);

        const text = panel.textContent;

        expect(text).toContain('Dabloo Ji Muzaffarpur');
        expect(text).toContain('Amit Ji Darbhanag');
        expect(text).toContain('1,600.00');
        // What was charged beside what was paid: the gap is the margin.
        expect(text).toContain('2,800.00');
        expect(text).toContain('BR07BA1145');
    });

    it('closes again, and only one is open at a time', async () => {
        const host = mount();

        openers(host)[0].click();
        await nextTick();
        expect(visiblePanels(host).length).toBe(1);

        openers(host)[0].click();
        await nextTick();
        expect(visiblePanels(host).length).toBe(0);
    });

    /*
     * The history is a pricing aid, not part of the form. It must never post
     * anything, or a rate looked up would be sent as a rate agreed.
     */
    it('posts nothing of its own', async () => {
        const host = mount();

        openers(host)[0].click();
        await nextTick();

        const named = [...host.querySelectorAll('.give-past [name]')];

        expect(named).toEqual([]);
    });
});

/*
 * A rate belongs to an office as well as to a work.
 *
 * The first four characters of a registration number are the RTO the papers go
 * through — BR06, BR01 — and what a transfer costs at one is not what it costs
 * at another. A rate from the wrong office is worse than no rate at all: it
 * reads as an answer.
 */
describe('the office a rate was paid at', () => {
    it('offers only what was paid at this file\u2019s own office', async () => {
        const host = mount();

        // The file is BR06; the BR01 rate of 9,999 must not appear.
        expect(openers(host)[0].textContent).toContain('1,800.00');
        expect(openers(host)[0].textContent).not.toContain('9,999');

        openers(host)[0].click();
        await nextTick();

        const text = visiblePanels(host)[0].textContent;

        expect(text).toContain('BR06CL5310');
        expect(text).not.toContain('BR01DD1234');
        expect(text).not.toContain('Someone Else');
    });

    it('names the office, so a rate is never read as the wrong one', async () => {
        const host = mount();

        expect(openers(host)[0].textContent).toContain('BR06');

        openers(host)[0].click();
        await nextTick();

        expect(visiblePanels(host)[0].querySelector('.give-past__head').textContent)
            .toContain('BR06');
    });

    it('says which office it found nothing for', () => {
        const host = mount();

        // HPA at BR06 has never been given out.
        expect(host.querySelector('.give-past__none').textContent.replace(/\s+/g, ' ').trim())
            .toBe('no earlier rate at BR06');
    });

    /*
     * A file with no registration number cannot say which office it is for, so
     * nothing is offered as a comparison rather than something from anywhere.
     */
    it('offers nothing for a file that does not say where it is going', () => {
        const host = mount({
            files: [{ ...FILES[0], registration_no: '' }],
        });

        expect(openers(host).length).toBe(0);
        expect(host.querySelectorAll('.give-past__none').length).toBe(2);
    });
});
