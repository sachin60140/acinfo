<script setup>
/*
 * A party's statement, and taking back an entry typed by mistake.
 *
 * The grid is the same grid every listing uses. Rows that can be taken back —
 * typed on the Entry screen, not a reversal, not already reversed — carry a
 * Change button; it opens a dialog that asks why, and either reverses the
 * entry or reverses it and opens the Entry screen with it filled in again.
 * The server decides again what can be reversed; this only offers it.
 */
import { computed, nextTick, ref } from 'vue';
import DataGrid from './DataGrid.vue';
import { money } from '../money';

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    lead: { type: Array, default: () => [] },
    tail: { type: Array, default: () => [] },
    title: { type: String, default: 'Export' },
    totals: { type: Object, default: () => ({}) },
    perPage: { type: Number, default: 50 },
    sortable: { type: Boolean, default: true },
    emptyText: { type: String, default: 'Nothing to show.' },
    rowClass: { type: String, default: '' },

    // Where the dialog posts, with __ID__ for the entry.
    action: { type: String, required: true },
    csrf: { type: String, default: '' },
});

const gridProps = computed(() => ({
    columns: props.columns,
    rows: props.rows,
    lead: props.lead,
    tail: props.tail,
    title: props.title,
    totals: props.totals,
    perPage: props.perPage,
    sortable: props.sortable,
    emptyText: props.emptyText,
    rowClass: props.rowClass,
}));

const entry = ref(null);
const reason = ref('');
const reasonBox = ref(null);

async function onAction(row) {
    if (! row?.change || ! row.id) {
        return;
    }

    entry.value = row;
    reason.value = '';
    await nextTick();
    reasonBox.value?.focus();
}

function close() {
    entry.value = null;
}

const target = computed(() => (entry.value ? props.action.replace('__ID__', String(entry.value.id)) : ''));

const amountText = computed(() => {
    if (! entry.value) {
        return '';
    }

    return entry.value.debit != null
        ? `${money(entry.value.debit)} Dr`
        : `${money(entry.value.credit)} Cr`;
});

// Said before it is pressed; the server refuses the same.
const blocked = computed(() => reason.value.trim() === '');
</script>

<template>
    <div class="party-statement">
        <DataGrid v-bind="gridProps" @action="onAction" />

        <Teleport to="body">
            <div v-if="entry" class="ps-dialog" @click.self="close" @keydown.esc="close">
                <form class="ps-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="ps-title" :action="target" method="POST">
                    <input type="hidden" name="_token" :value="csrf">

                    <div class="ps-dialog__head">
                        <h5 id="ps-title" class="ps-dialog__title">Change entry #{{ entry.id }}</h5>
                        <button type="button" class="ps-dialog__x" aria-label="Close" @click="close">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <div class="ps-dialog__body">
                        <div class="ps-dialog__entry">
                            <div><strong>{{ entry.particular }}</strong></div>
                            <div class="ui-hint">
                                {{ entry.txn_date }}
                                <template v-if="entry.payment_mode"> · {{ entry.payment_mode }}</template>
                                · <strong>{{ amountText }}</strong>
                            </div>
                            <div v-if="entry.against" class="ui-hint">Against: {{ entry.against }}</div>
                        </div>

                        <p class="ui-hint">
                            An entry is never deleted. Reversing it adds a line dated today on the other side for the
                            same amount, so the balance is as if it had never been typed and a statement already sent
                            stays as it was.
                            <template v-if="entry.against"> Its adjustment against files is released.</template>
                        </p>

                        <div class="ui-field">
                            <label class="ui-label" for="ps-reason">Why <span class="ui-label__req">*</span></label>
                            <textarea
                                id="ps-reason"
                                ref="reasonBox"
                                v-model="reason"
                                class="ui-textarea"
                                name="reason"
                                rows="2"
                                maxlength="255"
                                required
                                placeholder="e.g. Typed for the wrong customer"></textarea>
                            <div class="ui-hint">Kept for the office. The customer sees only that the entry was reversed.</div>
                        </div>
                    </div>

                    <div class="ps-dialog__foot">
                        <button type="button" class="ui-btn" @click="close">Cancel</button>
                        <button type="submit" name="correct" value="0" class="ui-btn ui-btn--danger" :disabled="blocked">
                            <i class="bi bi-arrow-counterclockwise"></i> Reverse
                        </button>
                        <button type="submit" name="correct" value="1" class="ui-btn ui-btn--primary" :disabled="blocked">
                            <i class="bi bi-pencil-square"></i> Reverse and enter it again
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </div>
</template>

<style>
/* A reversed entry and its reversal stay on the statement, struck through in
   their figures: still there to be read, no longer counting for anything. */
.party-statement tr.is-reversed td,
.party-statement tr.is-reversal td {
    color: var(--n-400);
}

.party-statement tr.is-reversed td .ui-money {
    text-decoration: line-through;
}

.ps-dialog {
    align-items: center;
    background: rgb(15 23 42 / 55%);
    display: flex;
    inset: 0;
    justify-content: center;
    padding: var(--s-4);
    position: fixed;
    z-index: 1060;
}

.ps-dialog__panel {
    background: var(--n-000);
    border-radius: var(--r-lg);
    box-shadow: 0 20px 50px rgb(15 23 42 / 30%);
    max-width: 34rem;
    width: 100%;
}

.ps-dialog__head,
.ps-dialog__foot {
    align-items: center;
    display: flex;
    gap: var(--s-2);
    padding: var(--s-3) var(--s-4);
}

.ps-dialog__head {
    border-bottom: 1px solid var(--n-200);
    justify-content: space-between;
}

.ps-dialog__foot {
    border-top: 1px solid var(--n-200);
    flex-wrap: wrap;
    justify-content: flex-end;
}

.ps-dialog__title {
    font-size: var(--t-lg);
    font-weight: 700;
    margin: 0;
}

.ps-dialog__x {
    background: none;
    border: 0;
    color: var(--n-500);
    cursor: pointer;
}

.ps-dialog__body {
    display: grid;
    gap: var(--s-3);
    padding: var(--s-4);
}

.ps-dialog__entry {
    background: var(--n-050);
    border-radius: var(--r-md);
    padding: var(--s-2) var(--s-3);
}
</style>
