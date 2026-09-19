import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createApp, h, nextTick, ref } from 'vue';
import CardSection from './components/CardSection.vue';

/*
 * A card that folds away, on a form.
 *
 * Two rules make folding a form safe, and both are here because breaking either
 * is silent. The body is hidden rather than removed, so the inputs inside a
 * folded section still post what was typed into them. And a section holding an
 * error opens itself, so a form can never fold away the reason it refused.
 */

const mounted = [];

function mount(props = {}, slot = null) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp({
        render: () => h(
            CardSection,
            { title: 'Documents', ...props },
            { default: () => slot ?? h('input', { name: 'document_names[4]', value: 'RC scan' }) }
        ),
    });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const body = (host) => host.querySelector('.ui-card__body');
const toggle = (host) => host.querySelector('.sect__toggle');
const summary = (host) => host.querySelector('.sect__summary');
const shut = (host) => body(host).style.display === 'none';

beforeEach(() => {
    localStorage.clear();
});

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    localStorage.clear();
});

describe('folding a section away', () => {
    it('opens by default', () => {
        expect(shut(mount())).toBe(false);
    });

    it('starts folded when the screen says so', () => {
        expect(shut(mount({ open: false }))).toBe(true);
    });

    it('folds and unfolds from the heading', async () => {
        const host = mount();

        await click(toggle(host));
        expect(shut(host)).toBe(true);

        await click(toggle(host));
        expect(shut(host)).toBe(false);
    });

    /*
     * The rule the whole thing rests on. Removed from the DOM rather than
     * hidden, a folded section would take its values with it, and a price typed
     * before it was folded would stop being saved with nothing to show for it.
     */
    it('keeps what is inside it posting while it is folded', async () => {
        const host = mount();

        await click(toggle(host));

        const input = host.querySelector('input[name="document_names[4]"]');

        expect(input).not.toBeNull();
        expect(input.value).toBe('RC scan');
        expect(input.disabled).toBe(false);
    });

    it('says what it is holding only while it is shut', async () => {
        const host = mount({ summary: '3 PDFs' });

        expect(summary(host)).toBeNull();

        await click(toggle(host));

        expect(summary(host).textContent).toBe('3 PDFs');
    });

    it('says nothing extra when the screen gave it no summary', async () => {
        const host = mount();

        await click(toggle(host));

        expect(summary(host)).toBeNull();
    });
});

describe('an error holds a section open', () => {
    it('opens a section that was folded', () => {
        expect(shut(mount({ open: false, forceOpen: true }))).toBe(false);
    });

    it('will not let it be folded away while the error stands', async () => {
        const host = mount({ forceOpen: true });

        expect(toggle(host).disabled).toBe(true);

        await click(toggle(host));

        expect(shut(host)).toBe(false);
    });

    it('opens when the error arrives, and stays open once it is answered', async () => {
        const host = document.createElement('div');
        document.body.appendChild(host);

        const bad = ref(false);
        const app = createApp({
            render: () => h(
                CardSection,
                { title: 'Expenses', open: false, forceOpen: bad.value },
                { default: () => h('input', { name: 'expenses[1][amount]' }) }
            ),
        });

        app.mount(host);
        mounted.push({ app, host });

        expect(shut(host)).toBe(true);

        bad.value = true;
        await nextTick();
        expect(shut(host)).toBe(false);

        // Answered. It does not fold itself back up under the reader's hands.
        bad.value = false;
        await nextTick();
        expect(shut(host)).toBe(false);
    });
});

describe('what the reader folded is remembered', () => {
    it('comes back folded next time', async () => {
        const first = mount({ remember: 'file.documents' });

        await click(toggle(first));

        expect(shut(mount({ remember: 'file.documents' }))).toBe(true);
    });

    it('remembers per section, not for every section at once', async () => {
        const docs = mount({ remember: 'file.documents' });

        await click(toggle(docs));

        expect(shut(mount({ remember: 'file.expenses' }))).toBe(false);
    });

    it('forgets at the end of the visit when given nowhere to remember', async () => {
        const first = mount();

        await click(toggle(first));

        expect(shut(mount())).toBe(false);
    });

    it('opens as usual when the memory is nonsense', () => {
        localStorage.setItem('acinfo.section.file.documents', 'neither');

        expect(shut(mount({ remember: 'file.documents' }))).toBe(false);
    });
});

async function click(el) {
    el.click();
    await nextTick();
}
