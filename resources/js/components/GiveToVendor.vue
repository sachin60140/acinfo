<script setup>
/*
 * Handing a batch of files to one vendor.
 *
 * Field names are the ones WorkFileController::assign() already validates —
 * vendor_id, vendor_date, files[], amounts[<job id>] and remark — so the form still
 * posts normally and the server still checks every value. That is what makes it
 * safe to convert a screen on a live ledger: only the rendering moves, the money
 * logic does not.
 *
 * The screen's job is one handover at a counter: tick the files going out, agree
 * a price on each, and see before saving what the vendor is being credited in
 * total and where that leaves their balance.
 *
 * A folder is ticked whole — the envelope goes across the counter in one piece —
 * but the price is agreed per job inside it. Papers for a transfer and a
 * hypothecation addition are one file, two works, and two costs.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { balance, money, side } from '../money';

const props = defineProps({
    files: { type: Array, default: () => [] },
    vendors: { type: Array, default: () => [] },
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    cancelUrl: { type: String, required: true },
    // Repopulation after a failed validation, so nothing typed is lost.
    vendorId: { type: [Number, String], default: '' },
    vendorDate: { type: String, default: '' },
    vendorDateDisplay: { type: String, default: '' },
    remark: { type: String, default: '' },
    pickedFiles: { type: Array, default: () => [] },
    oldAmounts: { type: Object, default: () => ({}) },
});

const chosen = ref(props.vendorId);
const remarkText = ref(props.remark);

/*
 * The ticked ids, bound straight to the checkboxes. An unticked box posts
 * nothing of its own, and its amount is disabled below, so an amount typed
 * against a file that is not going out cannot reach the vendor's ledger.
 */
const picked = ref(props.pickedFiles.map(Number));

// Keyed on the job, because that is what carries a rate.
const amounts = reactive(
    Object.fromEntries(
        props.files.flatMap((file) =>
            file.items.map((item) => [item.id, props.oldAmounts[item.id] ?? ''])
        )
    )
);

const isPicked = (file) => picked.value.includes(file.id);

const jobs = (file) => file.items ?? [];

/*
 * Ticking a file fills in what this kind of work usually costs.
 *
 * On the tick only, and never over something already typed. A rate that
 * recomputed itself could not be cleared — and a blank box has always meant
 * "not agreed yet", which is a thing the operator has to be able to say.
 *
 * It fills from the work type's vendor cost, never from what the customer is
 * charged. Those are the two sides of the job and the gap between them is the
 * margin; using one for the other would credit the vendor the whole charge and
 * book every file at nothing.
 */
function onPick(file) {
    if (! isPicked(file)) {
        return;
    }

    jobs(file).forEach((item) => {
        if (item.vendor_rate && String(amounts[item.id]).trim() === '') {
            amounts[item.id] = Number(item.vendor_rate).toFixed(2);
        }
    });
}

const allPicked = computed({
    get: () => props.files.length > 0 && picked.value.length === props.files.length,
    set: (on) => {
        picked.value = on ? props.files.map((file) => file.id) : [];

        if (on) {
            props.files.forEach(onPick);
        }
    },
});

const pickedJobs = computed(() => props.files.filter(isPicked).flatMap(jobs));

const total = computed(() =>
    pickedJobs.value.reduce((sum, item) => sum + (Number(amounts[item.id]) || 0), 0)
);

const blanks = computed(
    () => pickedJobs.value.filter((item) => String(amounts[item.id]).trim() === '').length
);

const vendor = computed(
    () => props.vendors.find((party) => String(party.id) === String(chosen.value)) ?? null
);

/*
 * A vendor's balance sits on the credit side, and crediting them more moves it
 * further that way. Kept signed here and given a side only where it is printed,
 * so nothing downstream has to guess the convention.
 */
const after = computed(() => Number(vendor.value?.current_balance ?? 0) - total.value);

// With no vendor chosen there is no opening balance to work from, so the figure
// shown is the batch itself.
const afterText = computed(() => (vendor.value ? balance(after.value) : money(total.value)));

const afterClass = computed(() =>
    vendor.value ? `ui-money--${side(after.value)}` : 'ui-money--strong'
);

const summary = computed(() => {
    if (!picked.value.length) {
        return 'Nothing ticked yet.';
    }

    const count = picked.value.length;
    const parts = [`${count} ${count === 1 ? 'file' : 'files'} going out`];

    const works = pickedJobs.value.length;

    if (works !== count) {
        parts.push(`${works} works`);
    }

    if (blanks.value) {
        parts.push(`${blanks.value} without a price yet`);
    }

    return parts.join(', ') + '.';
});

const vendorField = ref(null);

onMounted(() => {
    // The select carried autofocus as server markup. Inserted by Vue the
    // attribute is no longer dependable, so the focus is placed by hand — and
    // only if the user has not already started somewhere else.
    if (!document.activeElement || document.activeElement === document.body) {
        vendorField.value?.focus();
    }
});
</script>

<template>
    <form class="ui" :action="action" method="POST">
        <!-- Rendered here rather than passed as a slot: the component is mounted
             onto a bare element, so there is no server markup to slot in. -->
        <input type="hidden" name="_token" :value="csrf">

        <div class="ui-card">
            <div class="ui-card__head">
                <h5 class="ui-card__title">Vendor and Date</h5>
            </div>

            <div class="ui-card__body">
                <div class="give-head">
                    <div class="ui-field">
                        <label class="ui-label" for="vendor_id">
                            Vendor <span class="ui-label__req">*</span>
                        </label>
                        <select
                            id="vendor_id"
                            ref="vendorField"
                            class="ui-select"
                            name="vendor_id"
                            v-model="chosen"
                            required>
                            <option value="">Select vendor</option>
                            <option v-for="party in vendors" :key="party.id" :value="party.id">
                                {{ party.name }} ({{ party.mobile }})
                            </option>
                        </select>

                        <div class="give-balance">
                            <span class="give-balance__label">Balance after</span>
                            <span class="ui-money" :class="afterClass">{{ afterText }}</span>
                        </div>
                    </div>

                    <!--
                        v-once, and it has to be.

                        assets/js/datepicker.js takes ownership of both boxes and
                        writes the chosen date into them. A :value binding does
                        not leave them alone: Vue force-patches the "value" key on
                        every re-render even when the bound value has not changed
                        (runtime-core, patchProps: `next !== prev || key ===
                        "value"`), and compares against the live DOM value, so it
                        writes the page-load default back over the operator's
                        choice. Ticking a file was enough to trigger it, and the
                        result still passes date_format:Y-m-d — so the batch
                        posted against the wrong date with nothing to show for it.
                    -->
                    <div class="ui-field" v-once>
                        <label class="ui-label" for="vendor_date_display">
                            Given On <span class="ui-label__req">*</span>
                        </label>
                        <input
                            type="text"
                            id="vendor_date_display"
                            class="ui-input js-datefield"
                            data-target="vendor_date"
                            :value="vendorDateDisplay"
                            placeholder="dd-mm-yyyy"
                            inputmode="numeric"
                            maxlength="10"
                            autocomplete="off"
                            required>
                        <input type="hidden" id="vendor_date" name="vendor_date" :value="vendorDate">
                    </div>

                    <div class="ui-field">
                        <label class="ui-label" for="remark">Remark</label>
                        <input
                            type="text"
                            id="remark"
                            class="ui-input"
                            name="remark"
                            v-model="remarkText"
                            maxlength="200"
                            placeholder="Added to each file's history">
                    </div>
                </div>
            </div>
        </div>

        <div class="ui-card give-files">
            <div class="ui-card__head">
                <div>
                    <h5 class="ui-card__title">Files Waiting to Go Out</h5>
                    <div class="ui-hint">
                        Tick the files you are handing over. Leave an amount blank if the rate is not
                        agreed yet &mdash; nothing is posted to the vendor until it is filled in.
                    </div>
                </div>

                <label class="give-all">
                    <input type="checkbox" class="give-check" v-model="allPicked">
                    Select all
                </label>
            </div>

            <div class="ui-card__body">
                <div class="ui-table-wrap">
                    <table class="ui-table give">
                        <thead>
                            <tr>
                                <th style="width: 2.5rem;"></th>
                                <th>File No.</th>
                                <th>Vehicle</th>
                                <th>Received</th>
                                <th>Work Type</th>
                                <th>Details</th>
                                <th>Customer</th>
                                <th class="num">Charged</th>
                                <th class="num" style="min-width: 9rem;">Vendor Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="file in files" :key="file.id" :class="{ 'is-picked': isPicked(file) }">
                                <td data-label="Give out" class="give-pick">
                                    <input
                                        type="checkbox"
                                        class="give-check"
                                        name="files[]"
                                        :value="file.id"
                                        v-model="picked"
                                        @change="onPick(file)">
                                </td>

                                <td data-label="File No.">
                                    <span class="ui-lead">{{ file.file_no }}</span>
                                </td>

                                <td data-label="Vehicle">
                                    <span v-if="file.registration_no" class="file-plate">{{ file.registration_no }}</span>
                                    <span v-else class="ui-money--nil">&mdash;</span>
                                </td>

                                <td data-label="Received">{{ file.received_date }}</td>

                                <!-- The three columns below stack one line per job
                                     and stay in step, so a folder with two works reads
                                     across: this work, charged this, costing that. -->
                                <td data-label="Work Type">
                                    <div v-for="item in jobs(file)" :key="item.id" class="give-job">
                                        {{ item.work_type || '&mdash;' }}
                                    </div>
                                </td>

                                <td data-label="Details">{{ file.description }}</td>

                                <td data-label="Customer">{{ file.customer }}</td>

                                <td data-label="Charged" class="num">
                                    <div v-for="item in jobs(file)" :key="item.id" class="give-job">
                                        <span class="ui-money ui-money--dr">{{ money(item.customer_amount) }}</span>
                                    </div>
                                </td>

                                <td data-label="Vendor Amount" class="num">
                                    <!-- Disabled rather than hidden: a disabled input
                                         posts nothing, so an amount left on an unticked
                                         row cannot reach the server, and the greying out
                                         says so before anyone presses save. -->
                                    <div v-for="item in jobs(file)" :key="item.id" class="give-job">
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="ui-input ui-input--amount"
                                            :name="`amounts[${item.id}]`"
                                            v-model="amounts[item.id]"
                                            :disabled="!isPicked(file)"
                                            placeholder="0.00">
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="give-total">
                    <span class="give-total__label">Total Credit to Vendor</span>
                    <span class="ui-money ui-money--cr give-total__value">{{ money(total) }}</span>
                </div>
            </div>

            <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': picked.length > 0 }">
                <span class="ui-hint">{{ summary }}</span>
                <div class="give-actions">
                    <a :href="cancelUrl" class="ui-btn">Cancel</a>
                    <button type="submit" class="ui-btn ui-btn--primary">
                        <i class="bi bi-check2-circle"></i> Give to Vendor
                    </button>
                </div>
            </div>
        </div>
    </form>
</template>

<style>
.give-files {
    margin-top: var(--s-4);
}

.give-head {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: 2fr 1fr 1fr;
}

/* Where the vendor lands once this batch is credited. Sits under the select
   because it only means anything once a vendor is chosen. */
.give-balance {
    align-items: baseline;
    background: var(--n-050);
    border: 1px solid var(--n-200);
    border-radius: var(--r-sm);
    display: inline-flex;
    font-size: var(--t-sm);
    font-weight: 700;
    gap: var(--s-2);
    margin-top: var(--s-1);
    padding: var(--s-2) var(--s-3);
}

.give-balance__label {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.give-all {
    align-items: center;
    color: var(--n-600);
    cursor: pointer;
    display: inline-flex;
    font-size: var(--t-sm);
    font-weight: 600;
    gap: var(--s-2);
    white-space: nowrap;
}

.give-check {
    accent-color: var(--brand-500);
    cursor: pointer;
    height: 1.1rem;
    margin: 0;
    width: 1.1rem;
}

/* One line per job. The same rule in all three stacked columns is what keeps
   them level, so the amount box sits beside the work it prices. */
.give-job {
    align-items: center;
    display: flex;
    justify-content: flex-end;
    min-height: 2.5rem;
}

.give td:not(.num) .give-job {
    justify-content: flex-start;
}

.give-job + .give-job {
    margin-top: var(--s-1);
}

/* The vehicle, in the shape a number plate is read in. */
.file-plate {
    background: var(--n-000);
    border: 1px solid var(--n-300);
    border-radius: var(--r-sm);
    display: inline-block;
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.06em;
    padding: 0.1rem 0.4rem;
    white-space: nowrap;
}
/* Beats .ui-table's row hover, which would otherwise wash the tick out. */
.ui-table.give tbody tr.is-picked td {
    background: var(--brand-050);
}

.give .give-pick {
    text-align: center;
}

.give-total {
    align-items: baseline;
    background: var(--cr-050);
    border: 1px solid var(--cr-600);
    border-radius: var(--r-md);
    display: flex;
    gap: var(--s-4);
    justify-content: space-between;
    margin: var(--s-4) 0 0 auto;
    max-width: 24rem;
    padding: var(--s-3) var(--s-4);
}

.give-total__label {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.give-total__value {
    font-size: var(--t-xl);
    font-weight: 700;
}

.give-actions {
    display: flex;
    gap: var(--s-2);
}

/* Below the large breakpoint each row becomes a card: eight columns of ledger
   do not fit a phone, and this is a table of controls, which a sideways
   scrollbar makes close to unusable. */
@media (max-width: 991.98px) {
    .give-head {
        grid-template-columns: 1fr;
    }

    .give,
    .give tbody,
    .give tr,
    .give td {
        display: block;
        width: 100%;
    }

    .give thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
    }

    .give tbody tr {
        border: 1px solid var(--n-200);
        border-radius: var(--r-md);
        margin-bottom: var(--s-3);
        padding: var(--s-2) var(--s-3);
    }

    .give tbody td {
        border-bottom: 0;
        padding: var(--s-2) 0;
    }

    .give tbody td::before {
        color: var(--n-500);
        content: attr(data-label);
        display: block;
        font-size: var(--t-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        margin-bottom: var(--s-1);
        text-transform: uppercase;
    }

    .give tbody td.num {
        text-align: left;
    }

    .give .give-pick {
        text-align: left;
    }

    .give-total {
        max-width: none;
    }

    .give-actions {
        flex: 1 1 auto;
    }

    .give-actions .ui-btn {
        flex: 1 1 auto;
    }
}
</style>
