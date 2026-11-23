import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import VendorReturn from './components/VendorReturn.vue';

/*
 * Papers Returned by Vendor, a row a vendor a file.
 *
 * Asked for by the owner on 2026-09-28: a folder split between two vendors is
 * listed once for each, and a row takes back only that vendor's works. What a
 * row posts is its key — "12:5", folder 12's vendor 5 — so a slip back to the
 * file's own number would take back both vendors' work at once.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

function mount(props) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(VendorReturn, {
        action: '/admin/file/vendor-return',
        csrf: 'token',
        cancelUrl: '/admin/files',
        returnedOn: '2026-09-18',
        ...props,
    });
    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const row = (vendorId, vendor, amount) => ({
    id: 12, key: `12:${vendorId}`, vendor_id: vendorId, file_no: 'F-00012', registration_no: 'BR01AB1234',
    vendor, vendor_date: '12-09-2026', days_out: '6 days', work_type: vendorId === 5 ? 'HPT' : 'TR',
    description: '', customer: 'Car4Sales', vendor_amount: amount,
});

describe('a folder two vendors hold', () => {
    it('posts each vendor\'s row by its own key', () => {
        const host = mount({ files: [row(5, 'Sharma', 1000), row(6, 'Shailendra', 1500)] });

        const ticks = [...host.querySelectorAll('input.vr-tick[name="files[]"]')];
        expect(ticks.map((tick) => tick.value)).toEqual(['12:5', '12:6']);

        const amounts = [...host.querySelectorAll('input[type="number"]')];
        expect(amounts.map((box) => box.name)).toEqual(['amounts[12:5]', 'amounts[12:6]']);

        // Named for the vendor, so a screen reader can tell the two apart.
        expect(ticks[1].getAttribute('aria-label')).toContain('Shailendra');
    });

    it('comes back from a refused batch with only that vendor\'s row ticked and filled in', () => {
        const host = mount({
            files: [row(5, 'Sharma', 1000), row(6, 'Shailendra', 1500)],
            pickedIds: ['12:6'],
            oldAmounts: { '12:6': '700' },
        });

        const ticks = [...host.querySelectorAll('input.vr-tick[name="files[]"]')];
        expect(ticks.map((tick) => tick.checked)).toEqual([false, true]);

        const amounts = [...host.querySelectorAll('input[type="number"]')];
        expect(amounts[1].value).toBe('700');
        expect(amounts[0].value).toBe('');
    });
});

describe('work nobody priced', () => {
    it('is not filled in with a nought when ticked', async () => {
        const host = mount({ files: [row(5, 'Sharma', null)] });

        const tick = host.querySelector('input.vr-tick[name="files[]"]');
        tick.checked = true;
        tick.dispatchEvent(new Event('change'));
        await nextTick();

        expect(host.querySelector('input[type="number"]').value).toBe('');
    });
});

describe('a page from before rows had keys', () => {
    it('posts the file\'s own number', () => {
        const { key, ...old } = row(5, 'Sharma', 1000);
        const host = mount({ files: [old] });

        expect(host.querySelector('input.vr-tick[name="files[]"]').value).toBe('12');
    });
});

/*
 * Search, asked for on 2026-11-23. It narrows what is on screen and never what
 * is sent: a ticked file hidden by the search still comes back, and the footer
 * says so.
 */
describe('searching the files out with vendors', () => {
    const files = () => [
        { ...row(5, 'Sharma', 1000), id: 12, key: '12:5', file_no: 'F-00012' },
        { ...row(6, 'Shailendra', 1500), id: 13, key: '13:6', file_no: 'F-00013', registration_no: 'BR06CD5678' },
        { ...row(5, 'Sharma', 800), id: 14, key: '14:5', file_no: 'F-00014', customer: 'Maa Durga Auto', registration_no: 'BR01EF9999' },
    ];

    const search = async (host, text) => {
        const box = host.querySelector('input[type="search"]');
        box.value = text;
        box.dispatchEvent(new window.Event('input'));
        await nextTick();
    };

    const visible = (host) => [...host.querySelectorAll('input.vr-tick[name="files[]"]')]
        .filter((tick) => tick.closest('tr').style.display !== 'none')
        .map((tick) => tick.value);

    it('finds by vendor, customer, vehicle or file, every word narrowing', async () => {
        const host = mount({ files: files() });

        expect(host.querySelector('input[type="search"]').name).toBe('');

        await search(host, 'sharma');
        expect(visible(host)).toEqual(['12:5', '14:5']);

        await search(host, 'sharma maa durga');
        expect(visible(host)).toEqual(['14:5']);

        await search(host, 'br 06');
        expect(visible(host)).toEqual(['13:6']);
    });

    it('selects all of what is shown, and keeps a hidden tick going back', async () => {
        const host = mount({ files: files(), pickedIds: ['13:6'], remark: 'Vendor sent them back' });

        await search(host, 'sharma');
        const all = host.querySelector('.vr-all input');
        all.checked = true;
        all.dispatchEvent(new window.Event('change'));
        await nextTick();

        const ticked = [...host.querySelectorAll('input.vr-tick[name="files[]"]')].filter((tick) => tick.checked).map((tick) => tick.value);
        expect(ticked).toEqual(['12:5', '13:6', '14:5']);
        expect(host.textContent).toContain('1 ticked file is not shown by the search, and still comes back.');
    });

    it('says when nothing matches', async () => {
        const host = mount({ files: files() });

        await search(host, 'nobody');

        expect(visible(host)).toEqual([]);
        expect(host.textContent).toContain('No files match “nobody”.');
    });
});
