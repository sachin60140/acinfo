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
