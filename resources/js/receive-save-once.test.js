import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import ReceiveFileRows from './components/ReceiveFileRows.vue';

/*
 * A double click sent the batch twice, and a card with no registration number
 * was opened as two files with the customer charged for both. One press, one
 * batch.
 *
 * The form is the page's own, drawn around the component, so the tests draw
 * one too.
 */

const mounted = [];

function mount() {
    const form = document.createElement('form');
    const host = document.createElement('div');
    form.appendChild(host);
    document.body.appendChild(form);

    const app = createApp(ReceiveFileRows, {
        workTypes: [{ id: 1, name: 'HPA', default_rate: '2500.00' }],
        historyUrl: '/admin/api/work-files/history',
        cancelUrl: '/admin/files',
        oldRows: [],
    });

    app.mount(host);
    mounted.push({ app, form });

    return form;
}

const submitButton = (form) => form.querySelector('button[type="submit"]');

const press = (form) => {
    const event = new window.Event('submit', { cancelable: true });
    form.dispatchEvent(event);

    return event;
};

afterEach(() => {
    while (mounted.length) {
        const { app, form } = mounted.pop();
        app.unmount();
        form.remove();
    }
});

describe('pressing Receive Files twice', () => {
    it('sends the batch once', () => {
        const form = mount();

        expect(press(form).defaultPrevented).toBe(false);
        expect(press(form).defaultPrevented).toBe(true);
    });

    it('greys the button out while it saves', async () => {
        const form = mount();

        expect(submitButton(form).disabled).toBe(false);

        press(form);
        await nextTick();

        expect(submitButton(form).disabled).toBe(true);
    });

    it('lets it be pressed again on a page brought back with Back', async () => {
        const form = mount();

        press(form);
        await nextTick();

        const shown = new window.Event('pageshow');
        Object.defineProperty(shown, 'persisted', { value: true });
        window.dispatchEvent(shown);
        await nextTick();

        expect(submitButton(form).disabled).toBe(false);
        expect(press(form).defaultPrevented).toBe(false);
    });

    /* A page loaded afresh is a new page, not one coming back. */
    it('stays pressed on an ordinary page show', async () => {
        const form = mount();

        press(form);
        window.dispatchEvent(new window.Event('pageshow'));
        await nextTick();

        expect(submitButton(form).disabled).toBe(true);
    });

    it('stops listening once it is gone', () => {
        const form = mount();
        const { app } = mounted.pop();

        app.unmount();

        expect(press(form).defaultPrevented).toBe(false);
        expect(press(form).defaultPrevented).toBe(false);

        form.remove();
    });
});
