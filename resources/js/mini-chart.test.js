import { afterEach, describe, expect, it } from 'vitest';
import { createApp, h } from 'vue';
import MiniChart from './components/MiniChart.vue';

/*
 * The dashboard's charts, drawn by hand.
 *
 * What can go wrong in a chart is not that it throws — it is that it draws a
 * convincing picture of the wrong thing. So what is pinned here is the
 * arithmetic a reader cannot check by looking: that a bar's height is its share
 * of a range including zero, that a month with nothing in it is still a month,
 * and that a loss is drawn below the line rather than as a tall bar above it.
 */

const mounted = [];

function mount(props) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp({ render: () => h(MiniChart, props) });

    app.mount(host);
    mounted.push({ app, host });

    return host;
}

const rects = (host) => [...host.querySelectorAll('rect.mc-rect')];
const axis = (host) => host.querySelector('line.mc-axis');
const num = (el, attr) => Number(el.getAttribute(attr));

const SERIES = [
    { key: 'billed', label: 'Billed', tone: 'in' },
    { key: 'margin', label: 'Margin', tone: 'net' },
];

const columns = (rows, over = {}) => mount({
    kind: 'columns', series: SERIES, rows, format: 'money', caption: 'Money by month', ...over,
});

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

describe('months along the bottom', () => {
    const THREE = [
        { label: 'Jul', values: { billed: 0, margin: 0 } },
        { label: 'Aug', values: { billed: 10000, margin: 4000 } },
        { label: 'Sep', values: { billed: 5000, margin: 1000 } },
    ];

    it('draws a bar for every series in every month', () => {
        expect(rects(columns(THREE))).toHaveLength(6);
    });

    it('labels every month, including the quiet ones', () => {
        const host = columns(THREE);

        expect([...host.querySelectorAll('.mc-month')].map((m) => m.textContent))
            .toEqual(['Jul', 'Aug', 'Sep']);
    });

    /*
     * The one that makes a chart lie. Scaled between the smallest and largest
     * value rather than from zero, 5,000 beside 10,000 reads as nothing beside
     * everything instead of as half.
     */
    it('measures a bar against a range that includes zero', () => {
        const host = columns(THREE);

        const august = rects(host)[2];   // Aug billed, 10000 — the tallest
        const september = rects(host)[4]; // Sep billed, 5000 — half of it

        expect(num(september, 'height') / num(august, 'height')).toBeCloseTo(0.5, 2);
    });

    it('gives a month with nothing in it a mark on the line rather than a gap', () => {
        const host = columns(THREE);

        // July billed is zero: still drawn, so the month is visibly empty
        // rather than invisibly absent.
        expect(num(rects(host)[0], 'height')).toBeGreaterThan(0);
    });

    /* A loss belongs under the line, not as a tall bar above it. */
    it('draws a negative below the baseline', () => {
        const host = columns([
            { label: 'Jul', values: { billed: 8000, margin: 2000 } },
            { label: 'Aug', values: { billed: 6000, margin: -3000 } },
        ]);

        const line = num(axis(host), 'y1');
        const loss = rects(host)[3];

        expect(num(loss, 'y')).toBeGreaterThanOrEqual(line);
    });

    it('says what each bar is, for a pointer and for a reader', () => {
        const host = columns(THREE);

        expect(rects(host)[2].querySelector('title').textContent).toContain('Aug');
        expect(rects(host)[2].querySelector('title').textContent).toContain('Billed');
        expect(host.querySelector('svg').getAttribute('aria-label')).toContain('Money by month');
    });

    it('says so plainly when there is nothing to draw', () => {
        const host = columns([
            { label: 'Jul', values: { billed: 0, margin: 0 } },
            { label: 'Aug', values: { billed: 0, margin: 0 } },
        ]);

        expect(host.querySelector('svg')).toBeNull();
        expect(host.querySelector('.mc-empty').textContent).toContain('Nothing recorded');
    });

    it('names each series once, in a key', () => {
        const host = columns(THREE);

        expect([...host.querySelectorAll('.mc-key__item')].map((k) => k.textContent.trim()))
            .toEqual(['Billed', 'Margin']);
    });
});

describe('a row per thing', () => {
    const ROWS = [
        { label: 'Sharma Ji', value: 4, note: 'over 9 files' },
        { label: 'Dabloo Ji', value: 12, note: 'over 3 files' },
    ];

    const bars = (rows = ROWS) => mount({ kind: 'bars', rows, format: 'count' });

    it('draws no SVG at all, so the labels behave like text', () => {
        const host = bars();

        expect(host.querySelector('svg')).toBeNull();
        expect([...host.querySelectorAll('.mc-bar__label')].map((l) => l.textContent))
            .toEqual(['Sharma Ji', 'Dabloo Ji']);
    });

    it('sizes each bar against the longest', () => {
        const host = bars();
        const fills = [...host.querySelectorAll('.mc-bar__fill')];

        expect(fills[1].style.width).toBe('100%');
        expect(parseFloat(fills[0].style.width)).toBeCloseTo(33.33, 1);
    });

    it('carries the figure and what it is out of', () => {
        const host = bars();

        expect(host.querySelectorAll('.mc-bar__value')[0].textContent).toContain('4');
        expect(host.querySelectorAll('.mc-bar__value')[0].textContent).toContain('over 9 files');
    });

    it('says so plainly when there is nothing to rank', () => {
        expect(bars([]).querySelector('.mc-empty').textContent).toContain('Nothing to show');
    });
});
