<script setup>
/*
 * The dashboard tiles.
 *
 * Everything here is computed by the server and read-only, so this is a
 * presentation component: it decides how a figure reads, not what it is.
 *
 * The figures are the content, so they lead — large and tabular, with the label
 * quiet above and the detail quiet below. Two rules the ledger imposes on the
 * layout: receivable and payable sit side by side and are never combined, since
 * a business owed 10,000 and owing 7,000 is not a business owed 3,000; and a
 * tile that links somewhere lands on exactly the set it counted, or the count is
 * a claim the next screen contradicts.
 */
import { computed } from 'vue';
import { balance, money, side } from '../money';
import MiniChart from './MiniChart.vue';

const props = defineProps({
    tiles: { type: Array, default: () => [] },

    /*
     * The pictures under the figures, described by the server the same way the
     * tiles are: what to draw and what it is called, never how to draw it.
     * Each is { title, hint, kind, format, series, rows, href }.
     */
    charts: { type: Array, default: () => [] },
});

/*
 * A tile's figure is written according to what kind of figure it is, so the
 * decision lives here once rather than at each of the six call sites.
 */
function text(tile) {
    if (tile.type === 'count') return String(tile.value);
    if (tile.type === 'balance') return balance(tile.value);

    return money(tile.value);
}

function tone(tile) {
    if (tile.type === 'count') return 'nil';
    if (tile.type === 'balance') return side(tile.value);

    return tile.tone || 'nil';
}

const groups = computed(() => {
    const out = [];

    for (const tile of props.tiles) {
        const last = out[out.length - 1];

        if (last && last.group === tile.group) {
            last.tiles.push(tile);
        } else {
            out.push({ group: tile.group, tiles: [tile] });
        }
    }

    return out;
});
</script>

<template>
    <div class="dash">
        <div v-for="(band, i) in groups" :key="i" class="dash__band">
            <h2 v-if="band.group" class="dash__heading">{{ band.group }}</h2>

            <div class="ui-stats">
                <component
                    :is="tile.href ? 'a' : 'div'"
                    v-for="tile in band.tiles"
                    :key="tile.label"
                    :href="tile.href || null"
                    class="ui-stat"
                    :class="{ 'ui-stat--link': tile.href }">
                    <span class="ui-stat__label">{{ tile.label }}</span>
                    <span class="ui-stat__value" :class="`ui-money--${tone(tile)}`">{{ text(tile) }}</span>
                    <span v-if="tile.note" class="ui-stat__note">{{ tile.note }}</span>
                </component>
            </div>
        </div>

        <!--
            The pictures, under the figures.

            A tile says what a month was; these say which way it has been going,
            which is the question somebody actually opens this screen with. Each
            one links to the screen that holds the same rows, for the reason the
            tiles do: a figure nobody can get behind is a figure nobody trusts.
        -->
        <div v-if="charts.length" class="dash__band">
            <h2 class="dash__heading">How it is going</h2>

            <div class="dash__charts">
                <section v-for="chart in charts" :key="chart.title" class="ui-card dash__chart">
                    <div class="ui-card__head dash__chart-head">
                        <div>
                            <h3 class="dash__chart-title">{{ chart.title }}</h3>
                            <p v-if="chart.hint" class="ui-hint">{{ chart.hint }}</p>
                        </div>
                        <a v-if="chart.href" :href="chart.href" class="ui-btn ui-btn--sm">Open</a>
                    </div>

                    <div class="ui-card__body">
                        <MiniChart
                            :kind="chart.kind"
                            :rows="chart.rows"
                            :series="chart.series || []"
                            :format="chart.format || 'count'"
                            :caption="chart.title + '. ' + (chart.hint || '')" />
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>

<style>
.dash__band + .dash__band {
    margin-top: var(--s-6);
}

/* Two across on a desk and one on a phone. The money chart wants the room, so
   it is given both columns where there are two. */
.dash__charts {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr));
}

.dash__chart {
    min-width: 0;
}

.dash__chart--wide {
    grid-column: 1 / -1;
}

.dash__chart-head {
    align-items: start;
    gap: var(--s-3);
}

.dash__chart-title {
    color: var(--ink-800);
    font-size: var(--t-md);
    font-weight: 700;
    margin: 0;
}

.dash__heading {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.06em;
    margin: 0 0 var(--s-3);
    text-transform: uppercase;
}

.dash .ui-stat {
    display: flex;
    flex-direction: column;
    gap: var(--s-1);
}

.dash .ui-stat__label {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.dash .ui-stat__value {
    font-size: var(--t-2xl);
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    line-height: 1.1;
}

.dash .ui-stat__note {
    color: var(--n-500);
    font-size: var(--t-sm);
}

/* A tile that goes somewhere says so on hover rather than at rest — six tiles
   all advertising themselves at once is noise, not affordance. */
.dash a.ui-stat {
    color: inherit;
    text-decoration: none;
    transition: border-color 120ms ease, transform 120ms ease;
}

.dash a.ui-stat:hover {
    border-color: var(--brand-400);
    transform: translateY(-1px);
}

@media (prefers-reduced-motion: reduce) {
    .dash a.ui-stat {
        transition: none;
    }

    .dash a.ui-stat:hover {
        transform: none;
    }
}
</style>
