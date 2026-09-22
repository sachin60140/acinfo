import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import PaperAudit from './components/PaperAudit.vue';
import { pendingMessage, whatsappNumber, whatsappUrl } from './paperShare';

/*
 * Sending a customer the papers they still owe, on WhatsApp.
 *
 * The rows below are file 57's, from the Paper Audit screen as it was on the
 * day this was asked for.
 */

const row = (id, paper, over = {}) => ({
    id,
    file_id: 57,
    file_no: 'F-00057',
    registration_no: 'BR32PA0842',
    customer: 'Kuwy Technology Service Pvt Ltd',
    customer_id: 9,
    customer_mobile: '9835230000',
    paper,
    works: ['TR'],
    note: null,
    office_note: null,
    since: '21-09-2026',
    papers_url: '/admin/file/57/papers',
    ...over,
});

const FILE_57 = [
    row(1, 'Insurance certificate'),
    row(2, 'PUC certificate'),
    row(3, 'Seller Passport-size photographs'),
    row(4, 'Form 34', { note: 'Buyer has not signed it' }),
    row(5, 'Loan agreement / sanction copy'),
    row(6, 'Email Confirmation'),
];

describe('the message', () => {
    it('lists the papers under the file and vehicle they are for', () => {
        const text = pendingMessage(FILE_57);

        expect(text).toContain('*F-00057 · BR32PA0842*');
        expect(text).toContain('1. Insurance certificate');
        expect(text).toContain('6. Email Confirmation');
    });

    it('names the customer once at the top when it is all theirs', () => {
        const text = pendingMessage(FILE_57);

        expect(text.split('\n')[1]).toBe('Kuwy Technology Service Pvt Ltd');
        expect(text.match(/Kuwy Technology/g)).toHaveLength(1);
    });

    it('carries the note written for the customer', () => {
        expect(pendingMessage(FILE_57)).toContain('4. Form 34 — Buyer has not signed it');
    });

    /*
     * The line that must never move. office_note is the office's own and is
     * never shown on the customer's page; a chat is further from the office
     * than that page is.
     */
    /* No customer is told who does the work: the server sends the note with any vendor taken out. */
    it('carries the note as the customer may read it, with no vendor in it', () => {
        const text = pendingMessage([
            row(1, 'NOC', { note: 'Shailendra will get it from the bank', share_note: '… will get it from the bank' }),
        ]);

        expect(text).toContain('1. NOC — … will get it from the bank');
        expect(text).not.toContain('Shailendra');
    });

    it('never carries the note the office wrote for itself', () => {
        const text = pendingMessage([
            row(1, 'Form 34', { note: 'Buyer to sign', office_note: 'Customer is slow to pay, chase hard' }),
        ]);

        expect(text).toContain('Buyer to sign');
        expect(text).not.toContain('chase hard');
        expect(text).not.toContain('slow to pay');
    });

    it('numbers each file from one, so "number three" means one paper', () => {
        const text = pendingMessage([
            row(1, 'RC', { file_no: 'F-00001', registration_no: 'BR01AA0001' }),
            row(2, 'Form 30', { file_no: 'F-00002', registration_no: 'BR01AA0002' }),
            row(3, 'Form 34', { file_no: 'F-00002', registration_no: 'BR01AA0002' }),
        ]);

        expect(text).toContain('*F-00001 · BR01AA0001*\n1. RC');
        expect(text).toContain('*F-00002 · BR01AA0002*\n1. Form 30\n2. Form 34');
    });

    /* The same message can reach a group, so each file says whose it is. */
    it('names each customer when the papers belong to several', () => {
        const text = pendingMessage([
            row(1, 'RC', { file_no: 'F-00001', customer: 'Car4Sales', customer_id: 1 }),
            row(2, 'Form 30', { file_no: 'F-00002', customer: 'Rishu Ji', customer_id: 2 }),
        ]);

        expect(text).toContain('*F-00001 · BR32PA0842*\nCar4Sales');
        expect(text).toContain('*F-00002 · BR32PA0842*\nRishu Ji');
    });

    it('says nothing at all when there is nothing pending', () => {
        expect(pendingMessage([])).toBe('');
    });
});

describe('the number', () => {
    it('adds the country code to a ten-digit mobile', () => {
        expect(whatsappNumber('9835230000')).toBe('919835230000');
    });

    it('reads a number however it was typed in', () => {
        expect(whatsappNumber('98352 30000')).toBe('919835230000');
        expect(whatsappNumber('+91 98352-30000')).toBe('919835230000');
        expect(whatsappNumber('09835230000')).toBe('919835230000');
        expect(whatsappNumber('919835230000')).toBe('919835230000');
    });

    /* A landline has no WhatsApp; a chat opened with one fails where nobody can see why. */
    it('refuses what cannot be a mobile rather than guessing', () => {
        expect(whatsappNumber('0612223344')).toBeNull();   // a Patna landline
        expect(whatsappNumber('12345')).toBeNull();
        expect(whatsappNumber('')).toBeNull();
        expect(whatsappNumber(null)).toBeNull();
    });

    it('opens the chat on the customer, or asks whom when there is no number', () => {
        expect(whatsappUrl('919835230000', 'Hi there')).toBe('https://wa.me/919835230000?text=Hi%20there');
        expect(whatsappUrl(null, 'Hi there')).toBe('https://wa.me/?text=Hi%20there');
    });

    it('keeps the line breaks and the bold when it goes into the link', () => {
        const url = whatsappUrl('919835230000', pendingMessage(FILE_57));

        expect(decodeURIComponent(url.split('text=')[1])).toBe(pendingMessage(FILE_57));
    });
});

// ------------------------------------------------------------------ the screen

const mounted = [];

function mount(pending, search = '') {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(PaperAudit, {
        action: '/admin/file/paper-audit',
        csrf: 'token',
        today: '2026-09-21',
        search,
        toCheck: [],
        pending,
        stuck: [],
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

    vi.restoreAllMocks();
});

const shareButton = (host) => [...host.querySelectorAll('.pau-share button')].find((b) => b.textContent.includes('WhatsApp'));

describe('on the Paper Audit screen', () => {
    it('offers to send the papers that are showing, to the customer they belong to', () => {
        const host = mount(FILE_57);

        expect(host.querySelector('.pau-share__what').textContent).toContain('these 6 papers');
        expect(host.querySelector('.pau-share__what').textContent).toContain('Kuwy Technology Service Pvt Ltd');
        expect(host.querySelector('.pau-share__hint').textContent).toContain('+91 98352 30000');
    });

    /*
     * The one that would do damage. This card is the Mark received form, and a
     * button left to its default submits it.
     */
    it('never submits the Mark received form on the way to WhatsApp', () => {
        const host = mount(FILE_57);

        for (const button of host.querySelectorAll('.pau-share button')) {
            expect(button.getAttribute('type')).toBe('button');
        }
    });

    it('opens WhatsApp on the customer with the list filled in, and sends nothing itself', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const host = mount(FILE_57);

        shareButton(host).click();
        await nextTick();

        expect(open).toHaveBeenCalledTimes(1);

        const [url, target] = open.mock.calls[0];

        expect(url.startsWith('https://wa.me/919835230000?text=')).toBe(true);
        expect(decodeURIComponent(url.split('text=')[1])).toContain('1. Insurance certificate');
        expect(target).toBe('_blank');
    });

    it('sends only what the search is showing', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const host = mount(FILE_57, 'Form 34');

        shareButton(host).click();
        await nextTick();

        const sent = decodeURIComponent(open.mock.calls[0][0].split('text=')[1]);

        expect(sent).toContain('Form 34');
        expect(sent).not.toContain('Insurance certificate');
    });

    /*
     * Sending the list and receiving papers are separate things. Ticking a paper
     * received does not change what is sent, and sending ticks nothing.
     */
    it('does not care what is ticked as received', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const host = mount(FILE_57);

        host.querySelector('.pau-check').click();
        await nextTick();

        const ticked = [...host.querySelectorAll('input[type="checkbox"]:checked')].length;

        shareButton(host).click();
        await nextTick();

        expect(decodeURIComponent(open.mock.calls[0][0].split('text=')[1])).toContain('6. Email Confirmation');
        expect([...host.querySelectorAll('input[type="checkbox"]:checked')].length).toBe(ticked);
    });

    it('asks whom to send it to when the list spans several customers', () => {
        const host = mount([
            row(1, 'RC', { customer: 'Car4Sales', customer_id: 1 }),
            row(2, 'Form 30', { customer: 'Rishu Ji', customer_id: 2, file_no: 'F-00002' }),
        ]);

        expect(host.querySelector('.pau-share__hint').textContent).toContain('2 customers');
    });

    it('says so when the customer has no number WhatsApp can use', () => {
        const host = mount([row(1, 'RC', { customer_mobile: '0612223344' })]);

        expect(host.querySelector('.pau-share__hint').textContent).toContain('no mobile number');
    });

    it('offers nothing to send when nothing is pending', () => {
        expect(mount([]).querySelector('.pau-share')).toBeNull();
    });
});
