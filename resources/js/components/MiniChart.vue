<script setup>
/*
 * The small charts on the dashboard, drawn by hand.
 *
 * No charting library. The same reasoning that took DataTables off the listing
 * screens applies harder here: a chart library is hundreds of kilobytes to draw
 * four small pictures, the built bundle travels in this repository because the
 * host has no npm step, and every byte of it is pulled down by an office on a
 * connection nobody chose. Four rectangles and some text do not need a
 * dependency.
 *
 * Two shapes, one component, so the four charts on the screen read as one set
 * rather than four opinions:
 *
 *   columns — a month along the bottom, one bar per series. For anything
 *             measured over time, where the question is which way it is going.
 *   bars    — a row per thing, longest first. For comparing a handful of
 *             named things against each other at one moment.
 *
 * Everything is an <svg> with a viewBox and no fixed width, so it scales with
 * the card it is in and stays sharp on the counter's monitor and on a phone.
 */
import { computed } from 'vue';
import { money } from '../money';

const props = defineProps({
    kind: { type: String, default: 'columns' },

    /*
     * columns: [{ label, values: { <series key>: number } }] oldest first.
     * bars:    [{ label, value, note }] in the order to draw them.
     */
    rows: { type: Array, default: () => [] },

    // columns only: [{ key, label, tone }]. tone picks the colour; see the style.
    series: { type: Array, default: () => [] },

    // How a number reads: money gets thousands and two decimals, count does not.
    format: { type: String, default: 'count' },

    // Said to a screen reader, which cannot see any of this.
    caption: { type: String, default: '' },
});

const show = (n) => (props.format === 'money' ? money(n) : String(Math.round(n)));

/*
 * Short money on an axis: 58,500 becomes 58.5k, because the label sits under a
 * bar forty pixels wide and the exact figure is on the card above it anyway.
 */
function brief(n) {
    const size = Math.abs(n);

    if (props.format !== 'money') {
        return String(Math.round(n));
    }

    if (size >= 100000) {
        return Math.round(n / 1000) + 'k';
    }

    if (size >= 1000) {
        return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
    }

    return String(Math.round(n));
}

const every = computed(() => props.rows.flatMap(
    (row) => props.series.map((one) => Number(row.values?.[one.key] ?? 0))
));

/*
 * The range the bars are drawn against.
 *
 * Zero is always in it, so a bar's height is its share of the whole rather than
 * its share of the gap between the smallest and the largest — which is how a
 * chart makes 29,000 look twice 28,000. Margin can be negative, so the floor
 * follows the data down when it needs to and sits at zero when it does not.
 */
const top = computed(() => Math.max(0, ...every.value));
const floor = computed(() => Math.min(0, ...every.value));
const span = computed(() => (top.value - floor.value) || 1);

// A viewBox, not pixels: the card decides how wide this ends up.
const W = 720;
const H = 200;
const PAD = { top: 12, right: 8, bottom: 26, left: 8 };

const plot = computed(() => ({
    w: W - PAD.left - PAD.right,
    h: H - PAD.top - PAD.bottom,
}));

const zeroY = computed(() => PAD.top + (top.value / span.value) * plot.value.h);

const slot = computed(() => plot.value.w / Math.max(props.rows.length, 1));

// A gap either side of each month's group, and between the bars inside it.
const groupW = computed(() => slot.value * 0.7);
const barW = computed(() => groupW.value / Math.max(props.series.length, 1));

function bar(rowIndex, seriesIndex, value) {
    const v = Number(value ?? 0);
    const height = (Math.abs(v) / span.value) * plot.value.h;
    const x = PAD.left + rowIndex * slot.value + (slot.value - groupW.value) / 2 + seriesIndex * barW.value;

    return {
        x,
        // A zero-height rectangle draws nothing at all, so a month with no work
        // shows a hairline on the baseline rather than a silent gap.
        y: v >= 0 ? zeroY.value - height : zeroY.value,
        width: Math.max(barW.value - 2, 1),
        height: Math.max(height, v === 0 ? 1 : 1),
    };
}

const longest = computed(() => Math.max(1, ...props.rows.map((r) => Math.abs(Number(r.value ?? 0)))));

const width = (value) => `${(Math.abs(Number(value ?? 0)) / longest.value) * 100}%`;

const anything = computed(() => props.rows.length > 0 && every.value.some((n) => n !== 0));
</script>

<template>
    <!-- bars: no SVG at all. A row of labelled divs is a bar chart that wraps,
         selects, translates and reads aloud, which an SVG of the same thing
         does none of. -->
    <div v-if="kind === 'bars'" class="mc-bars">
        <div v-for="(row, i) in rows" :key="i" class="mc-bar">
            <span class="mc-bar__label" :title="row.label">{{ row.label }}</span>
            <span class="mc-bar__track">
                <span class="mc-bar__fill" :style="{ width: width(row.value) }"></span>
            </span>
            <span class="mc-bar__value">
                {{ show(row.value) }}
                <small v-if="row.note">{{ row.note }}</small>
            </span>
        </div>

        <p v-if="! rows.length" class="mc-empty">Nothing to show yet.</p>
    </div>

    <div v-else class="mc">
        <div v-if="series.length" class="mc-key">
            <span v-for="one in series" :key="one.key" class="mc-key__item">
                <span class="mc-key__dot" :data-tone="one.tone"></span>{{ one.label }}
            </span>
        </div>

        <svg
            v-if="anything"
            class="mc-svg"
            :viewBox="`0 0 ${W} ${H}`"
            preserveAspectRatio="none"
            role="img"
            :aria-label="caption">
            <!-- The line everything is measured from. Drawn under the bars so a
                 bar sitting on it hides its own edge rather than straddling. -->
            <line :x1="PAD.left" :x2="W - PAD.right" :y1="zeroY" :y2="zeroY" class="mc-axis" />

            <template v-for="(row, i) in rows" :key="i">
                <rect
                    v-for="(one, s) in series"
                    :key="one.key"
                    v-bind="bar(i, s, row.values?.[one.key])"
                    :data-tone="one.tone"
                    class="mc-rect">
                    <title>{{ row.label }} · {{ one.label }} · {{ show(row.values?.[one.key] ?? 0) }}</title>
                </rect>
            </template>
        </svg>

        <p v-else class="mc-empty">Nothing recorded in this period yet.</p>

        <!-- Outside the SVG, so they stay the page's font size however the
             chart above them is stretched. -->
        <div v-if="anything" class="mc-months">
            <span v-for="(row, i) in rows" :key="i" class="mc-month">{{ row.label }}</span>
        </div>

        <div v-if="anything" class="mc-scale">
            <span>{{ brief(floor) }}</span>
            <span>{{ brief(top) }}</span>
        </div>
    </div>
</template>

<style>
.mc-svg {
    display: block;
    height: 190px;
    width: 100%;
}

/* Debit green and credit red, the two directions the rest of the ledger uses,
   so a chart of the same money is not a third colour scheme to learn. */
.mc-rect[data-tone='in'],
.mc-key__dot[data-tone='in'] {
    fill: var(--dr-600);
    background: var(--dr-600);
}

.mc-rect[data-tone='out'],
.mc-key__dot[data-tone='out'] {
    fill: var(--cr-600);
    background: var(--cr-600);
}

.mc-rect[data-tone='net'],
.mc-key__dot[data-tone='net'] {
    fill: var(--brand-500);
    background: var(--brand-500);
}

.mc-axis {
    stroke: var(--n-300);
    stroke-width: 1;
}

.mc-key {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    margin-bottom: var(--s-2);
}

.mc-key__item {
    align-items: center;
    color: var(--n-600);
    display: inline-flex;
    font-size: var(--t-xs);
    font-weight: 600;
    gap: var(--s-2);
}

.mc-key__dot {
    border-radius: 2px;
    display: inline-block;
    height: 0.6rem;
    width: 0.6rem;
}

/* One cell a month, matching the bars above because both divide the same width
   into the same number of equal parts. */
.mc-months {
    display: grid;
    gap: 0;
    grid-auto-columns: 1fr;
    grid-auto-flow: column;
}

.mc-month {
    color: var(--n-500);
    font-size: var(--t-xs);
    text-align: center;
}

.mc-scale {
    color: var(--n-400);
    display: flex;
    font-size: var(--t-xs);
    justify-content: space-between;
    margin-top: var(--s-1);
}

.mc-bars {
    display: flex;
    flex-direction: column;
    gap: var(--s-2);
}

.mc-bar {
    align-items: center;
    display: grid;
    gap: var(--s-3);
    grid-template-columns: minmax(0, 8rem) minmax(0, 1fr) auto;
}

/* On a phone the name takes the width and the bar sits under it: three
   columns in three hundred pixels leaves a bar too short to compare. */
@media (max-width: 575.98px) {
    .mc-bar {
        grid-template-columns: minmax(0, 1fr) auto;
    }

    .mc-bar__label {
        grid-column: 1 / -1;
    }
}

.mc-bar__label {
    color: var(--ink-800);
    font-size: var(--t-sm);
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.mc-bar__track {
    background: var(--n-100);
    border-radius: 999px;
    display: block;
    height: 0.75rem;
    overflow: hidden;
}

.mc-bar__fill {
    background: var(--brand-500);
    border-radius: 999px;
    display: block;
    height: 100%;
    /* A thing with a value has a bar, however small its share. */
    min-width: 2px;
}

.mc-bar__value {
    color: var(--ink-800);
    font-size: var(--t-sm);
    font-weight: 700;
    white-space: nowrap;
}

.mc-bar__value small {
    color: var(--n-500);
    font-weight: 600;
    margin-left: var(--s-2);
}

.mc-empty {
    color: var(--n-500);
    font-size: var(--t-sm);
    margin: 0;
    padding: var(--s-4) 0;
}
</style>
