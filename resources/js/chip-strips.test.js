import { beforeEach, describe, expect, it } from 'vitest';
import { blocksOf, read, ruleFor } from './css-rules.test-support.js';
import { chipStrips, markEnds, revealChosen, watchChipStrips } from './chip-strips';

/*
 * The status board's filters, as strips to swipe along on a phone.
 *
 * Wrapped, the three rows of chips took more than a phone's first screen. On
 * a phone each is one line now, and two things have to hold that a stylesheet
 * cannot: the chosen chip is in view when the board loads, and a strip's ends
 * say whether there is more beyond them. jsdom lays nothing out, so each strip
 * is given the sizes a 390px phone measured.
 */

/** A strip as a phone lays it out: 390 wide, its chips 900, each chip 90. */
function lay(strip, { width = 390, content = 900, left = 0 } = {}) {
    let scrolled = left;

    Object.defineProperty(strip, 'clientWidth', { configurable: true, get: () => width });
    Object.defineProperty(strip, 'scrollWidth', { configurable: true, get: () => content });
    Object.defineProperty(strip, 'scrollLeft', {
        configurable: true,
        get: () => scrolled,
        set: (value) => { scrolled = Math.max(0, Math.min(content - width, value)); },
    });
    strip.getBoundingClientRect = () => ({ left: 0, right: width, width });

    [...strip.querySelectorAll('.chip')].forEach((chip, i) => {
        chip.getBoundingClientRect = () => {
            const at = i * 90 - scrolled;

            return { left: at, right: at + 90, width: 90 };
        };
    });

    return strip;
}

function board(chosen) {
    document.body.innerHTML = `
        <div class="filter-row">
          <span class="filter-key">Vendor</span>
          <div class="filter-chips">
            ${Array.from({ length: 10 }, (_, i) => `<a href="#" class="chip${i === chosen ? ' active' : ''}">Vendor ${i}</a>`).join('')}
          </div>
        </div>`;

    return document.querySelector('.filter-chips');
}

describe('the chosen chip', () => {
    it('is brought into the middle of its strip when it starts out of view', () => {
        const strip = lay(board(8));

        revealChosen(strip);

        // Chip 8 is 720 to 810; centred in 390 the strip scrolls to 570, as far
        // as it goes (900 - 390 = 510).
        expect(strip.scrollLeft).toBe(510);
        const chip = strip.querySelector('.active').getBoundingClientRect();
        expect(chip.left).toBeGreaterThanOrEqual(0);
        expect(chip.right).toBeLessThanOrEqual(390);
    });

    it('leaves the strip where it is when the chip is already in view', () => {
        const strip = lay(board(2));

        revealChosen(strip);

        expect(strip.scrollLeft).toBe(0);
    });

    it('does nothing where the strip does not scroll, as on a tablet', () => {
        const strip = lay(board(8), { width: 900, content: 900 });

        revealChosen(strip);

        expect(strip.scrollLeft).toBe(0);
    });
});

describe('the ends of a strip', () => {
    it('says there is more after it at the start, before it at the end, and both between', () => {
        const strip = lay(board(0));

        markEnds(strip);
        expect(strip.dataset.more).toBe('end');

        strip.scrollLeft = 200;
        markEnds(strip);
        expect(strip.dataset.more).toBe('both');

        strip.scrollLeft = 510;
        markEnds(strip);
        expect(strip.dataset.more).toBe('start');
    });

    it('says nothing is beyond either end of a strip that does not scroll', () => {
        const strip = lay(board(0), { width: 900, content: 900 });

        markEnds(strip);

        expect(strip.dataset.more).toBe('none');
    });

    it('follows the strip as it is swiped', () => {
        const strip = lay(board(0));

        chipStrips();
        strip.scrollLeft = 200;
        strip.dispatchEvent(new Event('scroll'));

        expect(strip.dataset.more).toBe('both');
    });
});

describe('a screen swapped in by navigate.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('has its strips set up too, not only the first page of the session', () => {
        watchChipStrips();

        const strip = lay(board(9));
        document.dispatchEvent(new CustomEvent('acinfo:content', { detail: { url: '/admin/file/status' } }));

        expect(strip.scrollLeft).toBe(510);
        expect(strip.dataset.more).toBe('start');
    });
});

describe('the strips in the stylesheet', () => {
    const page = read('resources/views/admin/work/status.blade.php');
    const phone = blocksOf(page, '@media (max-width: 575.98px), (pointer: coarse) and (max-height: 500px) {');

    it('are no box at all on a tablet or a desk, so the chips wrap as before', () => {
        expect(ruleFor(page.replace(phone, ''), ['.filter-chips'])).toContain('display: contents');
    });

    it('are one line that scrolls sideways on a phone, upright or turned', () => {
        const strip = ruleFor(phone, ['.filter-chips']);

        expect(strip).toContain('display: flex');
        expect(strip).toContain('flex-wrap: nowrap');
        expect(strip).toContain('overflow-x: auto');
        expect(ruleFor(phone, ['.filter-chips > *'])).toContain('flex: none');
    });

    it('fade the end with more beyond it', () => {
        expect(ruleFor(phone, ['.filter-chips[data-more="end"]'])).toContain('mask-image: linear-gradient(to right');
        expect(ruleFor(phone, ['.filter-chips[data-more="start"]'])).toContain('mask-image: linear-gradient(to left');
        expect(ruleFor(phone, ['.filter-chips[data-more="both"]'])).toContain('mask-image:');
    });
});
