<script setup>
/*
 * The Collection List: every customer who owes, with a reminder on each row.
 *
 * The grid is the one every listing uses; the only thing this adds is what
 * Remind does. It opens WhatsApp on the customer's number with the balance
 * reminder their statement sends — the same words from either place, and
 * nothing in them but their own name, the amount and the date. The office
 * presses Send itself.
 */
import { computed } from 'vue';
import DataGrid from './DataGrid.vue';
import { balanceMessage } from '../customerShare';
import { whatsappNumber, whatsappUrl } from '../whatsapp';

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    title: { type: String, default: 'Export' },
    totals: { type: Object, default: () => ({}) },
    perPage: { type: Number, default: 50 },
    emptyText: { type: String, default: 'Nothing to show.' },
    todayLabel: { type: String, default: '' },
});

const gridProps = computed(() => ({
    columns: props.columns,
    rows: props.rows,
    title: props.title,
    totals: props.totals,
    perPage: props.perPage,
    emptyText: props.emptyText,
}));

function onAction(row, column) {
    if (column.key !== 'remind') {
        return;
    }

    const text = balanceMessage(row.customer, row.owes, props.todayLabel);

    if (text) {
        window.open(whatsappUrl(whatsappNumber(row.whatsapp), text), '_blank', 'noopener');
    }
}
</script>

<template>
    <DataGrid v-bind="gridProps" @action="onAction" />
</template>

<style>
/* WhatsApp's own green, as on every other Send on WhatsApp. */
.cl-remind .ui-btn {
    background: #25d366;
    border-color: #1ebe5b;
    color: #fff;
}

.cl-remind .ui-btn:hover {
    background: #1ebe5b;
    color: #fff;
}
</style>
