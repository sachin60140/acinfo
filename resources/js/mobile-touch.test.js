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
 * measured here (jsdom lays nothing out), so it is the rules that are held —
 * and only inside the block they belong to: a touch rule moved out of its
 * @media applies to every desktop too.
 */

// Comments out, whitespace to single spaces: a selector list reads as one line.
const flat = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '').replace(/\s+/g, ' ');
const read = (path) => flat(readFileSync(path, 'utf8'));

/**
 * The bodies of every block opened by `header` ("@media (pointer: coarse) {"),
 * each to its own closing brace and no further, joined. Found in review: split
 * on "@media" instead, a rule moved out of the block still counted as in it.
 */
function blocksOf(css, header) {
    const bodies = [];
    let at = css.indexOf(header);

    while (at !== -1) {
        let depth = 1;
        let i = at + header.length;

        for (; i < css.length && depth > 0; i++) {
            if (css[i] === '{') depth++;
            if (css[i] === '}') depth--;
        }

        bodies.push(css.slice(at + header.length, i - 1));
        at = css.indexOf(header, i);
    }

    return bodies.join(' ');
}

/*
 * The bodies of every rule whose selector list includes all of `selectors`,
 * joined — one selector's declarations are often split over two rules — or
 * null when there is none.
 */
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

    const bodies = [];

    while ((m = re.exec(css))) {
        const list = split(m[1]);

        if (selectors.every((s) => list.includes(s))) {
            bodies.push(m[2]);
        }
    }

    return bodies.length ? bodies.join(' ') : null;
}

const COARSE = '@media (pointer: coarse) {';
const CARDS = '@media (max-width: 991.98px) {';

const appCss = read('resources/css/app.css');
const coarse = blocksOf(appCss, COARSE);
const gridSource = read('resources/js/components/DataGrid.vue');
const cards = blocksOf(gridSource, CARDS);
const touchOf = (component) => blocksOf(read(`resources/js/components/${component}.vue`), COARSE);

describe('on a touch screen', () => {
    it('makes every button and field at least the tap size, Bootstrap\'s too', () => {
        const rule = ruleFor(coarse, ['.btn', '.form-control', '.form-select', '.ui-btn', '.ui-input', '.ui-select']);

        expect(rule).toContain('min-height: var(--tap)');
        expect(appCss).toContain('--tap: 44px');
    });

    it('keeps a button\'s icon apart from its words as a flex box', () => {
        const rule = ruleFor(coarse, ['.btn']);

        expect(rule).toContain('display: inline-flex');
        expect(rule).toContain('gap: 0.35em');
    });

    it('writes every field at 16px — by element, so a one-class screen rule cannot undo it', () => {
        const rule = ruleFor(coarse, ['input.ui-input', 'select.ui-select', 'textarea.ui-textarea', 'input.form-control', 'select.form-select']);

        expect(rule).toContain('font-size: 16px');
    });

    it('keeps the 16px on the date boxes the forms style for themselves', () => {
        expect(ruleFor(touchOf('FileForm'), ['.wf-form .input-group > .js-datefield'])).toContain('font-size: 16px');
        expect(ruleFor(touchOf('PartyForm'), ['.party-form .input-group > .js-datefield'])).toContain('font-size: 16px');
    });

    it('makes a tick 24px', () => {
        const rule = ruleFor(coarse, ["input[type='checkbox']:not(.form-check-input)", "input[type='radio']:not(.form-check-input)"]);

        expect(rule).toContain('height: 1.5rem');
        expect(rule).toContain('width: 1.5rem');
    });

    it('grows the header\'s controls and the menu\'s sections to the tap size, both ways', () => {
        const header = ruleFor(coarse, ['.header .logo', '.header-nav .nav-link']);

        expect(header).toContain('min-width: var(--tap)');
        expect(header).toContain('min-height: var(--tap)');
        expect(ruleFor(coarse, ['.header .toggle-sidebar-btn'])).toContain('min-width: var(--tap)');
        expect(ruleFor(coarse, ['.sidebar-nav .nav-heading--toggle'])).toContain('min-height: var(--tap)');
    });

    /*
     * Found in review: grown by padding and a negative margin, a link's target
     * reached up into the link above it in a card, and a tap on the bottom of
     * a customer's name rang their mobile. It is the tap size itself now, and
     * grows sideways only.
     */
    it('makes a link in a table cell or a breadcrumb the tap size without reaching into the line above', () => {
        const rule = ruleFor(coarse, ['.breadcrumb a', '.ui-table td .ui-link']);

        expect(rule).toContain('min-height: var(--tap)');
        expect(rule).toContain('display: inline-flex');
        expect(rule).toContain('margin-inline: -11px');
        expect(rule).not.toMatch(/margin: -|margin-block|margin-top: -|margin-bottom: -/);

        // As wide as it is tall — a count's "2" was 30px — growing to the left
        // wherever figures sit on the right, so the figure does not move.
        expect(rule).toContain('min-width: var(--tap)');
        expect(ruleFor(coarse, ['.ui-table td:is(.num, .text-end) .ui-link'])).toContain('justify-content: flex-end');
        expect(ruleFor(cards, ['.grid__table tbody td .ui-link'])).toContain('justify-content: flex-end');

        // And the page's own name level with the taller link beside it.
        expect(ruleFor(coarse, ['.breadcrumb'])).toContain('align-items: center');

        const board = ruleFor(touchOf('StatusBoard'), ['.board__no']);
        expect(board).toContain('min-height: var(--tap)');
        expect(board).not.toMatch(/margin: -/);
    });

    it('makes a tick with its words, the report filters, the tabs and the date ranges the tap size', () => {
        expect(ruleFor(coarse, ["label:has(> input[type='checkbox'])"])).toContain('min-height: var(--tap)');
        expect(ruleFor(coarse, [':is(.report-filter, .statement-filter) :is(.form-control, .form-select)'])).toContain('min-height: var(--tap)');
        expect(ruleFor(coarse, ['.type-switch a'])).toContain('min-height: var(--tap)');
        expect(ruleFor(coarse, ['.quick-ranges a'])).toContain('min-height: var(--tap)');
    });

    it('lifts back-to-top clear of the Save bars on a phone, at the tap size', () => {
        expect(ruleFor(coarse, ['.back-to-top'])).toContain('width: var(--tap)');
        expect(ruleFor(blocksOf(appCss, CARDS), ['.back-to-top'])).toContain('bottom: 5.5rem');
    });
});

describe('a tick alone in a cell', () => {
    it('sits in a 44px square on a touch screen, and only there', () => {
        const square = ruleFor(coarse, ['.tick-hit']);

        expect(square).toContain('min-height: var(--tap)');
        expect(square).toContain('min-width: var(--tap)');

        // With a mouse, a row keeps its height (found in review).
        const everywhere = ruleFor(appCss.replace(coarse, ''), ['.tick-hit']);
        expect(everywhere).toContain('display: inline-flex');
        expect(everywhere).not.toContain('min-height');
    });

    it('is wrapped in that square on every list of ticks', () => {
        const lists = [
            ['HandOver', 'hov-check', 'files'],
            ['VendorReturn', 'vr-tick', 'files'],
            ['CustomerReturn', 'cr-check', 'files'],
            ['GiveToVendor', 'give-check', 'files'],
            ['PaperAudit', 'pau-check', 'received'],
        ];

        for (const [file, tick, name] of lists) {
            const source = read(`resources/js/components/${file}.vue`);

            expect(source, file).toMatch(new RegExp(`<label class="tick-hit"> <input type="checkbox" class="${tick}" name="${name}\\[\\]"`));
        }
    });
});

describe('what opens over a page', () => {
    it('gives a dialog\'s Close the tap size', () => {
        for (const [file, close] of [['WorkUpdateDialog', '.wu__x'], ['PartyStatement', '.ps-dialog__x']]) {
            const rule = ruleFor(touchOf(file), [close]);

            expect(rule, file).toContain('min-width: var(--tap)');
            expect(rule, file).toContain('min-height: var(--tap)');
        }
    });

    it('gives the calendar room, 44px arrows and 16px month and year boxes', () => {
        const touch = blocksOf(read('public/assets/css/datepicker.css'), COARSE);

        expect(ruleFor(touch, ['.dp-head select'])).toContain('font-size: 16px');
        expect(ruleFor(touch, ['.dp-nav'])).toContain('width: 44px');
        expect(ruleFor(touch, ['.dp-action'])).toContain('min-height: 44px');
        expect(ruleFor(touch, ['.dp-popup'])).toContain('calc(100vw - 1rem)');
    });
});

describe('a list as cards on a phone', () => {
    it('puts what a cell says under its value on a line of its own, so nothing runs off the card', () => {
        expect(ruleFor(cards, ['.grid__table tbody td'])).toContain('flex-wrap: wrap');
        expect(ruleFor(cards, ['.grid__table tbody td > .ui-sub'])).toContain('flex-basis: 100%');
        expect(ruleFor(cards, ['.grid__table tbody td > *'])).toContain('min-width: 0');
    });

    it('keeps a value on the right when it wraps onto a line of its own', () => {
        expect(ruleFor(cards, ['.grid__table tbody td'])).toContain('justify-content: flex-end');
        expect(ruleFor(cards, ['.grid__table tbody td::before'])).toContain('margin-right: auto');
    });

    it('shows the totals as a card of their own, not hidden with the header', () => {
        expect(ruleFor(cards, ['.grid__table tfoot'])).toContain('display: block');
        expect(ruleFor(cards, ['.grid__table thead'])).toContain('clip: rect(0 0 0 0)');
        expect(ruleFor(cards, ['.grid__table thead', '.grid__table tfoot'])).toBe(null);
    });
});

describe('a screen that did not fit a small phone', () => {
    it('never lays a dashboard chart wider than the screen', () => {
        expect(ruleFor(read('resources/js/components/Dashboard.vue'), ['.dash__charts']))
            .toContain('minmax(min(22rem, 100%), 1fr)');
    });

    it('lets a paper\'s three answers wrap on the narrowest phones rather than cut one off', () => {
        const narrow = blocksOf(read('resources/js/components/PaperChecklist.vue'), '@media (max-width: 359.98px) {');

        expect(ruleFor(narrow, ['.pck-choice__opt'])).toContain('white-space: normal');
        expect(ruleFor(touchOf('PaperChecklist'), ['.pck-addnote'])).toContain('min-height: var(--tap)');
    });

    it('turns Paper Audit\'s stuck files into cards like the lists above them', () => {
        expect(read('resources/js/components/PaperAudit.vue')).toMatch(/<div v-if="stuck.length" class="ui-card pau-stuck">[\s\S]*<table class="ui-table pau-table">/);
    });
});
