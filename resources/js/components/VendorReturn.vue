<script setup>
/*
 * Taking a batch of files back off vendors.
 *
 * Field names match what WorkFileController::vendorReturn() already validates,
 * so the form still posts normally and the server still checks every value.
 * That is what makes it safe to convert a screen on a live ledger: only the
 * rendering moves, the money logic does not.
 *
 * Reversing a booking is the whole point of the screen, so the two figures that
 * decide what the ledger does sit beside each other on every row — what was
 * booked to that vendor, and how much of it is coming back. Blank means all of
 * it, which is the common case and the reason the box may be left alone.
 */
import { computed, reactive, ref } from 'vue';
import { money } from '../money';

const props = defineProps({
    files: { type: Array, default: () => [] },
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    cancelUrl: { type: String, required: true },
    returnedOn: { type: String, default: '' },
    returnedOnDisplay: { type: String, default: '' },
    remark: { type: String, default: '' },
    pickedIds: { type: Array, default: () => [] },
    oldAmounts: { type: Object, default: () => ({}) },
});

/*
 * One row of working state per file. Old input is folded in, so a batch the
 * server bounced comes back with the same files ticked and the same figures
 * typed rather than making someone rebuild it.
 */
const rows = reactive(
    props.files.map((file) => ({
        ...file,
        picked: props.pickedIds.some((id) => Number(id) === Number(file.id)),
        amount: props.oldAmounts[file.id] ?? '',
    }))
);

const reason = ref(props.remark ?? '');

// A file booked at nothing reverses nothing, which is how the server reads a
// missing vendor_amount too.
const booked = (row) => Number(row.vendor_amount) || 0;

/*
 * Ticking a file fills in what was booked to the vendor, so the figure being
 * reversed is visible rather than implied by a grey placeholder. Done on the
 * tick alone, never inside a recompute — a recompute runs on every keystroke,
 * so filling in there makes the box impossible to clear or edit.
 */
function prefill(row) {
    if (row.picked && String(row.amount).trim() === '') {
        row.amount = booked(row).toFixed(2);
    }
}

// Old input comes back ticked but not always filled in.
rows.forEach(prefill);

/**
 * Why a typed figure cannot stand, in the same terms the server refuses it: an
 * amount above zero, and never more than was booked to that vendor. Saying so
 * here beats bouncing the whole batch back with a list of file numbers.
 */
function problem(row) {
    if (!row.picked) {
        return null;
    }

    const typed = String(row.amount).trim();

    // Blank is an instruction, not an omission: reverse the whole booking.
    if (typed === '') {
        return null;
    }

    const value = Number(typed);

    if (!(value > 0)) {
        return 'zero';
    }

    return value > booked(row) ? 'over' : null;
}

function hint(row) {
    const wrong = problem(row);

    if (wrong === 'over') {
        return `Only ${money(booked(row))} was booked here`;
    }

    if (wrong === 'zero') {
        return 'Reverse an amount, or leave it blank';
    }

    return 'Blank reverses it all';
}

const picked = computed(() => rows.filter((row) => row.picked));
const wrong = computed(() => rows.filter((row) => problem(row) !== null));

const total = computed(() =>
    picked.value.reduce((sum, row) => {
        const typed = String(row.amount).trim();

        return sum + (typed === '' ? booked(row) : (Number(typed) || 0));
    }, 0)
);

/*
 * A take-back moves a vendor's balance, so the server refuses a batch with no
 * reason and each file's history keeps the one that was given.
 */
const needsReason = computed(() => picked.value.length > 0 && reason.value.trim() === '');

const blocked = computed(() => needsReason.value || wrong.value.length > 0);

const allPicked = computed({
    get: () => rows.length > 0 && rows.every((row) => row.picked),
    set: (value) => {
        rows.forEach((row) => {
            row.picked = value;
            prefill(row);
        });
    },
});

const somePicked = computed(() => rows.some((row) => row.picked));

const summary = computed(() => {
    if (!picked.value.length) {
        return { tone: 'quiet', text: 'Nothing ticked yet.' };
    }

    if (wrong.value.length) {
        const overs = rows.filter((row) => problem(row) === 'over').length;
        const zeros = rows.filter((row) => problem(row) === 'zero').length;
        const parts = [];

        if (overs) {
            parts.push(`${overs} ${overs === 1 ? 'reversal is' : 'reversals are'} more than was booked`);
        }
        if (zeros) {
            parts.push(`${zeros} ${zeros === 1 ? 'is' : 'are'} not an amount`);
        }

        return { tone: 'error', text: parts.join(', ') + '.' };
    }

    if (needsReason.value) {
        return { tone: 'error', text: 'A reason is required — the vendor\'s balance moves.' };
    }

    const count = picked.value.length;

    return {
        tone: 'ready',
        text: `${count} ${count === 1 ? 'file comes' : 'files come'} back, ${money(total.value)} off what is owed to vendors.`,
    };
});
</script>

<template>
    <form class="ui ui-page" :action="action" method="POST">
        <!-- Rendered here rather than passed as a slot: the component is mounted
             onto a bare element, so there is no server markup to slot in. -->
        <input type="hidden" name="_token" :value="csrf">

        <div v-if="!rows.length" class="ui-empty">
            <div class="ui-empty__icon"><i class="bi bi-inbox"></i></div>
            <div class="ui-empty__title">Nothing out</div>
            <div>No files are with a vendor at the moment.</div>
        </div>

        <template v-else>
            <div class="ui-card">
                <div class="ui-card__head">
                    <h2 class="ui-card__title">When and Why</h2>
                </div>

                <div class="ui-card__body vr-when">
                    <!-- The calendar popup takes ownership of these two inputs at
                         DOMContentLoaded, which is after this component mounts.
                         v-once keeps Vue from patching its work back out. -->
                    <div class="ui-field" v-once>
                        <label class="ui-label" for="returned_on_display">
                            Returned On <span class="ui-label__req">*</span>
                        </label>
                        <div class="vr-inset">
                            <i class="bi bi-calendar3"></i>
                            <input
                                type="text"
                                class="ui-input js-datefield"
                                id="returned_on_display"
                                data-target="returned_on"
                                :value="returnedOnDisplay"
                                placeholder="dd-mm-yyyy"
                                inputmode="numeric"
                                maxlength="10"
                                autocomplete="off"
                                required>
                        </div>
                        <input type="hidden" id="returned_on" name="returned_on" :value="returnedOn">
                    </div>

                    <div class="ui-field">
                        <label class="ui-label" for="vr_remark">
                            Reason <span class="ui-label__req">*</span>
                        </label>
                        <div class="vr-inset">
                            <i class="bi bi-chat-left-text"></i>
                            <input
                                type="text"
                                class="ui-input"
                                :class="{ 'ui-input--invalid': needsReason }"
                                id="vr_remark"
                                name="remark"
                                v-model="reason"
                                maxlength="200"
                                placeholder="Why the papers came back"
                                required>
                        </div>
                        <div class="ui-hint" :class="{ 'ui-hint--error': needsReason }">
                            This changes the vendor's balance, so the reason is kept on each file's history.
                        </div>
                    </div>
                </div>
            </div>

            <div class="ui-card">
                <div class="ui-card__head">
                    <div>
                        <h2 class="ui-card__title">Files Out With Vendors</h2>
                        <div class="ui-hint vr-blurb">
                            Taking a file back reverses what was booked to that vendor. Their statement keeps
                            both lines &mdash; what was booked and what came back &mdash; and nets to nothing
                            owed. The file returns to <strong>In Office</strong>.
                        </div>
                    </div>

                    <label class="vr-all">
                        <input
                            type="checkbox"
                            class="vr-tick"
                            v-model="allPicked"
                            :indeterminate="somePicked && !allPicked">
                        Select all
                    </label>
                </div>

                <div class="ui-card__body">
                    <div class="ui-table-wrap">
                        <table class="ui-table vr-table">
                            <thead>
                                <tr>
                                    <th class="vr-pick"></th>
                                    <th>File No.</th>
                                    <th>Vendor</th>
                                    <th>Given On</th>
                                    <th>Work Type</th>
                                    <th>Details</th>
                                    <th>Customer</th>
                                    <th class="num">Booked to Vendor</th>
                                    <th style="min-width: 10rem;">Reverse</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in rows"
                                    :key="row.id"
                                    :class="{ 'is-picked': row.picked, 'is-blocked': problem(row) !== null }">
                                    <td data-label="Take back" class="vr-pick">
                                        <input
                                            type="checkbox"
                                            class="vr-tick"
                                            name="files[]"
                                            :value="row.id"
                                            v-model="row.picked"
                                            @change="prefill(row)">
                                    </td>

                                    <td data-label="File No."><span class="ui-lead">{{ row.file_no }}</span></td>

                                    <td data-label="Vendor">{{ row.vendor || '—' }}</td>

                                    <td data-label="Given On">{{ row.vendor_date || '—' }}</td>

                                    <td data-label="Work Type">{{ row.work_type || '—' }}</td>

                                    <td data-label="Details">{{ row.description || '—' }}</td>

                                    <td data-label="Customer">{{ row.customer || '—' }}</td>

                                    <td data-label="Booked to Vendor" class="num">
                                        <span v-if="row.vendor_amount === null" class="ui-money ui-money--nil">—</span>
                                        <span v-else class="ui-money ui-money--cr">{{ money(row.vendor_amount) }}</span>
                                    </td>

                                    <td data-label="Reverse">
                                        <!-- Disabled, so an amount left on an unticked row posts nothing. -->
                                        <input
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            :max="row.vendor_amount"
                                            class="ui-input ui-input--amount"
                                            :class="{ 'ui-input--invalid': problem(row) !== null }"
                                            :name="`amounts[${row.id}]`"
                                            v-model="row.amount"
                                            :disabled="!row.picked"
                                            :placeholder="booked(row).toFixed(2)">
                                        <div class="ui-hint" :class="{ 'ui-hint--error': problem(row) !== null }">
                                            {{ hint(row) }}
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="vr-total">
                        <span class="ui-stat__label">Total Reversed to Vendors</span>
                        <span class="ui-money ui-money--dr vr-total__value">{{ money(total) }}</span>
                    </div>
                </div>

                <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': picked.length && !blocked }">
                    <span class="ui-hint" :class="{ 'ui-hint--error': summary.tone === 'error' }">{{ summary.text }}</span>
                    <div class="vr-actions">
                        <a :href="cancelUrl" class="ui-btn">Cancel</a>
                        <button type="submit" class="ui-btn ui-btn--primary" :disabled="!picked.length || blocked">
                            <i class="bi bi-arrow-return-left"></i> Take Files Back
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </form>
</template>

<style>
/* Date and reason are typed once for the whole batch and read together, so they
   share a line on anything wider than a phone. */
.vr-when {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: minmax(11rem, 16rem) minmax(0, 1fr);
}

.vr-inset {
    position: relative;
}

.vr-inset > .bi {
    color: var(--n-400);
    left: var(--s-3);
    pointer-events: none;
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
}

.vr-inset > .ui-input {
    padding-left: var(--s-8);
}

.vr-blurb {
    margin-top: var(--s-1);
    max-width: 46rem;
}

.vr-all {
    align-items: center;
    color: var(--n-600);
    cursor: pointer;
    display: inline-flex;
    font-size: var(--t-sm);
    font-weight: 600;
    gap: var(--s-2);
    white-space: nowrap;
}

.vr-tick {
    accent-color: var(--brand-500);
    cursor: pointer;
    height: 1.05rem;
    margin: 0;
    width: 1.05rem;
}

.vr-table th.vr-pick,
.vr-table td.vr-pick {
    width: 2.5rem;
}

/* Ticked rows are the batch; a row the server would refuse is pulled out of it. */
.vr-table tbody tr.is-picked td {
    background: var(--brand-050);
}

.vr-table tbody tr.is-blocked td {
    background: var(--cr-050);
}

/* The one figure anyone checks before saving, so it sits under the table on its
   own rather than as another column of it. */
.vr-total {
    align-items: baseline;
    background: var(--dr-050);
    border: 1px solid var(--dr-600);
    border-radius: var(--r-md);
    display: flex;
    gap: var(--s-4);
    justify-content: space-between;
    margin-left: auto;
    margin-top: var(--s-4);
    max-width: 28rem;
    padding: var(--s-3) var(--s-4);
}

.vr-total__value {
    font-size: var(--t-xl);
    font-weight: 700;
}

.vr-actions {
    display: flex;
    gap: var(--s-2);
}

@media (max-width: 991.98px) {
    /* Below the large breakpoint each row becomes a card: nine columns of ledger
       do not fit a phone, and this is a table of controls, which a sideways
       scrollbar makes close to unusable. */
    .vr-table,
    .vr-table tbody,
    .vr-table tr,
    .vr-table td {
        display: block;
        width: 100%;
    }

    .vr-table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
    }

    .vr-table tbody tr {
        border: 1px solid var(--n-200);
        border-radius: var(--r-md);
        margin-bottom: var(--s-3);
        padding: var(--s-2) var(--s-3);
    }

    .vr-table tbody td {
        border-bottom: 0;
        padding: var(--s-2) 0;
    }

    .vr-table tbody td::before {
        color: var(--n-500);
        content: attr(data-label);
        display: block;
        font-size: var(--t-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        margin-bottom: var(--s-1);
        text-transform: uppercase;
    }

    .vr-table tbody td.num {
        text-align: left;
    }

    .vr-total {
        margin-left: 0;
        max-width: none;
    }

    .vr-actions {
        flex: 1 1 auto;
    }

    .vr-actions .ui-btn {
        flex: 1 1 auto;
    }
}

@media (max-width: 767.98px) {
    .vr-when {
        grid-template-columns: 1fr;
    }
}
</style>
