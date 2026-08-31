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

/*
 * A file is for one transfer and one hypothecation addition, never two of
 * either. The dropdown stops offering what is already on the card, so the
 * mistake is not available to make.
 */
describe('the works a file may still be for', () => {
    const optionsOn = (host, index) =>
        [...host.querySelectorAll('.rcv-work select')[index].options]
            .map((option) => option.textContent.trim())
            .filter((text) => text !== 'Select work');

    it('drops a work from the other lines once it is chosen', async () => {
        const host = mount();

        expect(optionsOn(host, 0)).toEqual(['HPA — 2,500.00', 'TR']);

        host.querySelector('.rcv-works__add').click();
        await nextTick();

        const first = byName(host, 'rows[0][works][0][work_type_id]');
        first.value = '1';
        first.dispatchEvent(new window.Event('change'));
        await nextTick();

        // The line that chose it keeps it, or it would have nothing to show.
        expect(optionsOn(host, 0)).toEqual(['HPA — 2,500.00', 'TR']);
        expect(optionsOn(host, 1)).toEqual(['TR']);
    });

    it('offers it again when the line that took it lets go', async () => {
        const host = mount();

        host.querySelector('.rcv-works__add').click();
        await nextTick();

        const first = byName(host, 'rows[0][works][0][work_type_id]');
        first.value = '1';
        first.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(optionsOn(host, 1)).toEqual(['TR']);

        first.value = '';
        first.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(optionsOn(host, 1)).toEqual(['HPA — 2,500.00', 'TR']);
    });

    /*
     * Another line would have nothing to offer, so the button says so rather
     * than adding one that cannot be filled in.
     */
    it('stops adding lines once every work is on the file', async () => {
        const host = mount();
        const add = host.querySelector('.rcv-works__add');

        expect(add.disabled).toBe(false);

        add.click();
        await nextTick();

        // Two work types, two lines.
        expect(host.querySelectorAll('.rcv-work').length).toBe(2);
        expect(host.querySelector('.rcv-works__add').disabled).toBe(true);

        host.querySelector('.rcv-works__add').click();
        await nextTick();

        expect(host.querySelectorAll('.rcv-work').length).toBe(2);
    });

    it('counts each file on its own — two vehicles may need the same work', async () => {
        const host = mount();

        const first = byName(host, 'rows[0][works][0][work_type_id]');
        first.value = '1';
        first.dispatchEvent(new window.Event('change'));

        host.querySelector('.rcv-add').click();
        await nextTick();

        expect(optionsOn(host, 1)).toEqual(['HPA — 2,500.00', 'TR']);
    });
});

/*
 * What this customer paid before, where the next price is typed.
 *
 * The mirror of the vendor screen's rate history, on the other side of the
 * counter. The customer is chosen after the page loads, so it is fetched — and
 * the panel above announces the choice rather than this reaching for it.
 */
describe('what this customer paid before', () => {
    const RATES = {
        works: [{
            work_type_id: 1,
            rto: 'BR06',
            rates: [
                { file_no: 'F-00007', received_date: '14-08-2026', registration_no: 'BR06CL5310', work_type: 'HPA', status: 'Approval Done', amount: 2500 },
                { file_no: 'F-00004', received_date: '02-08-2026', registration_no: 'BR07BA1145', work_type: 'HPA', status: 'File Dispatch', amount: 2200 },
            ],
        }, {
            work_type_id: 1,
            rto: 'BR01',
            rates: [
                { file_no: 'F-00001', received_date: '01-08-2026', registration_no: 'BR01DD1234', work_type: 'HPA', status: 'Approval Done', amount: 9999 },
            ],
        }],
    };

    const chooseCustomer = async (id) => {
        document.dispatchEvent(new window.CustomEvent('receive-customer', { detail: id }));

        // The fetch resolves on its own microtask, then Vue renders.
        await Promise.resolve();
        await Promise.resolve();
        await nextTick();
    };

    function stubFetch(payload = RATES) {
        const calls = [];

        global.fetch = (url) => {
            calls.push(url);

            // The registration lookup and the rates lookup are different
            // endpoints; answering both with the same body would have the
            // vehicle history render whatever the rates returned.
            const body = url.includes('customer-rates')
                ? payload
                : { registration_no: 'BR06AS1267', count: 0, files: [] };

            return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
        };

        return calls;
    }

    // A row has to say which office its papers go through before a price from
    // one can be offered.
    const typeRegistration = async (host, value) => {
        const box = byName(host, 'rows[0][registration_no]');
        box.value = value;
        box.dispatchEvent(new window.Event('input'));
        await nextTick();
    };

    it('asks for the chosen customer, and nothing before one is chosen', async () => {
        const calls = stubFetch();
        const host = mount({ ratesUrl: '/admin/api/work-files/customer-rates' });

        await chooseCustomer(null);
        expect(calls).toEqual([]);

        await chooseCustomer(7);
        expect(calls).toEqual(['/admin/api/work-files/customer-rates?customer_id=7']);

        host.remove();
    });

    it('says the last price beside the box where the next one is typed', async () => {
        stubFetch();
        const host = mount({ ratesUrl: '/admin/api/work-files/customer-rates' });

        // Nothing until a work type is chosen: the history is per work.
        await chooseCustomer(7);
        expect(host.querySelectorAll('.rcv-past__open').length).toBe(0);

        await typeRegistration(host, 'BR06AS1267');

        const type = byName(host, 'rows[0][works][0][work_type_id]');
        type.value = '1';
        type.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(host.querySelectorAll('.rcv-past__open').length).toBe(1);
        expect(host.querySelector('.rcv-past__open').textContent).toContain('2,500.00');
    });

    it('opens what they were charged, and closes again', async () => {
        stubFetch();
        const host = mount({ ratesUrl: '/admin/api/work-files/customer-rates' });

        await chooseCustomer(7);

        await typeRegistration(host, 'BR06AS1267');

        const type = byName(host, 'rows[0][works][0][work_type_id]');
        type.value = '1';
        type.dispatchEvent(new window.Event('change'));
        await nextTick();

        host.querySelector('.rcv-past__open').click();
        await nextTick();

        const panel = host.querySelector('.rcv-past');

        expect(panel).toBeTruthy();
        expect(panel.querySelectorAll('tbody tr').length).toBe(2);
        expect(panel.textContent).toContain('BR07BA1145');
        expect(panel.textContent).toContain('2,200.00');
        // Status, because a price on a cancelled file is not a price to repeat.
        expect(panel.textContent).toContain('Approval Done');

        host.querySelector('.rcv-past__open').click();
        await nextTick();

        expect(host.querySelector('.rcv-past')).toBe(null);
    });

    /*
     * The history is a pricing aid, not part of the form: a price looked up
     * must never be sent as a price agreed.
     */
    it('posts nothing of its own', async () => {
        stubFetch();
        const host = mount({ ratesUrl: '/admin/api/work-files/customer-rates' });

        await chooseCustomer(7);

        await typeRegistration(host, 'BR06AS1267');

        const type = byName(host, 'rows[0][works][0][work_type_id]');
        type.value = '1';
        type.dispatchEvent(new window.Event('change'));
        await nextTick();

        host.querySelector('.rcv-past__open').click();
        await nextTick();

        expect([...host.querySelectorAll('.rcv-past [name]')]).toEqual([]);
    });

    /*
     * A price can still be agreed without it, so a lookup that fails is quiet
     * rather than an error in the middle of taking papers in.
     */
    it('stays quiet when the lookup fails', async () => {
        global.fetch = () => Promise.resolve({ ok: false, status: 500 });

        const host = mount({ ratesUrl: '/admin/api/work-files/customer-rates' });

        await chooseCustomer(7);

        await typeRegistration(host, 'BR06AS1267');

        const type = byName(host, 'rows[0][works][0][work_type_id]');
        type.value = '1';
        type.dispatchEvent(new window.Event('change'));
        await nextTick();

        expect(host.querySelectorAll('.rcv-past__open').length).toBe(0);
        // And the row still works.
        expect(byName(host, 'rows[0][works][0][amount]')).not.toBe(null);
    });
});

/*
 * A price belongs to an office as well as to a customer and a work.
 *
 * The RTO fee is passed on, so the same work costs this customer one thing at
 * BR01 and another at BR06. A price from the wrong office is worse than none:
 * it reads as an answer.
 */
describe('the office a price was charged at', () => {
    const RATES = {
        works: [
            {
                work_type_id: 1,
                rto: 'BR06',
                rates: [{ file_no: 'F-00012', received_date: '14-08-2026', registration_no: 'BR06DY2856', work_type: 'HPT+TR+HPA', status: 'Approval Done', amount: 5600 }],
            },
            {
                work_type_id: 1,
                rto: 'BR01',
                rates: [{ file_no: 'F-00023', received_date: '23-08-2026', registration_no: 'BR01HB1967', work_type: 'HPT+TR+HPA', status: 'File Dispatch', amount: 11000 }],
            },
        ],
    };

    async function screenFor(registration) {
        global.fetch = (url) => Promise.resolve({
            ok: true,
            json: () => Promise.resolve(
                url.includes('customer-rates') ? RATES : { registration_no: registration, count: 0, files: [] }
            ),
        });

        const host = mount({ ratesUrl: '/admin/api/work-files/customer-rates' });

        document.dispatchEvent(new window.CustomEvent('receive-customer', { detail: 7 }));
        await Promise.resolve();
        await Promise.resolve();
        await nextTick();

        const box = byName(host, 'rows[0][registration_no]');
        box.value = registration;
        box.dispatchEvent(new window.Event('input'));

        const type = byName(host, 'rows[0][works][0][work_type_id]');
        type.value = '1';
        type.dispatchEvent(new window.Event('change'));
        await nextTick();

        return host;
    }

    it('quotes the office the papers are actually going to', async () => {
        const host = await screenFor('BR06AS1267');

        expect(host.querySelector('.rcv-past__open').textContent).toContain('5,600.00');
        expect(host.querySelector('.rcv-past__open').textContent).toContain('BR06');
        expect(host.querySelector('.rcv-past__open').textContent).not.toContain('11,000');
    });

    it('quotes a different one for a different office', async () => {
        const host = await screenFor('BR01ZZ9999');

        expect(host.querySelector('.rcv-past__open').textContent).toContain('11,000.00');
        expect(host.querySelector('.rcv-past__open').textContent).not.toContain('5,600');
    });

    it('shows only that office in the panel', async () => {
        const host = await screenFor('BR06AS1267');

        host.querySelector('.rcv-past__open').click();
        await nextTick();

        const panel = host.querySelector('.rcv-past');

        expect(panel.textContent).toContain('BR06DY2856');
        expect(panel.textContent).not.toContain('BR01HB1967');
        expect(panel.querySelector('.rcv-past__head').textContent).toContain('BR06');
    });

    /*
     * Until the registration says which office, there is nothing honest to
     * offer — every office's price is a different answer.
     */
    it('offers nothing until the papers say where they are going', async () => {
        const host = await screenFor('BR');

        expect(host.querySelectorAll('.rcv-past__open').length).toBe(0);
    });
});
