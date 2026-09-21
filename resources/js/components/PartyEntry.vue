<script setup>
/*
 * A single ledger entry: one party, one side, one amount.
 *
 * The field names are the ones PartyController::entry() already validates, so
 * the form still posts normally and the server still checks every value. That
 * is what makes it safe to convert a screen on a live ledger: only the
 * rendering moves, the money logic does not.
 *
 * The summary beside the form is the reason this screen is interactive at all.
 * An entry is only ever typed against a balance, and the figure the person at
 * the counter is asked about is the one the party is left with — so it is
 * worked out as the amount is typed, not after saving.
 */
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { balance, money, side } from '../money';

const props = defineProps({
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    label: { type: String, required: true },
    indexUrl: { type: String, required: true },
    statementUrl: { type: String, required: true },
    sideHint: { type: String, default: '' },
    parties: { type: Array, default: () => [] },
    paymentModes: { type: Array, default: () => [] },
    dateField: { type: String, required: true },
    initial: { type: Object, required: true },

    /*
     * Adjusting a payment against files: which of the party's files it is
     * for. Only on the payment side — a customer's Credit, a vendor's Debit —
     * and only once the database has what it needs (adjustable).
     */
    adjustable: { type: Boolean, default: false },
    paymentSide: { type: String, default: 'credit' },
    // The party's open files, with __ID__ for the party.
    billsUrl: { type: String, default: '' },
    // A refused save's amounts, by file, to be put back.
    initialAlloc: { type: Object, default: () => ({}) },
});

const entry = reactive({ ...props.initial });

// Ids arrive as numbers and a form field is always a string, so the comparison
// is made on one type rather than left to ==.
const selected = computed(
    () => props.parties.find((party) => String(party.id) === entry.party_id) ?? null
);

const currentBalance = computed(() => Number(selected.value?.current_balance) || 0);

// Credit lowers what the party owes, debit raises it. Anything that is not an
// explicit credit counts as a debit, which is how the radio group was read
// before as well.
const signed = computed(
    () => (Number(entry.amount) || 0) * (entry.entry_type === 'credit' ? -1 : 1)
);

const after = computed(() => currentBalance.value + signed.value);

const priced = computed(() => Number(entry.amount) > 0);

/*
 * __ID__ is replaced here rather than built by string concatenation, so the URL
 * always matches whatever the route actually generates.
 */
const statementHref = computed(() =>
    selected.value ? props.statementUrl.replace('__ID__', String(selected.value.id)) : '#'
);

const touched = computed(() =>
    Object.keys(props.initial).some((field) => entry[field] !== props.initial[field])
);

const hint = computed(() => {
    if (!selected.value) {
        return `Pick a ${props.label.toLowerCase()} to see where the entry lands.`;
    }

    if (!priced.value) {
        return 'Enter an amount.';
    }

    const verb = entry.entry_type === 'credit' ? 'Credits' : 'Debits';

    return `${verb} ${money(entry.amount)} — ${selected.value.name} ends on ${balance(after.value)}.`;
});

/* ---- Adjusting against files ------------------------------------------- */

const alloc = reactive({ ...props.initialAlloc });
const bills = ref([]);
const billsState = ref('idle');

// Files already covered by money on account, listed only when asked for.
const covered = ref(0);
const showCovered = ref(false);

const showAdjust = computed(() =>
    props.adjustable && Boolean(selected.value) && entry.entry_type === props.paymentSide
);

/*
 * The party's open files, fetched when one is picked. A slow answer for a
 * party since changed is thrown away rather than shown under the wrong name.
 */
let asked = 0;

async function loadBills() {
    bills.value = [];

    if (! showAdjust.value || ! props.billsUrl) {
        billsState.value = 'idle';

        return;
    }

    const ticket = ++asked;
    billsState.value = 'loading';

    try {
        const url = props.billsUrl.replace('__ID__', String(selected.value.id)) + (showCovered.value ? '?all=1' : '');

        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (! response.ok) {
            throw new Error(String(response.status));
        }

        const data = await response.json();

        if (ticket === asked) {
            bills.value = data.bills ?? [];
            covered.value = Number(data.covered) || 0;
            billsState.value = 'ready';
        }
    } catch {
        if (ticket === asked) {
            billsState.value = 'failed';
        }
    }
}

/*
 * Set while Reset is putting the page back, so the party changing back is
 * not taken for a new party whose amounts must go. Found in review: Reset
 * after picking another party lost the amounts it had just put back.
 */
let resetting = false;

watch(() => [entry.party_id, entry.entry_type], (now, before) => {
    // Another party's files are not this one's: what was typed against them goes.
    if (before && now[0] !== before[0] && ! resetting) {
        for (const key of Object.keys(alloc)) {
            delete alloc[key];
        }

        showCovered.value = false;
    }

    loadBills();
}, { immediate: true });

watch(showCovered, () => loadBills());

const amountOf = (bill) => Number(alloc[bill.id]) || 0;

const allocated = computed(() => bills.value.reduce((sum, bill) => sum + amountOf(bill), 0));

const onAccount = computed(() => Math.max(0, (Number(entry.amount) || 0) - allocated.value));

const overAllocated = computed(() => allocated.value > (Number(entry.amount) || 0) + 0.005);

const overOpen = (bill) => amountOf(bill) > Number(bill.open) + 0.005;

// Said before Save is pressed; the server refuses the same, and keeps the typing.
const adjustProblem = computed(() => {
    if (! showAdjust.value) {
        return '';
    }

    const over = bills.value.filter(overOpen);

    if (over.length) {
        return `${over.map((bill) => bill.fileNo).join(', ')}: more than is open on the file.`;
    }

    if (overAllocated.value) {
        return `The files come to ${money(allocated.value)}, more than the payment of ${money(entry.amount)}.`;
    }

    return '';
});

/*
 * Oldest first, which is what the payment would do if nobody said: against
 * what is still due, in the order the ledger reaches the files. Found in
 * review: filled against what was open, it put the payment on files already
 * paid by money on account, and the customer's receipt named them.
 */
function fillOldest() {
    let left = Number(entry.amount) || 0;

    for (const bill of bills.value) {
        const take = Math.min(Number(bill.due), left);
        alloc[bill.id] = take > 0.005 ? take.toFixed(2) : '';
        left -= Math.max(0, take);
    }
}

function clearAlloc() {
    for (const bill of bills.value) {
        alloc[bill.id] = '';
    }
}

function full(bill) {
    alloc[bill.id] = Number(bill.open).toFixed(2);
}

const dateBox = ref(null);

/*
 * The browser's own reset is cancelled and done here instead.
 *
 * Vue writes a field's value as a property, not an attribute, so every control
 * this component renders has an empty default: a native reset would clear the
 * form rather than put back the values the page loaded with, and would leave
 * the boxes disagreeing with the state driving the summary.
 */
function onReset() {
    resetting = true;
    Object.assign(entry, props.initial);

    for (const key of Object.keys(alloc)) {
        delete alloc[key];
    }

    Object.assign(alloc, props.initialAlloc);
    resetDateField();

    // After the watcher has seen the party change back.
    nextTick(() => {
        resetting = false;
    });
}

/*
 * The date field is server-rendered markup with real value attributes, so it is
 * put back to those and then told to re-read itself: the picker keeps the
 * hidden Y-m-d in step with the typed text, and only it knows how.
 * See assets/js/datepicker.js.
 */
function resetDateField() {
    const display = dateBox.value?.querySelector('.js-datefield');
    const hidden = dateBox.value?.querySelector('input[type="hidden"]');

    if (!display || !hidden) {
        return;
    }

    display.value = display.defaultValue;
    hidden.value = hidden.defaultValue;
    display.dispatchEvent(new Event('input', { bubbles: true }));
}
</script>

<template>
    <div class="ui party-entry">
        <form class="ui-card entry-form" :action="action" method="POST" @reset.prevent="onReset">
            <!-- Rendered here rather than passed as a slot: the component is
                 mounted onto a bare element, so there is no server markup to
                 slot in. -->
            <input type="hidden" name="_token" :value="csrf">

            <div class="ui-card__head">
                <h2 class="ui-card__title">New Ledger Entry</h2>
                <a :href="indexUrl" class="ui-btn ui-btn--sm">
                    <i class="bi bi-list-ul"></i> All {{ label }}s
                </a>
            </div>

            <div class="ui-card__body entry-grid">
                <div class="ui-field">
                    <label class="ui-label" for="party_id">
                        {{ label }} <span class="ui-label__req">*</span>
                    </label>
                    <select
                        id="party_id"
                        class="ui-select"
                        name="party_id"
                        v-model="entry.party_id"
                        required
                        autofocus>
                        <option value="">Select {{ label.toLowerCase() }}</option>
                        <option v-for="party in parties" :key="party.id" :value="String(party.id)">
                            {{ party.name }} ({{ party.mobile }})
                        </option>
                    </select>
                    <div v-if="selected" class="ui-hint">
                        Standing at
                        <span class="ui-money" :class="`ui-money--${side(currentBalance)}`">
                            {{ balance(currentBalance) }}
                        </span>
                    </div>
                </div>

                <div class="ui-field">
                    <span class="ui-label">Entry Type <span class="ui-label__req">*</span></span>
                    <div class="side-pick">
                        <input
                            type="radio"
                            id="entry_debit"
                            name="entry_type"
                            value="debit"
                            v-model="entry.entry_type"
                            required>
                        <label for="entry_debit" class="side-pick__dr">Debit (Dr)</label>

                        <input
                            type="radio"
                            id="entry_credit"
                            name="entry_type"
                            value="credit"
                            v-model="entry.entry_type"
                            required>
                        <label for="entry_credit" class="side-pick__cr">Credit (Cr)</label>
                    </div>
                    <div class="ui-hint">{{ sideHint }}</div>
                </div>

                <div class="ui-field">
                    <label class="ui-label" for="txn_date_display">
                        Transaction Date <span class="ui-label__req">*</span>
                    </label>
                    <!-- The shared Blade partial, dropped in exactly as the
                         server rendered it. The picker binds every .js-datefield
                         on DOMContentLoaded and the Vue bundle is a deferred
                         module, so this markup is already in the page by then;
                         nothing here re-renders it afterwards. -->
                    <div ref="dateBox" class="entry-date" v-html="dateField"></div>
                </div>

                <div class="ui-field">
                    <label class="ui-label" for="amount">Amount <span class="ui-label__req">*</span></label>
                    <div class="entry-money">
                        <span class="entry-money__unit">INR</span>
                        <input
                            type="number"
                            id="amount"
                            class="ui-input ui-input--amount"
                            name="amount"
                            min="0.01"
                            step="0.01"
                            v-model="entry.amount"
                            placeholder="0.00"
                            required>
                    </div>
                    <div v-if="selected && priced" class="ui-hint">
                        Leaves
                        <span class="ui-money" :class="`ui-money--${side(after)}`">{{ balance(after) }}</span>
                    </div>
                </div>

                <div class="ui-field">
                    <label class="ui-label" for="payment_mode">Payment Mode</label>
                    <select id="payment_mode" class="ui-select" name="payment_mode" v-model="entry.payment_mode">
                        <option value="">Not specified</option>
                        <option v-for="mode in paymentModes" :key="mode" :value="mode">{{ mode }}</option>
                    </select>
                </div>

                <div class="ui-field">
                    <label class="ui-label" for="ref_no">Bill / Reference No.</label>
                    <input
                        type="text"
                        id="ref_no"
                        class="ui-input"
                        name="ref_no"
                        v-model="entry.ref_no"
                        maxlength="50"
                        placeholder="Optional">
                </div>

                <div class="ui-field entry-grid__wide">
                    <label class="ui-label" for="particular">
                        Particulars <span class="ui-label__req">*</span>
                    </label>
                    <textarea
                        id="particular"
                        class="ui-textarea"
                        name="particular"
                        rows="3"
                        maxlength="255"
                        v-model="entry.particular"
                        required></textarea>
                </div>
            </div>

            <!-- Which files this payment is for. Optional: left empty, the
                 payment settles the oldest files first, as it always has. -->
            <section v-if="showAdjust" class="adjust">
                <div class="adjust__head">
                    <h3 class="adjust__title">
                        Adjust against files <span class="adjust__opt">optional</span>
                    </h3>
                    <div v-if="bills.length" class="adjust__tools">
                        <button type="button" class="ui-btn ui-btn--sm" :disabled="!priced" @click="fillOldest">
                            <i class="bi bi-sort-down"></i> Fill oldest first
                        </button>
                        <button type="button" class="ui-btn ui-btn--sm" @click="clearAlloc">Clear</button>
                    </div>
                </div>

                <p class="ui-hint adjust__lead">
                    Leave these empty and the payment settles the oldest files first, as before.
                </p>

                <label v-if="covered > 0 || showCovered" class="adjust__toggle ui-hint">
                    <input type="checkbox" v-model="showCovered">
                    Also show {{ covered }} {{ covered === 1 ? 'file' : 'files' }} already covered by money on account
                </label>

                <div v-if="billsState === 'loading'" class="ui-hint">Looking up {{ selected.name }}'s files…</div>
                <div v-else-if="billsState === 'failed'" class="ui-hint adjust__error">
                    The files could not be loaded. The payment can still be saved, on account.
                </div>
                <div v-else-if="!bills.length" class="ui-hint">
                    Nothing owed on {{ selected.name }}'s files — the payment goes on account.
                </div>

                <div v-else class="adjust__list">
                    <div
                        v-for="bill in bills"
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
                            <span v-if="bill.due < bill.open - 0.005" class="ui-sub">
                                {{ bill.due > 0.005 ? 'partly' : 'already' }} covered by money on account
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
                </div>

                <div v-if="bills.length" class="adjust__foot" :class="{ 'is-error': adjustProblem }">
                    <span>Against files <strong>{{ money(allocated) }}</strong></span>
                    <span>On account <strong>{{ money(onAccount) }}</strong></span>
                    <span v-if="adjustProblem" class="adjust__error">{{ adjustProblem }}</span>
                </div>
            </section>

            <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': touched }">
                <span class="ui-hint">{{ hint }}</span>
                <div class="foot-actions">
                    <button type="reset" class="ui-btn">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                    </button>
                    <button type="submit" class="ui-btn ui-btn--primary" :disabled="Boolean(adjustProblem)">
                        <i class="bi bi-check2-circle"></i> Save Entry
                    </button>
                </div>
            </div>
        </form>

        <aside class="entry-side">
            <div class="ui-card entry-summary">
                <div class="ui-card__head">
                    <h2 class="ui-card__title">Entry Summary</h2>
                </div>
                <div class="ui-card__body">
                    <div class="sum-row">
                        <span class="sum-row__label">{{ label }}</span>
                        <span class="sum-row__value">
                            {{ selected ? `${selected.name} (${selected.mobile})` : 'Not selected' }}
                        </span>
                    </div>
                    <div class="sum-row">
                        <span class="sum-row__label">Current Balance</span>
                        <span class="sum-row__value ui-money" :class="`ui-money--${side(currentBalance)}`">
                            {{ balance(currentBalance) }}
                        </span>
                    </div>
                    <div class="sum-row">
                        <span class="sum-row__label">This Entry</span>
                        <span class="sum-row__value ui-money" :class="`ui-money--${side(signed)}`">
                            {{ balance(signed) }}
                        </span>
                    </div>
                    <div class="sum-row sum-row--total">
                        <span class="sum-row__label">Balance After</span>
                        <span class="sum-row__value ui-money" :class="`ui-money--${side(after)}`">
                            {{ balance(after) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Only worth offering once there is a party to open it for. -->
            <div v-if="selected" class="ui-card entry-statement">
                <div class="ui-card__body">
                    <h2 class="ui-card__title">Statement</h2>
                    <a :href="statementHref" class="ui-btn entry-statement__link">
                        <i class="bi bi-file-earmark-text"></i> Open full statement
                    </a>
                </div>
            </div>
        </aside>
    </div>
</template>

<style>
/* Every rule hangs off the component's own root: these class names are plain
   enough that another screen could reasonably use them, and a converted screen
   must not reach outside itself. */

.party-entry {
    align-items: start;
    display: grid;
    gap: var(--s-4);
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
}

.party-entry .entry-grid {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.party-entry .entry-grid__wide {
    grid-column: 1 / -1;
}

.party-entry .entry-side {
    display: flex;
    flex-direction: column;
    gap: var(--s-4);
    /* The balance being typed against stays on screen while the form is filled.
       The offset clears the template's fixed header, which is 60px tall. */
    position: sticky;
    top: calc(60px + var(--s-4));
}

/* ---- Debit / credit ---------------------------------------------------- */

.party-entry .side-pick {
    display: flex;
    gap: var(--s-2);
}

.party-entry .side-pick input {
    opacity: 0;
    pointer-events: none;
    position: absolute;
}

.party-entry .side-pick label {
    align-items: center;
    border: 1px solid var(--n-300);
    border-radius: var(--r-sm);
    cursor: pointer;
    display: flex;
    flex: 1;
    font-weight: 700;
    justify-content: center;
    min-height: 40px;
    padding: 0 var(--s-3);
}

.party-entry .side-pick input:checked + label.side-pick__dr {
    background: var(--dr-050);
    border-color: var(--dr-600);
    color: var(--dr-700);
}

.party-entry .side-pick input:checked + label.side-pick__cr {
    background: var(--cr-050);
    border-color: var(--cr-600);
    color: var(--cr-700);
}

/* The radio itself is off screen, so the ring has to go on what is on screen —
   otherwise tabbing through the form loses its place here. */
.party-entry .side-pick input:focus-visible + label {
    outline: 2px solid var(--brand-500);
    outline-offset: 2px;
}

/* ---- Amount ------------------------------------------------------------ */

.party-entry .entry-money {
    display: flex;
}

.party-entry .entry-money__unit {
    align-items: center;
    background: var(--n-050);
    border: 1px solid var(--n-300);
    border-radius: var(--r-sm) 0 0 var(--r-sm);
    border-right: 0;
    color: var(--n-500);
    display: flex;
    font-size: var(--t-xs);
    font-weight: 700;
    padding: 0 var(--s-3);
}

.party-entry .entry-money .ui-input {
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
}

/* ---- Date -------------------------------------------------------------- */

/* The partial carries the older Bootstrap classes. Only its shell is restated
   in tokens so it sits level with the fields beside it; the picker's own markup
   and behaviour are left alone. */
.party-entry .entry-date .form-control,
.party-entry .entry-date .input-group-text {
    border-color: var(--n-300);
    border-radius: var(--r-sm);
    min-height: 40px;
}

.party-entry .entry-date .input-group > .form-control {
    border-bottom-left-radius: 0;
    border-top-left-radius: 0;
}

.party-entry .entry-date .input-group-text {
    background: var(--n-050);
    border-right: 0;
    color: var(--n-500);
    justify-content: center;
    min-width: 2.5rem;
}

.party-entry .entry-date .form-control:focus {
    border-color: var(--brand-500);
    box-shadow: var(--ring);
}

@media (pointer: coarse) {
    .party-entry .entry-date .form-control,
    .party-entry .entry-date .input-group-text,
    .party-entry .side-pick label {
        min-height: var(--tap);
    }
}

/* ---- Summary ----------------------------------------------------------- */

.party-entry .entry-summary {
    border-top: 3px solid var(--brand-500);
}

.party-entry .sum-row {
    align-items: baseline;
    border-bottom: 1px solid var(--n-100);
    display: flex;
    gap: var(--s-3);
    justify-content: space-between;
    padding: var(--s-3) 0;
}

.party-entry .sum-row:first-child {
    padding-top: 0;
}

.party-entry .sum-row:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.party-entry .sum-row__label {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
}

.party-entry .sum-row__value {
    color: var(--n-900);
    font-weight: 700;
    text-align: right;
}

.party-entry .sum-row--total .sum-row__value {
    font-size: var(--t-lg);
}

.party-entry .entry-statement .ui-card__title {
    margin-bottom: var(--s-3);
}

.party-entry .entry-statement__link {
    width: 100%;
}

.party-entry .foot-actions {
    display: flex;
    gap: var(--s-2);
}

/* ---- Adjust against files ---------------------------------------------- */

.party-entry .adjust {
    border-top: 1px solid var(--n-200);
    padding: var(--s-4);
}

.party-entry .adjust__head {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    justify-content: space-between;
}

.party-entry .adjust__title {
    font-size: var(--t-base);
    font-weight: 700;
    margin: 0;
}

.party-entry .adjust__opt {
    color: var(--n-400);
    font-size: var(--t-xs);
    font-weight: 400;
}

.party-entry .adjust__tools {
    display: flex;
    gap: var(--s-2);
}

.party-entry .adjust__lead {
    margin: var(--s-1) 0 var(--s-3);
}

.party-entry .adjust__toggle {
    align-items: center;
    display: flex;
    gap: var(--s-2);
    margin-bottom: var(--s-2);
}

.party-entry .adjust__list {
    border: 1px solid var(--n-200);
    border-radius: var(--r-md);
}

.party-entry .adjust__row {
    align-items: center;
    border-bottom: 1px solid var(--n-100);
    display: grid;
    gap: var(--s-2) var(--s-4);
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1.2fr) minmax(0, 1fr);
    padding: var(--s-2) var(--s-3);
}

.party-entry .adjust__row:last-child {
    border-bottom: 0;
}

.party-entry .adjust__row.is-set {
    background: var(--dr-050);
}

.party-entry .adjust__row.is-over {
    background: var(--cr-050);
}

.party-entry .adjust__vehicle {
    font-weight: 700;
    margin-left: var(--s-2);
}

.party-entry .adjust__figures {
    display: flex;
    flex-direction: column;
    font-size: var(--t-sm);
}

.party-entry .adjust__amount {
    display: flex;
    gap: var(--s-2);
}

.party-entry .adjust__amount .ui-input {
    min-width: 0;
    text-align: right;
}

.party-entry .adjust__foot {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    justify-content: flex-end;
    margin-top: var(--s-3);
}

.party-entry .adjust__error {
    color: var(--cr-700);
    font-weight: 600;
}

/* Below the large breakpoint the two columns stack and every field takes the
   full width: this is a form filled one box at a time on a phone at a counter,
   and each summary line becomes its own labelled block rather than a pair
   squeezed onto one line. */
@media (max-width: 991.98px) {
    .party-entry,
    .party-entry .entry-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .party-entry .entry-side {
        position: static;
    }

    .party-entry .sum-row {
        align-items: stretch;
        flex-direction: column;
        gap: var(--s-1);
    }

    .party-entry .sum-row__value {
        text-align: left;
    }

    .party-entry .foot-actions {
        flex: 1 1 auto;
    }

    .party-entry .foot-actions .ui-btn {
        flex: 1 1 auto;
    }

    /* One file per block on a phone: which file, its figures, then the box. */
    .party-entry .adjust__row {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
