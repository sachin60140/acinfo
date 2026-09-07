import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import ClientPassword from './components/ClientPassword.vue';

/*
 * Show / Hide on a password form.
 *
 * The one control on this screen whose whole purpose is to be believed: it is
 * there so the two boxes can be read against each other before a password is
 * saved that nobody can see and nobody will be able to use. A toggle that
 * changes its own label and nothing else is worse than no toggle at all —
 * it says the password is being shown while it is not.
 */

const mounted = [];

function mount(overrides = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(ClientPassword, {
        action: '/admin/password',
        csrf: 'test-token',
        cancelUrl: '/admin/dashboard',
        clientName: 'Test Person',
        clientMobile: '9000000000',
        hasPassword: true,
        errors: {},
        ...overrides,
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const toggle = (host) => [...host.querySelectorAll('button')]
    .find((b) => /show|hide/i.test(b.textContent));

const boxes = (host) => [
    host.querySelector('#password'),
    host.querySelector('#password_confirmation'),
];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

describe('showing a password', () => {
    it('starts hidden', () => {
        const host = mount();

        for (const box of boxes(host)) {
            expect(box.type).toBe('password');
        }
    });

    it('shows both boxes when Show is pressed', async () => {
        const host = mount();

        toggle(host).click();
        await nextTick();

        for (const box of boxes(host)) {
            expect(box.type, 'the box actually becomes readable').toBe('text');
        }
    });

    it('hides them again', async () => {
        const host = mount();

        toggle(host).click();
        await nextTick();
        toggle(host).click();
        await nextTick();

        for (const box of boxes(host)) {
            expect(box.type).toBe('password');
        }
    });

    it('says which of the two it is about to do', async () => {
        const host = mount();

        expect(toggle(host).textContent).toMatch(/show/i);

        toggle(host).click();
        await nextTick();

        expect(toggle(host).textContent).toMatch(/hide/i);
    });

    /*
     * The value has to survive the switch. An input whose type changes is the
     * same element, but a v-model bound the wrong way round for a dynamic type
     * would drop what was typed at the moment it became readable.
     */
    it('keeps what was typed when it is revealed', async () => {
        const host = mount();

        const [box] = boxes(host);

        box.value = 'a-typed-password';
        box.dispatchEvent(new window.Event('input'));
        await nextTick();

        toggle(host).click();
        await nextTick();

        expect(host.querySelector('#password').value).toBe('a-typed-password');
    });

    /*
     * The current-password box on the change-password screen. It is a third
     * box, added later, and the toggle above it does not mention it — so this
     * pins what it is meant to do rather than leaving it to be noticed.
     */
    it('leaves the current password alone', async () => {
        const host = mount({ requireCurrent: true });

        const current = host.querySelector('#current_password');

        expect(current).not.toBe(null);

        toggle(host).click();
        await nextTick();

        expect(
            host.querySelector('#current_password').type,
            'the box being checked against is not the one being read'
        ).toBe('password');
    });
});
