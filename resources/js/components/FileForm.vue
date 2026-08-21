<script setup>
/*
 * Correcting one work file.
 *
 * Field names are the ones WorkFileController::edit() already validates, so the
 * form still posts normally and the server still checks every value. That is
 * what makes it safe to convert a screen on a live ledger: only the rendering
 * moves, the money logic does not — and on this screen the money logic is
 * WorkFileModel::syncLedger(), which rewrites this file's existing entries on
 * both statements rather than appending a correction beside them.
 *
 * So the panel beside the form is not decoration. Every field here is capable of
 * moving a balance that someone has already seen, and the panel says where the
 * two statements land before the save rather than after it.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { balance, money, side } from '../money';
import FilePreview from './FilePreview.vue';

/*
 * The approval document being looked at, or null. Held here so the link
 * only has to say which one; the dialog itself is shared.
 */
const preview = ref(null);


const props = defineProps({
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    indexUrl: { type: String, required: true },
    isEdit: { type: Boolean, default: false },
    statuses: { type: Object, default: () => ({}) },
    workTypes: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    vendors: { type: Array, default: () => [] },
    values: { type: Object, default: () => ({}) },
    // Both date boxes arrive as server-rendered markup; see the template.
    receivedDateField: { type: String, default: '' },
    vendorDateField: { type: String, default: '' },
    refundPlaceholder: { type: String, default: '0.00' },
    screenshotUrl: { type: String, default: '' },
    // The works this file is for, each with its own price and approval.
    items: { type: Array, default: () => [] },
    alreadyPosted: { type: Object, default: () => ({}) },
    timeline: { type: Array, default: () => [] },
    returnedKey: { type: String, required: true },
    approvedKey: { type: String, required: true },
    cancelledKey: { type: String, required: true },
    errors: { type: Object, default: () => ({}) },
});

/*
 * A status the list does not offer cannot be chosen, and an unmatched one would
 * leave the box empty and the file unsavable. Server markup fell back to the
 * first option in that case — a <select> with nothing marked selected shows its
 * first one — so that is what happens here too.
 */
const statusKeys = Object.keys(props.statuses);
const startingStatus = statusKeys.includes(props.values.status)
    ? props.values.status
    : (statusKeys[0] ?? '');

const form = reactive({
    file_no: props.values.file_no ?? '',
    status: startingStatus,
    returned_amount: props.values.returned_amount ?? '',
    work_type_id: props.values.work_type_id ?? '',
    registration_no: props.values.registration_no ?? '',
    description: props.values.description ?? '',
    customer_id: props.values.customer_id ?? '',
    customer_amount: props.values.customer_amount ?? '',
    vendor_id: props.values.vendor_id ?? '',
    vendor_amount: props.values.vendor_amount ?? '',
    remarks: props.values.remarks ?? '',
});

/*
 * A folder holding several works has no single type, charge or cost to type
 * into a box: each of those is the sum of its works, and is written back from
 * them when this is saved. So the boxes give way to a panel with a line per
 * work, and the file's own figures are shown as the totals they are.
 *
 * The ordinary file, holding one work, is unchanged — it is that work, and the
 * boxes still write straight through to it.
 */
const multiWork = computed(() => props.items.length > 1);

const works = reactive(
    props.items.map((item) => ({
        ...item,
        customer_amount: item.customer_amount ?? '',
        vendor_amount: item.vendor_amount ?? '',
    }))
);

const worksCharged = computed(() =>
    works.reduce((sum, work) => sum + (Number(work.customer_amount) || 0), 0)
);

// A work with no rate agreed leaves the folder's cost short of complete, so it
// is counted and said rather than quietly summed as nothing.
const worksUnpriced = computed(
    () => works.filter((work) => String(work.vendor_amount).trim() === '').length
);

const worksCost = computed(() =>
    works.reduce((sum, work) => sum + (Number(work.vendor_amount) || 0), 0)
);

const cancelled = computed(() => form.status === props.cancelledKey);
const returning = computed(() => form.status === props.returnedKey);
const approving = computed(() => form.status === props.approvedKey);

/*
 * Both of these statuses take money off a statement, so they say so beside the
 * dropdown rather than in the panel alone — the dropdown is where the decision
 * is made.
 */
const statusHint = computed(() => {
    if (cancelled.value) {
        return "Cancelling removes this file's entries from both ledgers. The file and its number are kept.";
    }

    if (returning.value) {
        return 'The papers go back and the full amount is credited to the customer. '
            + 'The original charge stays on the statement beside it.';
    }

    return '';
});

const charged = computed(() => Number(form.customer_amount) || 0);

// A blank refund gives the whole charge back, and the server reads it the same
// way — WorkFileController::partialOrNull() stores blank and "all of it" alike.
const refunded = computed(() =>
    String(form.returned_amount).trim() === ''
        ? charged.value
        : Math.min(Number(form.returned_amount) || 0, charged.value)
);

// A cancelled file posts nothing; a returned one still debits, then credits the
// refund back. Anything else is the plain charge.
const debit = computed(() => {
    if (cancelled.value) {
        return 0;
    }

    return returning.value ? charged.value - refunded.value : charged.value;
});

const credit = computed(() => (cancelled.value ? 0 : Number(form.vendor_amount) || 0));

const margin = computed(() => debit.value - credit.value);

const chosenWorkType = computed(
    () => props.workTypes.find((type) => String(type.id) === String(form.work_type_id)) ?? null
);

const chosenCustomer = computed(
    () => props.customers.find((party) => String(party.id) === String(form.customer_id)) ?? null
);

const chosenVendor = computed(
    () => props.vendors.find((party) => String(party.id) === String(form.vendor_id)) ?? null
);

/*
 * What this file already contributes to each party's balance.
 *
 * The balance carried on each party is their current one, which for the file
 * being edited already includes that file's own entries. Adding the new amount
 * on top counted it twice, so the panel promised a balance the statement would
 * never show. Discount the existing effect first — but only while the party is
 * still the one those entries were posted against.
 */
const postedToCustomer = computed(() =>
    String(form.customer_id) === String(props.alreadyPosted.customerId)
        ? Number(props.alreadyPosted.customer) || 0
        : 0
);

const postedToVendor = computed(() =>
    String(form.vendor_id) === String(props.alreadyPosted.vendorId)
        ? Number(props.alreadyPosted.vendor) || 0
        : 0
);

const customerAfter = computed(
    () => Number(chosenCustomer.value?.balance ?? 0) - postedToCustomer.value + debit.value
);

// A vendor's balance sits on the credit side and goes further that way as they
// are owed, so removing this file's existing cost adds back rather than
// subtracts, and the new cost is taken off.
const vendorAfter = computed(
    () => Number(chosenVendor.value?.balance ?? 0) + postedToVendor.value - credit.value
);

/*
 * Picking a type fills in its standard rate, but never overwrites an amount
 * already typed — the rate is a starting point, not a rule.
 */
function onWorkType() {
    const rate = Number(chosenWorkType.value?.rate) || 0;

    if (rate && !String(form.customer_amount).trim()) {
        form.customer_amount = rate.toFixed(2);
    }
}

const workTypeField = ref(null);

onMounted(() => {
    // The select carried autofocus as server markup. Inserted by Vue the
    // attribute is no longer dependable, so the focus is placed by hand — and
    // only if the user has not already started somewhere else.
    if (!document.activeElement || document.activeElement === document.body) {
        workTypeField.value?.focus();
    }
});
</script>

<template>
    <div class="ui wf">
        <form
            id="file_form"
            class="wf-form"
            :action="action"
            method="POST"
            enctype="multipart/form-data">
            <!-- Rendered here rather than passed as a slot: the component is
                 mounted onto a bare element, so there is no server markup to
                 slot in. -->
            <input type="hidden" name="_token" :value="csrf">

            <div class="ui-card">
                <div class="ui-card__head">
                    <h2 class="ui-card__title">File Details</h2>
                    <a :href="indexUrl" class="ui-btn ui-btn--sm">
                        <i class="bi bi-list-ul"></i> All Files
                    </a>
                </div>

                <div class="ui-card__body">
                    <div class="wf-grid">
                        <div class="ui-field wf-2">
                            <label class="ui-label" for="received_date_display">
                                Received Date <span class="ui-label__req">*</span>
                            </label>
                            <!--
                                Still partials/_datefield, handed over whole.

                                public/assets/js/datepicker.js owns this pair of
                                boxes: it writes the typed day into the visible
                                one and the Y-m-d into the hidden one. A :value
                                binding would take that ownership back — Vue
                                force-patches the "value" key on every re-render
                                even when the bound value has not changed
                                (runtime-core, patchProps: `next !== prev || key
                                === "value"`) and compares against the live DOM
                                value, so it writes the page-load default over
                                the operator's choice. Typing an amount would be
                                enough to trigger it, the result still passes
                                date_format:Y-m-d, and the file would be dated
                                wrongly with nothing shown to anyone. v-html
                                renders the string once and leaves it alone.
                            -->
                            <div v-html="receivedDateField"></div>
                            <div v-if="errors.received_date" class="ui-hint ui-hint--error">
                                {{ errors.received_date }}
                            </div>
                        </div>

                        <div class="ui-field wf-2">
                            <label class="ui-label" for="file_no">File No.</label>
                            <div class="wf-affix">
                                <span class="wf-affix__tag"><i class="bi bi-hash"></i></span>
                                <input
                                    id="file_no"
                                    type="text"
                                    name="file_no"
                                    class="ui-input"
                                    :class="{ 'ui-input--invalid': errors.file_no }"
                                    v-model="form.file_no"
                                    maxlength="30"
                                    placeholder="Auto">
                            </div>
                            <div class="ui-hint">Leave blank to number it automatically.</div>
                            <div v-if="errors.file_no" class="ui-hint ui-hint--error">{{ errors.file_no }}</div>
                        </div>

                        <!-- A folder of several works takes its status from them,
                             so it is stated here and moved on the board, a work at
                             a time. The value still posts, because the server
                             writes it back from the works either way. -->
                        <div v-if="multiWork" class="ui-field wf-2">
                            <label class="ui-label">Status</label>
                            <div class="wf-derived">
                                <span class="ui-badge" :data-state="form.status">{{ statuses[form.status] }}</span>
                                <span class="ui-hint">from the works below</span>
                            </div>
                            <input type="hidden" name="status" :value="form.status">
                        </div>

                        <div v-else class="ui-field wf-2">
                            <label class="ui-label" for="status">
                                Status <span class="ui-label__req">*</span>
                            </label>
                            <div class="wf-affix">
                                <span class="wf-affix__tag"><i class="bi bi-flag"></i></span>
                                <select
                                    id="status"
                                    class="ui-select"
                                    name="status"
                                    v-model="form.status"
                                    required>
                                    <option v-for="(label, key) in statuses" :key="key" :value="key">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>
                            <div v-if="statusHint" class="ui-hint ui-hint--error">{{ statusHint }}</div>
                            <div v-if="errors.status" class="ui-hint ui-hint--error">{{ errors.status }}</div>
                        </div>

                        <!-- Not rendered rather than merely hidden: a field that
                             is not on the page posts nothing, so a refund typed
                             before a change of mind about the status cannot
                             reach the ledger. The figure is kept in the model,
                             so coming back to Paper Returned brings it back. -->
                        <div v-if="returning" class="ui-field wf-2">
                            <label class="ui-label" for="returned_amount">Refund to Customer</label>
                            <div class="wf-affix">
                                <span class="wf-affix__tag">INR</span>
                                <input
                                    id="returned_amount"
                                    type="number"
                                    name="returned_amount"
                                    class="ui-input ui-input--amount"
                                    :class="{ 'ui-input--invalid': errors.returned_amount }"
                                    v-model="form.returned_amount"
                                    min="0.01"
                                    step="0.01"
                                    :placeholder="refundPlaceholder">
                            </div>
                            <div class="ui-hint">Leave blank to refund the whole charge.</div>
                            <div v-if="errors.returned_amount" class="ui-hint ui-hint--error">
                                {{ errors.returned_amount }}
                            </div>
                        </div>

                        <!--
                            Off the page unless the file is being approved, and
                            that is the guard rather than the styling.

                            A file chosen while Approval Done was selected still
                            posts after the status is moved away, and
                            storeScreenshot() deletes the existing one before
                            saving the new one — so a mis-click could replace the
                            evidence on an approval that is no longer being made.
                            An input that is not rendered posts nothing, and
                            re-rendering it brings it back empty.
                        -->
                        <div v-if="approving" class="ui-field wf-full">
                            <label class="ui-label" for="approval_screenshot">
                                Approval Screenshot <span class="ui-label__req">*</span>
                            </label>
                            <input
                                id="approval_screenshot"
                                type="file"
                                name="approval_screenshot"
                                class="ui-input wf-file"
                                :class="{ 'ui-input--invalid': errors.approval_screenshot }"
                                accept="image/*,application/pdf">
                            <div v-if="screenshotUrl" class="ui-hint">
                                <i class="bi bi-paperclip"></i>
                                <a
                                    :href="screenshotUrl"
                                    class="ui-link"
                                    @click.prevent="preview = { src: screenshotUrl, title: 'Approval screenshot' }">Screenshot on file</a>
                                &mdash; choose a file only if you want to replace it.
                            </div>
                            <div v-else class="ui-hint">
                                Required to mark this file approved. JPG, PNG, WEBP or PDF, up to 4&nbsp;MB.
                            </div>
                            <div v-if="errors.approval_screenshot" class="ui-hint ui-hint--error">
                                {{ errors.approval_screenshot }}
                            </div>
                        </div>

                        <div v-if="!multiWork" class="ui-field wf-3">
                            <label class="ui-label" for="work_type_id">
                                Type of Work <span class="ui-label__req">*</span>
                            </label>
                            <div class="wf-affix">
                                <span class="wf-affix__tag"><i class="bi bi-briefcase"></i></span>
                                <select
                                    id="work_type_id"
                                    ref="workTypeField"
                                    class="ui-select"
                                    :class="{ 'ui-select--invalid': errors.work_type_id }"
                                    name="work_type_id"
                                    v-model="form.work_type_id"
                                    @change="onWorkType"
                                    required
                                    autofocus>
                                    <option value="">Select type of work</option>
                                    <option v-for="type in workTypes" :key="type.id" :value="type.id">
                                        {{ type.label }}
                                    </option>
                                </select>
                            </div>
                            <div v-if="errors.work_type_id" class="ui-hint ui-hint--error">
                                {{ errors.work_type_id }}
                            </div>
                        </div>

                        <!-- The folder still has to name a type, and the server
                             sets it from the first work. Posted so the form stays
                             valid without asking for an answer that is not one. -->
                        <input v-else type="hidden" name="work_type_id" :value="form.work_type_id">

                        <div class="ui-field wf-3">
                            <label class="ui-label" for="registration_no">Registration No.</label>
                            <div class="wf-affix">
                                <span class="wf-affix__tag"><i class="bi bi-car-front"></i></span>
                                <input
                                    id="registration_no"
                                    type="text"
                                    name="registration_no"
                                    class="ui-input wf-reg"
                                    :class="{ 'ui-input--invalid': errors.registration_no }"
                                    v-model="form.registration_no"
                                    maxlength="20"
                                    autocomplete="off"
                                    placeholder="BR01AB1234">
                            </div>
                            <div class="ui-hint">
                                Stored without spaces or dashes, so the same vehicle is always found.
                            </div>
                            <div v-if="errors.registration_no" class="ui-hint ui-hint--error">
                                {{ errors.registration_no }}
                            </div>
                        </div>

                        <div class="ui-field wf-3">
                            <label class="ui-label" for="description">File Details</label>
                            <div class="wf-affix">
                                <span class="wf-affix__tag"><i class="bi bi-card-text"></i></span>
                                <input
                                    id="description"
                                    type="text"
                                    name="description"
                                    class="ui-input"
                                    :class="{ 'ui-input--invalid': errors.description }"
                                    v-model="form.description"
                                    maxlength="255"
                                    placeholder="Vehicle no., party name, reference">
                            </div>
                            <div class="ui-hint">Shown next to the work type on both statements.</div>
                            <div v-if="errors.description" class="ui-hint ui-hint--error">
                                {{ errors.description }}
                            </div>
                        </div>

                        <div class="wf-full wf-section">
                            <h3 class="wf-section__title">
                                Customer <span class="ui-money--dr">&mdash; will be debited</span>
                            </h3>
                            <div class="ui-hint">
                                The customer who gave you this file is charged for the work.
                            </div>
                        </div>

                        <div class="ui-field wf-3">
                            <label class="ui-label" for="customer_id">
                                Customer <span class="ui-label__req">*</span>
                            </label>
                            <div class="wf-affix">
                                <span class="wf-affix__tag"><i class="bi bi-people"></i></span>
                                <select
                                    id="customer_id"
                                    class="ui-select"
                                    :class="{ 'ui-select--invalid': errors.customer_id }"
                                    name="customer_id"
                                    v-model="form.customer_id"
                                    required>
                                    <option value="">Select customer</option>
                                    <option v-for="party in customers" :key="party.id" :value="party.id">
                                        {{ party.label }}
                                    </option>
                                </select>
                            </div>
                            <div v-if="errors.customer_id" class="ui-hint ui-hint--error">
                                {{ errors.customer_id }}
                            </div>
                        </div>

                        <div v-if="multiWork" class="ui-field wf-3">
                            <label class="ui-label">Amount Charged</label>
                            <div class="wf-derived">
                                <span class="ui-money ui-money--dr ui-money--strong">{{ money(worksCharged) }}</span>
                                <span class="ui-hint">{{ works.length }} works</span>
                            </div>
                            <input type="hidden" name="customer_amount" :value="worksCharged">
                        </div>

                        <div v-else class="ui-field wf-3">
                            <label class="ui-label" for="customer_amount">
                                Amount Charged <span class="ui-label__req">*</span>
                            </label>
                            <div class="wf-affix">
                                <span class="wf-affix__tag">INR</span>
                                <input
                                    id="customer_amount"
                                    type="number"
                                    name="customer_amount"
                                    class="ui-input ui-input--amount"
                                    :class="{ 'ui-input--invalid': errors.customer_amount }"
                                    v-model="form.customer_amount"
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00"
                                    required>
                            </div>
                            <div v-if="errors.customer_amount" class="ui-hint ui-hint--error">
                                {{ errors.customer_amount }}
                            </div>
                        </div>

                        <div class="wf-full wf-section">
                            <h3 class="wf-section__title">
                                Vendor <span class="ui-money--cr">&mdash; will be credited</span>
                                <span class="wf-section__opt">(optional)</span>
                            </h3>
                            <div class="ui-hint">
                                Fill this in when the file is handed to a vendor. Leave blank for work done in-house.
                            </div>
                        </div>

                        <div class="ui-field wf-2">
                            <label class="ui-label" for="vendor_id">Vendor</label>
                            <div class="wf-affix">
                                <span class="wf-affix__tag"><i class="bi bi-truck"></i></span>
                                <select
                                    id="vendor_id"
                                    class="ui-select"
                                    :class="{ 'ui-select--invalid': errors.vendor_id }"
                                    name="vendor_id"
                                    v-model="form.vendor_id">
                                    <option value="">In-house / not assigned</option>
                                    <option v-for="party in vendors" :key="party.id" :value="party.id">
                                        {{ party.label }}
                                    </option>
                                </select>
                            </div>
                            <div v-if="errors.vendor_id" class="ui-hint ui-hint--error">
                                {{ errors.vendor_id }}
                            </div>
                        </div>

                        <div v-if="multiWork" class="ui-field wf-2">
                            <label class="ui-label">Vendor Amount</label>
                            <div class="wf-derived">
                                <span class="ui-money ui-money--cr ui-money--strong">{{ money(worksCost) }}</span>
                                <span v-if="worksUnpriced" class="ui-hint">
                                    {{ worksUnpriced }} still without a rate
                                </span>
                            </div>
                        </div>

                        <div v-else class="ui-field wf-2">
                            <label class="ui-label" for="vendor_amount">Vendor Amount</label>
                            <div class="wf-affix">
                                <span class="wf-affix__tag">INR</span>
                                <input
                                    id="vendor_amount"
                                    type="number"
                                    name="vendor_amount"
                                    class="ui-input ui-input--amount"
                                    :class="{ 'ui-input--invalid': errors.vendor_amount }"
                                    v-model="form.vendor_amount"
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00">
                            </div>
                            <div class="ui-hint">Leave blank until the rate is agreed.</div>
                            <div v-if="errors.vendor_amount" class="ui-hint ui-hint--error">
                                {{ errors.vendor_amount }}
                            </div>
                        </div>

                        <div class="ui-field wf-2">
                            <label class="ui-label" for="vendor_date_display">Given On</label>
                            <!-- The second date box, and the same rule: handed
                                 over as server markup so assets/js/datepicker.js
                                 keeps both halves of it. See the note above. -->
                            <div v-html="vendorDateField"></div>
                            <div class="ui-hint">Defaults to the received date.</div>
                            <div v-if="errors.vendor_date" class="ui-hint ui-hint--error">
                                {{ errors.vendor_date }}
                            </div>
                        </div>

                        <div class="ui-field wf-full wf-section">
                            <label class="ui-label" for="remarks">Remarks</label>
                            <textarea
                                id="remarks"
                                name="remarks"
                                class="ui-textarea"
                                :class="{ 'ui-input--invalid': errors.remarks }"
                                v-model="form.remarks"
                                rows="2"
                                maxlength="255"></textarea>
                            <div v-if="errors.remarks" class="ui-hint ui-hint--error">{{ errors.remarks }}</div>
                        </div>
                    </div>
                </div>

                <!--
                    The works this file is for.

                    Shown whenever there is more than one, because that is when
                    the boxes above stop being able to say what the file is: a
                    transfer and a hypothecation addition on one folder have a
                    charge each, a rate each, and an approval each that arrives
                    on its own day with its own document.

                    Status is not editable here. A work moves on the board, where
                    the evidence goes with it — this screen is for corrections.
                -->
                <div v-if="multiWork" class="wf-works">
                    <div class="wf-works__head">
                        <h3 class="wf-section__title">Works on This File</h3>
                        <div class="ui-hint">
                            Correct a type or a price here. Statuses move on the status board,
                            a work at a time.
                        </div>
                    </div>

                    <div class="ui-table-wrap">
                        <table class="ui-table wf-works__table">
                            <thead>
                                <tr>
                                    <th>Work</th>
                                    <th class="num" style="min-width: 8rem;">Charged</th>
                                    <th class="num" style="min-width: 8rem;">Vendor Rate</th>
                                    <th>Status</th>
                                    <th>Approval</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="work in works" :key="work.id">
                                    <td data-label="Work">
                                        <select
                                            class="ui-select"
                                            :name="`items[${work.id}][work_type_id]`"
                                            v-model="work.work_type_id">
                                            <option v-for="type in workTypes" :key="type.id" :value="type.id">
                                                {{ type.label }}
                                            </option>
                                        </select>
                                    </td>

                                    <td data-label="Charged" class="num">
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="ui-input ui-input--amount"
                                            :name="`items[${work.id}][customer_amount]`"
                                            v-model="work.customer_amount"
                                            placeholder="0.00">
                                    </td>

                                    <td data-label="Vendor Rate" class="num">
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="ui-input ui-input--amount"
                                            :name="`items[${work.id}][vendor_amount]`"
                                            v-model="work.vendor_amount"
                                            placeholder="Not agreed">
                                    </td>

                                    <td data-label="Status">
                                        <span class="ui-badge" :data-state="work.status">{{ work.status_label }}</span>
                                        <div v-if="work.approved_on" class="ui-sub">{{ work.approved_on }}</div>
                                    </td>

                                    <td data-label="Approval">
                                        <!-- Every approval keeps the document it
                                             arrived with. The list upstairs can
                                             only link to one; they all hang here. -->
                                        <a
                                            v-if="work.screenshot_url"
                                            :href="work.screenshot_url"
                                            class="ui-link"
                                            @click.prevent="preview = { src: work.screenshot_url, title: (work.work_type || 'Work') + ' — approval' }">
                                            <i class="bi bi-paperclip"></i> View
                                        </a>
                                        <span v-else class="ui-hint">&mdash;</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="ui-card__foot">
                    <span class="ui-hint">
                        Saving rewrites this file's entries on both statements.
                    </span>
                    <div class="wf-actions">
                        <a :href="indexUrl" class="ui-btn">Cancel</a>
                        <button type="submit" class="ui-btn ui-btn--primary">
                            <i class="bi bi-check2-circle"></i> {{ isEdit ? 'Update File' : 'Receive File' }}
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <aside class="wf-side">
            <div class="ui-card wf-effect">
                <div class="ui-card__body">
                    <h2 class="ui-card__title wf-effect__title">Ledger Effect</h2>

                    <dl class="wf-rows">
                        <div class="wf-row">
                            <dt class="ui-label">Customer</dt>
                            <dd class="wf-row__value">{{ chosenCustomer ? chosenCustomer.label : 'Not selected' }}</dd>
                        </div>

                        <div class="wf-row">
                            <dt class="ui-label">Debit</dt>
                            <dd class="wf-row__value ui-money ui-money--dr">{{ money(debit) }}</dd>
                        </div>

                        <div class="wf-row">
                            <dt class="ui-label">Balance After</dt>
                            <dd class="wf-row__value ui-money" :class="`ui-money--${side(customerAfter)}`">
                                {{ chosenCustomer ? balance(customerAfter) : money(0) }}
                            </dd>
                        </div>

                        <div class="wf-row">
                            <dt class="ui-label">Vendor</dt>
                            <dd class="wf-row__value">{{ chosenVendor ? chosenVendor.label : 'In-house' }}</dd>
                        </div>

                        <div class="wf-row">
                            <dt class="ui-label">Credit</dt>
                            <dd class="wf-row__value ui-money ui-money--cr">{{ money(credit) }}</dd>
                        </div>

                        <div class="wf-row">
                            <dt class="ui-label">Balance After</dt>
                            <dd class="wf-row__value ui-money" :class="`ui-money--${side(vendorAfter)}`">
                                {{ chosenVendor ? balance(vendorAfter) : money(0) }}
                            </dd>
                        </div>

                        <!-- What is left of the charge once the vendor is paid.
                             Signed, unlike a balance: a job done at a loss has
                             to look like one. -->
                        <div class="wf-row">
                            <dt class="ui-label">Margin</dt>
                            <dd class="wf-row__value ui-money wf-margin" :class="`ui-money--${side(margin)}`">
                                {{ money(margin) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <template v-if="isEdit">
                <div class="ui-note ui-note--warn">
                    <i class="bi bi-info-circle"></i>
                    Changing an amount, party or date here rewrites this file's existing ledger entries
                    rather than adding a correction, so both statements will show only the corrected figure.
                </div>

                <div class="ui-card">
                    <div class="ui-card__body">
                        <h2 class="ui-card__title wf-effect__title">History</h2>

                        <ol class="wf-timeline">
                            <li v-for="entry in timeline" :key="entry.id">
                                <div class="wf-tl__head">
                                    <template v-if="entry.kind === 'opening'">
                                        Received &mdash; <strong>{{ entry.to }}</strong>
                                    </template>
                                    <template v-else-if="entry.kind === 'note'">
                                        Note &mdash; <strong>{{ entry.to }}</strong>
                                    </template>
                                    <template v-else>
                                        {{ entry.from }} &rarr; <strong>{{ entry.to }}</strong>
                                    </template>
                                </div>
                                <div v-if="entry.remark" class="wf-tl__remark">{{ entry.remark }}</div>
                                <div class="wf-tl__meta">
                                    {{ entry.date }} at {{ entry.time }}
                                    <template v-if="entry.user">&middot; {{ entry.user }}</template>
                                </div>
                            </li>

                            <li v-if="!timeline.length">
                                <div class="wf-tl__meta">Nothing recorded yet.</div>
                            </li>
                        </ol>
                    </div>
                </div>
            </template>
        </aside>

        <FilePreview :src="preview?.src" :title="preview?.title" @close="preview = null" />
    </div>
</template>

<style>
/* The form and what it does to the two statements, side by side at desk width
   and stacked on a phone. There is no table on this screen, so the row-to-card
   rule has nothing to convert — the same job is done by the grids collapsing
   and every field keeping its own label. */
.wf {
    align-items: start;
    display: grid;
    gap: var(--s-4);
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
}

.wf-side {
    display: flex;
    flex-direction: column;
    gap: var(--s-4);
    min-width: 0;
}

/* Six columns, so a field can take a half or a third of the row and land on the
   same edges either way. */
.wf-grid {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: repeat(6, minmax(0, 1fr));
}

.wf-2 { grid-column: span 2; }
.wf-3 { grid-column: span 3; }
.wf-full { grid-column: 1 / -1; }

.wf-section {
    border-top: 1px solid var(--n-200);
    padding-top: var(--s-4);
}

.wf-section__title {
    color: var(--ink-800);
    font-size: var(--t-md);
    font-weight: 700;
    margin: 0 0 var(--s-1);
}

.wf-section__opt {
    color: var(--n-400);
    font-size: var(--t-sm);
    font-weight: 400;
}

/* A leading tag on a field: the currency, or the icon that says which of the
   several selects this is. */
.wf-affix {
    display: flex;
    min-width: 0;
}

.wf-affix__tag,
.wf-form .input-group > .input-group-text {
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

.wf-affix .ui-input,
.wf-affix .ui-select {
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
    min-width: 0;
}

/* Both date boxes are still the shared Blade partial, so they arrive wearing
   the Bootstrap classes the unconverted screens use. Restyled onto the tokens
   here rather than forked, so the datepicker keeps to one markup contract. */
.wf-form .input-group {
    display: flex;
    min-width: 0;
}

.wf-form .input-group > .js-datefield {
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

.wf-form .input-group > .js-datefield:focus {
    border-color: var(--brand-500);
    box-shadow: var(--ring);
    outline: none;
}

/* Typed in whichever way, read back in one: the server strips the spaces and
   dashes, and this stops the box disagreeing with what was stored. */
.wf-reg {
    text-transform: uppercase;
}

/* A file input is taller than its text than a text box is, and the shared
   padding leaves the button clipped. */
.wf-file {
    line-height: 2.2;
    padding: 0 var(--s-2);
}

/* A figure the file works out for itself, standing where its box used to be so
   the row does not go ragged when a folder holds several works. */
.wf-derived {
    align-items: baseline;
    display: flex;
    gap: var(--s-2);
    min-height: 2.5rem;
}

/* The works panel sits between the fields and the footer, inside the same card,
   so it reads as part of the file rather than as a second thing about it. */
.wf-works {
    border-top: 1px solid var(--n-200);
    padding: var(--s-4);
}

.wf-works__head {
    margin-bottom: var(--s-3);
}

.wf-works__table td {
    vertical-align: middle;
}

@media (max-width: 767.98px) {
    .wf-works__table,
    .wf-works__table tbody,
    .wf-works__table tr,
    .wf-works__table td {
        display: block;
        width: 100%;
    }

    .wf-works__table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
    }

    .wf-works__table tbody tr {
        border: 1px solid var(--n-200);
        border-radius: var(--r-md);
        margin-bottom: var(--s-3);
        padding: var(--s-2) var(--s-3);
    }

    .wf-works__table tbody td {
        border-bottom: 0;
        padding: var(--s-2) 0;
    }

    .wf-works__table tbody td::before {
        color: var(--n-500);
        content: attr(data-label);
        display: block;
        font-size: var(--t-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        margin-bottom: var(--s-1);
        text-transform: uppercase;
    }

    .wf-works__table tbody td.num {
        text-align: left;
    }
}


.wf-actions {
    display: flex;
    gap: var(--s-2);
}

/* ---- Ledger effect ----------------------------------------------------- */

.wf-effect {
    border-top: 3px solid var(--brand-500);
}

.wf-effect__title {
    margin-bottom: var(--s-2);
}

.wf-rows {
    margin: 0;
}

.wf-row {
    border-bottom: 1px solid var(--n-100);
    display: flex;
    gap: var(--s-3);
    justify-content: space-between;
    padding: var(--s-3) 0;
}

.wf-row:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.wf-row__value {
    color: var(--n-900);
    font-weight: 700;
    margin: 0;
    min-width: 0;
    overflow-wrap: anywhere;
    text-align: right;
}

.wf-margin {
    font-size: var(--t-lg);
}

/* ---- History ----------------------------------------------------------- */

.wf-timeline {
    border-left: 2px solid var(--n-200);
    list-style: none;
    margin: 0;
    padding: 0 0 0 var(--s-4);
}

.wf-timeline li {
    padding: 0 0 var(--s-3) var(--s-3);
    position: relative;
}

.wf-timeline li:last-child {
    padding-bottom: 0;
}

.wf-timeline li::before {
    background: var(--n-000);
    border: 2px solid var(--brand-500);
    border-radius: 50%;
    content: "";
    height: 0.6rem;
    left: -1.42rem;
    position: absolute;
    top: 0.35rem;
    width: 0.6rem;
}

/* Newest first, so the top marker is the live one. */
.wf-timeline li:first-child::before {
    background: var(--brand-500);
}

.wf-tl__head {
    color: var(--n-700);
    font-size: var(--t-sm);
}

.wf-tl__remark {
    background: var(--n-050);
    border-left: 3px solid var(--n-300);
    border-radius: 3px;
    color: var(--n-900);
    font-size: var(--t-sm);
    margin-top: var(--s-1);
    padding: var(--s-1) var(--s-2);
}

.wf-tl__meta {
    color: var(--n-400);
    font-size: var(--t-xs);
    margin-top: var(--s-1);
}

@media (max-width: 991.98px) {
    .wf,
    .wf-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .wf-2,
    .wf-3 {
        grid-column: 1 / -1;
    }

    .wf-actions {
        flex: 1 1 auto;
    }

    .wf-actions .ui-btn {
        flex: 1 1 auto;
    }
}

@media (pointer: coarse) {
    .wf-form .input-group > .js-datefield {
        min-height: var(--tap);
    }
}
</style>
