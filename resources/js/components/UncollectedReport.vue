<script setup>
/*
 * Not Yet Collected, banded by customer, with a message for each.
 *
 * The grid is the same grid every other listing uses. One band is one
 * customer, so the buttons sit on the band's own heading and send exactly the
 * rows under it — which the grid has already narrowed by any search typed
 * above it. The customer's number rides on those rows, because the report
 * holds every customer at once.
 */
import DataGrid from './DataGrid.vue';
import WhatsAppShare from './WhatsAppShare.vue';
import { readyMessage } from '../customerShare';

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    title: { type: String, default: 'Export' },
    groupBy: { type: String, default: '' },
    groupLabel: { type: String, default: '' },
    totals: { type: Object, default: () => ({}) },
    perPage: { type: Number, default: 50 },
    emptyText: { type: String, default: 'Nothing to show.' },
    todayLabel: { type: String, default: '' },
});

const customerOf = (band) => band.rows?.[0] ?? {};

const bandMessage = (band) => readyMessage(customerOf(band).customer || band.label, band.rows ?? [], props.todayLabel);
</script>

<template>
    <DataGrid
        :columns="columns"
        :rows="rows"
        :title="title"
        :group-by="groupBy"
        :group-label="groupLabel"
        :totals="totals"
        :per-page="perPage"
        :empty-text="emptyText">
        <template #band="{ band }">
            <span class="uc-band">
                <span class="uc-band__label">{{ band.label }}</span>
                <WhatsAppShare
                    v-if="band.rows && band.rows.length"
                    :text="bandMessage(band)"
                    :mobile="customerOf(band).customer_mobile || ''"
                    :name="customerOf(band).customer || band.label" />
            </span>
        </template>
    </DataGrid>
</template>

<style>
/* Beside the name rather than at the far end, for the reason the Work Report
   gives: the table scrolls sideways and a button at its right edge is off
   the screen. */
.uc-band {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    justify-content: flex-start;
}
</style>
