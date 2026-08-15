<script setup>
/*
 * Add or edit a customer or vendor.
 *
 * Field names are the ones PartyController::create() and ::edit() already
 * validate, so the form still posts normally and the server still checks every
 * value. That is what makes it safe to convert a screen on a live ledger: only
 * the rendering moves.
 *
 * The opening balance belongs to the add form alone. It is written as the first
 * line of the statement, and by the time anyone opens the edit form there are
 * usually entries sitting on top of it — so it is corrected as an entry, not by
 * quietly restating the ledger from underneath.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { balance, side } from '../money';

const props = defineProps({
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    label: { type: String, required: true },
    indexUrl: { type: String, required: true },
    isEdit: { type: Boolean, default: false },
    values: { type: Object, default: () => ({}) },
    isActive: { type: Boolean, default: true },
    defaultOpeningType: { type: String, default: 'debit' },
    dateField: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});

const form = reactive({
    name: props.values.name ?? '',
    mobile: props.values.mobile ?? '',
    whatsapp: props.values.whatsapp ?? '',
    address: props.values.address ?? '',
    is_active: props.isActive,
    opening_balance: props.values.opening_balance ?? '',
    opening_type: props.values.opening_type || props.defaultOpeningType,
});

/*
 * Ticked when the two numbers already match, which is how the form comes back
 * from a rejected submission: the browser restores both values, but nothing
 * else remembers that the one was mirroring the other.
 */
const sameAsMobile = ref(form.mobile !== '' && form.mobile === form.whatsapp);

const lower = computed(() => props.label.toLowerCase());

/*
 * Both numbers are ten digits, so anything else is dropped as it is typed.
 * The element's own value is corrected alongside the model: a rejected
 * character leaves the model unchanged, Vue has nothing to re-render, and the
 * character would otherwise sit in the box.
 */
function onDigits(field, event) {
    const digits = event.target.value.replace(/\D/g, '').slice(0, 10);

    form[field] = digits;
    event.target.value = digits;

    if (field === 'mobile') {
        mirrorWhatsapp();
    }
}

function mirrorWhatsapp() {
    if (sameAsMobile.value) {
        form.whatsapp = form.mobile;
    }
}

/*
 * The flag is set from the event rather than left to a v-model on the same box:
 * v-model writes after a handler declared beside it, so the copy would run
 * against the previous state and lag a tick behind.
 *
 * Unticking leaves the copied number in place to be edited, rather than wiping
 * what is nearly always right.
 */
function onSameAsMobile(event) {
    sameAsMobile.value = event.target.checked;
    mirrorWhatsapp();
}

const openingAmount = computed(() => Number(form.opening_balance) || 0);

// Credit is the negative side, the same convention money.js and the server use.
const openingSigned = computed(() =>
    form.opening_type === 'credit' ? -openingAmount.value : openingAmount.value
);

const nameField = ref(null);

onMounted(() => {
    // The autofocus attribute is only reliable for markup the parser saw, and
    // this form is inserted after that. It matters on a re-render too: the name
    // is where you start whether the page is fresh or has just bounced back.
    nameField.value?.focus();
});
</script>

<template>
    <form id="party_form" class="ui party-form" :action="action" method="POST">
        <!-- Rendered here rather than passed as a slot: the component is mounted
             onto a bare element, so there is no server markup to slot in. -->
        <input type="hidden" name="_token" :value="csrf">

        <div class="pf-grid">
            <div class="ui-field">
                <label class="ui-label" for="name">{{ label }} Name <span class="ui-label__req">*</span></label>
                <div class="pf-affix">
                    <span class="pf-affix__tag"><i class="bi bi-person"></i></span>
                    <input
                        id="name"
                        ref="nameField"
                        type="text"
                        name="name"
                        class="ui-input"
                        :class="{ 'ui-input--invalid': errors.name }"
                        v-model="form.name"
                        autofocus
                        required>
                </div>
                <div v-if="errors.name" class="ui-hint ui-hint--error">{{ errors.name }}</div>
            </div>

            <div class="ui-field">
                <label class="ui-label" for="mobile">Mobile Number <span class="ui-label__req">*</span></label>
                <div class="pf-affix">
                    <span class="pf-affix__tag">+91</span>
                    <input
                        id="mobile"
                        type="text"
                        name="mobile"
                        class="ui-input"
                        :class="{ 'ui-input--invalid': errors.mobile }"
                        :value="form.mobile"
                        @input="onDigits('mobile', $event)"
                        inputmode="numeric"
                        pattern="\d{10}"
                        maxlength="10"
                        placeholder="10 digit number"
                        required>
                </div>
                <div v-if="errors.mobile" class="ui-hint ui-hint--error">{{ errors.mobile }}</div>
            </div>

            <div class="ui-field">
                <label class="ui-label" for="whatsapp">WhatsApp Number</label>
                <div class="pf-affix">
                    <span class="pf-affix__tag"><i class="bi bi-whatsapp"></i></span>
                    <!-- Read-only rather than disabled while mirrored: a disabled
                         field posts nothing, and the copied number has to reach
                         the server like any other. -->
                    <input
                        id="whatsapp"
                        type="text"
                        name="whatsapp"
                        class="ui-input"
                        :class="{ 'ui-input--invalid': errors.whatsapp }"
                        :value="form.whatsapp"
                        :readonly="sameAsMobile"
                        @input="onDigits('whatsapp', $event)"
                        inputmode="numeric"
                        pattern="\d{10}"
                        maxlength="10"
                        placeholder="10 digit number">
                </div>
                <label class="pf-tick">
                    <input
                        id="same_as_mobile"
                        type="checkbox"
                        :checked="sameAsMobile"
                        @change="onSameAsMobile">
                    <span>Same as mobile number</span>
                </label>
                <div class="ui-hint">Leave blank if WhatsApp is on the mobile number above.</div>
                <div v-if="errors.whatsapp" class="ui-hint ui-hint--error">{{ errors.whatsapp }}</div>
            </div>

            <div v-if="isEdit" class="ui-field">
                <label class="ui-label">Status</label>
                <label class="pf-tick">
                    <input
                        id="is_active"
                        type="checkbox"
                        name="is_active"
                        value="1"
                        v-model="form.is_active">
                    <span>Active</span>
                </label>
                <div class="ui-hint">
                    Inactive {{ lower }}s stay in the list and keep their statement,
                    but are hidden from the entry form.
                </div>
            </div>

            <div class="ui-field pf-grid__wide">
                <label class="ui-label" for="address">Address</label>
                <div class="pf-affix">
                    <span class="pf-affix__tag"><i class="bi bi-geo-alt"></i></span>
                    <input
                        id="address"
                        type="text"
                        name="address"
                        class="ui-input"
                        :class="{ 'ui-input--invalid': errors.address }"
                        v-model="form.address"
                        placeholder="Shop / street, city">
                </div>
                <div v-if="errors.address" class="ui-hint ui-hint--error">{{ errors.address }}</div>
            </div>
        </div>

        <!-- Adding only. Nothing here is rendered on the edit form, so nothing
             here can be posted from it. -->
        <template v-if="!isEdit">
            <hr class="pf-rule">

            <div>
                <h6 class="pf-section__title">
                    Opening Balance <span class="pf-section__opt">(optional)</span>
                </h6>
                <div class="ui-hint">
                    The balance already outstanding before this ledger starts. It is saved as the
                    first entry in the statement, so it can be corrected later like any other entry.
                </div>
            </div>

            <div class="pf-grid pf-grid--thirds">
                <div class="ui-field">
                    <label class="ui-label" for="opening_balance">Amount</label>
                    <div class="pf-affix">
                        <span class="pf-affix__tag">INR</span>
                        <input
                            id="opening_balance"
                            type="number"
                            name="opening_balance"
                            class="ui-input ui-input--amount"
                            :class="{ 'ui-input--invalid': errors.opening_balance }"
                            v-model="form.opening_balance"
                            min="0"
                            step="0.01"
                            placeholder="0.00">
                    </div>
                    <div v-if="errors.opening_balance" class="ui-hint ui-hint--error">
                        {{ errors.opening_balance }}
                    </div>
                </div>

                <div class="ui-field">
                    <label class="ui-label">Side</label>
                    <div class="pf-side">
                        <input
                            id="opening_debit"
                            type="radio"
                            name="opening_type"
                            value="debit"
                            v-model="form.opening_type">
                        <label for="opening_debit" class="pf-side__dr">Debit (Dr)</label>

                        <input
                            id="opening_credit"
                            type="radio"
                            name="opening_type"
                            value="credit"
                            v-model="form.opening_type">
                        <label for="opening_credit" class="pf-side__cr">Credit (Cr)</label>
                    </div>
                    <div class="ui-hint">
                        Dr = {{ lower }} owes you <span class="pf-dot">·</span> Cr = you owe {{ lower }}
                    </div>
                </div>

                <div class="ui-field">
                    <label class="ui-label" for="opening_date_display">As On Date</label>
                    <!-- Still partials/_datefield: a typed dd-mm-yyyy box over a
                         hidden Y-m-d value, bound by class in assets/js/datepicker.js.
                         Rebuilding that markup here would be a second copy of the
                         contract to keep in step. -->
                    <div v-html="dateField"></div>
                    <div v-if="errors.opening_date" class="ui-hint ui-hint--error">
                        {{ errors.opening_date }}
                    </div>
                </div>
            </div>

            <!-- What the figure above will read as on the statement, in the form
                 the statement prints it. -->
            <div v-if="openingAmount > 0" class="ui-note ui-note--info">
                This {{ lower }}'s statement opens at
                <span class="ui-money pf-amount" :class="`ui-money--${side(openingSigned)}`">{{ balance(openingSigned) }}</span>
                — {{ form.opening_type === 'credit' ? `you owe the ${lower}` : `the ${lower} owes you` }}.
            </div>
        </template>

        <div class="pf-foot">
            <a :href="indexUrl" class="ui-btn">Cancel</a>
            <button type="submit" class="ui-btn ui-btn--primary">
                <i class="bi bi-check2-circle"></i> {{ isEdit ? 'Update' : 'Save' }} {{ label }}
            </button>
        </div>
    </form>
</template>

<style>
/* Two columns at desk width, one on a phone. There is no table on this screen,
   so the row-to-card rule has nothing to convert — the same job is done by the
   grid collapsing and every field keeping its own label. */
.party-form {
    display: flex;
    flex-direction: column;
    gap: var(--s-5);
}

.pf-grid {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.pf-grid--thirds {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.pf-grid__wide {
    grid-column: 1 / -1;
}

/* A leading tag on a field: the +91 nobody types, the currency, the icon that
   says which of the two numbers this is. */
.pf-affix {
    display: flex;
    min-width: 0;
}

.pf-affix__tag,
.party-form .input-group > .input-group-text {
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

.pf-affix .ui-input {
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
    min-width: 0;
}

/* The date box is still the shared Blade partial, so it arrives wearing the
   Bootstrap classes the unconverted screens use. Restyled onto the tokens here
   rather than forked, so the datepicker keeps to one markup contract. */
.party-form .input-group {
    display: flex;
    min-width: 0;
}

.party-form .input-group > .js-datefield {
    background: var(--n-000);
    border: 1px solid var(--n-300);
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
    color: var(--n-900);
    flex: 1 1 auto;
    font-family: inherit;
    font-size: var(--t-base);
    min-height: 40px;
    min-width: 0;
    padding: 0 var(--s-3);
}

.party-form .input-group > .js-datefield:focus {
    border-color: var(--brand-500);
    box-shadow: var(--ring);
    outline: none;
}

.pf-tick {
    align-items: center;
    color: var(--n-700);
    cursor: pointer;
    display: inline-flex;
    font-size: var(--t-sm);
    gap: var(--s-2);
    margin-top: var(--s-1);
}

.pf-tick input {
    accent-color: var(--brand-500);
    height: 1rem;
    margin: 0;
    width: 1rem;
}

.pf-side {
    display: flex;
    gap: var(--s-2);
}

.pf-side input {
    opacity: 0;
    pointer-events: none;
    position: absolute;
}

.pf-side label {
    align-items: center;
    border: 1px solid var(--n-300);
    border-radius: var(--r-sm);
    cursor: pointer;
    display: flex;
    flex: 1 1 0;
    font-weight: 700;
    justify-content: center;
    min-height: 40px;
    padding: 0 var(--s-2);
    text-align: center;
}

.pf-side input:checked + label.pf-side__dr {
    background: var(--dr-050);
    border-color: var(--dr-600);
    color: var(--dr-700);
}

.pf-side input:checked + label.pf-side__cr {
    background: var(--cr-050);
    border-color: var(--cr-600);
    color: var(--cr-700);
}

/* The radio itself is off-screen, so the focus ring has to be drawn on the
   label standing in for it. */
.pf-side input:focus-visible + label {
    box-shadow: var(--ring);
}

.pf-rule {
    border: 0;
    border-top: 1px solid var(--n-200);
    margin: 0;
}

.pf-section__title {
    color: var(--ink-800);
    font-size: var(--t-md);
    font-weight: 700;
    margin: 0 0 var(--s-1);
}

.pf-section__opt {
    color: var(--n-400);
    font-weight: 400;
}

.pf-dot {
    color: var(--n-300);
    padding: 0 var(--s-1);
}

.pf-amount {
    font-weight: 700;
}

.pf-foot {
    align-items: center;
    border-top: 1px solid var(--n-200);
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2);
    justify-content: flex-end;
    padding-top: var(--s-4);
}

@media (max-width: 991.98px) {
    .pf-grid,
    .pf-grid--thirds {
        grid-template-columns: minmax(0, 1fr);
    }

    .pf-foot .ui-btn {
        flex: 1 1 auto;
    }
}

@media (pointer: coarse) {
    .party-form .input-group > .js-datefield,
    .pf-side label {
        min-height: var(--tap);
    }
}
</style>
