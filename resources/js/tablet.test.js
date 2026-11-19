import { describe, expect, it } from 'vitest';
import { blocksOf, read, ruleFor } from './css-rules.test-support.js';

/*
 * What the tablet audit of 2026-11-19 fixed, held in place.
 *
 * Every screen was measured at 360, 390, 768, 820, 1024 and 1180px wide. None
 * scrolled sideways, but between 992 and about 1,400px — a tablet held
 * sideways, a laptop with the menu open — the file form's works squeezed the
 * work's own select to its arrow, and Give to Vendor, Return from Vendor and
 * Return to Customer put the amount box past the right edge of the screen.
 * Between 768 and 991, a tablet held upright, every screen was the phone's
 * layout stretched: buttons and filters a row each, one card to a row.
 *
 * jsdom lays nothing out, so it is the rules that are held, each inside the
 * block it belongs to.
 */

const COARSE = '@media (pointer: coarse) {';
const PHONE = '@media (max-width: 575.98px) {';
const BELOW_LARGE = '@media (max-width: 991.98px) {';
const LARGE = '@media (min-width: 992px) {';

const appCss = read('resources/css/app.css');
const coarse = blocksOf(appCss, COARSE);
const component = (name) => read(`resources/js/components/${name}.vue`);
const responsive = read('public/assets/css/responsive.css');

describe('the works on a file', () => {
    const form = component('FileForm');
    const box = blocksOf(form, '@container wf-works (max-width: 51.99rem) {');
    const phone = blocksOf(form, '@container wf-works (max-width: 29.99rem) {');

    it('asks the box it sits in, not the screen, whether there is room for the table', () => {
        expect(ruleFor(form, ['.wf-works'])).toContain('container: wf-works / inline-size');
        expect(box).not.toBe('');
    });

    it('is a card of three lines when there is not: the work, its prices, where it stands', () => {
        const card = ruleFor(box, ['.wf-works__table tbody tr']);

        expect(card).toContain('display: grid');
        expect(card).toContain('grid-template-columns: repeat(3, minmax(0, 1fr)) auto');
        expect(ruleFor(box, ['.wf-works__table tbody td:first-child'])).toContain('grid-column: 1 / 4');
        expect(ruleFor(box, ['.wf-works__table tbody td.wf-works__off'])).toContain('grid-row: 1');
        expect(ruleFor(box, ['.wf-works__table tbody td[data-label="Status"]'])).toContain('grid-column: 1');
        expect(ruleFor(box, ['.wf-works__table thead'])).toContain('clip: rect(0 0 0 0)');
    });

    it('is a field to a line on a phone, the remove button still beside the work', () => {
        expect(ruleFor(phone, ['.wf-works__table tbody tr'])).toContain('grid-template-columns: minmax(0, 1fr) auto');
        expect(ruleFor(phone, ['.wf-works__table tbody td.wf-works__off'])).toContain('grid-column: 2');
    });

    it('still turns to cards on a phone whose browser cannot ask the box', () => {
        const fallback = blocksOf(blocksOf(form, '@supports not (container-type: inline-size) {'), '@media (max-width: 767.98px) {');

        expect(ruleFor(fallback, ['.wf-works__table', '.wf-works__table td'])).toContain('display: block');
    });

    it('keeps the fields side by side on a tablet held upright, and stacks them only on a phone', () => {
        expect(ruleFor(blocksOf(form, BELOW_LARGE), ['.wf-grid'])).toBe(null);
        expect(ruleFor(blocksOf(form, BELOW_LARGE), ['.wf'])).toContain('grid-template-columns: minmax(0, 1fr)');
        expect(ruleFor(blocksOf(form, '@media (max-width: 767.98px) {'), ['.wf-grid'])).toContain('grid-template-columns: minmax(0, 1fr)');
    });
});

describe('a table of controls wider than its box', () => {
    const large = blocksOf(appCss, LARGE);

    it('pins the column a row is for to the right edge, from where it is a table', () => {
        const pinned = ruleFor(large, ['.ui-table .ui-pin-end']);

        expect(pinned).toContain('position: sticky');
        expect(pinned).toContain('right: 0');

        // Something to hide the cells scrolling underneath, of no weight, so a
        // ticked or hovered row keeps its own colour.
        expect(ruleFor(large, [':where(.ui-table td.ui-pin-end)'])).toContain('background:');
    });

    it('pins the amount on Give to Vendor, Return from Vendor and Return to Customer', () => {
        const give = component('GiveToVendor');
        const back = component('VendorReturn');
        const refund = component('CustomerReturn');

        expect(give).toMatch(/<th class="num ui-pin-end"[^>]*>Vendor Amount<\/th>/);
        expect(give).toContain('data-label="Vendor Amount" class="num ui-pin-end"');
        expect(back).toMatch(/<th class="ui-pin-end"[^>]*>Reverse<\/th>/);
        expect(back).toContain('data-label="Reverse" class="ui-pin-end"');
        expect(refund).toMatch(/<th class="num ui-pin-end"[^>]*>Refund<\/th>/);
        expect(refund).toContain('data-label="Refund" class="ui-pin-end"');
    });

    it('keeps room for the amount beside its last rate, and the note before it short', () => {
        const give = component('GiveToVendor');

        expect(ruleFor(give, ['.give-job .ui-input--amount'])).toContain('min-width: 6.5rem');
        expect(ruleFor(give, ['.give-work__with'])).toContain('max-width: 12rem');

        // Cut short with an ellipsis, so the whole of it is in the tooltip.
        expect(give).toMatch(/class="give-work__with"\s+:title="`with \$\{item\.vendor/);
    });
});

describe('a tablet held upright', () => {
    const grid = component('DataGrid');
    const upright = blocksOf(grid, '@media (min-width: 768px) and (max-width: 991.98px) {');

    it('sets a list two cards to a row, a group\'s heading and totals across both', () => {
        const body = ruleFor(upright, ['.grid__table tbody']);

        expect(body).toContain('display: grid');
        expect(body).toContain('grid-template-columns: repeat(2, minmax(0, 1fr))');
        expect(ruleFor(upright, ['.grid__table tbody tr:is(.grid__band, .grid__subtotal, .grid__edge)'])).toContain('grid-column: 1 / -1');
    });

    it('sets a card\'s value on the line of its name, a grown link included', () => {
        expect(ruleFor(blocksOf(grid, BELOW_LARGE), ['.grid__table tbody td'])).toContain('align-items: baseline');
    });

    it('lets a card\'s buttons share a line, and stacks them only on a phone', () => {
        // Bootstrap's d-flex is !important, so a grid here never drew.
        expect(ruleFor(blocksOf(responsive, BELOW_LARGE), ['.card-actions'])).not.toContain('display: grid');
        expect(ruleFor(blocksOf(responsive, BELOW_LARGE), ['.card-actions .btn'])).toContain('flex: 1 1 auto');
        expect(ruleFor(blocksOf(responsive, PHONE), ['.card-actions .btn'])).toContain('width: 100%');
    });

    it('keeps a statement\'s dates and Apply on one line, and stacks them only on a phone', () => {
        expect(ruleFor(blocksOf(responsive, BELOW_LARGE), ['.statement-filter .col-sm-auto'])).toBe(null);
        expect(ruleFor(blocksOf(responsive, BELOW_LARGE), ['.statement-filter .btn'])).toBe(null);
        expect(ruleFor(blocksOf(responsive, PHONE), ['.statement-filter .btn'])).toContain('width: 100%');
    });
});

describe('the menu over the page', () => {
    it('dims the page under it below 1200px, under the menu and the header', () => {
        const over = blocksOf(read('public/assets/css/nav.css'), '@media (max-width: 1199.98px) {');
        const dim = ruleFor(over, ['body.toggle-sidebar::before']);

        expect(dim).toContain('position: fixed');
        expect(dim).toContain('z-index: 995');
    });
});

describe('on a touch screen', () => {
    it('sets the slash in a breadcrumb level with a grown link, not at the top of its line', () => {
        expect(ruleFor(coarse, ['.breadcrumb-item'])).toContain('align-items: center');
        expect(ruleFor(coarse, ['.breadcrumb-item + .breadcrumb-item::before'])).toContain('float: none');
    });

    it('makes a statement\'s mobile and WhatsApp the tap size, growing sideways', () => {
        const rule = ruleFor(coarse, ['.statement-period :is(a[href^="tel:"], .wa-link)']);

        expect(rule).toContain('min-height: var(--tap)');
        expect(rule).toContain('margin-inline: -11px');
    });

    it('makes a date range as wide as it is tall, All included', () => {
        expect(ruleFor(coarse, ['.quick-ranges a'])).toContain('min-width: var(--tap)');
    });

    it('makes Bootstrap\'s tick and its words a row the tap size', () => {
        expect(ruleFor(coarse, ['.form-check'])).toContain('min-height: var(--tap)');
        expect(ruleFor(coarse, ['.form-check .form-check-label'])).toContain('align-self: stretch');
    });

    it('lifts back-to-top over a Save bar at any width, not only on a phone', () => {
        const outside = appCss.replace(coarse, '');

        expect(ruleFor(outside, ['body:has(.ui-card__foot, .wf-save) .back-to-top'])).toContain('bottom: 5.5rem');
    });
});
