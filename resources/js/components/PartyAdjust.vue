<script setup>
/*
 * Setting or changing the files a payment already saved is for.
 *
 * The payment itself is shown and not editable: no money moves here, only
 * what it is adjusted against. The files are the party's as they stood when
 * it came in — every other payment's adjustments kept, only money received
 * before it counted as covering them — and start filled in with what it is
 * adjusted against now. See PartyLedgerModel::bills().
 */
import { computed, onBeforeUnmount, ref } from 'vue';
import { money } from '../money';
import { useAdjust } from '../adjust';
import AdjustFiles from './AdjustFiles.vue';

const props = defineProps({
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    label: { type: String, required: true },
    statementUrl: { type: String, required: true },
    // The party's files, with __ID__ for the party.
    billsUrl: { type: String, required: true },
    party: { type: Object, required: true },
    entry: { type: Object, required: true },
    // What it is adjusted against now, by file.
    current: { type: Object, default: () => ({}) },
    // The same, as lines the page can draw when the file is not listed.
    currentLines: { type: Array, default: () => [] },
    // What the boxes start with: the same, or a refused save's amounts.
    initialAlloc: { type: Object, default: () => ({}) },
    // What the page was drawn from, posted back: a save is refused if it has moved.
    drawn: { type: String, required: true },
    // When its files were last changed, by whom, and what it was for before.
    history: { type: String, default: null },
});

const adjust = useAdjust({
    billsUrl: props.billsUrl,
    initialAlloc: props.initialAlloc,
    partyId: () => props.party.id,
    partyName: () => props.party.name,
    amount: () => props.entry.amount,
    active: () => true,
    except: props.entry.id,
    kept: props.currentLines,
});

adjust.loadBills();

// The same set of files for the same amounts, however it was typed.
const plain = (lines) => Object.entries(lines)
    .filter(([, amount]) => Number(amount) > 0)
    .map(([fileId, amount]) => `${Number(fileId)}:${Number(amount).toFixed(2)}`)
    .sort()
    .join('|');

const changed = computed(() => plain(adjust.alloc) !== plain(props.current));

/*
 * Saved only once the files are on screen. The boxes are the form: with the
 * list not loaded nothing would be posted, and nothing posted means "against
 * no file" — every adjustment the payment has would be let go.
 */
const ready = computed(() => adjust.billsState.value === 'ready');

const hint = computed(() => {
    if (adjust.billsState.value === 'failed') {
        return 'The files could not be loaded, so nothing can be changed. Try again in a moment.';
    }

    if (! ready.value) {
        return '';
    }

    if (adjust.problem.value) {
        return adjust.problem.value;
    }

    return changed.value ? '' : 'Nothing changed yet.';
});

/*
 * One press, one save. Not disabled inside the submit itself, or the press
 * would still go but the browser would drop it.
 */
const submitting = ref(false);

function onSubmit(event) {
    if (submitting.value || ! ready.value || adjust.problem.value || ! changed.value) {
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
onBeforeUnmount(() => window.removeEventListener('pageshow', onPageShow));

/*
 * Back to what the page loaded with, and the files read again, so an amount
 * put back against a file no longer open is taken off and said so rather
 * than kept out of sight.
 */
function onReset() {
    adjust.restore(props.initialAlloc);
    adjust.loadBills();
}

const amountText = computed(() => `${money(props.entry.amount)} ${props.entry.side}`);

// What the customer's statement says it is for now.
const nowText = computed(() => (props.currentLines.length
    ? props.currentLines.map((line) => `${line.fileNo}${line.vehicle ? ` ${line.vehicle}` : ''} ${money(line.amount)}`).join(' · ')
    : 'Nothing — on account, settling the oldest files first'));
</script>

<template>
    <div class="ui party-adjust">
        <form class="ui-card" :action="action" method="POST" @submit="onSubmit" @reset.prevent="onReset">
            <input type="hidden" name="_token" :value="csrf">
            <input type="hidden" name="drawn" :value="drawn">

            <div class="ui-card__head">
                <h2 class="ui-card__title">Adjust entry #{{ entry.id }} against files</h2>
                <a :href="statementUrl" class="ui-btn ui-btn--sm">
                    <i class="bi bi-file-earmark-text"></i> Statement
                </a>
            </div>

            <div class="ui-card__body party-adjust__entry">
                <div>
                    <div class="ui-label">{{ label }}</div>
                    <div><strong>{{ party.name }}</strong> <span class="ui-hint">{{ party.mobile }}</span></div>
                </div>
                <div>
                    <div class="ui-label">Payment</div>
                    <div>
                        <strong>{{ amountText }}</strong> · {{ entry.date }}
                        <template v-if="entry.mode"> · {{ entry.mode }}</template>
                        <template v-if="entry.reference"> · {{ entry.reference }}</template>
                    </div>
                    <div class="ui-sub">{{ entry.particular }}</div>
                </div>
                <div class="party-adjust__now">
                    <div class="ui-label">Adjusted against now</div>
                    <div>{{ nowText }}</div>
                    <div v-if="history" class="ui-sub">{{ history }}</div>
                </div>
                <p class="ui-hint party-adjust__note">
                    Only which files this payment is for changes. Its amount, its date and the balance stay as they
                    are, and what it was adjusted against before is kept on record.
                </p>
            </div>

            <AdjustFiles
                :state="adjust"
                title="Files this payment is for"
                :optional="false"
                covered-by="money received before this payment"
                lead="Clear every box to put the whole payment on account; it then settles the oldest files first." />

            <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': changed }">
                <span class="ui-hint" :class="{ 'party-adjust__error': adjust.problem.value }">{{ hint }}</span>
                <div class="party-adjust__actions">
                    <a :href="statementUrl" class="ui-btn">Cancel</a>
                    <button type="reset" class="ui-btn">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                    </button>
                    <button
                        type="submit"
                        class="ui-btn ui-btn--primary"
                        :disabled="submitting || !ready || Boolean(adjust.problem.value) || !changed">
                        <i class="bi bi-check2-circle"></i> Save
                    </button>
                </div>
            </div>
        </form>
    </div>
</template>

<style>
.party-adjust {
    max-width: 60rem;
}

.party-adjust .party-adjust__entry {
    display: grid;
    gap: var(--s-3) var(--s-4);
    grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
}

.party-adjust .party-adjust__now,
.party-adjust .party-adjust__note {
    grid-column: 1 / -1;
    margin: 0;
}

.party-adjust .party-adjust__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2);
}

.party-adjust .party-adjust__error {
    color: var(--cr-700);
    font-weight: 600;
}

@media (max-width: 991.98px) {
    .party-adjust .party-adjust__entry {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
