import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import Dashboard from './components/Dashboard.vue';

/*
 * A tile that says something in words — when the ledger was last backed up —
 * and colours the whole tile by how it is going, not by a side of the ledger.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

async function mount(tiles) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(Dashboard, { tiles });
    app.mount(host);
    mounted.push({ app, host });
    await nextTick();

    return host;
}

const backup = (value, tone) => ({ group: 'Safety', label: 'Last Backup', value, type: 'text', tone, note: '22-09-2026 01:30 · 1.4 MB' });

describe('the Last Backup tile', () => {
    it('says its words, not 0.00', async () => {
        const host = await mount([backup('Yesterday', 'ok')]);

        expect(host.querySelector('.ui-stat__value').textContent.trim()).toBe('Yesterday');
        expect(host.textContent).not.toContain('0.00');
    });

    it('turns the whole tile red when it is late, and amber when it is slipping', async () => {
        const host = await mount([backup('Never', 'bad'), { ...backup('2 days ago', 'warn'), label: 'Other' }]);
        const [bad, warn] = host.querySelectorAll('.ui-stat');

        expect(bad.classList.contains('ui-stat--bad')).toBe(true);
        expect(warn.classList.contains('ui-stat--warn')).toBe(true);
    });

    it('stays plain when it is fine', async () => {
        const host = await mount([backup('Today', 'ok')]);
        const tile = host.querySelector('.ui-stat');

        expect(tile.classList.contains('ui-stat--bad')).toBe(false);
        expect(tile.classList.contains('ui-stat--warn')).toBe(false);
    });

    it('never takes a colour that means a side of the ledger', async () => {
        const host = await mount([backup('Today', 'dr')]);
        const value = host.querySelector('.ui-stat__value');

        expect(value.classList.contains('ui-money--dr')).toBe(false);
        expect(value.classList.contains('ui-money--nil')).toBe(true);
    });

    it('links nowhere', async () => {
        const host = await mount([backup('Today', 'ok')]);

        expect(host.querySelector('a.ui-stat')).toBe(null);
        expect(host.querySelector('div.ui-stat')).not.toBe(null);
    });
});

describe('the money tiles', () => {
    it('are drawn as before', async () => {
        const host = await mount([{ group: 'Parties', label: 'Receivable', value: 1250, type: 'money', tone: 'dr', note: '', href: '/x' }]);

        expect(host.querySelector('.ui-stat__value').textContent.trim()).toBe('1,250.00');
        expect(host.querySelector('.ui-stat__value').classList.contains('ui-money--dr')).toBe(true);
        expect(host.querySelector('.ui-stat').className).not.toContain('ui-stat--null');
    });
});

/*
 * Found in review: the colour was put on the value by a class of its own, and
 * the tile's own rule for its value, one line later in the sheet and no less
 * specific, drew "Never" in near-black on the red tile. A class being there is
 * not the words being red; the rule that colours them has to outrank that one.
 */
describe('the stylesheet', () => {
    const css = readFileSync('resources/css/app.css', 'utf8');
    const at = (rule) => css.indexOf(rule);

    it('colours the words of a late tile, after the rule that would win otherwise', () => {
        const plain = at('.ui-stat__value {');

        for (const [state, colour] of [['warn', '--warn-600'], ['bad', '--cr-700']]) {
            const rule = `.ui-stat--${state} .ui-stat__value { color: var(${colour}); }`;

            expect(at(rule), rule).toBeGreaterThan(plain);
        }
    });
});
