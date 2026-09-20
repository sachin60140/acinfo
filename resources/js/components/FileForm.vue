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
import CardSection from './CardSection.vue';

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

    // What the office has paid out on this file, and the kinds it may be paid
    // out under. Office cash: none of it touches a party ledger.
    expenses: { type: Array, default: () => [] },
    expenseTypes: { type: Array, default: () => [] },
    today: { type: String, default: '' },

    // Papers scanned against this file, newest first.
    documents: { type: Array, default: () => [] },

    /*
     * Whether the papers have gone back to the customer: { on, by,
     * collectedBy, undoUrl } once they have, and null until then. handoverUrl
     * is the Hand Over Papers screen, offered only when the file is approved
     * and its papers are still here.
     */
    handover: { type: Object, default: null },
    handoverUrl: { type: String, default: null },

    /*
     * Where the paper checklist stands: { state: to_check | pending | complete,
     * pending: [names], toCheck, received, total, url }, or null when none of
     * the unfinished work needs papers.
     */
    papers: { type: Object, default: null },
});

/*
 * Whether anything went wrong inside a part of the form that can be folded
 * away. Matched on the field name's first word, because that is what groups
 * them: items[3][amount] and new_works[0][work_type_id] are both the works.
 *
 * A section holding an error is held open by it. A form that folds away the
 * reason it would not save is worse than a form that is merely long.
 */
const errorIn = (...prefixes) => Object.keys(props.errors ?? {})
    .some((key) => prefixes.some((prefix) => key === prefix || key.startsWith(`${prefix}[`) || key.startsWith(`${prefix}.`)));

// Asked for only when taking a handover back, which has to say why.
const undoing = ref(Boolean(props.errors && props.errors.undo_remark));

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
        // Whether the office already said this one is theirs.
        in_house: Boolean(item.in_house),
    }))
);

/*
 * A price that was already agreed, moving.
 *
 * Changing one rewrites this file's entries on a statement somebody has already
 * seen, so it says why — and the reason is the office's, never the customer's.
 *
 * Filling in a blank, or a nought, is agreeing a price for the first time and
 * asks nothing: that is a file being priced, not a price being changed.
 */
const wasAgreed = (value) => value !== null && value !== undefined && value !== '' && Number(value) > 0;

const moved = (was, now) => {
    if (! wasAgreed(was)) {
        return false;
    }

    const typed = String(now ?? '').trim();

    return typed === '' || Math.abs(Number(was) - Number(typed)) > 0.005;
};

const priceRemark = ref('');

const priceChanges = computed(() => {
    const said = [];

    if (props.isEdit && multiWork.value) {
        works.forEach((work, index) => {
            if (going(work)) {
                return;
            }

            const was = props.items[index] ?? {};

            if (moved(was.customer_amount, work.customer_amount)) {
                said.push(`${work.work_type || 'work'} charged`);
            }

            if (moved(was.vendor_amount, work.vendor_amount)) {
                said.push(`${work.work_type || 'work'} vendor rate`);
            }
        });
    } else if (props.isEdit) {
        if (moved(props.values.customer_amount, form.customer_amount)) {
            said.push('the charge');
        }

        if (moved(props.values.vendor_amount, form.vendor_amount)) {
            said.push('the vendor rate');
        }
    }

    return said;
});

/*
 * A refusal coming back from the server.
 *
 * The screen is filled from what was typed, so by then the price on it and the
 * price it is being compared against are the same figure and nothing looks
 * changed. Without this the box the refusal asks for would not be on the page.
 */
const serverAsked = computed(() => Boolean(props.errors.price_remark));

const askingWhy = computed(() => priceChanges.value.length > 0 || serverAsked.value);

const needsPriceReason = computed(() => askingWhy.value && priceRemark.value.trim() === '');

/*
 * The button bar is stuck to the bottom of the screen and the box is at the end
 * of a long form, so the office types a new price, sees Save go dead, and has
 * to go looking. This takes them there instead.
 */
const priceRemarkBox = ref(null);

function askWhy() {
    priceRemarkBox.value?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
    priceRemarkBox.value?.focus();
}

const worksCharged = computed(() =>
    keeping.value.reduce((sum, work) => sum + (Number(work.customer_amount) || 0), 0)
        + newWorks.reduce((sum, work) => sum + (Number(work.amount) || 0), 0)
);

// A work with no rate agreed leaves the folder's cost short of complete, so it
// is counted and said rather than quietly summed as nothing.
const worksUnpriced = computed(
    () => keeping.value.filter((work) => String(work.vendor_amount).trim() === '').length
);

const worksCost = computed(() =>
    keeping.value.reduce((sum, work) => sum + (Number(work.vendor_amount) || 0), 0)
);

/*
 * Work being added to this file.
 *
 * Papers turn up later for another job on the same vehicle — a permit surrender
 * on a folder taken in for a transfer. Recording it as a second file would
 * split one envelope across two numbers and two lines on the statement.
 *
 * These are rows on a form until the file is saved, so one added by mistake is
 * simply taken off again. A work that has been saved is struck off on the
 * board, where cancelling asks why and keeps the record.
 */
const newWorks = reactive([]);

// One vehicle has one transfer: a work already on the file, or already being
// added on another line, is not offered again.
function worksLeftFor(index) {
    const taken = new Set([
        ...keeping.value.map((work) => String(work.work_type_id)),
        ...newWorks.filter((_, i) => i !== index).map((work) => String(work.work_type_id)),
    ].filter(Boolean));

    // A single-work file's own work is in the boxes above, not in the panel.
    if (! multiWork.value && form.work_type_id) {
        taken.add(String(form.work_type_id));
    }

    return props.workTypes.filter((type) => ! taken.has(String(type.id)));
}

/*
 * Work being taken off the file.
 *
 * Different from cancelling it on the board, and both are wanted. Cancelling
 * says the work was booked and struck off, and it stays on the file with a
 * reason. Removing says it should never have been there.
 *
 * Marked rather than removed from the page: the row stays, struck through, with
 * a way back — a row that vanished on a click would take its charge off the
 * total with nothing to undo and nothing left to say what had gone.
 */
const removing = reactive(new Set());

// An approved work has a date and a document behind it, recording something
// that happened at the RTO. Striking it off is cancelling, on the board.
const canRemove = (work) => work.status !== props.approvedKey;

const going = (work) => removing.has(work.id);

function toggleRemove(work) {
    if (! canRemove(work)) {
        return;
    }

    removing.has(work.id) ? removing.delete(work.id) : removing.add(work.id);
}

// What the file is left for, so the last work cannot be taken off and the
// totals above say what will actually be charged.
const keeping = computed(() => works.filter((work) => ! going(work)));

/*
 * What the office paid out on this file.
 *
 * Office cash, and cost only — a challan or an affidavit leaves the till and
 * goes to nobody this application keeps a ledger for. It raises what the file
 * cost, which is the whole reason it is recorded: every margin shown before
 * this existed was too high by exactly the amount nobody was tracking.
 */
const paid = reactive(props.expenses.map((one) => ({ ...one })));
const newPaid = reactive([]);
const droppedPaid = reactive(new Set());

const typeAmount = (id) =>
    props.expenseTypes.find((type) => String(type.id) === String(id))?.amount ?? '';

function addExpense() {
    newPaid.push({
        expense_type_id: '',
        amount: '',
        spent_on: props.today,
        remark: '',
    });
}

/*
 * Choosing a kind fills in what it usually costs, on the choice only and never
 * over something already typed — a figure that recomputed itself could not be
 * corrected, and a challan is not the same at every office.
 */
function onExpenseType(row) {
    if (String(row.amount).trim() !== '') {
        return;
    }

    const usual = typeAmount(row.expense_type_id);

    if (usual !== '') {
        row.amount = Number(usual).toFixed(2);
    }
}

function dropExpense(one) {
    droppedPaid.has(one.id) ? droppedPaid.delete(one.id) : droppedPaid.add(one.id);
}

/*
 * Papers scanned against this file.
 *
 * One row per PDF: the file, and the name the customer will see it under. A
 * folder's papers are an RC, a Form 29, an NOC, and the customer is offered all
 * of them — so each needs a name a person gave it, not whatever the scanner
 * called it. Each upload is a new document and never an overwrite.
 *
 * A row per file rather than one input taking several. A browser will not let a
 * page take one file back out of a multiple picker, so a wrong pick among five
 * meant choosing all five again; a row can simply be removed, and its file goes
 * with it.
 */
const droppedDocs = reactive(new Set());

let docKey = 0;
const blankDoc = () => ({ key: docKey++, title: '', chosen: '' });
const newDocs = reactive([blankDoc()]);

/** A starting point for the name, from the file: "Form-34.pdf" reads "Form 34". */
function suggestName(filename) {
    return filename.replace(/\.pdf$/i, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
}

function onDocPicked(row, event) {
    const file = event.target.files && event.target.files[0];

    row.chosen = file ? file.name : '';

    // Suggested only into an empty box. A name already typed is the office's,
    // and the scanner's filename is not an improvement on it.
    if (file && ! row.title.trim()) {
        row.title = suggestName(file.name);
    }

    // Always a spare row at the foot, so the next PDF is one click away rather
    // than an "add another" and then a click.
    if (file && newDocs[newDocs.length - 1] === row) {
        newDocs.push(blankDoc());
    }
}

function removeNewDoc(row) {
    const at = newDocs.indexOf(row);

    if (at !== -1) {
        newDocs.splice(at, 1);
    }

    if (! newDocs.length || newDocs[newDocs.length - 1].chosen) {
        newDocs.push(blankDoc());
    }
}

/**
 * What the scanner called it, when that says something the name does not.
 *
 * A document nobody has named yet shows the scan's own name as its name, and
 * repeating it underneath with ".pdf" on the end is the same thing said twice.
 */
function arrivedNote(doc) {
    if (! doc.arrived) {
        return '';
    }

    return doc.arrived.replace(/\.pdf$/i, '') === doc.name ? '' : doc.arrived;
}

const addingDocs = computed(() => newDocs.filter((row) => row.chosen).length);

// A row with a file and no name is refused on save; said here first, in place.
const unnamedDocs = computed(() => newDocs.filter((row) => row.chosen && ! row.title.trim()).length);

function dropDoc(doc) {
    droppedDocs.has(doc.id) ? droppedDocs.delete(doc.id) : droppedDocs.add(doc.id);
}

const keepingDocs = computed(() => props.documents.filter((doc) => ! droppedDocs.has(doc.id)));

const paidTotal = computed(() =>
    paid.filter((one) => ! droppedPaid.has(one.id))
        .reduce((sum, one) => sum + (Number(one.amount) || 0), 0)
    + newPaid.reduce((sum, one) => sum + (Number(one.amount) || 0), 0)
);

const worksOnFile = computed(
    () => (multiWork.value ? keeping.value.length : 1) + newWorks.length
);

const canAddWork = computed(() => worksOnFile.value < props.workTypes.length);

/*
 * What each section that can be folded says about itself while it is shut.
 *
 * Counts and totals rather than a label, because the point of the line is to
 * answer "is there anything in there" without opening it. A file with three
 * PDFs and a folded Documents section must not look like a file with none.
 */
const worksSummary = computed(
    () => `${worksOnFile.value} ${worksOnFile.value === 1 ? 'work' : 'works'}`
);

const paidCount = computed(
    () => paid.filter((one) => ! droppedPaid.has(one.id)).length + newPaid.length
);

const paidSummary = computed(() => (paidCount.value
    ? `${paidCount.value} ${paidCount.value === 1 ? 'expense' : 'expenses'} · ${money(paidTotal.value)}`
    : 'nothing paid out'));

const docsSummary = computed(() => {
    const held = keepingDocs.value.length;
    const parts = [];

    if (held) {
        parts.push(`${held} ${held === 1 ? 'PDF' : 'PDFs'}`);
    }

    if (addingDocs.value) {
        parts.push(`${addingDocs.value} to add`);
    }

    return parts.length ? parts.join(' · ') : 'nothing uploaded';
});

function addWork() {
    if (canAddWork.value) {
        newWorks.push({ work_type_id: '', amount: '', vendor_amount: '' });
    }
}

function removeNewWork(index) {
    newWorks.splice(index, 1);
}

/**
 * Picking a type fills in what that work usually costs, never over an amount
 * already typed — the same rule the receiving screen follows.
 */
function onNewWorkType(work) {
    const type = props.workTypes.find((one) => String(one.id) === String(work.work_type_id));

    if (type && type.rate && ! work.amount) {
        work.amount = Number(type.rate).toFixed(2);
    }
}

// A settled file does not gain work: papers arriving after it is approved,
// returned or cancelled are a new file. Said here rather than on save.
const settled = computed(() =>
    [props.approvedKey, props.returnedKey, props.cancelledKey].includes(form.status)
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
            </div>

            <!--
                The works this file is for.

                Shown whenever there is more than one, because that is when the
                boxes above stop being able to say what the file is: a transfer
                and a hypothecation addition on one folder have a charge each, a
                rate each, and an approval each that arrives on its own day with
                its own document.

                Status is not editable here. A work moves on the board, where the
                evidence goes with it — this screen is for corrections.
            -->
            <CardSection
                v-if="isEdit"
                title="Works on This File"
                hint="Correct a type or a price here. Statuses move on the status board, a work at a time."
                :summary="worksSummary"
                :force-open="errorIn('items', 'new_works', 'remove_works')"
                remember="file.works">
                <div class="wf-works">

                    <div v-if="multiWork" class="ui-table-wrap">
                        <table class="ui-table wf-works__table">
                            <thead>
                                <tr>
                                    <th>Work</th>
                                    <th class="num" style="min-width: 8rem;">Charged</th>
                                    <th class="num" style="min-width: 8rem;">Vendor Rate</th>
                                    <th>Ours</th>
                                    <th>Status</th>
                                    <th>Approval</th>
                                    <th class="wf-works__off"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="work in works" :key="work.id" :class="{ 'is-going': going(work) }">
                                    <td data-label="Work">
                                        <select
                                            class="ui-select"
                                            :name="going(work) ? null : `items[${work.id}][work_type_id]`"
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
                                            :name="going(work) ? null : `items[${work.id}][customer_amount]`"
                                            v-model="work.customer_amount"
                                            placeholder="0.00">
                                    </td>

                                    <td data-label="Vendor Rate" class="num">
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="ui-input ui-input--amount"
                                            :name="going(work) ? null : `items[${work.id}][vendor_amount]`"
                                            v-model="work.vendor_amount"
                                            placeholder="Not agreed">
                                    </td>

                                    <!--
                                        Work the office is doing itself.

                                        Ticked on Give to Vendor, where it was
                                        cluttering the list of work waiting to go
                                        out. Untickable here, which is the whole
                                        reason it is here: by then the folder has
                                        left that screen and there is nowhere
                                        there to change its mind.

                                        Work already with a vendor has no box.
                                        It is with them, and calling it ours
                                        would be a second answer to a question
                                        somebody already settled.
                                    -->
                                    <td data-label="Ours" class="wf-works__ours">
                                        <label v-if="! work.has_vendor && ! going(work)" class="wf-works__keep">
                                            <input
                                                type="checkbox"
                                                :name="`items[${work.id}][in_house]`"
                                                value="1"
                                                v-model="work.in_house"
                                                :aria-label="`We are doing ${work.work_type || 'this work'} here`">
                                            <span>In-house</span>
                                        </label>
                                        <span v-else-if="work.has_vendor" class="ui-sub">with a vendor</span>
                                        <span v-else class="ui-money--nil">&mdash;</span>
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

                                    <td class="wf-works__off">
                                        <input v-if="going(work)" type="hidden" name="remove_works[]" :value="work.id">

                                        <button
                                            type="button"
                                            class="wf-work__off"
                                            :class="{ 'is-going': going(work) }"
                                            :disabled="! canRemove(work)"
                                            :title="canRemove(work)
                                                ? (going(work) ? 'Keep this work after all' : 'Take this work off the file')
                                                : 'Approved work has a date and a document behind it — cancel it on the status board'"
                                            @click="toggleRemove(work)">
                                            <i class="bi" :class="going(work) ? 'bi-arrow-counterclockwise' : 'bi-trash3'"></i>
                                            <span class="wf-sr">{{ going(work) ? 'Keep this work' : 'Remove this work' }}</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Work being added. A row on a form until this is saved. -->
                    <div v-for="(work, index) in newWorks" :key="index" class="wf-new-work">
                        <div class="ui-field">
                            <label class="ui-label">Work <span class="ui-label__req">*</span></label>
                            <select
                                class="ui-select"
                                :name="`new_works[${index}][work_type_id]`"
                                v-model="work.work_type_id"
                                @change="onNewWorkType(work)"
                                required>
                                <option value="">Select work</option>
                                <option v-for="type in worksLeftFor(index)" :key="type.id" :value="type.id">
                                    {{ type.label }}
                                </option>
                            </select>
                        </div>

                        <div class="ui-field">
                            <label class="ui-label">Charged <span class="ui-label__req">*</span></label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                class="ui-input ui-input--amount"
                                :name="`new_works[${index}][amount]`"
                                v-model="work.amount"
                                placeholder="0.00"
                                required>
                        </div>

                        <div class="ui-field">
                            <label class="ui-label">Vendor Rate</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                class="ui-input ui-input--amount"
                                :name="`new_works[${index}][vendor_amount]`"
                                v-model="work.vendor_amount"
                                placeholder="Not agreed">
                        </div>

                        <button
                            type="button"
                            class="wf-new-work__remove"
                            title="Do not add this work"
                            @click="removeNewWork(index)">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <div class="wf-works__add">
                        <button
                            type="button"
                            class="ui-btn ui-btn--sm"
                            :disabled="settled || ! canAddWork"
                            :title="settled
                                ? 'A file that is finished does not gain work — receive the papers as a new file'
                                : (canAddWork ? 'Add another work to this file' : 'Every work is already on this file')"
                            @click="addWork">
                            <i class="bi bi-plus-lg"></i> Add another work
                        </button>

                        <span v-if="settled" class="ui-hint">
                            This file is finished. Papers for more work are a new file.
                        </span>
                        <span v-else-if="removing.size" class="ui-hint ui-hint--error">
                            {{ removing.size }} {{ removing.size === 1 ? 'work comes' : 'works come' }} off the file when you save,
                            and {{ removing.size === 1 ? 'its charge' : 'their charges' }} off the statement.
                        </span>
                        <span v-else-if="newWorks.length" class="ui-hint">
                            {{ newWorks.length }} {{ newWorks.length === 1 ? 'work' : 'works' }} will be added when you save,
                            and charged to the customer.
                        </span>
                    </div>
                </div>
            </CardSection>

            <!--
                Money the office paid out on this file: a transfer challan, an
                affidavit, a notary's fee. It raises what the file cost and
                writes to no ledger, because it went out of the till rather than
                to a vendor or on to a customer.

                Folded away on a file with none, which is most of them.
            -->
            <CardSection
                v-if="isEdit"
                title="Expenses on this file"
                hint="Money the office paid out. It adds to the cost and lowers the margin; nobody's ledger moves."
                :summary="paidSummary"
                :open="expenses.length > 0"
                :force-open="errorIn('expenses', 'new_expenses', 'remove_expenses')"
                remember="file.expenses">
                <div class="wf-paid">

                    <div v-for="one in paid" :key="one.id" class="wf-paid__row" :class="{ 'is-going': droppedPaid.has(one.id) }">
                        <select
                            class="ui-select"
                            :name="`expenses[${one.id}][expense_type_id]`"
                            v-model="one.expense_type_id"
                            :disabled="droppedPaid.has(one.id)">
                            <option v-for="type in expenseTypes" :key="type.id" :value="type.id">{{ type.label }}</option>
                        </select>
                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            class="ui-input ui-input--num"
                            :name="`expenses[${one.id}][amount]`"
                            v-model="one.amount"
                            :disabled="droppedPaid.has(one.id)">
                        <input
                            type="date"
                            class="ui-input"
                            :name="`expenses[${one.id}][spent_on]`"
                            v-model="one.spent_on"
                            :max="today"
                            :disabled="droppedPaid.has(one.id)">
                        <input
                            type="text"
                            class="ui-input"
                            :name="`expenses[${one.id}][remark]`"
                            v-model="one.remark"
                            maxlength="255"
                            placeholder="What it was for"
                            :disabled="droppedPaid.has(one.id)">
                        <button
                            type="button"
                            class="ui-btn ui-btn--sm"
                            :title="droppedPaid.has(one.id) ? 'Keep this expense' : 'Take this expense off the file'"
                            @click="dropExpense(one)">
                            <i class="bi" :class="droppedPaid.has(one.id) ? 'bi-arrow-counterclockwise' : 'bi-trash'"></i>
                        </button>
                        <!-- Marked rather than removed from the page: the row
                             stays readable until the save, and an unticked mind
                             can be changed. -->
                        <input v-if="droppedPaid.has(one.id)" type="hidden" name="remove_expenses[]" :value="one.id">
                    </div>

                    <div v-for="(one, index) in newPaid" :key="`new-${index}`" class="wf-paid__row">
                        <select
                            class="ui-select"
                            :name="`new_expenses[${index}][expense_type_id]`"
                            v-model="one.expense_type_id"
                            @change="onExpenseType(one)">
                            <option value="">Select kind</option>
                            <option v-for="type in expenseTypes" :key="type.id" :value="type.id">{{ type.label }}</option>
                        </select>
                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            class="ui-input ui-input--num"
                            :name="`new_expenses[${index}][amount]`"
                            v-model="one.amount"
                            placeholder="0.00">
                        <input
                            type="date"
                            class="ui-input"
                            :name="`new_expenses[${index}][spent_on]`"
                            v-model="one.spent_on"
                            :max="today">
                        <input
                            type="text"
                            class="ui-input"
                            :name="`new_expenses[${index}][remark]`"
                            v-model="one.remark"
                            maxlength="255"
                            placeholder="What it was for">
                        <button type="button" class="ui-btn ui-btn--sm" title="Drop this line" @click="newPaid.splice(index, 1)">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <div class="wf-paid__foot">
                        <button type="button" class="ui-btn ui-btn--sm" @click="addExpense">
                            <i class="bi bi-plus-lg"></i> Add an expense
                        </button>

                        <span v-if="paidTotal" class="wf-paid__total">
                            Paid out on this file <strong>{{ money(paidTotal) }}</strong>
                        </span>
                        <span v-else class="ui-hint">Nothing recorded yet.</span>
                    </div>
                </div>
            </CardSection>

            <!--
                The papers themselves. Different from the approval screenshots
                above, which are evidence that one work came through: these are
                the file's own documents, and every one of them is offered to
                the customer under its name.
            -->
            <CardSection
                v-if="isEdit"
                title="Documents"
                hint="PDFs for this file. The customer can download every one, under the name you give it here."
                :summary="docsSummary"
                :open="documents.length > 0"
                :force-open="unnamedDocs > 0 || errorIn('documents', 'document_names', 'remove_documents')"
                remember="file.documents">
                <div class="wf-docs">

                    <div v-for="doc in documents" :key="doc.id" class="wf-docs__row" :class="{ 'is-going': droppedDocs.has(doc.id) }">
                        <i class="bi bi-file-earmark-pdf wf-docs__icon"></i>
                        <div class="wf-docs__label">
                            <!-- The name, editable in place: most documents
                                 uploaded before names existed carry only what
                                 the scanner called them. Disabled once marked
                                 for removal, so a document on its way out is not
                                 renamed on the way. -->
                            <input
                                type="text"
                                class="ui-input wf-docs__title"
                                :name="`document_names[${doc.id}]`"
                                :value="doc.name"
                                maxlength="120"
                                :disabled="droppedDocs.has(doc.id)"
                                :aria-label="`Name of the document uploaded as ${doc.arrived}`">
                            <span class="ui-hint wf-docs__meta">
                                <template v-if="arrivedNote(doc)">{{ arrivedNote(doc) }} &middot; </template>{{ doc.uploaded }}<template v-if="doc.size"> &middot; {{ doc.size }}</template>
                            </span>
                        </div>
                        <a :href="doc.url" target="_blank" rel="noopener" class="ui-btn ui-btn--sm" title="Open this PDF">
                            <i class="bi bi-box-arrow-up-right"></i> Open
                        </a>
                        <button
                            type="button"
                            class="ui-btn ui-btn--sm"
                            :title="droppedDocs.has(doc.id) ? 'Keep this document' : 'Take this document off the file'"
                            @click="dropDoc(doc)">
                            <i class="bi" :class="droppedDocs.has(doc.id) ? 'bi-arrow-counterclockwise' : 'bi-trash'"></i>
                        </button>
                        <input v-if="droppedDocs.has(doc.id)" type="hidden" name="remove_documents[]" :value="doc.id">
                    </div>

                    <div class="wf-docs__add">
                        <h6 class="wf-docs__subtitle">Add PDFs</h6>

                        <!-- Named by the row's own key, not its position, so the
                             server pairs each name with its own file even when a
                             row in the middle was removed. -->
                        <div v-for="row in newDocs" :key="row.key" class="wf-docs__pick">
                            <input
                                type="file"
                                class="ui-input wf-docs__file"
                                :name="`documents[${row.key}][file]`"
                                accept="application/pdf,.pdf"
                                aria-label="PDF to add"
                                @change="onDocPicked(row, $event)">
                            <input
                                v-model="row.title"
                                type="text"
                                class="ui-input wf-docs__title"
                                :class="{ 'is-invalid': row.chosen && ! row.title.trim() }"
                                :name="`documents[${row.key}][title]`"
                                maxlength="120"
                                placeholder="Name, e.g. RC or Form 29"
                                aria-label="Name the customer will see">
                            <button
                                v-if="row.chosen || row.title"
                                type="button"
                                class="ui-btn ui-btn--sm"
                                title="Don't add this one"
                                @click="removeNewDoc(row)">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <span v-else class="wf-docs__spacer" aria-hidden="true"></span>
                        </div>

                        <span v-if="unnamedDocs" class="ui-hint wf-docs__warn">
                            Give {{ unnamedDocs === 1 ? 'the PDF' : 'each PDF' }} a name before saving — it is what the customer sees.
                        </span>
                        <span v-else-if="addingDocs" class="ui-hint">
                            {{ addingDocs }} {{ addingDocs === 1 ? 'PDF' : 'PDFs' }} will be added when you save.
                        </span>
                        <span v-else-if="! keepingDocs.length" class="ui-hint">
                            Nothing uploaded for this file yet.
                        </span>
                        <span v-else class="ui-hint">
                            {{ keepingDocs.length }} on file. Adding another does not replace them.
                        </span>
                    </div>
                </div>
            </CardSection>

            <!-- Asked only when a price that was already agreed has moved,
                 and kept for the office: see WorkFileModel::PRICE. -->
            <div v-if="askingWhy" class="ui-card wf-why">
                <div class="ui-card__body">
                    <h2 class="ui-card__title wf-effect__title">Why is the price changing?</h2>
                    <p v-if="priceChanges.length" class="wf-why__what">
                        You are changing <strong>{{ priceChanges.join(', ') }}</strong> on a file that was
                        already priced.
                    </p>
                    <p v-else class="wf-why__what">
                        You are changing a price on a file that was already priced.
                    </p>
                    <label class="ui-label" for="price_remark">
                        Internal remark <span class="ui-label__req">*</span>
                    </label>
                    <input
                        id="price_remark"
                        ref="priceRemarkBox"
                        type="text"
                        name="price_remark"
                        class="ui-input"
                        :class="{ 'ui-input--invalid': needsPriceReason || errors.price_remark }"
                        v-model="priceRemark"
                        maxlength="200"
                        placeholder="e.g. Vendor agreed a lower rate for this office">
                    <div class="ui-hint" :class="{ 'ui-hint--error': errors.price_remark }">
                        {{ errors.price_remark || 'Kept on this file\'s history for the office. The customer never sees it.' }}
                    </div>
                </div>
            </div>

            <!--
                One save for the whole form, wherever the change was made.

                Its own bar at the foot rather than the foot of the first card:
                the fields, the works, the expenses and the documents are four
                cards now, and a Save button belonging to one of them would read
                as saving only that one.
            -->
            <div class="ui-card wf-save">
                <div class="ui-card__foot">
                    <span class="ui-hint" :class="{ 'ui-hint--error': needsPriceReason }">
                        <template v-if="needsPriceReason">
                            <button type="button" class="wf-why__jump" @click="askWhy">
                                Say why the price is changing
                            </button>
                            before saving.
                        </template>
                        <template v-else>
                            Saving rewrites this file's entries on both statements.
                        </template>
                    </span>
                    <div class="wf-actions">
                        <a :href="indexUrl" class="ui-btn">Cancel</a>
                        <button type="submit" class="ui-btn ui-btn--primary" :disabled="needsPriceReason">
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
                <!--
                    Where the papers are. Outside the main form on purpose: taking
                    a handover back is its own request with its own reason, and a
                    form cannot sit inside another.
                -->
                <div v-if="papers || handover || handoverUrl" class="ui-card wf-papers">
                    <div class="ui-card__body">
                        <h2 class="ui-card__title wf-effect__title">Papers</h2>

                        <!-- The checklist, summed up. The list itself is its own
                             page: fifteen lines with a note each do not fit here. -->
                        <div v-if="papers" class="wf-papers__check" :class="`is-${papers.state}`">
                            <p class="wf-papers__state">
                                <template v-if="papers.state === 'to_check'">
                                    <i class="bi bi-clipboard"></i>
                                    {{ papers.toCheck }} {{ papers.toCheck === 1 ? 'paper' : 'papers' }} still to check.
                                </template>
                                <template v-else-if="papers.state === 'pending'">
                                    <i class="bi bi-hourglass-split"></i>
                                    Pending: <strong>{{ papers.pending.join(', ') }}</strong>
                                </template>
                                <template v-else>
                                    <i class="bi bi-clipboard-check"></i>
                                    All {{ papers.total }} papers in.
                                </template>
                            </p>
                            <a :href="papers.url" class="ui-btn ui-btn--sm" :class="{ 'ui-btn--primary': papers.state !== 'complete' }">
                                <i class="bi bi-list-check"></i>
                                {{ papers.state === 'to_check' ? 'Check papers' : 'Open checklist' }}
                            </a>
                        </div>

                        <template v-if="handover">
                            <p class="wf-papers__state">
                                <i class="bi bi-send-check"></i>
                                Handed over to the customer on <strong>{{ handover.on }}</strong>
                            </p>
                            <dl class="wf-papers__facts">
                                <template v-if="handover.collectedBy">
                                    <dt>Collected by</dt>
                                    <dd>{{ handover.collectedBy }}</dd>
                                </template>
                                <template v-if="handover.by">
                                    <dt>Recorded by</dt>
                                    <dd>{{ handover.by }}</dd>
                                </template>
                            </dl>

                            <button
                                v-if="! undoing"
                                type="button"
                                class="ui-btn ui-btn--sm"
                                @click="undoing = true">
                                <i class="bi bi-arrow-counterclockwise"></i> Recorded by mistake?
                            </button>

                            <form v-else :action="handover.undoUrl" method="POST" class="wf-papers__undo">
                                <input type="hidden" name="_token" :value="csrf">
                                <label class="ui-label" for="undo_remark">
                                    Why is this being taken back? <span class="ui-label__req">*</span>
                                </label>
                                <input
                                    id="undo_remark"
                                    type="text"
                                    name="undo_remark"
                                    class="ui-input"
                                    :class="{ 'ui-input--invalid': errors.undo_remark }"
                                    maxlength="200"
                                    placeholder="e.g. Wrong file ticked"
                                    required>
                                <div class="ui-hint" :class="{ 'ui-hint--error': errors.undo_remark }">
                                    {{ errors.undo_remark || 'Kept on this file\'s history. The customer will no longer see the handover.' }}
                                </div>
                                <div class="wf-papers__actions">
                                    <button type="button" class="ui-btn ui-btn--sm" @click="undoing = false">Keep it</button>
                                    <button type="submit" class="ui-btn ui-btn--sm ui-btn--danger">Take back the handover</button>
                                </div>
                            </form>
                        </template>

                        <template v-else>
                            <p class="wf-papers__state">
                                <i class="bi bi-folder-check"></i>
                                Every work is approved and the papers are still here.
                            </p>
                            <a :href="handoverUrl" class="ui-btn ui-btn--sm ui-btn--primary">
                                <i class="bi bi-send-check"></i> Hand Over Papers
                            </a>
                        </template>
                    </div>
                </div>

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
                                    <template v-else-if="entry.kind === 'price'">
                                        <strong>Price changed</strong>
                                        <span class="wf-tl__office">office only</span>
                                    </template>
                                    <template v-else-if="entry.kind === 'papers'">
                                        <strong>Papers checked</strong>
                                        <template v-if="entry.from !== entry.to"> &mdash; {{ entry.from }} &rarr; {{ entry.to }}</template>
                                    </template>
                                    <template v-else-if="entry.kind === 'handover'">
                                        <strong>Papers handed over</strong>
                                    </template>
                                    <template v-else-if="entry.kind === 'handover_undone'">
                                        <strong>Handover taken back</strong>
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
/* The four cards of the form stack, with one save bar under them. */
.wf-form {
    display: flex;
    flex-direction: column;
    gap: var(--s-4);
    min-width: 0;
}

/*
 * The save bar follows the operator down the form.
 *
 * ui-card__foot was already sticky, because this form is taller than a
 * screen and a Save button that scrolls away is one nobody finds. Splitting
 * the one card into four left the foot in a card of its own, where sticking
 * to the bottom of a bar-height card means nothing — so the card is what
 * sticks now, and the foot inside it sits still.
 */
.wf-save {
    bottom: var(--s-4);
    position: sticky;
    z-index: 5;
}

/* Its whole body is the foot, so the foot's own top edge would draw a second
   line inside the card's own, and its square top corners would sit inside the
   card's rounded ones. */
.wf-save .ui-card__foot {
    border-radius: var(--r-lg);
    border-top: 0;
    position: static;
}

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
/* Each of these is a card of its own now, and a card body brings its own
   padding and its own edge with it. */
.wf-paid,
.wf-docs {
    display: flex;
    flex-direction: column;
    gap: var(--s-2);
}

/* A work being added, laid out on the same grid as the works panel above it so
   the two read as one list. */
/* A work on its way off the file. Struck through rather than gone, so what is
   about to happen can be read and undone. */
/* A narrow column: it holds one word and one box, and the money beside it
   needs the room more. */
.wf-works__ours {
    white-space: nowrap;
}

.wf-works__keep {
    align-items: center;
    cursor: pointer;
    display: inline-flex;
    font-size: var(--t-sm);
    gap: var(--s-2);
}

.wf-works__table tr.is-going td {
    background: var(--cr-050);
    opacity: 0.7;
}

.wf-works__table tr.is-going .ui-select,
.wf-works__table tr.is-going .ui-input {
    text-decoration: line-through;
}

.wf-works__off {
    text-align: right;
    width: 2.75rem;
}

.wf-work__off {
    background: none;
    border: 1px solid var(--n-200);
    border-radius: var(--r-sm);
    color: var(--n-500);
    cursor: pointer;
    height: 2.25rem;
    width: 2.25rem;
}

.wf-work__off:hover:not(:disabled) {
    background: var(--cr-050);
    border-color: var(--cr-600);
    color: var(--cr-600);
}

.wf-work__off.is-going {
    background: var(--n-000);
    border-color: var(--brand-500);
    color: var(--brand-600);
}

.wf-work__off:disabled {
    color: var(--n-300);
    cursor: not-allowed;
}

.wf-work__off:focus-visible {
    outline: 2px solid var(--brand-500);
    outline-offset: 1px;
}

/* Named for a screen reader, where an icon on its own says nothing. */
.wf-sr {
    clip: rect(0 0 0 0);
    height: 1px;
    overflow: hidden;
    position: absolute;
    width: 1px;
}

@media (pointer: coarse) {
    .wf-work__off {
        height: var(--tap);
        width: var(--tap);
    }

    .wf-works__off {
        width: calc(var(--tap) + var(--s-2));
    }
}

.wf-new-work {
    align-items: end;
    border: 1px dashed var(--brand-400);
    border-radius: var(--r-md);
    display: grid;
    gap: var(--s-3);
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr) 2.25rem;
    margin-top: var(--s-3);
    padding: var(--s-3);
}

.wf-new-work__remove {
    background: none;
    border: 1px solid var(--n-200);
    border-radius: var(--r-sm);
    color: var(--n-500);
    cursor: pointer;
    height: 2.25rem;
    width: 2.25rem;
}

.wf-new-work__remove:hover {
    background: var(--cr-050);
    border-color: var(--cr-600);
    color: var(--cr-600);
}

.wf-new-work__remove:focus-visible {
    outline: 2px solid var(--brand-500);
    outline-offset: 1px;
}

.wf-works__add {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-3);
    margin-top: var(--s-3);
}

@media (pointer: coarse) {
    .wf-new-work__remove {
        height: var(--tap);
        width: var(--tap);
    }

    .wf-new-work {
        grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr) var(--tap);
    }
}

@media (max-width: 767.98px) {
    .wf-new-work {
        grid-template-columns: minmax(0, 1fr) var(--tap);
    }

    .wf-new-work__remove {
        grid-column: 2;
        grid-row: 1 / span 3;
        height: 100%;
    }
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

/* Said on the entry itself, so nobody has to remember which of these the
   customer can read. */
.wf-tl__office {
    background: var(--n-100);
    border-radius: var(--r-sm);
    color: var(--n-600);
    font-size: var(--t-xs);
    font-weight: 700;
    margin-left: var(--s-2);
    padding: 0.05rem 0.35rem;
}

/* The reason a price moved, asked beside the money it is about. Tinted, so it
   reads as a question among the white cards rather than another box to fill. */
.wf-why {
    background: var(--warn-050);
    border-color: var(--warn-500);
}

/* A sentence in the button bar, not a button-looking thing: it goes to the box
   rather than doing anything to the file. */
.wf-why__jump {
    background: none;
    border: 0;
    color: inherit;
    cursor: pointer;
    font: inherit;
    padding: 0;
    text-decoration: underline;
}

.wf-why__what {
    color: var(--n-700);
    font-size: var(--t-sm);
    margin: 0 0 var(--s-2);
}

/* ---- Where the papers are ---------------------------------------------- */

.wf-papers__state {
    align-items: baseline;
    color: var(--n-700);
    display: flex;
    font-size: var(--t-sm);
    gap: var(--s-2);
    margin: 0 0 var(--s-2);
}

.wf-papers__state .bi {
    color: var(--dr-600);
}

/* The checklist's summary sits above the handover, and apart from it. */
.wf-papers__check {
    align-items: flex-start;
    display: flex;
    flex-direction: column;
    gap: var(--s-2);
}

.wf-papers__check + * {
    border-top: 1px solid var(--n-100);
    margin-top: var(--s-3);
    padding-top: var(--s-3);
}

.wf-papers__check.is-pending .wf-papers__state .bi {
    color: var(--warn-600);
}

.wf-papers__check.is-to_check .wf-papers__state .bi {
    color: var(--n-500);
}

.wf-papers__facts {
    display: grid;
    font-size: var(--t-sm);
    gap: var(--s-1) var(--s-3);
    grid-template-columns: auto minmax(0, 1fr);
    margin: 0 0 var(--s-3);
}

.wf-papers__facts dt {
    color: var(--n-500);
    font-weight: 600;
}

.wf-papers__facts dd {
    color: var(--n-800);
    margin: 0;
    overflow-wrap: anywhere;
}

.wf-papers__undo {
    display: flex;
    flex-direction: column;
    gap: var(--s-2);
}

.wf-papers__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2);
    justify-content: flex-end;
}

/* On a phone each fact sits under its label rather than beside it, where a
   long name would push the value to one word a line. */
@media (max-width: 575.98px) {
    .wf-papers__facts {
        grid-template-columns: minmax(0, 1fr);
    }

    .wf-papers__facts dd {
        margin-bottom: var(--s-2);
    }
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

/* Money the office paid out on a file. Laid out as a line per expense so a
   challan and an affidavit read as two entries rather than one paragraph. */
.wf-paid__row {
    align-items: center;
    display: grid;
    gap: var(--s-2);
    grid-template-columns: minmax(8rem, 1fr) 7rem 9rem minmax(8rem, 1.4fr) auto;
}

.wf-paid__row.is-going {
    opacity: 0.55;
}

.wf-paid__foot {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-3);
    justify-content: space-between;
}

.wf-paid__total {
    font-size: var(--t-sm);
}

@media (max-width: 767.98px) {
    .wf-paid__row {
        grid-template-columns: 1fr 1fr;
    }
}

/* Papers scanned against a file. A line each, so a form and its annexure
   read as two documents rather than one run of text. */
.wf-docs__row {
    align-items: start;
    display: grid;
    gap: var(--s-2) var(--s-3);
    grid-template-columns: auto minmax(0, 1fr) auto auto;
}

.wf-docs__icon {
    color: var(--cr-600);
    font-size: 1.35rem;
    line-height: 2.2rem;
}

.wf-docs__label {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: 0;
}

.wf-docs__meta {
    overflow-wrap: anywhere;
}

.wf-docs__row.is-going {
    opacity: 0.55;
}

.wf-docs__row.is-going .wf-docs__title {
    text-decoration: line-through;
}

.wf-docs__add {
    border-top: 1px dashed var(--n-200);
    display: flex;
    flex-direction: column;
    gap: var(--s-2);
    margin-top: var(--s-2);
    padding-top: var(--s-3);
}

.wf-docs__subtitle {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.06em;
    margin: 0;
    text-transform: uppercase;
}

/* The file, its name, and the way out of adding it — side by side on a desk,
   so the name sits beside the file it belongs to. */
.wf-docs__pick {
    align-items: center;
    display: grid;
    gap: var(--s-2);
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 2.2rem;
}

.wf-docs__pick .ui-input {
    min-width: 0;
}

.wf-docs__spacer {
    display: block;
}

.wf-docs__title.is-invalid {
    border-color: var(--cr-600);
}

.wf-docs__warn {
    color: var(--cr-700);
    font-weight: 600;
}

@media (max-width: 575.98px) {
    /* The name takes the width it needs and the rest sits under it, rather
       than four columns squeezing a filename to three characters. */
    .wf-docs__row {
        grid-template-columns: auto minmax(0, 1fr);
    }

    /* On a phone the file and its name stack, with the remove button beside
       the name it removes. */
    .wf-docs__pick {
        grid-template-columns: minmax(0, 1fr) 2.2rem;
    }

    .wf-docs__pick .wf-docs__file {
        grid-column: 1 / -1;
    }
}
</style>
