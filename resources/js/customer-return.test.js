import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import CustomerReturn from './components/CustomerReturn.vue';

/*
 * Return to Customer: the refund box.
 *
 * Reported 2026-11-23: a file charged nothing could not go back to its
 * customer. Ticking it filled the box with 0.00, which the screen then refused
 * as not more than zero, with the button greyed out; the browser refused it
 * too, its least allowed figure (0.01) being above its most (0). All of
 * nothing is nothing, and it goes back.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const file = (id, charged) => ({
    id, file_no: `F-000${id}`, received_date: '01-11-2026', registration_no: `BR01CD000${id}`,
    work_type: 'Duplicate RC', description: '', customer: 'Car4Sales', status: 'in_office',
    status_label: 'In Office', customer_amount: charged,
});

function mount(files) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(CustomerReturn, {
        files,
        action: '/admin/file/customer-return',
        csrf: 'token',
        cancelUrl: '/admin/files',
        returnedOn: '2026-11-23',
        oldRemark: 'Customer took the papers back',
        oldFiles: [],
        oldAmounts: {},
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const tick = async (host, id) => {
    const box = host.querySelector(`input[name="files[]"][value="${id}"]`);
    box.checked = true;
    box.dispatchEvent(new window.Event('change'));
    await nextTick();
};

const amountOf = (host, id) => host.querySelector(`input[name="amounts[${id}]"]`);

const type = async (box, value) => {
    box.value = value;
    box.dispatchEvent(new window.Event('input'));
    await nextTick();
};

const returnButton = (host) => [...host.querySelectorAll('button[type="submit"]')].pop();

describe('a file charged nothing', () => {
    it('goes back with nothing filled in and nothing refused', async () => {
        const host = mount([file(1, 0)]);
        await tick(host, 1);

        const box = amountOf(host, 1);
        expect(box.value).toBe('');
        expect(box.validity.valid).toBe(true);
        expect(host.textContent).toContain('Nothing was charged, so nothing is refunded');
        expect(host.textContent).not.toContain('Must be more than zero');
        expect(returnButton(host).disabled).toBe(false);
    });

    it('may be given a 0, which is all of it', async () => {
        const host = mount([file(1, 0)]);
        await tick(host, 1);
        await type(amountOf(host, 1), '0');

        expect(amountOf(host, 1).validity.valid).toBe(true);
        expect(host.textContent).not.toContain('must be more than zero');
        expect(returnButton(host).disabled).toBe(false);
    });

    it('still cannot be refunded more than nothing', async () => {
        const host = mount([file(1, 0)]);
        await tick(host, 1);
        await type(amountOf(host, 1), '5');

        expect(host.textContent).toContain('More than the');
        expect(returnButton(host).disabled).toBe(true);
    });
});

describe('a file that was charged', () => {
    it('fills in what was charged, as before', async () => {
        const host = mount([file(2, 3000)]);
        await tick(host, 2);

        expect(amountOf(host, 2).value).toBe('3000.00');
        expect(returnButton(host).disabled).toBe(false);
    });

    it('still refuses a refund of nothing: blank is all of it', async () => {
        const host = mount([file(2, 3000)]);
        await tick(host, 2);
        await type(amountOf(host, 2), '0');

        expect(host.textContent).toContain('Must be more than zero');
        expect(amountOf(host, 2).validity.valid).toBe(false);
        expect(returnButton(host).disabled).toBe(true);
    });

    it('goes back beside a file charged nothing in one batch', async () => {
        const host = mount([file(1, 0), file(2, 3000)]);
        await tick(host, 1);
        await tick(host, 2);

        expect(returnButton(host).disabled).toBe(false);
        expect(host.textContent).toContain('2 files go back, 3,000.00 credited.');
    });
});
