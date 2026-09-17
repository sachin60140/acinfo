import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import HandOver from './components/HandOver.vue';

/*
 * Choosing whose papers go back.
 *
 * Nothing on this screen moves money, but it does record something about a
 * customer's papers that they will read. So the questions are the same as on
 * Give to Vendor: what a search does to a file ticked before it was typed, and
 * what Select all means while some rows are hidden.
 */

const mounted = [];

const FILES = [
    { id: 1, file_no: 'F-00050', registration_no: 'BR01JB8140', customer: 'Rakesh Ji Madhubani', work_type: 'HPT, TR', description: '', received_date: '07-09-2026', approved_on: '12-09-2026' },
    { id: 2, file_no: 'F-00056', registration_no: 'BR01HD8416', customer: 'Car4Sales', work_type: 'HPA', description: 'Without Challan', received_date: '09-09-2026', approved_on: '13-09-2026' },
    { id: 3, file_no: 'F-00040', registration_no: 'BR31AQ9028', customer: 'Car4Sales', work_type: 'TR', description: '', received_date: '01-09-2026', approved_on: null },
];

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(HandOver, {
        files: FILES,
        action: '/admin/file/handover',
        csrf: 'test-token',
        cancelUrl: '/admin/files/approved',
        handedOverOn: '2026-09-16',
        today: '2026-09-16',
        ...overrides,
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
});

const rowOf = (host, fileNo) =>
    [...host.querySelectorAll('tbody tr')].find((tr) => tr.textContent.includes(fileNo));

const tick = (host, fileNo) => rowOf(host, fileNo).querySelector('input[name="files[]"]');

const visible = (host) =>
    [...host.querySelectorAll('tbody tr')]
        .filter((tr) => tr.querySelector('input[name="files[]"]') && tr.style.display !== 'none')
        .map((tr) => tr.querySelector('.ui-lead').textContent);

const posted = (host) =>
    [...host.querySelectorAll('input[name="files[]"]')].filter((box) => box.checked).map((box) => Number(box.value));

async function search(host, text) {
    const box = host.querySelector('input[type="search"]');
    box.value = text;
    box.dispatchEvent(new Event('input'));
    await nextTick();
}

describe('handing papers over', () => {
    it('posts under the names the server reads', () => {
        const host = mount();

        expect(host.querySelector('form').getAttribute('action')).toBe('/admin/file/handover');
        expect(host.querySelector('input[name="_token"]').value).toBe('test-token');
        expect(host.querySelector('input[name="handed_over_on"]').value).toBe('2026-09-16');
        expect(host.querySelector('input[name="collected_by"]')).not.toBeNull();
        expect(host.querySelector('input[name="remark"]')).not.toBeNull();

        // Nothing about money on this screen at all.
        expect(host.querySelector('input[name^="amounts"]')).toBeNull();
    });

    it('will not submit until a file is ticked', async () => {
        const host = mount();
        const submit = host.querySelector('button[type="submit"]');

        expect(submit.disabled).toBe(true);

        tick(host, 'F-00050').click();
        await nextTick();

        expect(submit.disabled).toBe(false);
        expect(host.querySelector('.ui-card__foot').textContent).toContain('No balance changes');
    });

    it('does not let the date be picked in the future', () => {
        const host = mount();

        expect(host.querySelector('#handed_over_on_display').dataset.max).toBe('2026-09-16');
    });

    it('says which of the two optional boxes the customer sees', () => {
        const host = mount();
        const hints = [...host.querySelectorAll('.ui-hint')].map((el) => el.textContent);

        expect(hints.some((text) => text.includes('customer does not see'))).toBe(true);
        expect(hints.some((text) => text.includes('customer sees'))).toBe(true);
    });
});

describe('narrowing the list', () => {
    it('narrows by every word typed', async () => {
        const host = mount();

        await search(host, 'car4sales tr');

        expect(visible(host)).toEqual(['F-00040']);
    });

    it('opens already narrowed when arriving from one file', () => {
        const host = mount({ search: 'F-00056' });

        expect(host.querySelector('input[type="search"]').value).toBe('F-00056');
        expect(visible(host)).toEqual(['F-00056']);
    });

    /*
     * A ticked row the search hides is still on the form and still handed over.
     * That is right, and surprising, so the footer says it.
     */
    it('keeps a ticked file ticked while the search hides it, and says so', async () => {
        const host = mount();

        tick(host, 'F-00050').click();
        await nextTick();

        await search(host, 'car4sales');

        expect(visible(host)).not.toContain('F-00050');
        expect(posted(host)).toEqual([1]);
        expect(host.querySelector('.ui-card__foot').textContent).toContain('hidden by the search');
    });

    it('selects only what is on screen', async () => {
        const host = mount();

        await search(host, 'car4sales');

        host.querySelector('.hov-all input').click();
        await nextTick();

        expect(posted(host).sort()).toEqual([2, 3]);
    });

    it('leaves a hidden ticked file alone when unselecting what is on screen', async () => {
        const host = mount();

        tick(host, 'F-00050').click();
        await nextTick();

        await search(host, 'car4sales');

        const all = host.querySelector('.hov-all input');
        all.click();
        await nextTick();
        all.click();
        await nextTick();

        expect(posted(host)).toEqual([1]);
    });

    it('says so when nothing matches', async () => {
        const host = mount();

        await search(host, 'nobody');

        expect(host.querySelector('.hov-none')).not.toBeNull();
        expect(host.querySelector('.hov-all input').disabled).toBe(true);
    });
});
