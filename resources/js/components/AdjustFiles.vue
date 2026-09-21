<script setup>
import { money } from '../money';

/*
 * Which files a payment is for: the party's files, an amount against each.
 *
 * Draws the state useAdjust() keeps (resources/js/adjust.js), which belongs to
 * the screen using it — the Entry screen for a payment being typed, the Adjust
 * screen for one already saved. Posts alloc[file][work_file_id] and
 * alloc[file][amount] as part of that screen's form.
 */
const props = defineProps({
    state: { type: Object, required: true },
    title: { type: String, default: 'Adjust against files' },
    // Whether the section may be left alone, as it may on a new entry.
    optional: { type: Boolean, default: true },
    lead: { type: String, default: 'Leave these empty and the payment settles the oldest files first, as before.' },
    // What a covered file is covered by, as this screen can say it.
    coveredBy: { type: String, default: 'money on account' },
    // What happens when the files cannot be fetched, as this screen can say it.
    failedText: { type: String, default: 'The files could not be loaded. The payment can still be saved, on account.' },
});

// Taken apart once, so the template reads each as the ref it is.
const {
    alloc,
    bills,
    billsState,
    showCovered,
    dropped,
    priced,
    partyName,
    coveredCount,
    visibleBills,
    allocated,
    onAccount,
    problem,
    amountOf,
    overOpen,
    overKept,
    keptRows,
    keptOn,
    keptWhy,
    idle,
    keep,
    fillOldest,
    clear,
    full,
} = props.state;
</script>

<template>
    <!-- Optional: left empty, the payment settles the oldest files first. -->
    <section class="adjust">
        <div class="adjust__head">
            <h3 class="adjust__title">
                {{ title }} <span v-if="optional" class="adjust__opt">optional</span>
            </h3>
            <div v-if="bills.length || keptRows.length" class="adjust__tools">
                <button type="button" class="ui-btn ui-btn--sm" :disabled="!priced" @click="fillOldest">
                    <i class="bi bi-sort-down"></i> Fill oldest first
                </button>
                <button type="button" class="ui-btn ui-btn--sm" @click="clear">Clear</button>
            </div>
        </div>

        <p class="ui-hint adjust__lead">{{ lead }}</p>

        <!-- Kept while ticked, so what was asked for can be put away again. -->
        <label v-if="coveredCount > 0 || showCovered" class="adjust__toggle ui-hint">
            <input type="checkbox" v-model="showCovered">
            Also show {{ coveredCount }} {{ coveredCount === 1 ? 'file' : 'files' }} already covered by {{ coveredBy }}
        </label>

        <div v-if="dropped" class="ui-hint adjust__error">
            An amount was against a file that is no longer open, and has been taken off.
        </div>

        <div v-if="billsState === 'loading'" class="ui-hint">Looking up {{ partyName }}'s files…</div>
        <div v-else-if="billsState === 'failed'" class="ui-hint adjust__error">{{ failedText }}</div>
        <div v-else-if="!visibleBills.length && !keptRows.length" class="ui-hint">
            Nothing owed on {{ partyName }}'s files — the payment goes on account.
        </div>

        <div v-else class="adjust__list">
            <!-- Open files first, then the payment's own lines on files that are not. -->
            <div
                v-for="bill in visibleBills"
                :key="bill.id"
                class="adjust__row"
                :class="{ 'is-over': overOpen(bill), 'is-set': amountOf(bill) > 0 }">
                <div class="adjust__file">
                    <a :href="bill.editUrl" target="_blank" rel="noopener" class="ui-link">{{ bill.fileNo }}</a>
                    <span v-if="bill.vehicle" class="adjust__vehicle">{{ bill.vehicle }}</span>
                    <div class="ui-sub">{{ bill.works }} · received {{ bill.received }}</div>
                </div>

                <div class="adjust__figures">
                    <span>Charged {{ money(bill.charged) }}</span>
                    <span v-if="bill.returned > 0">Returned {{ money(bill.returned) }}</span>
                    <span v-if="bill.adjusted > 0">Adjusted {{ money(bill.adjusted) }}</span>
                    <strong>Open {{ money(bill.open) }}</strong>
                    <span v-if="keptOn(bill) > 0" class="ui-sub">This payment has {{ money(keptOn(bill)) }} on it now</span>
                    <span v-if="keptWhy(bill)" class="ui-sub adjust__why">{{ keptWhy(bill) }}</span>
                    <span v-if="bill.due < bill.open - 0.005" class="ui-sub">
                        {{ bill.due > 0.005 ? 'partly' : 'already' }} covered by {{ coveredBy }}
                    </span>
                </div>

                <div class="adjust__amount">
                    <!-- Named only when there is an amount: an empty box is
                         not posted, so a party with hundreds of files
                         does not send hundreds of empty lines. -->
                    <input
                        v-if="amountOf(bill) > 0"
                        type="hidden"
                        :name="`alloc[${bill.id}][work_file_id]`"
                        :value="bill.id">
                    <input
                        type="number"
                        class="ui-input"
                        :class="{ 'ui-input--invalid': overOpen(bill) }"
                        :name="amountOf(bill) > 0 ? `alloc[${bill.id}][amount]` : null"
                        min="0"
                        step="0.01"
                        placeholder="0.00"
                        v-model="alloc[bill.id]"
                        :aria-label="`Amount against ${bill.fileNo}`">
                    <button type="button" class="ui-btn ui-btn--sm" @click="full(bill)">Full</button>
                </div>
            </div>

            <!-- The payment's own lines on files no longer open to it: kept,
                 lowered or let go by the office, never taken off by the page. -->
            <div
                v-for="line in keptRows"
                :key="`kept-${line.id}`"
                class="adjust__row adjust__row--kept"
                :class="{ 'is-over': overKept(line), 'is-set': amountOf(line) > 0 }">
                <div class="adjust__file">
                    <strong>{{ line.fileNo }}</strong>
                    <span v-if="line.vehicle" class="adjust__vehicle">{{ line.vehicle }}</span>
                    <div v-if="line.why" class="ui-sub">{{ line.why }}</div>
                </div>

                <div class="adjust__figures">
                    <span>This payment has {{ money(line.amount) }} on it</span>
                    <strong>Nothing more open</strong>
                </div>

                <div class="adjust__amount">
                    <input
                        v-if="amountOf(line) > 0"
                        type="hidden"
                        :name="`alloc[${line.id}][work_file_id]`"
                        :value="line.id">
                    <input
                        type="number"
                        class="ui-input"
                        :class="{ 'ui-input--invalid': overKept(line) }"
                        :name="amountOf(line) > 0 ? `alloc[${line.id}][amount]` : null"
                        min="0"
                        step="0.01"
                        placeholder="0.00"
                        v-model="alloc[line.id]"
                        :aria-label="`Amount against ${line.fileNo}`">
                    <button type="button" class="ui-btn ui-btn--sm" @click="keep(line)">Keep</button>
                </div>
            </div>
        </div>

        <div v-if="visibleBills.length || keptRows.length" class="adjust__foot" :class="{ 'is-error': problem }">
            <span>Against files <strong>{{ money(allocated) }}</strong></span>
            <span>On account <strong>{{ money(onAccount) }}</strong></span>
            <span v-if="idle > 0.005" class="ui-hint">
                Of the files, {{ money(idle) }} settles nothing and counts as on account while those lines stay as they are.
            </span>
            <span v-if="problem" class="adjust__error">{{ problem }}</span>
        </div>
    </section>
</template>

<style>
/* Every rule hangs off .adjust, the section's own root, and every other class
   is the component's own BEM name: it sits inside other screens' forms and must
   not reach outside itself. */

.adjust {
    border-top: 1px solid var(--n-200);
    padding: var(--s-4);
}

.adjust .adjust__head {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    justify-content: space-between;
}

.adjust .adjust__title {
    font-size: var(--t-base);
    font-weight: 700;
    margin: 0;
}

.adjust .adjust__opt {
    color: var(--n-400);
    font-size: var(--t-xs);
    font-weight: 400;
}

.adjust .adjust__tools {
    display: flex;
    gap: var(--s-2);
}

.adjust .adjust__lead {
    margin: var(--s-1) 0 var(--s-3);
}

.adjust .adjust__toggle {
    align-items: center;
    display: flex;
    gap: var(--s-2);
    margin-bottom: var(--s-2);
}

.adjust .adjust__list {
    border: 1px solid var(--n-200);
    border-radius: var(--r-md);
}

.adjust .adjust__row {
    align-items: center;
    border-bottom: 1px solid var(--n-100);
    display: grid;
    gap: var(--s-2) var(--s-4);
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1.2fr) minmax(0, 1fr);
    padding: var(--s-2) var(--s-3);
}

.adjust .adjust__row:last-child {
    border-bottom: 0;
}

.adjust .adjust__row.is-set {
    background: var(--dr-050);
}

.adjust .adjust__row.is-over {
    background: var(--cr-050);
}

.adjust .adjust__why {
    color: var(--cr-700);
}

.adjust .adjust__row--kept {
    border-left: 3px solid var(--n-300);
}

.adjust .adjust__vehicle {
    font-weight: 700;
    margin-left: var(--s-2);
}

.adjust .adjust__figures {
    display: flex;
    flex-direction: column;
    font-size: var(--t-sm);
}

.adjust .adjust__amount {
    display: flex;
    gap: var(--s-2);
}

.adjust .adjust__amount .ui-input {
    min-width: 0;
    text-align: right;
}

.adjust .adjust__foot {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    justify-content: flex-end;
    margin-top: var(--s-3);
}

.adjust .adjust__error {
    color: var(--cr-700);
    font-weight: 600;
}

/* One file per block on a phone: which file, its figures, then the box. */
@media (max-width: 991.98px) {
    .adjust .adjust__row {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
