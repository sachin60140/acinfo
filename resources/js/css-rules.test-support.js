import { readFileSync } from 'node:fs';

/*
 * Reading the stylesheets the way a test can.
 *
 * jsdom lays nothing out, so a rule cannot be measured here, only read, and
 * only inside the block it belongs to: a touch rule moved out of its @media
 * applies to every desktop too. Shared by the mobile and tablet audits' tests.
 */

// Comments out, whitespace to single spaces: a selector list reads as one line.
export const flat = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '').replace(/\s+/g, ' ');
export const read = (path) => flat(readFileSync(path, 'utf8'));

/**
 * The bodies of every block opened by `header` ("@media (pointer: coarse) {"),
 * each to its own closing brace and no further, joined. Found in review: split
 * on "@media" instead, a rule moved out of the block still counted as in it.
 */
export function blocksOf(css, header) {
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
export function ruleFor(css, selectors) {
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
