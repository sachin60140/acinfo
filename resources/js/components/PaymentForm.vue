<script setup>
/*
 * A payment out to a client: one client, one mode, one amount.
 *
 * This screen predates the party tables and still writes to client_ledger, so
 * the field names are the ones AuthController::ledgerEntry() already validates
 * and the sign is still the server's to apply — the form posts normally and the
 * server checks every value. That is what makes it safe to convert a screen on a
 * live ledger: only the rendering moves, the money logic does not.
 *
 * The summary beside the form is the reason this screen is interactive at all.
 * A payment is only ever entered against a balance, so the balance appears as
 * the client is picked rather than being looked up on another screen first.
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
    errors: { type: Object, default: () => ({}) },
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
 * Negated deliberately, for the same reason the client statement negates it: a
 * receipt credits the client and client_ledger stores it positive, so a positive
 * sum is money held for the client — a credit — while balance() prints a
 * negative as Cr. Passing the raw figure would name every side backwards.
 */
const currentBalance = computed(() => -(Number(selected.value?.current_balance) || 0));

const amount = computed(() => Number(entry.amount) || 0);

const dateBox = ref(null);
const clientField = ref(null);

/*
 * The date on the summary is read back off the visible box rather than held in
 * state. assets/js/datepicker.js owns that field, and it is the only thing that
 * knows what the typed digits or the picked day came to.
 */
const txnDate = ref('');

function syncDate() {
    txnDate.value = dateBox.value?.querySelector('.js-datefield')?.value ?? '';
}

onMounted(() => {
    // The autofocus attribute is only reliable for markup the parser saw, and
    // this form is inserted after that. It matters on a re-render too: the
    // client is where you start whether the page is fresh or has just bounced
    // back.
    clientField.value?.focus();

    syncDate();

    /*
     * Typing bubbles, and datepicker.js fires a bubbling change of its own when
     * a day is picked or cleared, so one pair of listeners on the wrapper
     * catches every way the date can move — and nothing here binds to the field
     * itself, which has to stay the picker's alone.
     */
    dateBox.value?.addEventListener('input', syncDate);
    dateBox.value?.addEventListener('change', syncDate);
});

/*
 * The browser's own reset is cancelled and done here instead.
 *
 * Vue writes a field's value as a property, not an attribute, so every control
 * this component renders has an empty default: a native reset would clear the
 * form rather than put back the values the page loaded with — including a
 * rejected submission's own — and would leave the boxes disagreeing with the
 * state driving the summary.
 */
function onReset() {
    Object.assign(entry, props.initial);
    resetDateField();
}

/*
 * The date field is server-rendered markup with real value attributes, so it is
 * put back to those and then told to re-read itself: the picker keeps the hidden
 * Y-m-d in step with the typed text, and only it knows how. The input event it
 * is handed bubbles, so the summary follows without being told separately.
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
    <div class="ui payment-form">
        <form id="payment_form" class="ui-card pay-card" :action="action" method="POST" @reset.prevent="onReset">
            <!-- Rendered here rather than passed as a slot: the component is
                 mounted onto a bare element, so there is no server markup to
                 slot in. -->
            <input type="hidden" name="_token" :value="csrf">

            <div class="ui-card__head">
                <h2 class="ui-card__title">Payment Details</h2>
                <a :href="clientsUrl" class="ui-btn ui-btn--sm">
                    <i class="bi bi-people"></i> Clients
                </a>
            </div>

            <div class="ui-card__body pay-grid">
                <div class="ui-field">
                    <label class="ui-label" for="client_name">Client <span class="ui-label__req">*</span></label>
                    <div class="pay-affix">
                        <span class="pay-affix__tag"><i class="bi bi-person"></i></span>
                        <select
                            id="client_name"
                            ref="clientField"
                            class="ui-select"
                            :class="{ 'ui-select--invalid': errors.client_name }"
                            name="client_name"
                            v-model="entry.client_name"
                            required
                            autofocus>
                            <option value="">Select client ledger</option>
                            <option v-for="client in clients" :key="client.id" :value="String(client.id)">
                                {{ client.name }}
                            </option>
                        </select>
                    </div>
                    <div class="pay-chip">
                        Current balance:
                        <span class="ui-money" :class="`ui-money--${side(currentBalance)}`">
                            INR {{ balance(currentBalance) }}
                        </span>
                    </div>
                    <div v-if="errors.client_name" class="ui-hint ui-hint--error">{{ errors.client_name }}</div>
                </div>

                <div class="ui-field">
                    <label class="ui-label" for="paymentMode">Payment Mode <span class="ui-label__req">*</span></label>
                    <div class="pay-affix">
                        <span class="pay-affix__tag"><i class="bi bi-credit-card"></i></span>
                        <select
                            id="paymentMode"
                            class="ui-select"
                            :class="{ 'ui-select--invalid': errors.paymentMode }"
                            name="paymentMode"
                            v-model="entry.paymentMode"
                            required>
                            <option value="">Select payment mode</option>
                            <option v-for="mode in paymentModes" :key="mode.id" :value="String(mode.id)">
                                {{ mode.payment_mode }}
                            </option>
                        </select>
                    </div>
                    <div v-if="errors.paymentMode" class="ui-hint ui-hint--error">{{ errors.paymentMode }}</div>
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
                    <div ref="dateBox" class="pay-date" v-html="dateField"></div>
                    <div v-if="errors.txn_date" class="ui-hint ui-hint--error">{{ errors.txn_date }}</div>
                </div>

                <div class="ui-field">
                    <label class="ui-label" for="amount">Amount <span class="ui-label__req">*</span></label>
                    <div class="pay-affix">
                        <span class="pay-affix__tag">INR</span>
                        <input
                            id="amount"
                            type="number"
                            class="ui-input ui-input--amount"
                            :class="{ 'ui-input--invalid': errors.amount }"
                            name="amount"
                            min="0.01"
                            max="500000.00"
                            step="0.01"
                            v-model="entry.amount"
                            placeholder="0.00"
                            required>
                    </div>
                    <div v-if="errors.amount" class="ui-hint ui-hint--error">{{ errors.amount }}</div>
                </div>

                <div class="ui-field pay-grid__wide">
                    <label class="ui-label" for="remarks">Remarks <span class="ui-label__req">*</span></label>
                    <textarea
                        id="remarks"
                        class="ui-textarea"
                        :class="{ 'ui-input--invalid': errors.remarks }"
                        name="remarks"
                        rows="4"
                        v-model="entry.remarks"
                        required></textarea>
                    <div v-if="errors.remarks" class="ui-hint ui-hint--error">{{ errors.remarks }}</div>
                </div>
            </div>

            <div class="ui-card__foot pay-foot">
                <button type="reset" class="ui-btn">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </button>
                <button type="submit" class="ui-btn pay-save">
                    <i class="bi bi-check2-circle"></i> Save Payment
                </button>
            </div>
        </form>

        <aside class="pay-side">
            <div class="ui-card pay-summary">
                <div class="ui-card__head">
                    <h2 class="ui-card__title">Payment Summary</h2>
                </div>
                <div class="ui-card__body">
                    <div class="sum-row">
                        <span class="sum-row__label">Client</span>
                        <span class="sum-row__value">{{ selected ? selected.name : 'Not selected' }}</span>
                    </div>
                    <div class="sum-row">
                        <span class="sum-row__label">Mode</span>
                        <span class="sum-row__value">
                            {{ selectedMode ? selectedMode.payment_mode : 'Not selected' }}
                        </span>
                    </div>
                    <div class="sum-row">
                        <span class="sum-row__label">Current Balance</span>
                        <span class="sum-row__value ui-money" :class="`ui-money--${side(currentBalance)}`">
                            INR {{ balance(currentBalance) }}
                        </span>
                    </div>
                    <div class="sum-row">
                        <span class="sum-row__label">Date</span>
                        <span class="sum-row__value">{{ txnDate || 'Not selected' }}</span>
                    </div>
                    <div class="sum-row">
                        <span class="sum-row__label">Payment Amount</span>
                        <span class="sum-row__value ui-money pay-preview">INR {{ money(amount) }}</span>
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

.payment-form {
    align-items: start;
    display: grid;
    gap: var(--s-4);
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
}

.payment-form .pay-grid {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.payment-form .pay-grid__wide {
    grid-column: 1 / -1;
}

.payment-form .pay-side {
    /* The balance being paid against stays on screen while the form is filled.
       The offset clears the template's fixed header, which is 60px tall. */
    position: sticky;
    top: calc(60px + var(--s-4));
}

/* An amount is typed as a figure rather than stepped one paisa at a time, and
   the spinner sits exactly where the last digit is read. */
.payment-form input[type="number"]::-webkit-outer-spin-button,
.payment-form input[type="number"]::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

/* ---- Field affixes ----------------------------------------------------- */

/* A leading tag on a field: the currency, the icon that says which of the two
   dropdowns this is. */
.payment-form .pay-affix {
    display: flex;
    min-width: 0;
}

.payment-form .pay-affix__tag {
    align-items: center;
    background: var(--n-050);
    border: 1px solid var(--n-300);
    border-radius: var(--r-sm) 0 0 var(--r-sm);
    border-right: 0;
    color: var(--n-500);
    display: inline-flex;
    flex: 0 0 auto;
    font-size: var(--t-sm);
    font-weight: 600;
    justify-content: center;
    min-width: 2.6rem;
    padding: 0 var(--s-2);
}

.payment-form .pay-affix .ui-input,
.payment-form .pay-affix .ui-select {
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
    min-width: 0;
}

/* The balance the operator is about to pay against, next to the name they just
   picked — the summary is across the page and this is the figure that decides
   whether the amount below is right. The chip is neutral so the colour on the
   figure inside it is the only thing saying which side the client stands on. */
.payment-form .pay-chip {
    align-items: baseline;
    background: var(--n-050);
    border: 1px solid var(--n-200);
    border-radius: var(--r-sm);
    color: var(--n-600);
    display: inline-flex;
    flex-wrap: wrap;
    font-size: var(--t-sm);
    font-weight: 600;
    gap: var(--s-1);
    margin-top: var(--s-1);
    padding: var(--s-2) var(--s-3);
    width: fit-content;
}

/* ---- Date -------------------------------------------------------------- */

/* The partial carries the older Bootstrap classes. Only its shell is restated
   in tokens so it sits level with the fields beside it; the picker's own markup
   and behaviour are left alone. */
.payment-form .pay-date .form-control,
.payment-form .pay-date .input-group-text {
    border-color: var(--n-300);
    border-radius: var(--r-sm);
    min-height: 40px;
}

.payment-form .pay-date .input-group {
    display: flex;
    min-width: 0;
}

.payment-form .pay-date .input-group > .form-control {
    border-bottom-left-radius: 0;
    border-top-left-radius: 0;
    min-width: 0;
}

.payment-form .pay-date .input-group-text {
    background: var(--n-050);
    border-right: 0;
    color: var(--n-500);
    justify-content: center;
    min-width: 2.6rem;
}

.payment-form .pay-date .form-control:focus {
    border-color: var(--brand-500);
    box-shadow: var(--ring);
}

/* ---- Actions ----------------------------------------------------------- */

.payment-form .pay-foot {
    justify-content: flex-end;
}

/* Payment and receipt are the same form twice over, and the colour of this
   button is the quickest way to tell which one is open: red for money going
   out, green on the receipt screen. */
.payment-form .pay-save {
    background: var(--cr-600);
    border-color: var(--cr-600);
    color: var(--n-000);
}

.payment-form .pay-save:hover:not(:disabled) {
    background: var(--cr-700);
    border-color: var(--cr-700);
    color: var(--n-000);
}

/* ---- Summary ----------------------------------------------------------- */

.payment-form .pay-summary {
    border-top: 3px solid var(--cr-600);
}

.payment-form .sum-row {
    align-items: baseline;
    border-bottom: 1px solid var(--n-100);
    display: flex;
    gap: var(--s-3);
    justify-content: space-between;
    padding: var(--s-3) 0;
}

.payment-form .sum-row:first-child {
    padding-top: 0;
}

.payment-form .sum-row:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.payment-form .sum-row__label {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
}

.payment-form .sum-row__value {
    color: var(--n-900);
    font-weight: 700;
    text-align: right;
}

/* The figure the whole screen is about, and the one thing on it worth reading
   from across a counter. */
.payment-form .pay-preview {
    color: var(--cr-700);
    font-size: var(--t-xl);
}

/* Below the large breakpoint the two columns stack and every field takes the
   full width: this is a form filled one box at a time on a phone at a counter.
   There is no table on this screen for the row-to-card rule to convert — the
   same job is done by the grid collapsing, by each field keeping its own label,
   and by every summary line becoming its own labelled block rather than a pair
   squeezed onto one line. */
@media (max-width: 991.98px) {
    .payment-form,
    .payment-form .pay-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .payment-form .pay-side {
        position: static;
    }

    .payment-form .sum-row {
        align-items: stretch;
        flex-direction: column;
        gap: var(--s-1);
    }

    .payment-form .sum-row__value {
        text-align: left;
    }

    .payment-form .pay-foot .ui-btn {
        flex: 1 1 auto;
    }
}

@media (pointer: coarse) {
    .payment-form .pay-date .form-control,
    .payment-form .pay-date .input-group-text,
    .payment-form .pay-affix__tag {
        min-height: var(--tap);
    }
}
</style>
