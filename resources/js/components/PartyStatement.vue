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
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
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
const adjustLink = ref(null);

/*
 * One press, one reversal. Found in review: a double click sent two; the
 * first reversed the entry and asked for the Entry screen, the second was
 * refused as already reversed, and its answer — the statement — was the page
 * the browser showed. Not disabled inside the submit itself, or the button
 * pressed would not send which of the two it is.
 */
const submitting = ref(false);

// The Change button that opened the dialog, to go back to when it closes.
let opener = null;

async function onAction(row) {
    if (! row?.change || ! row.id) {
        return;
    }

    opener = document.activeElement;
    entry.value = row;
    reason.value = '';
    submitting.value = false;
    await nextTick();
    /*
     * Where a payment's files can be changed, that comes first. Found in
     * review: with the reason box taking the focus and Reverse and enter it
     * again the one bold button, the office reached for a reversal — a new
     * line on the customer's statement — when only the files were wrong.
     */
    (row.adjust_url ? adjustLink.value : reasonBox.value)?.focus();
}

function close() {
    entry.value = null;
    opener?.focus?.();
    opener = null;
}

/*
 * Escape closes it wherever focus is. Found in review: bound to the overlay,
 * it stopped working the moment a click inside the panel moved focus off its
 * fields.
 */
function onKey(event) {
    if (event.key === 'Escape') {
        close();
    }
}

watch(entry, (open) => {
    if (open) {
        document.addEventListener('keydown', onKey);
    } else {
        document.removeEventListener('keydown', onKey);
    }
});

function onSubmit(event) {
    if (submitting.value) {
        event.preventDefault();

        return;
    }

    submitting.value = true;
}

// Brought back from the browser's history, the page can be used again.
function onPageShow(event) {
    if (event.persisted) {
        submitting.value = false;
    }
}

window.addEventListener('pageshow', onPageShow);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('pageshow', onPageShow);
});

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
            <div v-if="entry" class="ps-dialog" @click.self="close">
                <form
                    class="ps-dialog__panel"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="ps-title"
                    :action="target"
                    method="POST"
                    @submit="onSubmit">
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

                        <!-- No money moves, so no reason is asked for. A link,
                             so the reason box below cannot stand in its way. -->
                        <div v-if="entry.adjust_url" class="ps-dialog__adjust">
                            <div>
                                <strong>Only the files it is for are wrong?</strong>
                                <div class="ui-hint">
                                    No money moves and no line is added; only what it is adjusted against changes.
                                </div>
                            </div>
                            <a ref="adjustLink" :href="entry.adjust_url" class="ui-btn ui-btn--primary">
                                <i class="bi bi-diagram-3"></i> Adjust against files
                            </a>
                        </div>

                        <p class="ui-hint">
                            <strong>Amount, date or party wrong?</strong>
                            An entry is never deleted. Reversing it adds a line on the other side for the same amount,
                            dated today — or on the entry's own date, if that is later — so the balance is as if it had
                            never been typed and a statement already sent stays as it was.
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
                                placeholder="e.g. Typed on the wrong account"></textarea>
                            <!-- Said of either party: found in review saying "the
                                 customer" on a vendor's statement. -->
                            <div class="ui-hint">
                                Kept for the office: shown under the reversal on this screen only. Printed, exported and
                                portal statements show only that the entry was reversed.
                            </div>
                        </div>
                    </div>

                    <div class="ps-dialog__foot">
                        <button type="button" class="ui-btn" @click="close">Cancel</button>
                        <button type="submit" name="correct" value="0" class="ui-btn ui-btn--danger" :disabled="blocked || submitting">
                            <i class="bi bi-arrow-counterclockwise"></i> Reverse
                        </button>
                        <button
                            type="submit"
                            name="correct"
                            value="1"
                            class="ui-btn"
                            :class="{ 'ui-btn--primary': !entry.adjust_url }"
                            :disabled="blocked || submitting">
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

/* Scrolls when taller than the screen — a phone held sideways — rather than
   losing its title above the top and its buttons below the bottom. */
.ps-dialog {
    align-items: flex-start;
    background: rgb(15 23 42 / 55%);
    display: flex;
    inset: 0;
    justify-content: center;
    overflow-y: auto;
    padding: var(--s-4);
    position: fixed;
    z-index: 1060;
}

.ps-dialog__panel {
    margin: auto;
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

/* The button drops under the words on a phone rather than squeezing them to a
   word a line. */
.ps-dialog__adjust {
    align-items: center;
    border: 1px solid var(--n-200);
    border-radius: var(--r-md);
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-3);
    justify-content: space-between;
    padding: var(--s-2) var(--s-3);
}

.ps-dialog__adjust > div {
    flex: 1 1 14rem;
}

.ps-dialog__entry {
    background: var(--n-050);
    border-radius: var(--r-md);
    padding: var(--s-2) var(--s-3);
}
</style>
