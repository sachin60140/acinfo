<script setup>
/*
 * Vendor Payments: what the office owes each vendor, bill by bill.
 *
 * The grid is the one every listing uses, banded by vendor. The band's heading
 * is the vendor — what they are owed in all, and the two things to do about
 * it: record a payment, with them already picked, or open their statement.
 * No WhatsApp: nothing about money is sent to a vendor from here.
 */
import DataGrid from './DataGrid.vue';
import { money } from '../money';

defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    title: { type: String, default: 'Export' },
    groupBy: { type: String, default: '' },
    groupLabel: { type: String, default: '' },
    totals: { type: Object, default: () => ({}) },
    perPage: { type: Number, default: 50 },
    emptyText: { type: String, default: 'Nothing to show.' },
});

const vendorOf = (band) => band.rows?.[0] ?? {};
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
            <span class="vp-band">
                <span class="vp-band__label">{{ band.label }}</span>
                <span v-if="band.rows && band.rows.length" class="vp-band__owed ui-money cr">
                    {{ money(vendorOf(band).vendor_owed) }} owed
                </span>
                <a v-if="vendorOf(band).pay_url" :href="vendorOf(band).pay_url" class="ui-btn ui-btn--sm vp-band__pay">
                    <i class="bi bi-cash-stack"></i>
                    Record payment
                </a>
                <a v-if="vendorOf(band).statement_url" :href="vendorOf(band).statement_url" class="ui-btn ui-btn--sm">
                    Statement
                </a>
                <span v-if="vendorOf(band).setoff_note" class="vp-band__note">{{ vendorOf(band).setoff_note }}</span>
            </span>
        </template>
    </DataGrid>
</template>

<style>
/* Beside the name rather than at the far end, as on Not Yet Collected: the
   table scrolls sideways and a button at its right edge is off the screen. */
.vp-band {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    justify-content: flex-start;
}

.vp-band__owed {
    font-weight: 700;
}

.vp-band__note {
    color: var(--warn-600);
    font-size: var(--t-sm);
    font-weight: 600;
}
</style>
