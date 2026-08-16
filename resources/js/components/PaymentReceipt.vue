<script setup>
/*
 * A receipt against a client: money coming in.
 *
 * The field names are the ones AuthController::paymentreceipt() already
 * validates — client_name, paymentMode, txn_date, amount, remarks — so the form
 * still posts normally and the server still checks every value. That is what
 * makes it safe to convert a screen on a live ledger: only the rendering moves,
 * the money logic does not.
 *
 * The summary beside the form is the reason this screen is interactive at all.
 * A receipt is only ever taken against a balance, and the figure the person at
 * the counter is asked about is the one the client is left standing on — so it
 * is worked out as the amount is typed, not after saving.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { balance, money, side } from '../money';

const props = defineProps({
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    clientsUrl: { type: String, required: true },
    clients: { type: Array, default: () => [] },
    paymentModes: { type: Array, default: () => [] },
    dateField: { type: String, required: true },
    initial: { type: Object, required: true },
});

const entry = reactive({ ...props.initial });

// Ids arrive as numbers and a form field is always a string, so the comparison
// is made on one type rather than left to ==.
const selected = computed(
    () => props.clients.find((client) => String(client.id) === entry.client_name) ?? null
);

const selectedMode = computed(
    () => props.paymentModes.find((mode) => String(mode.id) === entry.paymentMode) ?? null
);

/*
 * The client ledger stores a receipt positive, which is the opposite of the
 * party tables, so a positive sum is money being held for the client — a credit
 * — while balance() prints a negative as Cr. The figure is negated before it is
 * shown, exactly as the client statement does it; printing the raw sum would
 * name every side backwards.
 */
const currentBalance = computed(() => -(Number(selected.value?.current_balance) || 0));

const amount = computed(() => Number(entry.amount) || 0);

// A receipt credits the client, and credit is the negative side here, so taking
// money in moves the balance further Cr.
const after = computed(() => currentBalance.value - amount.value);

const priced = computed(() => amount.value > 0);

const hint = computed(() => {
    if (!selected.value) {
        return 'Pick a client to see where the receipt lands.';
    }

    if (!priced.value) {
        return 'Enter an amount.';
    }

    return `Credits ${money(amount.value)} to ${selected.value.name} — ends on ${balance(after.value)}.`;
});

const clientField = ref(null);
const dateBox = ref(null);

/*
 * The typed date, mirrored out of the picker's own box.
 *
 * It is read from the DOM rather than modelled here because the box is server
 * markup that assets/js/datepicker.js owns — see the template.
 */
const dateText = ref('');
const dateDefault = ref('');

const touched = computed(
    () =>
        Object.keys(props.initial).some((field) => entry[field] !== props.initial[field]) ||
        dateText.value !== dateDefault.value
);

function dateInput() {
    return dateBox.value?.querySelector('.js-datefield') ?? null;
}

function readDate() {
    dateText.value = dateInput()?.value ?? '';
}

function watchDate() {
    const field = dateInput();

    if (!field) {
        return;
    }

    dateDefault.value = field.defaultValue;
    field.addEventListener('input', readDate);
    field.addEventListener('change', readDate);
    readDate();
}

onMounted(() => {
    /*
     * The autofocus attribute is only reliable for markup the parser saw, and
     * this form is inserted after that. It matters on a re-render too: the
     * client is where you start whether the page is fresh or has just bounced
     * back from a rejected submission.
     */
    clientField.value?.focus();

    /*
     * The date listeners wait for DOMContentLoaded so that they are added after
     * the picker's own. The Vue bundle is a deferred module and so runs first,
     * and the picker's input handler is what inserts the dashes as digits are
     * typed — reading the box ahead of it would put "1512" in the summary
     * instead of "15-12-2025". This is the order the inline script this screen
     * used to carry ran in.
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', watchDate, { once: true });
    } else {
        watchDate();
    }
});

/*
 * The browser's own reset is cancelled and done here instead.
 *
 * Vue writes a field's value as a property, not an attribute, so every control
 * this component renders has an empty default: a native reset would clear the
 * form rather than put back the values the page loaded with, and would leave
 * the boxes disagreeing with the summary beside them.
 */
function onReset() {
    Object.assign(entry, props.initial);
    resetDateField();
}

/*
 * The date field is server-rendered markup with real value attributes, so it is
 * put back to those and then told to re-read itself: the picker keeps the
 * hidden Y-m-d in step with the typed text, and only it knows how.
 * See assets/js/datepicker.js.
 */
function resetDateField() {
    const display = dateInput();
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
    <div class="ui receipt-entry">
        <form class="ui-card receipt-form" :action="action" method="POST" @reset.prevent="onReset">
            <!-- Rendered here rather than passed as a slot: the component is
                 mounted onto a bare element, so there is no server markup to
                 slot in. -->
            <input type="hidden" name="_token" :value="csrf">

            <div class="ui-card__head">
                <h2 class="ui-card__title">Receipt Details</h2>
                <a :href="clientsUrl" class="ui-btn ui-btn--sm">
                    <i class="bi bi-people"></i> Clients
                </a>
            </div>

            <div class="ui-card__body receipt-grid">
                <div class="ui-field">
                    <label class="ui-label" for="client_name">Client <span class="ui-label__req">*</span></label>
                    <select
                        id="client_name"
                        ref="clientField"
                        class="ui-select"
                        name="client_name"
                        v-model="entry.client_name"
                        required
                        autofocus>
                        <option value="">Select client ledger</option>
                        <option v-for="client in clients" :key="client.id" :value="String(client.id)">
                            {{ client.name }}
                        </option>
                    </select>
                    <!-- The balance the receipt is being taken against, kept
                         where it was: under the name it belongs to. -->
                    <div class="balance-chip">
                        Current balance
                        <span class="ui-money" :class="`ui-money--${side(currentBalance)}`">
                            {{ balance(currentBalance) }}
                        </span>
                    </div>
                </div>

                <div class="ui-field">
                    <label class="ui-label" for="paymentMode">Payment Mode <span class="ui-label__req">*</span></label>
                    <select
                        id="paymentMode"
                        class="ui-select"
                        name="paymentMode"
                        v-model="entry.paymentMode"
                        required>
                        <option value="">Select payment mode</option>
                        <option v-for="mode in paymentModes" :key="mode.id" :value="String(mode.id)">
                            {{ mode.name }}
                        </option>
                    </select>
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
                        <!-- The bounds are the server's own: gt:0 and max:500000. -->
                        <input
                            id="amount"
                            type="number"
                            class="ui-input ui-input--amount"
                            name="amount"
                            min="0.01"
                            max="500000.00"
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

                <div class="ui-field receipt-grid__wide">
                    <label class="ui-label" for="remarks">Remarks <span class="ui-label__req">*</span></label>
                    <textarea
                        id="remarks"
                        class="ui-textarea"
                        name="remarks"
                        rows="4"
                        v-model="entry.remarks"
                        required></textarea>
                </div>
            </div>

            <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': touched }">
                <span class="ui-hint">{{ hint }}</span>
                <div class="foot-actions">
                    <button type="reset" class="ui-btn">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                    </button>
                    <button type="submit" class="ui-btn ui-btn--primary">
                        <i class="bi bi-check2-circle"></i> Save Receipt
                    </button>
                </div>
            </div>
        </form>

        <aside class="receipt-side">
            <div class="ui-card receipt-summary">
                <div class="ui-card__head">
                    <h2 class="ui-card__title">Receipt Summary</h2>
                </div>
                <div class="ui-card__body">
                    <div class="sum-row">
                        <span class="sum-row__label">Client</span>
                        <span class="sum-row__value">{{ selected ? selected.name : 'Not selected' }}</span>
                    </div>
                    <div class="sum-row">
                        <span class="sum-row__label">Mode</span>
                        <span class="sum-row__value">
                            {{ selectedMode ? selectedMode.name : 'Not selected' }}
                        </span>
                    </div>
                    <div class="sum-row">
                        <span class="sum-row__label">Current Balance</span>
                        <span class="sum-row__value ui-money" :class="`ui-money--${side(currentBalance)}`">
                            {{ balance(currentBalance) }}
                        </span>
                    </div>
                    <div class="sum-row">
                        <span class="sum-row__label">Date</span>
                        <span class="sum-row__value">{{ dateText || 'Not selected' }}</span>
                    </div>
                    <div class="sum-row">
                        <span class="sum-row__label">Amount</span>
                        <span class="sum-row__value ui-money receipt-amount">{{ money(entry.amount) }}</span>
                    </div>
                    <div class="sum-row sum-row--total">
                        <span class="sum-row__label">Balance After</span>
                        <span class="sum-row__value ui-money" :class="`ui-money--${side(after)}`">
                            {{ balance(after) }}
                        </span>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</template>

<style>
/* Every rule hangs off the component's own root: these class names are plain
   enough that another screen could reasonably use them, and a converted screen
   must not reach outside itself. */

.receipt-entry {
    align-items: start;
    display: grid;
    gap: var(--s-4);
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
}

.receipt-entry .receipt-grid {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.receipt-entry .receipt-grid__wide {
    grid-column: 1 / -1;
}

.receipt-entry .receipt-side {
    display: flex;
    flex-direction: column;
    gap: var(--s-4);
    /* The balance being taken against stays on screen while the form is filled.
       The offset clears the template's fixed header, which is 60px tall. */
    position: sticky;
    top: calc(60px + var(--s-4));
}

/* ---- Client ------------------------------------------------------------ */

/* Neutral rather than the old green: the chip carries whichever side the client
   happens to stand on, and a Dr balance in a green box reads as the wrong
   thing. The figure inside is coloured by its own side. */
.receipt-entry .balance-chip {
    align-items: center;
    background: var(--n-050);
    border: 1px solid var(--n-200);
    border-radius: var(--r-sm);
    color: var(--n-500);
    display: inline-flex;
    font-size: var(--t-xs);
    font-weight: 700;
    gap: var(--s-2);
    letter-spacing: 0.04em;
    margin-top: var(--s-1);
    padding: 0.4rem 0.6rem;
    text-transform: uppercase;
}

/* ---- Amount ------------------------------------------------------------ */

.receipt-entry .entry-money {
    display: flex;
}

.receipt-entry .entry-money__unit {
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

.receipt-entry .entry-money .ui-input {
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
}

/* The spinners sit over the last digits of a five-figure amount and are one
   mis-click away from changing it. Carried across from the old page. */
.receipt-entry input::-webkit-outer-spin-button,
.receipt-entry input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

/* ---- Date -------------------------------------------------------------- */

/* The partial carries the older Bootstrap classes. Only its shell is restated
   in tokens so it sits level with the fields beside it; the picker's own markup
   and behaviour are left alone. */
.receipt-entry .entry-date .form-control,
.receipt-entry .entry-date .input-group-text {
    border-color: var(--n-300);
    border-radius: var(--r-sm);
    min-height: 40px;
}

.receipt-entry .entry-date .input-group > .form-control {
    border-bottom-left-radius: 0;
    border-top-left-radius: 0;
}

.receipt-entry .entry-date .input-group-text {
    background: var(--n-050);
    border-right: 0;
    color: var(--n-500);
    justify-content: center;
    min-width: 2.5rem;
}

.receipt-entry .entry-date .form-control:focus {
    border-color: var(--brand-500);
    box-shadow: var(--ring);
}

@media (pointer: coarse) {
    .receipt-entry .entry-date .form-control,
    .receipt-entry .entry-date .input-group-text {
        min-height: var(--tap);
    }
}

/* ---- Summary ----------------------------------------------------------- */

/* Green along the top, as this screen has always had: money coming in. The
   payment screen is the same layout in red, and at a counter the colour is what
   tells the two apart before the heading is read. */
.receipt-entry .receipt-summary {
    border-top: 3px solid var(--dr-600);
}

.receipt-entry .sum-row {
    align-items: baseline;
    border-bottom: 1px solid var(--n-100);
    display: flex;
    gap: var(--s-3);
    justify-content: space-between;
    padding: var(--s-3) 0;
}

.receipt-entry .sum-row:first-child {
    padding-top: 0;
}

.receipt-entry .sum-row:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.receipt-entry .sum-row__label {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
}

.receipt-entry .sum-row__value {
    font-weight: 700;
    text-align: right;
}

/* Only the values that are not money take the plain ink: a rule on the row
   would outrank .ui-money--dr / --cr, and the side a balance falls on would be
   the one thing on this panel that lost its colour. */
.receipt-entry .sum-row__value:not(.ui-money) {
    color: var(--n-900);
}

/* The one figure being typed, and the reason the operator is on this screen. */
.receipt-entry .receipt-amount {
    color: var(--dr-700);
    font-size: var(--t-lg);
}

.receipt-entry .sum-row--total .sum-row__value {
    font-size: var(--t-lg);
}

.receipt-entry .foot-actions {
    display: flex;
    gap: var(--s-2);
}

/* Below the large breakpoint the two columns stack and every field takes the
   full width: this is a form filled one box at a time on a phone at a counter,
   and each summary line becomes its own labelled block rather than a pair
   squeezed onto one line. */
@media (max-width: 991.98px) {
    .receipt-entry,
    .receipt-entry .receipt-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .receipt-entry .receipt-side {
        position: static;
    }

    .receipt-entry .sum-row {
        align-items: stretch;
        flex-direction: column;
        gap: var(--s-1);
    }

    .receipt-entry .sum-row__value {
        text-align: left;
    }

    .receipt-entry .foot-actions {
        flex: 1 1 auto;
    }

    .receipt-entry .foot-actions .ui-btn {
        flex: 1 1 auto;
    }
}
</style>
