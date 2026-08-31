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
    { work_type_id: 2, rates: [
        { file_no: 'F-00007', vendor_date: '2026-08-14', registration_no: 'BR06CL5310', vendor: 'Dabloo Ji Muzaffarpur', amount: 1800, charged: 3000 },
        { file_no: 'F-00004', vendor_date: '2026-08-02', registration_no: 'BR07BA1145', vendor: 'Amit Ji Darbhanag', amount: 1600, charged: 2800 },
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
        expect(host.querySelector('.give-past__none').textContent.trim()).toBe('no earlier rate');
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
