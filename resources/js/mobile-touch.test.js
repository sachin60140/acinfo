import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/*
 * What the mobile audit of 2026-09-28 fixed, held in place.
 *
 * Every screen was measured at 375px and 320px wide. None scrolled sideways,
 * but one list could be swiped sideways inside itself, fields were small
 * enough that an iPhone zooms the page in when one is tapped, and buttons,
 * links, ticks and the header's own controls were 17 to 40px — under the 44px
 * a thumb needs. These are the rules that fixed it. A rendered page cannot be
 * measured here (jsdom lays nothing out), so it is the rules that are held.
 */

// Comments out, whitespace to single spaces: a selector list reads as one line.
const flat = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '').replace(/\s+/g, ' ');
const appCss = flat(readFileSync('resources/css/app.css', 'utf8'));
const grid = flat(readFileSync('resources/js/components/DataGrid.vue', 'utf8'));

// The body of the first rule in `css` whose selector list includes all of `selectors`.
function ruleFor(css, selectors) {
    const re = /([^{}]+)\{([^{}]*)\}/g;
    let m;

    // Split on the commas between selectors, not those inside :is( ... ).
    const split = (text) => {
        const out = [];
        let depth = 0;
        let part = '';

        for (const ch of text) {
            if (ch === '(') depth++;
            if (ch === ')') depth--;

            if (ch === ',' && depth === 0) {
                out.push(part.trim());
                part = '';
            } else {
                part += ch;
            }
        }

        return [...out, part.trim()];
    };

    while ((m = re.exec(css))) {
        const list = split(m[1]);

        if (selectors.every((s) => list.includes(s))) {
            return m[2];
        }
    }

    return null;
}

// Everything inside the (pointer: coarse) blocks of app.css.
const coarse = appCss.split('@media (pointer: coarse) {').slice(1).map((part) => part.split('@media')[0]).join(' ');

describe('on a touch screen', () => {
    it('makes every button and field at least the tap size, Bootstrap\'s too', () => {
        const rule = ruleFor(coarse, ['.btn', '.form-control', '.form-select', '.ui-btn', '.ui-input', '.ui-select']);

        expect(rule).toContain('min-height: var(--tap)');
        expect(appCss).toContain('--tap: 44px');
    });

    it('writes every field at 16px, so an iPhone does not zoom the page on the tap', () => {
        const rule = ruleFor(coarse, ['.ui-input', '.ui-select', '.ui-textarea', '.form-control', '.form-select']);

        expect(rule).toContain('font-size: 16px');
    });

    it('grows the header\'s controls and the menu\'s sections to the tap size', () => {
        expect(ruleFor(coarse, ['.header .logo', '.header-nav .nav-link'])).toContain('min-width: var(--tap)');
        expect(ruleFor(coarse, ['.header .toggle-sidebar-btn'])).toContain('min-width: var(--tap)');
        expect(ruleFor(coarse, ['.sidebar-nav .nav-heading--toggle'])).toContain('min-height: var(--tap)');
    });

    it('grows a link in a table cell or a breadcrumb without growing its line', () => {
        const rule = ruleFor(coarse, ['.breadcrumb a', '.ui-table td .ui-link']);

        expect(rule).toContain('padding: 14px 11px');
        expect(rule).toContain('margin: -14px -11px');
    });

    it('makes a tick with its words, and the report filters, the tap size', () => {
        expect(ruleFor(coarse, ["label:has(> input[type='checkbox'])"])).toContain('min-height: var(--tap)');
        expect(ruleFor(coarse, [':is(.report-filter, .statement-filter) :is(.form-control, .form-select)'])).toContain('min-height: var(--tap)');
        expect(ruleFor(coarse, ['.type-switch a'])).toContain('min-height: var(--tap)');
    });
});

describe('a tick alone in a cell', () => {
    it('is wrapped in a square the size of a thumb', () => {
        expect(ruleFor(appCss, ['.tick-hit'])).toContain('min-height: var(--tap)');

        for (const [file, tick] of [['HandOver', 'hov-check'], ['VendorReturn', 'vr-tick'], ['CustomerReturn', 'cr-check']]) {
            const source = flat(readFileSync(`resources/js/components/${file}.vue`, 'utf8'));

            expect(source, file).toMatch(new RegExp(`<label class="tick-hit"> <input type="checkbox" class="${tick}" name="files\\[\\]"`));
        }
    });
});

describe('what opens over a page', () => {
    it('gives a dialog\'s Close the tap size', () => {
        for (const [file, close] of [['WorkUpdateDialog', '.wu__x'], ['PartyStatement', '.ps-dialog__x']]) {
            const source = flat(readFileSync(`resources/js/components/${file}.vue`, 'utf8'));
            const touch = source.split('@media (pointer: coarse) {').slice(1).join(' ');

            expect(ruleFor(touch, [close]), file).toContain('min-width: var(--tap)');
        }
    });

    it('gives the calendar room, 44px arrows and 16px month and year boxes', () => {
        const dp = flat(readFileSync('public/assets/css/datepicker.css', 'utf8'));
        const touch = dp.split('@media (pointer: coarse) {')[1] ?? '';

        expect(ruleFor(touch, ['.dp-head select'])).toContain('font-size: 16px');
        expect(ruleFor(touch, ['.dp-nav'])).toContain('width: 44px');
        expect(ruleFor(touch, ['.dp-action'])).toContain('min-height: 44px');
        expect(ruleFor(touch, ['.dp-popup'])).toContain('calc(100vw - 1rem)');
    });
});

describe('a list as cards on a phone', () => {
    it('puts what a cell says under its value on a line of its own, so nothing runs off the card', () => {
        expect(ruleFor(grid, ['.grid__table tbody td'])).toContain('flex-wrap: wrap');
        expect(ruleFor(grid, ['.grid__table tbody td > .ui-sub'])).toContain('flex-basis: 100%');
        expect(ruleFor(grid, ['.grid__table tbody td > *'])).toContain('min-width: 0');
    });
});
