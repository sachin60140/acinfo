/*
 * The status board's filters, as strips a thumb swipes along on a phone.
 *
 * Wrapped, the three rows of chips (status, work type, vendor) took more than
 * a phone's first screen, so the board under them started a scroll away. On a
 * phone each row is now one line that scrolls sideways (the rules are in
 * admin/work/status.blade.php); this is the part a stylesheet cannot do.
 *
 * - The chosen chip is brought into its strip's view. A vendor near the end of
 *   the row was chosen, the page came back with the strip at its start, and
 *   nothing on the screen said which vendor the board was showing.
 * - Each strip says which of its ends has more beyond it (data-more), and the
 *   stylesheet fades that end, so a strip reads as one to swipe and not as all
 *   there is.
 *
 * Where the strips do not scroll (a tablet, a desk) there is nothing to bring
 * into view and nothing beyond either end, and this does nothing.
 */

const STRIP = '.filter-chips';

/** Within a pixel: scroll positions are fractional on a high-density screen. */
const SLACK = 1;

/**
 * Bring the strip's chosen chip into view, centred where there is room, by
 * scrolling the strip alone. scrollIntoView would move the page as well, and a
 * board that loads already scrolled past its own filters is worse than one
 * whose chosen chip has to be looked for.
 */
export function revealChosen(strip) {
    const chosen = strip.querySelector('.chip.active');

    if (! chosen || strip.scrollWidth <= strip.clientWidth + SLACK) {
        return;
    }

    const box = strip.getBoundingClientRect();
    const chip = chosen.getBoundingClientRect();

    if (chip.left >= box.left && chip.right <= box.right) {
        return;
    }

    strip.scrollLeft += chip.left - box.left - (box.width - chip.width) / 2;
}

/** Which ends of the strip have more beyond them: none, start, end or both. */
export function markEnds(strip) {
    const furthest = strip.scrollWidth - strip.clientWidth;
    const before = strip.scrollLeft > SLACK;
    const after = furthest > SLACK && strip.scrollLeft < furthest - SLACK;

    strip.dataset.more = before && after ? 'both' : before ? 'start' : after ? 'end' : 'none';
}

/** Every strip on the page: its chosen chip in view, its ends marked. */
export function chipStrips(root = document) {
    for (const strip of root.querySelectorAll(STRIP)) {
        revealChosen(strip);
        markEnds(strip);

        // Once per strip. A strip that arrives with the next screen is a new
        // element, and the one it replaced took its listener with it.
        if (! strip.dataset.swipeWatched) {
            strip.dataset.swipeWatched = '1';
            strip.addEventListener('scroll', () => markEnds(strip), { passive: true });
        }
    }
}

/**
 * At load, on every screen navigate.js swaps in (DOMContentLoaded fires once
 * per session now, not once per screen), and when the phone is turned, which
 * can make a strip scroll that did not or stop one that did.
 */
export function watchChipStrips() {
    chipStrips();

    document.addEventListener('acinfo:content', () => chipStrips());

    let pending = false;

    window.addEventListener('resize', () => {
        if (pending) {
            return;
        }

        pending = true;

        requestAnimationFrame(() => {
            pending = false;
            chipStrips();
        });
    });
}
