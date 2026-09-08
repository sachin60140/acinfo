import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import GiveToVendor from './components/GiveToVendor.vue';

/*
 * Narrowing the list of files waiting to go out.
 *
 * The rows on this screen are not a report — they carry the ticks and the rates
 * that credit a vendor. So the whole question about a search here is what it
 * does to a file that was ticked before the search was typed, and to Select all
 * while something is hidden. Getting either wrong pays a vendor for work that
 * was never handed over, or fails to pay for work that was.
 */

const mounted = [];

const FILES = [
    {
        id: 1,
        file_no: 'F-00020',
        registration_no: 'BR06CN8740',
        received_date: '23-08-2026',
        description: 'With Duplicate RC',
        customer: 'Car4Sales',
        customer_amount: 5600,
        items: [{ id: 11, work_type_id: 2, work_type: 'TR', customer_amount: 3000, vendor_rate: 1800 }],
    },
    {
        id: 2,
        file_no: 'F-00044',
        registration_no: 'BR11BU1926',
        received_date: '03-09-2026',
        description: '',
        customer: 'Kuwy Technology Service Pvt Ltd',
        customer_amount: 0,
        items: [{ id: 12, work_type_id: 3, work_type: 'HPA', customer_amount: 0, vendor_rate: null }],
    },
    {
        id: 3,
        file_no: 'F-00051',
        registration_no: 'BR07AK2575',
        received_date: '07-09-2026',
        description: '',
        customer: 'Rakesh JI Madhubani',
        customer_amount: 7000,
        items: [{ id: 13, work_type_id: 2, work_type: 'TR', customer_amount: 7000, vendor_rate: 2500 }],
    },
];

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(GiveToVendor, {
        files: FILES,
        vendors: [{ id: 6, name: 'Dabloo Ji Muzaffarpur', mobile: '9835630000', current_balance: 0 }],
        action: '/admin/file/assign',
        csrf: 'test-token',
        cancelUrl: '/admin/files',
        vendorId: '',
        vendorDate: '2026-09-08',
        vendorDateDisplay: '08-09-2026',
        remark: '',
        pickedFiles: [],
        oldAmounts: {},
        rateHistory: [],
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const box = (host) => host.querySelector('input[type="search"]');

async function type(host, text) {
    const field = box(host);

    field.value = text;
    field.dispatchEvent(new window.Event('input'));

    await nextTick();
}

// The file rows, and whether each is on screen. v-show leaves them in the DOM,
// which is the point — so "visible" is about display, not existence.
const fileRows = (host) =>
    [...host.querySelectorAll('tbody tr')].filter((tr) => tr.querySelector('.give-pick'));

const visibleRows = (host) => fileRows(host).filter((tr) => tr.style.display !== 'none');

const tickOf = (tr) => tr.querySelector('.give-pick input[type="checkbox"]');

/*
 * What the form would actually post. The checkbox itself carries the name, so
 * an unticked one is still in the DOM and sends nothing — reading them all
 * without filtering on checked reports every file as going out, which is what
 * the first version of this helper did.
 */
const ticked = (host) =>
    [...host.querySelectorAll('input[name="files[]"]')]
        .filter((el) => el.checked)
        .map((el) => Number(el.value))
        .sort((a, b) => a - b);

const selectAll = (host) =>
    [...host.querySelectorAll('input[type="checkbox"]')].find((el) => ! el.closest('.give-pick'));

const footer = (host) => host.querySelector('.ui-card__foot').textContent.replace(/\s+/g, ' ').trim();

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

describe('searching the files waiting to go out', () => {
    it('starts with everything on screen', () => {
        const host = mount();

        expect(visibleRows(host).length).toBe(3);
    });

    it('narrows on the registration number', async () => {
        const host = mount();

        await type(host, 'BR11');

        expect(visibleRows(host).length).toBe(1);
        expect(visibleRows(host)[0].textContent).toContain('F-00044');
    });

    it('narrows on the customer, the file number and the work', async () => {
        const host = mount();

        for (const [term, expected] of [
            ['Rakesh', 'F-00051'],
            ['F-00020', 'F-00020'],
            ['HPA', 'F-00044'],
        ]) {
            await type(host, term);

            expect(visibleRows(host).length, `"${term}" matched more than one row`).toBe(1);
            expect(visibleRows(host)[0].textContent).toContain(expected);
        }
    });

    it('narrows rather than widens on a second word', async () => {
        const host = mount();

        await type(host, 'car4sales tr');
        expect(visibleRows(host).length).toBe(1);

        // Both words are on the list but not on one row.
        await type(host, 'car4sales rakesh');
        expect(visibleRows(host).length).toBe(0);
    });

    it('says so when nothing matches', async () => {
        const host = mount();

        await type(host, 'nothing like this');

        expect(visibleRows(host).length).toBe(0);
        expect(footer(host)).toContain('No files match that search');
    });

    /*
     * The one that matters. A file ticked before the search was typed is still
     * being handed over, and its checkbox has to still be on the form — v-if
     * would have taken it, and the file would quietly stop going out.
     */
    it('keeps a ticked file on the form when the search hides it', async () => {
        const host = mount();

        tickOf(fileRows(host)[0]).click();
        await nextTick();

        expect(ticked(host)).toEqual([1]);

        await type(host, 'BR11');

        expect(visibleRows(host).length, 'it is off the screen').toBe(1);
        expect(ticked(host), 'but still on the form').toEqual([1]);
    });

    it('says how many ticked files the search is covering up', async () => {
        const host = mount();

        tickOf(fileRows(host)[0]).click();
        await nextTick();

        await type(host, 'BR11');

        expect(footer(host)).toContain('1 not shown by the search');
        expect(footer(host)).toContain('still going out');
    });

    it('says nothing about hidden files when none are hidden', async () => {
        const host = mount();

        tickOf(fileRows(host)[0]).click();
        await nextTick();

        expect(footer(host)).not.toContain('not shown by the search');
    });
});

/*
 * Select all, with a search typed.
 *
 * This is the control that credits a vendor for a whole screenful at once, so
 * what it means while something is hidden is a money question rather than a
 * convenience one.
 */
describe('select all while the list is narrowed', () => {
    it('ticks only what is on screen', async () => {
        const host = mount();

        await type(host, 'BR11');

        selectAll(host).click();
        await nextTick();

        expect(ticked(host), 'it handed over files nobody could see').toEqual([2]);
    });

    it('leaves files ticked out of sight alone when it is cleared', async () => {
        const host = mount();

        tickOf(fileRows(host)[0]).click();
        await nextTick();

        await type(host, 'BR11');

        selectAll(host).click();
        await nextTick();
        expect(ticked(host)).toEqual([1, 2]);

        // Clearing it drops what is on screen and nothing else: quietly
        // dropping a file somebody ticked earlier is as wrong as adding one.
        selectAll(host).click();
        await nextTick();

        expect(ticked(host)).toEqual([1]);
    });

    it('reads as ticked once everything shown is ticked', async () => {
        const host = mount();

        await type(host, 'BR11');

        expect(selectAll(host).checked).toBe(false);

        tickOf(visibleRows(host)[0]).click();
        await nextTick();

        expect(selectAll(host).checked, 'every file on screen is ticked').toBe(true);
    });

    it('posts nothing of its own', async () => {
        const host = mount();

        await type(host, 'BR11');

        // The search narrows what is on screen and must never narrow, or add
        // to, what is sent.
        expect(box(host).getAttribute('name')).toBe(null);
    });
});
