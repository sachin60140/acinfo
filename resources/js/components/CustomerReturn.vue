<script setup>
/*
 * Returning a batch of files to the customer.
 *
 * Field names match what WorkFileController::customerReturn() already
 * validates — files[], amounts[<id>], remark, returned_on — so the form still
 * posts normally and the server still checks every value. That is what makes it
 * safe to convert a screen on a live ledger: only the rendering moves, the money
 * logic does not.
 *
 * Every refund here credits a customer, and the server refuses the whole batch
 * over one bad figure or a missing reason. So the rules it would bounce on are
 * shown before the batch is sent rather than after.
 */
import { computed, reactive, ref } from 'vue';
import { money } from '../money';

const props = defineProps({
    files: { type: Array, default: () => [] },
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    cancelUrl: { type: String, required: true },
    returnedOn: { type: String, default: '' },
    oldRemark: { type: String, default: '' },
    oldFiles: { type: Array, default: () => [] },
    oldAmounts: { type: Object, default: () => ({}) },
});

/*
 * One row of working state per file, restored from old input so a bounced batch
 * comes back with the same files ticked and the same figures typed — including
 * the over-refund that bounced it, which is the one the operator has to find.
 */
const wasPicked = new Set(props.oldFiles.map(String));

const rows = reactive(
    props.files.map((file) => ({
        ...file,
        picked: wasPicked.has(String(file.id)),
        // Blank is a real answer: the server reads a missing amount as the whole
        // charge going back.
        amount: props.oldAmounts[file.id] ?? '',
    }))
);

const remark = ref(props.oldRemark ?? '');

const typed = (row) => String(row.amount).trim();

/** What this row credits: what was typed, or the whole charge when left blank. */
const refundOf = (row) =>
    typed(row) === '' ? Number(row.customer_amount) || 0 : Number(typed(row)) || 0;

const chosen = computed(() => rows.filter((row) => row.picked));
const total = computed(() => chosen.value.reduce((sum, row) => sum + refundOf(row), 0));

const allPicked = computed(() => rows.length > 0 && chosen.value.length === rows.length);
const somePicked = computed(() => chosen.value.length > 0);

// You cannot hand back more than the customer was charged; the server checks
// this too and names the offending files.
const overRefunded = (row) =>
    row.picked && typed(row) !== '' && Number(typed(row)) > Number(row.customer_amount);

// 'nullable|numeric|gt:0' — a zero or a minus is a rejected batch, not a nil refund.
const notPositive = (row) => row.picked && typed(row) !== '' && !(Number(typed(row)) > 0);

const invalid = (row) => overRefunded(row) || notPositive(row);

/*
 * Money moves back, so it has to say why. Only raised once something is ticked:
 * nagging for a reason before a file is chosen is noise.
 */
const needsReason = computed(() => somePicked.value && remark.value.trim() === '');

const blocked = computed(() => !somePicked.value || needsReason.value || rows.some(invalid));

const summary = computed(() => {
    const over = rows.filter(overRefunded).length;
    const zero = rows.filter(notPositive).length;
    const parts = [];

    if (needsReason.value) {
        parts.push("Returning a file changes the customer's balance, so it needs a reason.");
    }
    if (over) {
        parts.push(`${over} ${over === 1 ? 'refund is' : 'refunds are'} more than what was charged.`);
    }
    if (zero) {
        parts.push(`${zero} ${zero === 1 ? 'refund' : 'refunds'} must be more than zero.`);
    }

    if (parts.length) {
        return { tone: 'error', text: parts.join(' ') };
    }

    if (!somePicked.value) {
        return { tone: 'quiet', text: 'Tick the files going back.' };
    }

    const count = chosen.value.length;

    return {
        tone: 'ready',
        text: `${count} ${count === 1 ? 'file goes' : 'files go'} back, ${money(total.value)} credited.`,
    };
});

/*
 * Ticking a file fills in what was charged, so the figure going back is visible
 * rather than implied by a grey placeholder. Done on the tick alone, never in a
 * recompute — a recompute runs on every keystroke, so filling in there would
 * make the box impossible to clear or edit.
 */
function prefill(row) {
    if (row.picked && typed(row) === '') {
        row.amount = Number(row.customer_amount).toFixed(2);
    }
}

function toggleAll(checked) {
    rows.forEach((row) => {
        row.picked = checked;
        prefill(row);
    });
}

// Old input can come back with files already ticked and their amounts cleared.
rows.forEach((row) => prefill(row));

const BADGES = {
    in_office: 'neutral',
    paper_pendency: 'warn',
    file_dispatch: 'info',
    part_pesi_required: 'warn',
    under_verification: 'brand',
    approval_done: 'dr',
    paper_returned: 'dark',
    cancelled: 'cr',
};

const badge = (row) => `ui-badge ui-badge--${BADGES[row.status] ?? 'neutral'}`;

/*
 * dd-mm-yyyy for the box, Y-m-d for the server — the same pair
 * partials/_datefield.blade.php renders. Read once and never rebound, so the
 * calendar owns both fields from the moment it binds them.
 */
const stamp = String(props.returnedOn).match(/^(\d{4})-(\d{2})-(\d{2})$/);
const displayDate = stamp ? `${stamp[3]}-${stamp[2]}-${stamp[1]}` : '';
</script>

<template>
    <div v-if="!rows.length" class="ui ui-empty">
        <div class="ui-empty__icon"><i class="bi bi-inbox"></i></div>
        <div class="ui-empty__title">Nothing to return</div>
        <div>Everything received has already been returned or cancelled.</div>
    </div>

    <!-- Not "cr": party/_style.blade.php, which this page includes, uses .cr to
         mean credit and paints it red. Both rules sit at the same specificity,
         so which one wins is decided by stylesheet order alone. -->
    <form v-else class="ui cr-return" :action="action" method="POST">
        <!-- Rendered here rather than passed as a slot: the component is mounted
             onto a bare element, so there is no server markup to slot in. -->
        <input type="hidden" name="_token" :value="csrf">

        <div class="ui-card">
            <div class="ui-card__head">
                <h2 class="ui-card__title">When and Why</h2>
            </div>

            <div class="ui-card__body cr-when">
                <!--
                    public/assets/js/datepicker.js binds every .js-datefield on
                    DOMContentLoaded, which fires after this component has
                    mounted, so it picks this one up and owns both boxes from
                    then on.

                    v-once is what keeps it owning them. A :value binding is a
                    rebinding: Vue force-patches the "value" key on every
                    re-render even when the bound value has not changed
                    (runtime-core, patchProps: `next !== prev || key ===
                    "value"`), comparing against the live DOM value — so it puts
                    the page-load default back over the date the operator chose.
                    Ticking a file or typing the reason was enough to trigger it,
                    and the result still passes date_format:Y-m-d, so the refund
                    posted against the wrong date silently.
                -->
                <div class="ui-field cr-when__date" v-once>
                    <label class="ui-label" for="returned_on_display">
                        Returned On <span class="ui-label__req">*</span>
                    </label>
                    <input
                        type="text"
                        id="returned_on_display"
                        class="ui-input js-datefield"
                        :value="displayDate"
                        data-target="returned_on"
                        placeholder="dd-mm-yyyy"
                        inputmode="numeric"
                        maxlength="10"
                        autocomplete="off"
                        required>
                    <input type="hidden" id="returned_on" name="returned_on" :value="returnedOn">
                </div>

                <div class="ui-field cr-when__reason">
                    <label class="ui-label" for="remark">
                        Reason <span class="ui-label__req">*</span>
                    </label>
                    <input
                        type="text"
                        id="remark"
                        name="remark"
                        class="ui-input"
                        :class="{ 'ui-input--invalid': needsReason }"
                        v-model="remark"
                        maxlength="200"
                        placeholder="Why the papers are going back"
                        required>
                    <div class="ui-hint" :class="{ 'ui-hint--error': needsReason }">
                        <template v-if="needsReason">
                            Returning a file changes the customer's balance, so it needs a reason.
                        </template>
                        <template v-else>
                            This changes the customer's balance, so the reason is kept on each file's history.
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <div class="ui-card">
            <div class="ui-card__head">
                <div>
                    <h2 class="ui-card__title">Files You Can Return</h2>
                    <div class="ui-page__sub">
                        The refund is filled in with what the customer was charged. Reduce it if you are
                        keeping part &mdash; for fees already paid or a trip already made. The original
                        charge stays on their statement with the refund beside it.
                    </div>
                </div>

                <label class="cr-all">
                    <input
                        type="checkbox"
                        class="cr-check"
                        :checked="allPicked"
                        :indeterminate="somePicked && !allPicked"
                        @change="toggleAll($event.target.checked)">
                    Select all
                </label>
            </div>

            <div class="ui-table-wrap">
                <table class="ui-table cr-table">
                    <thead>
                        <tr>
                            <th class="cr-tick">Return</th>
                            <th>File No.</th>
                            <th>Received</th>
                            <th>Work Type</th>
                            <th>Details</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th class="num">Charged</th>
                            <th class="num" style="min-width: 10rem;">Refund</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            :class="{ 'is-picked': row.picked, 'is-blocked': invalid(row) }">
                            <td data-label="Return" class="cr-tick">
                                <input
                                    type="checkbox"
                                    class="cr-check"
                                    name="files[]"
                                    :value="row.id"
                                    v-model="row.picked"
                                    @change="prefill(row)"
                                    :aria-label="`Return file ${row.file_no}`">
                            </td>

                            <td data-label="File No."><span class="ui-lead">{{ row.file_no }}</span></td>

                            <td data-label="Received">{{ row.received_date }}</td>

                            <td data-label="Work Type">{{ row.work_type || '—' }}</td>

                            <td data-label="Details">{{ row.description || '—' }}</td>

                            <td data-label="Customer">{{ row.customer || '—' }}</td>

                            <td data-label="Status">
                                <span :class="badge(row)">{{ row.status_label }}</span>
                            </td>

                            <td data-label="Charged" class="num">
                                <span class="ui-money ui-money--dr">{{ money(row.customer_amount) }}</span>
                            </td>

                            <td data-label="Refund">
                                <!-- Disabled rather than hidden: a disabled input posts
                                     nothing, so an amount left on an unticked row cannot
                                     reach the server. -->
                                <input
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    :max="row.customer_amount"
                                    class="ui-input ui-input--amount"
                                    :class="{ 'ui-input--invalid': invalid(row) }"
                                    :name="`amounts[${row.id}]`"
                                    v-model="row.amount"
                                    :disabled="!row.picked"
                                    :placeholder="money(row.customer_amount)"
                                    :aria-label="`Refund for file ${row.file_no}`">

                                <div v-if="overRefunded(row)" class="ui-hint ui-hint--error">
                                    More than the {{ money(row.customer_amount) }} charged
                                </div>
                                <div v-else-if="notPositive(row)" class="ui-hint ui-hint--error">
                                    Must be more than zero
                                </div>
                                <div v-else-if="row.picked && typed(row) === ''" class="ui-hint">
                                    Blank returns all {{ money(row.customer_amount) }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="cr-totals">
                <div class="cr-total">
                    <span class="ui-label">Total Credited to Customers</span>
                    <span class="ui-money ui-money--cr cr-total__value">{{ money(total) }}</span>
                </div>
            </div>

            <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': somePicked && !blocked }">
                <span class="ui-hint" :class="{ 'ui-hint--error': summary.tone === 'error' }">
                    {{ summary.text }}
                </span>
                <div class="cr-actions">
                    <a :href="cancelUrl" class="ui-btn">Cancel</a>
                    <button type="submit" class="ui-btn ui-btn--primary" :disabled="blocked">
                        <i class="bi bi-arrow-return-left"></i> Return to Customer
                    </button>
                </div>
            </div>
        </div>
    </form>
</template>

<style>
.cr {
    display: flex;
    flex-direction: column;
    gap: var(--s-4);
}

.cr-when {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-4);
}

.cr-when__date { flex: 0 0 12rem; }
.cr-when__reason { flex: 1 1 24rem; }

.cr-all {
    align-items: center;
    color: var(--n-600);
    cursor: pointer;
    display: inline-flex;
    font-size: var(--t-sm);
    font-weight: 600;
    gap: var(--s-2);
    white-space: nowrap;
}

.cr-check {
    accent-color: var(--brand-500);
    cursor: pointer;
    height: 1.05rem;
    width: 1.05rem;
}

.cr-table .cr-tick {
    width: 2.5rem;
}

/* The chosen rows have to be findable in a long list at a glance. */
.cr-table tbody tr.is-picked td { background: var(--brand-050); }
.cr-table tbody tr.is-blocked td { background: var(--cr-050); }

.cr-totals {
    display: flex;
    justify-content: flex-end;
    padding: var(--s-4) var(--s-5) 0;
}

/* The one figure that matters is the one leaving, so it is stated on its own. */
.cr-total {
    align-items: baseline;
    background: var(--cr-050);
    border: 1px solid var(--cr-600);
    border-radius: var(--r-md);
    display: flex;
    flex: 0 1 26rem;
    gap: var(--s-4);
    justify-content: space-between;
    padding: var(--s-3) var(--s-4);
}

.cr-total__value {
    font-size: var(--t-xl);
    font-weight: 700;
}

.cr-actions {
    display: flex;
    gap: var(--s-2);
}

@media (pointer: coarse) {
    .cr-check {
        height: 1.35rem;
        width: 1.35rem;
    }
}

/* Below the large breakpoint each row becomes a card: nine columns of ledger do
   not fit a phone, and this screen is a table of controls, which a sideways
   scrollbar makes close to unusable. */
@media (max-width: 991.98px) {
    .cr-table,
    .cr-table tbody,
    .cr-table tr,
    .cr-table td {
        display: block;
        width: 100%;
    }

    .cr-table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
    }

    .cr-table tbody tr {
        border: 1px solid var(--n-200);
        border-radius: var(--r-md);
        margin-bottom: var(--s-3);
        padding: var(--s-2) var(--s-3);
    }

    .cr-table tbody td {
        border-bottom: 0;
        padding: var(--s-2) 0;
    }

    .cr-table tbody td::before {
        color: var(--n-500);
        content: attr(data-label);
        display: block;
        font-size: var(--t-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        margin-bottom: var(--s-1);
        text-transform: uppercase;
    }

    .cr-table tbody td.num {
        text-align: left;
    }

    .cr-table .cr-tick {
        width: auto;
    }

    .cr-totals {
        padding: var(--s-4) var(--s-3) 0;
    }

    .cr-total {
        flex: 1 1 auto;
    }

    .cr-actions {
        flex: 1 1 auto;
    }

    .cr-actions .ui-btn {
        flex: 1 1 auto;
    }
}
</style>
