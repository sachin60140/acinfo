<script setup>
/*
 * In-house Work, and moving a job along from it.
 *
 * The list is the office's own to-do list, so the thing a reader wants to do
 * to a row is the thing they would otherwise go to Update Status for: say the
 * work has moved. The dialog is the Work Report's, posting to the status
 * screen's own controller, so none of the rules about moving a work live here.
 *
 * One row is one work, and the dialog is handed just that work — never the
 * rest of its folder, some of which may be with a vendor.
 */
import { computed, onMounted, ref } from 'vue';
import DataGrid from './DataGrid.vue';
import WorkUpdateDialog from './WorkUpdateDialog.vue';

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    title: { type: String, default: 'Export' },
    totals: { type: Object, default: () => ({}) },
    perPage: { type: Number, default: 50 },
    emptyText: { type: String, default: 'Nothing to show.' },

    // What the dialog needs, and nothing the grid cares about.
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    returnTo: { type: String, default: '' },
    jobStatuses: { type: Object, default: () => ({}) },
    approvedKey: { type: String, default: 'approval_done' },
    pendencyKey: { type: String, default: 'paper_pendency' },
    cancelledKey: { type: String, default: 'cancelled' },
    reasonKeys: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    // What a refused save held, to be put back; see WorkUpdateDialog.
    restore: { type: Object, default: null },
});

const gridProps = computed(() => ({
    columns: props.columns,
    rows: props.rows,
    title: props.title,
    totals: props.totals,
    perPage: props.perPage,
    emptyText: props.emptyText,
}));

const editing = ref(null);

/*
 * Back from a save the server refused: open the dialog on the row it was
 * about again, with what was typed put back, rather than leave the reader
 * to find the row and type it all a second time.
 */
onMounted(() => {
    const ids = Object.keys(props.restore?.statuses ?? {}).map(Number);

    if (! ids.length) {
        return;
    }

    const row = props.rows.find((r) => (r.items ?? []).some((item) => ids.includes(Number(item.id))));

    if (row) {
        editing.value = row;
    }
});

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
            :pendency-key="pendencyKey"
            :cancelled-key="cancelledKey"
            :reason-keys="reasonKeys"
            :today="today"
            :restore="restore"
            @close="editing = null" />
    </div>
</template>
