<script setup>
/*
 * The work report, and the one thing a reader wants to do to a row of it.
 *
 * The grid is the same grid every other listing uses and is left alone; this
 * only listens for the row somebody asked about and opens the dialog over it.
 * Everything the grid is handed is passed straight through, so a column added
 * to the report tomorrow needs nothing here.
 */
import { computed, ref } from 'vue';
import DataGrid from './DataGrid.vue';
import WorkUpdateDialog from './WorkUpdateDialog.vue';

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    title: { type: String, default: 'Export' },
    groupBy: { type: String, default: '' },
    groupLabel: { type: String, default: '' },
    totals: { type: Object, default: () => ({}) },
    perPage: { type: Number, default: 50 },
    sortable: { type: Boolean, default: true },
    sortedBy: { type: String, default: '' },
    sortedDesc: { type: Boolean, default: false },
    rowClass: { type: String, default: '' },
    emptyText: { type: String, default: 'Nothing to show.' },
    lead: { type: Array, default: () => [] },
    tail: { type: Array, default: () => [] },

    // What the dialog needs, and nothing the grid cares about.
    action: { type: String, default: '' },
    csrf: { type: String, default: '' },
    returnTo: { type: String, default: '' },
    jobStatuses: { type: Object, default: () => ({}) },
    approvedKey: { type: String, default: 'approval_done' },
    reasonKeys: { type: Array, default: () => [] },
    today: { type: String, default: '' },
});

// Split once, so the grid is handed its own props and not the dialog's.
const gridProps = computed(() => ({
    columns: props.columns,
    rows: props.rows,
    title: props.title,
    groupBy: props.groupBy,
    groupLabel: props.groupLabel,
    totals: props.totals,
    perPage: props.perPage,
    sortable: props.sortable,
    sortedBy: props.sortedBy,
    sortedDesc: props.sortedDesc,
    rowClass: props.rowClass,
    emptyText: props.emptyText,
    lead: props.lead,
    tail: props.tail,
}));

const editing = ref(null);

/*
 * A row with no works has nothing to move. It should not have offered a button
 * at all, and opening an empty dialog over the report would be a worse way of
 * saying so than the disabled cell the server sends.
 */
function onAction(row) {
    if (! row?.items?.length) {
        return;
    }

    editing.value = row;
}
</script>

<template>
    <div>
        <DataGrid v-bind="gridProps" @action="onAction" />

        <WorkUpdateDialog
            :file="editing"
            :action="action"
            :csrf="csrf"
            :return-to="returnTo"
            :statuses="jobStatuses"
            :approved-key="approvedKey"
            :reason-keys="reasonKeys"
            :today="today"
            @close="editing = null" />
    </div>
</template>
