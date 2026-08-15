<script setup>
/*
 * The repeating "files received" rows.
 *
 * The row inputs keep the exact names the existing controller already
 * validates (rows[i][registration_no] and so on), so this replaces the markup
 * without changing the contract: the form still posts normally and the server
 * still validates every field. That is what makes it safe to convert one screen
 * at a time on a live application.
 */
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    workTypes: { type: Array, default: () => [] },
    historyUrl: { type: String, required: true },
    oldRows: { type: Array, default: () => [] },
});

function blankRow() {
    return {
        registration_no: '',
        work_type_id: '',
        description: '',
        amount: '',
        // Lookup state, never submitted.
        history: null,
        looking: false,
        failed: false,
        open: true,
    };
}

const rows = reactive(
    props.oldRows.length
        ? props.oldRows.map((row) => ({ ...blankRow(), ...row }))
        : [blankRow()]
);

const total = computed(() =>
    rows.reduce((sum, row) => sum + (Number(row.amount) || 0), 0)
);

const money = (value) =>
    (Number(value) || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

function addRow() {
    rows.push(blankRow());
}

function removeRow(index) {
    if (rows.length > 1) {
        rows.splice(index, 1);
    }
}

/**
 * Picking a work type fills in its standard rate, but never overwrites an
 * amount already typed — the rate is a starting point, not a rule.
 */
function onWorkTypeChange(row) {
    const type = props.workTypes.find((t) => String(t.id) === String(row.work_type_id));

    if (type && type.default_rate && !row.amount) {
        row.amount = Number(type.default_rate).toFixed(2);
    }
}

/*
 * The history lookup.
 *
 * Requests are sequenced per row: a slower earlier reply must not overwrite a
 * newer one, which is what makes a fast typist see results for a number they
 * have already moved on from.
 */
const requestSeq = new WeakMap();

async function lookup(row) {
    const reg = (row.registration_no || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();

    if (reg.length < 4) {
        row.history = null;
        row.failed = false;

        return;
    }

    const seq = (requestSeq.get(row) || 0) + 1;
    requestSeq.set(row, seq);

    row.looking = true;
    row.failed = false;

    try {
        const url = `${props.historyUrl}?registration_no=${encodeURIComponent(reg)}`;
        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`Lookup failed: ${response.status}`);
        }

        const data = await response.json();

        // A reply for a number the user has since changed is discarded.
        if (requestSeq.get(row) !== seq) {
            return;
        }

        row.history = data;
        row.open = true;
    } catch (error) {
        if (requestSeq.get(row) === seq) {
            row.failed = true;
            row.history = null;
        }
    } finally {
        if (requestSeq.get(row) === seq) {
            row.looking = false;
        }
    }
}

// Typing is debounced so the lookup runs when the number settles, not on every
// keystroke.
const timers = new WeakMap();

function onRegInput(row) {
    window.clearTimeout(timers.get(row));
    timers.set(row, window.setTimeout(() => lookup(row), 400));
}

const totalOut = ref(null);
watch(total, (value) => {
    // The summary panel outside this component listens for the running total.
    document.dispatchEvent(new CustomEvent('receive-total', { detail: value }));
});
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h5 class="card-title mb-0">Files Received</h5>
                <div class="side-hint">
                    Each row is saved as its own file with its own number, and its own line on the customer's statement.
                </div>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" @click="addRow">
                <i class="bi bi-plus-lg me-1"></i> Add Row
            </button>
        </div>

        <div class="receive-rows">
            <div v-for="(row, index) in rows" :key="index" class="receive-row">
                <div class="receive-row__no">{{ index + 1 }}</div>

                <div class="receive-row__fields">
                    <div class="rf rf--reg">
                        <label class="form-label">Registration No.</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-car-front"></i></span>
                            <input
                                type="text"
                                class="form-control text-uppercase"
                                :name="`rows[${index}][registration_no]`"
                                v-model="row.registration_no"
                                @input="onRegInput(row)"
                                maxlength="20"
                                autocomplete="off"
                                placeholder="BR01AB1234">
                            <span v-if="row.looking" class="input-group-text rf__busy">
                                <span class="rf__dot"></span><span class="rf__dot"></span><span class="rf__dot"></span>
                            </span>
                        </div>
                    </div>

                    <div class="rf rf--work">
                        <label class="form-label">Type of Work <span class="required-mark">*</span></label>
                        <select
                            class="form-select"
                            :name="`rows[${index}][work_type_id]`"
                            v-model="row.work_type_id"
                            @change="onWorkTypeChange(row)"
                            required>
                            <option value="">Select work</option>
                            <option v-for="type in workTypes" :key="type.id" :value="type.id">
                                {{ type.name }}<template v-if="type.default_rate"> — {{ money(type.default_rate) }}</template>
                            </option>
                        </select>
                    </div>

                    <div class="rf rf--details">
                        <label class="form-label">File Details</label>
                        <input
                            type="text"
                            class="form-control"
                            :name="`rows[${index}][description]`"
                            v-model="row.description"
                            maxlength="255"
                            placeholder="Party name, reference">
                    </div>

                    <div class="rf rf--amount">
                        <label class="form-label">Amount <span class="required-mark">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">INR</span>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                class="form-control text-end"
                                :name="`rows[${index}][amount]`"
                                v-model="row.amount"
                                placeholder="0.00"
                                required>
                        </div>
                    </div>

                    <div class="rf rf--remove">
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-danger"
                            :disabled="rows.length === 1"
                            title="Remove this row"
                            @click="removeRow(index)">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>

                <!-- What this vehicle has been charged before. -->
                <div v-if="row.failed" class="rf-history rf-history--warn">
                    <i class="bi bi-exclamation-triangle"></i>
                    Could not check this registration number. The file can still be saved.
                </div>

                <div v-else-if="row.history && row.history.count === 0" class="rf-history rf-history--new">
                    <i class="bi bi-patch-check"></i>
                    No earlier files against <strong>{{ row.history.registration_no }}</strong> — this is its first.
                </div>

                <div v-else-if="row.history" class="rf-history">
                    <button type="button" class="rf-history__head" @click="row.open = !row.open">
                        <i class="bi" :class="row.open ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                        <strong>{{ row.history.count }}</strong>
                        earlier {{ row.history.count === 1 ? 'file' : 'files' }} against
                        <strong>{{ row.history.registration_no }}</strong>
                        <span class="rf-history__hint">— check the work and the price before booking</span>
                    </button>

                    <table v-if="row.open" class="rf-history__table">
                        <thead>
                            <tr>
                                <th>File No.</th>
                                <th>Received</th>
                                <th>Type of Work</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="past in row.history.files" :key="past.id">
                                <td class="fw-bold" data-label="File No.">{{ past.file_no }}</td>
                                <td data-label="Received">{{ past.received_date }}</td>
                                <td data-label="Type of Work">{{ past.work_type }}</td>
                                <td data-label="Customer">{{ past.customer }}</td>
                                <td data-label="Status"><span class="badge" :class="past.status_badge">{{ past.status_label }}</span></td>
                                <td class="text-end" data-label="Amount">
                                    <span v-if="past.was_returned" class="rf-history__void">{{ past.charged }}</span>
                                    {{ past.net }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-md-6 ms-auto">
                <div class="batch-total">
                    <span class="label">Total Debit to Customer</span>
                    <span class="value dr" ref="totalOut">{{ money(total) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
.receive-row {
    border: 1px solid #eef1f6;
    border-radius: 8px;
    margin-bottom: 0.7rem;
    padding: 0.7rem 0.85rem 0.75rem;
    position: relative;
}

.receive-row__no {
    color: #b6bfcc;
    font-size: 0.7rem;
    font-weight: 700;
    left: 0.85rem;
    position: absolute;
    top: 0.3rem;
}

.receive-row__fields {
    align-items: flex-end;
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    padding-top: 0.5rem;
}

.rf {
    flex: 1 1 10rem;
    min-width: 0;
}

.rf--reg { flex: 0 0 12rem; }
.rf--work { flex: 1 1 12rem; }
.rf--details { flex: 2 1 14rem; }
.rf--amount { flex: 0 0 10rem; }
.rf--remove { flex: 0 0 auto; }

.rf .form-label {
    color: #64748b;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    margin-bottom: 0.2rem;
    text-transform: uppercase;
}

.rf .form-control,
.rf .form-select {
    min-height: 40px;
}

/* Three dots that rise in sequence while the lookup is in flight. */
.rf__busy {
    gap: 3px;
}

.rf__dot {
    animation: rf-bounce 1.1s ease-in-out infinite;
    background: #4154f1;
    border-radius: 50%;
    display: inline-block;
    height: 5px;
    width: 5px;
}

.rf__dot:nth-child(2) { animation-delay: 0.15s; }
.rf__dot:nth-child(3) { animation-delay: 0.3s; }

@keyframes rf-bounce {
    0%, 80%, 100% { opacity: 0.35; transform: translateY(0); }
    40% { opacity: 1; transform: translateY(-3px); }
}

.rf-history {
    background: #f8fafc;
    border-left: 3px solid #4154f1;
    border-radius: 0 6px 6px 0;
    font-size: 0.78rem;
    margin-top: 0.6rem;
    padding: 0.5rem 0.7rem;
}

.rf-history--new {
    border-left-color: #198754;
    color: #15803d;
}

.rf-history--warn {
    border-left-color: #f59e0b;
    color: #b45309;
}

.rf-history__head {
    background: none;
    border: 0;
    color: #012970;
    padding: 0;
    text-align: left;
    width: 100%;
}

.rf-history__hint {
    color: #64748b;
    font-weight: 400;
}

.rf-history__table {
    font-size: 0.74rem;
    margin-top: 0.5rem;
    width: 100%;
}

.rf-history__table th {
    color: #8b95a5;
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    padding: 0.2rem 0.4rem;
    text-transform: uppercase;
}

.rf-history__table td {
    border-top: 1px solid #eef1f6;
    padding: 0.3rem 0.4rem;
}

/* The figure that no longer stands, shown beside the one that does. */
.rf-history__void {
    color: #94a3b8;
    text-decoration: line-through;
}

@media (max-width: 767.98px) {
    .rf--reg,
    .rf--work,
    .rf--details,
    .rf--amount {
        flex: 1 1 100%;
    }

    /* This table exists so someone can check what was charged for this vehicle
       last time before pricing it again. Scrolling it sideways puts Amount —
       the one figure it is here for — off the edge of the screen, so on a phone
       each earlier file becomes a card instead. */
    .rf-history__table,
    .rf-history__table tbody,
    .rf-history__table tr,
    .rf-history__table td {
        display: block;
        width: 100%;
    }

    .rf-history__table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
    }

    .rf-history__table tbody tr {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        margin-bottom: 8px;
        padding: 8px 12px;
    }

    .rf-history__table tbody td {
        border: 0;
        display: flex;
        gap: 12px;
        justify-content: space-between;
        padding: 2px 0;
        text-align: right;
    }

    .rf-history__table tbody td::before {
        color: #64748b;
        content: attr(data-label);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-align: left;
        text-transform: uppercase;
    }
}
</style>
