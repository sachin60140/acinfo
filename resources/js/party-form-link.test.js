import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import PartyForm from './components/PartyForm.vue';

/*
 * A customer and a vendor with the same mobile may be one person. The Edit
 * screen says so and offers to link them for set-off — offered, never done:
 * nothing is linked until the office picks it and presses Update.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const WORKS = { id: 9, name: 'Arman Works', mobile: '9835230001' };
const OTHER = { id: 10, name: 'Chandan Works', mobile: '9835230002' };

async function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(PartyForm, {
        action: '/admin/party/edit/7',
        csrf: 'test-token',
        label: 'Customer',
        indexUrl: '/admin/parties/customer',
        isEdit: true,
        values: { name: 'Arman Qadri', mobile: '9835230001', whatsapp: '', address: '' },
        link: { shown: true, side: 'customer', value: '', options: [WORKS, OTHER], linkedName: '', linkedUrl: '', suggestName: '', suggestUrl: '' },
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });
    await nextTick();

    return host;
}

const select = (host) => host.querySelector('select[name="linked_vendor_id"]');
const hint = (host) => host.querySelector('.pf-suggest');

async function typeMobile(host, value) {
    const input = host.querySelector('input[name="mobile"]');
    input.value = value;
    input.dispatchEvent(new window.Event('input'));
    await nextTick();
}

describe('on a customer\'s Edit screen', () => {
    it('offers the vendor with the same mobile', async () => {
        const host = await mount();

        expect(hint(host)).not.toBe(null);
        expect(hint(host).textContent).toContain('Same mobile as vendor Arman Works');
        expect(hint(host).textContent).toContain('Nothing is linked until you press Update');
        // Offered, not chosen.
        expect(select(host).value).toBe('');
    });

    it('links them when asked, and then says no more', async () => {
        const host = await mount();

        [...hint(host).querySelectorAll('button')].find((b) => b.textContent.includes('Link them')).click();
        await nextTick();

        expect(select(host).value).toBe('9');
        expect(hint(host)).toBe(null);
    });

    it('follows the mobile as it is typed', async () => {
        const host = await mount({ values: { name: 'Arman Qadri', mobile: '9835230099', whatsapp: '', address: '' } });
        expect(hint(host)).toBe(null);

        await typeMobile(host, '9835230002');
        expect(hint(host).textContent).toContain('Chandan Works');

        await typeMobile(host, '98352300');
        expect(hint(host)).toBe(null);
    });

    it('says nothing when a vendor is chosen already', async () => {
        const host = await mount({ link: { shown: true, side: 'customer', value: '10', options: [WORKS, OTHER], linkedName: '', linkedUrl: '', suggestName: '', suggestUrl: '' } });

        expect(select(host).value).toBe('10');
        expect(hint(host)).toBe(null);
    });
});

describe('on a vendor\'s Edit screen', () => {
    it('points to the customer with the same mobile, where the link is made', async () => {
        const host = await mount({
            label: 'Vendor',
            link: { shown: true, side: 'vendor', value: '', options: [], linkedName: '', linkedUrl: '', suggestName: 'Arman Qadri', suggestUrl: '/admin/party/edit/7' },
        });

        expect(host.textContent).toContain('Same mobile as customer Arman Qadri');
        expect(host.querySelector('a[href="/admin/party/edit/7"]')).not.toBe(null);
    });

    it('says who it is linked to instead, once it is', async () => {
        const host = await mount({
            label: 'Vendor',
            link: { shown: true, side: 'vendor', value: '', options: [], linkedName: 'Arman Qadri (9835230001)', linkedUrl: '/admin/party/edit/7', suggestName: '', suggestUrl: '' },
        });

        expect(host.textContent).toContain('Arman Qadri (9835230001)');
        expect(host.textContent).not.toContain('Same mobile as customer');
    });
});

describe('pressing Link them', () => {
    it('leaves focus on the box it picked into, not on nothing', async () => {
        const host = await mount();

        [...hint(host).querySelectorAll('button')].find((b) => b.textContent.includes('Link them')).click();
        await nextTick();
        await nextTick();

        expect(document.activeElement).toBe(select(host));
    });
});
