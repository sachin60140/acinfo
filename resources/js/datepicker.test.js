import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';

/*
 * The date field.
 *
 * Written by hand so every ledger reads dd-mm-yyyy whoever opens it, which is
 * the one thing a native date input cannot promise. That makes it ours to keep
 * working, and it had nothing covering it at all.
 */

const SOURCE = readFileSync('public/assets/js/datepicker.js', 'utf8');

// The script is a plain IIFE, not a module: it is run against this document the
// way a <script> tag would run it against a page.
function loadDatepicker() {
    // eslint-disable-next-line no-new-func
    new Function(SOURCE).call(window);
}

function field({ value = '', required = false, max = '', min = '' } = {}) {
    document.body.innerHTML = `
        <form id="f">
            <input type="text" class="js-datefield" id="d_display" data-target="d"
                   value="${value}" ${required ? 'required' : ''}
                   ${max ? `data-max="${max}"` : ''} ${min ? `data-min="${min}"` : ''}>
            <input type="hidden" id="d" name="d" value="">
        </form>
    `;

    return {
        visible: document.getElementById('d_display'),
        hidden: document.getElementById('d'),
    };
}

// What binds a field: the page finishing, or a screen announcing new markup.
const ready = () => document.dispatchEvent(new window.Event('DOMContentLoaded'));
const rebind = () => document.dispatchEvent(new window.Event('acinfo:content'));

const popup = () => document.querySelector('.dp-popup');

// Loaded once, as a page loads it once. Loading it per test left the previous
// instance holding a reference to a popup that had been thrown away, and its
// own open() then refused to open a second one for the same field.
beforeAll(loadDatepicker);

beforeEach(() => {
    // Any picker left open belongs to markup that is about to go.
    document.body.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    document.body.innerHTML = '';
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the date field', () => {
    it('opens a calendar when the box is clicked', () => {
        const { visible } = field();

        ready();

        expect(popup()).toBe(null);

        visible.dispatchEvent(new window.Event('focus'));

        expect(popup()).not.toBe(null);
    });

    it('writes the day it was given to the server in Y-m-d', () => {
        const { visible, hidden } = field();

        ready();

        visible.dispatchEvent(new window.Event('focus'));

        const day = [...popup().querySelectorAll('.dp-day')].find((b) => b.dataset.iso.endsWith('-15'));

        day.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

        expect(visible.value).toMatch(/^15-\d{2}-\d{4}$/);
        expect(hidden.value).toMatch(/^\d{4}-\d{2}-15$/);
        expect(popup()).toBe(null);
    });

    it('reads a typed date, dashes and all', () => {
        const { visible, hidden } = field();

        ready();

        visible.value = '16122025';
        visible.dispatchEvent(new window.Event('input'));

        expect(visible.value).toBe('16-12-2025');

        visible.dispatchEvent(new window.Event('blur'));

        expect(hidden.value).toBe('2025-12-16');
    });

    /*
     * 31-02 is not a day. The Date constructor rolls it over to 3 March rather
     * than failing, so a ledger would quietly record a date nobody typed.
     */
    it('refuses a day that does not exist', () => {
        const { visible, hidden } = field();

        ready();

        visible.value = '31-02-2026';
        visible.dispatchEvent(new window.Event('blur'));

        expect(hidden.value).toBe('');
        expect(visible.validationMessage).not.toBe('');
    });

    it('starts from the value it was given', () => {
        const { visible, hidden } = field({ value: '16-12-2025' });

        ready();

        // Bound on load, so the server already has it without anyone touching
        // the box.
        expect(hidden.value).toBe('2025-12-16');
    });

    /*
     * The one that matters most. Half the date boxes in this application are
     * drawn by Vue, which mounts after the page is parsed — so a calendar that
     * only binds on DOMContentLoaded is a calendar half the screens do not have.
     */
    it('binds a field that appears after the page has loaded', () => {
        document.body.innerHTML = '';

        ready();

        const { visible } = field();

        // What a screen swapped in without a page load announces.
        document.dispatchEvent(new window.Event('acinfo:content'));

        visible.dispatchEvent(new window.Event('focus'));

        expect(popup(), 'a field added later still gets its calendar').not.toBe(null);
    });

    it('binds a field only once, however many times it is asked to', () => {
        const { visible, hidden } = field();

        ready();

        document.dispatchEvent(new window.Event('acinfo:content'));
        document.dispatchEvent(new window.Event('acinfo:content'));

        visible.value = '16122025';
        visible.dispatchEvent(new window.Event('input'));

        // Two sets of listeners would each reformat what the other wrote.
        expect(visible.value).toBe('16-12-2025');

        visible.dispatchEvent(new window.Event('blur'));
        expect(hidden.value).toBe('2025-12-16');
    });

    it('closes when the page is clicked elsewhere', () => {
        const { visible } = field();

        ready();

        visible.dispatchEvent(new window.Event('focus'));
        expect(popup()).not.toBe(null);

        document.body.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

        expect(popup()).toBe(null);
    });

    it('will not offer a day outside the range it was given', () => {
        const { visible } = field({ max: '2026-08-20' });

        ready();

        visible.value = '25-08-2026';
        visible.dispatchEvent(new window.Event('blur'));

        expect(document.getElementById('d').value).toBe('');
        expect(visible.validationMessage).toContain('outside');
    });
});

/*
 * The calendar icon.
 *
 * Every date box drawn by the shared partial has one sitting beside it, and it
 * is the first thing anyone clicks — it is a picture of a calendar. It is a
 * sibling span, so the click lands on nothing at all and the box never learns
 * it was wanted.
 */
describe('the calendar icon beside the box', () => {
    function grouped() {
        document.body.innerHTML = `
            <form>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                    <input type="text" class="form-control js-datefield" id="d_display" data-target="d" value="">
                    <input type="hidden" id="d" name="d" value="">
                </div>
            </form>
        `;

        return {
            icon: document.querySelector('.input-group-text'),
            glyph: document.querySelector('.bi-calendar3'),
            visible: document.getElementById('d_display'),
        };
    }

    it('opens the calendar, like the box it belongs to', () => {
        const { icon } = grouped();

        rebind();

        icon.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

        expect(popup(), 'clicking the calendar shows a calendar').not.toBe(null);
    });

    it('opens it from the glyph inside, which is what the pointer is over', () => {
        const { glyph } = grouped();

        rebind();

        glyph.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

        expect(popup()).not.toBe(null);
    });

    it('still opens from the box itself', () => {
        const { visible } = grouped();

        rebind();

        visible.dispatchEvent(new window.Event('focus'));

        expect(popup()).not.toBe(null);
    });
});
