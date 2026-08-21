import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import ReceiveFileRows from './components/ReceiveFileRows.vue';

/*
 * Taking papers in over the counter.
 *
 * The screen is a form the server validates field by field, so what matters
 * most is that the boxes keep the names it expects however many files and works
 * are added. That, the running total, and the rate that fills itself in are all
 * browser behaviour, and had nothing covering them at all.
 */

const mounted = [];

function mount(props = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(ReceiveFileRows, {
        workTypes: [
            { id: 1, name: 'HPA', default_rate: '2500.00' },
            { id: 2, name: 'TR', default_rate: null },
        ],
        historyUrl: '/admin/api/work-files/history',
        cancelUrl: '/admin/files',
        oldRows: [],
        ...props,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const byName = (host, name) => host.querySelector(`[name="${name}"]`);

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    vi.restoreAllMocks();
});

describe('receiving files', () => {
    it('starts as one file for one work', () => {
        const host = mount();

        expect(host.querySelectorAll('.rcv-file').length).toBe(1);
        expect(host.querySelectorAll('.rcv-work').length).toBe(1);
        expect(byName(host, 'rows[0][registration_no]')).toBeTruthy();
        expect(byName(host, 'rows[0][works][0][work_type_id]')).toBeTruthy();
        expect(byName(host, 'rows[0][works][0][amount]')).toBeTruthy();
    });

    /*
     * The names are the contract with the server. A second work has to land at
     * works[1], and a second file at rows[1], or the batch posts to fields
     * nothing validates and the extra work is silently dropped.
     */
    it('names every added work and file the way the server reads them', async () => {
        const host = mount();

        host.querySelector('.rcv-works__add').click();
        await nextTick();

        expect(byName(host, 'rows[0][works][1][work_type_id]')).toBeTruthy();
        expect(byName(host, 'rows[0][works][1][amount]')).toBeTruthy();

        host.querySelector('.rcv-add').click();
        await nextTick();

        expect(host.querySelectorAll('.rcv-file').length).toBe(2);
        expect(byName(host, 'rows[1][registration_no]')).toBeTruthy();
        expect(byName(host, 'rows[1][works][0][work_type_id]')).toBeTruthy();
    });

    it('will not remove the only work, or the only file', async () => {
        const host = mount();

        expect(host.querySelector('.rcv-work__remove').disabled).toBe(true);
        expect(host.querySelector('.rcv-file__remove').disabled).toBe(true);

        host.querySelector('.rcv-works__add').click();
        host.querySelector('.rcv-add').click();
        await nextTick();

        expect(host.querySelector('.rcv-work__remove').disabled).toBe(false);
        expect(host.querySelector('.rcv-file__remove').disabled).toBe(false);
    });

    /*
     * Picking a work type fills in what it usually costs — a starting point,
     * not a rule. Overwriting a price already agreed with the customer would be
     * the screen arguing with the person using it.
     */
    it('offers the standard rate but never overwrites a price already typed', async () => {
        const host = mount();

        const type = byName(host, 'rows[0][works][0][work_type_id]');
        const amount = byName(host, 'rows[0][works][0][amount]');

        type.value = '1';
        type.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(amount.value).toBe('2500.00');

        // Priced by hand, then the work type corrected: the price stands.
        amount.value = '4000';
        amount.dispatchEvent(new window.Event('input'));
        await nextTick();

        type.value = '2';
        type.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(amount.value).toBe('4000');
    });

    it('adds up every work on every file, and says what is still to fill in', async () => {
        const host = mount();

        host.querySelector('.rcv-works__add').click();
        await nextTick();

        const first = byName(host, 'rows[0][works][0][amount]');
        const second = byName(host, 'rows[0][works][1][amount]');

        first.value = '2500';
        first.dispatchEvent(new window.Event('input'));
        second.value = '3000';
        second.dispatchEvent(new window.Event('input'));
        await nextTick();

        expect(host.querySelector('.rcv-foot__value').textContent.trim()).toBe('5,500.00');

        // And the file's own card says what that file comes to.
        expect(host.querySelector('.rcv-file__sum .ui-money').textContent.trim()).toBe('5,500.00');

        // Neither work has a type yet, so the footer says so rather than
        // letting the batch bounce off the server.
        expect(host.querySelector('.rcv-foot .ui-hint').textContent)
            .toContain('2 still need a work type and a price');
    });

    /*
     * The panel above this one shows where the customer's balance lands. It
     * listens for the total rather than reaching into this markup, so the event
     * is part of the contract.
     */
    it('announces the running total', async () => {
        const host = mount();
        const heard = [];

        document.addEventListener('receive-total', (event) => heard.push(event.detail));

        const amount = byName(host, 'rows[0][works][0][amount]');
        amount.value = '1200';
        amount.dispatchEvent(new window.Event('input'));
        await nextTick();

        expect(heard).toEqual([1200]);
    });

    /*
     * A bounced form comes back with everything as it was typed. Losing the
     * second work would be worse than losing the whole form: the operator
     * cannot see that it went.
     */
    it('comes back with every work a bounced form was carrying', () => {
        const host = mount({
            oldRows: [{
                registration_no: 'BR01AB1234',
                description: 'Party ref',
                works: [
                    { work_type_id: 1, amount: '2500' },
                    { work_type_id: 2, amount: '3000' },
                ],
            }],
        });

        expect(host.querySelectorAll('.rcv-work').length).toBe(2);
        expect(byName(host, 'rows[0][registration_no]').value).toBe('BR01AB1234');
        expect(byName(host, 'rows[0][works][1][amount]').value).toBe('3000');
        expect(host.querySelector('.rcv-foot__value').textContent.trim()).toBe('5,500.00');
    });
});
