<script setup>
/*
 * The work status board.
 *
 * Field names match what WorkFileController::status() already validates, so the
 * form still posts normally and the server still checks every value. That is
 * what makes it safe to convert a screen on a live ledger: only the rendering
 * moves, the money logic does not.
 *
 * The board's job is to let one person move a stack of files along in a single
 * pass, so every rule that would otherwise bounce back from the server is shown
 * here first — a refund that needs a reason, an approval that needs evidence.
 */
import { computed, reactive, ref } from 'vue';
import { balance, money, side } from '../money';

const props = defineProps({
    files: { type: Array, default: () => [] },
    statuses: { type: Object, default: () => ({}) },
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    resetUrl: { type: String, required: true },
    returnedKey: { type: String, required: true },
    approvedKey: { type: String, required: true },
    cancelledKey: { type: String, required: true },
});

/*
 * One row of working state per file. The stored status is kept beside the
 * chosen one so "changed" is a comparison rather than a flag someone has to
 * remember to set.
 */
const rows = reactive(
    props.files.map((file) => ({
        ...file,
        chosen: file.status,
        remark: '',
        refund: file.returned_amount ?? '',
        screenshot: null,
    }))
);

const changed = (row) => row.chosen !== row.status;
const returning = (row) => row.chosen === props.returnedKey;
const approving = (row) => row.chosen === props.approvedKey;
const cancelling = (row) => row.chosen === props.cancelledKey;

/*
 * Cancelling and returning move a customer's balance, so the server refuses
 * them without a reason. Saying so here beats bouncing the whole board back.
 */
const needsReason = (row) =>
    changed(row) && (returning(row) || cancelling(row)) && row.remark.trim() === '';

const needsEvidence = (row) => approving(row) && !row.screenshot && !row.has_screenshot;

const blocked = computed(() => rows.some((row) => needsReason(row) || needsEvidence(row)));

const touched = computed(() =>
    rows.filter((row) => changed(row) || row.remark.trim() !== '' || row.screenshot)
);

const summary = computed(() => {
    if (blocked.value) {
        const reasons = rows.filter(needsReason).length;
        const evidence = rows.filter(needsEvidence).length;
        const parts = [];

        if (reasons) {
            parts.push(`${reasons} ${reasons === 1 ? 'file needs' : 'files need'} a reason`);
        }
        if (evidence) {
            parts.push(`${evidence} ${evidence === 1 ? 'needs' : 'need'} a screenshot`);
        }

        return { tone: 'error', text: parts.join(', ') + '.' };
    }

    if (!touched.value.length) {
        return { tone: 'quiet', text: 'No changes yet.' };
    }

    const moved = rows.filter(changed).length;
    const noted = rows.filter((r) => !changed(r) && r.remark.trim() !== '').length;
    const shots = rows.filter((r) => r.screenshot).length;
    const backs = rows.filter((r) => changed(r) && returning(r)).length;
    const voids = rows.filter((r) => changed(r) && cancelling(r)).length;

    const parts = [];
    if (moved) parts.push(`${moved} ${moved === 1 ? 'file' : 'files'} will move`);
    if (noted) parts.push(`${noted} ${noted === 1 ? 'remark' : 'remarks'} added`);
    if (shots) parts.push(`${shots} ${shots === 1 ? 'screenshot' : 'screenshots'} attached`);
    if (backs) parts.push(`${backs} credited back`);
    if (voids) parts.push(`${voids} cancelled`);

    return { tone: 'ready', text: parts.join(', ') + '.' };
});

/*
 * Choosing Paper Returned fills in the full charge, so the figure going back is
 * visible rather than implied by a placeholder. Only on the change itself — the
 * box must stay clearable afterwards.
 */
function onStatusChange(row) {
    if (returning(row) && String(row.refund).trim() === '') {
        row.refund = Number(row.customer_amount).toFixed(2);
    }

    if (!approving(row)) {
        row.screenshot = null;
    }
}

function onScreenshot(row, event) {
    row.screenshot = event.target.files[0] ?? null;
}

const form = ref(null);
</script>

<template>
    <form ref="form" class="ui" :action="action" method="POST" enctype="multipart/form-data">
        <!-- Rendered here rather than passed as a slot: the component is mounted
             onto a bare element, so there is no server markup to slot in. -->
        <input type="hidden" name="_token" :value="csrf">

        <div v-if="!rows.length" class="ui-empty">
            <div class="ui-empty__icon"><i class="bi bi-inbox"></i></div>
            <div class="ui-empty__title">Nothing here</div>
            <div>No files match this view.</div>
        </div>

        <template v-else>
            <div class="ui-table-wrap">
                <table class="ui-table board">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Vehicle</th>
                            <th>Work</th>
                            <th>Customer &rarr; Vendor</th>
                            <th class="num">Charged</th>
                            <th style="min-width: 12rem;">Status</th>
                            <th style="min-width: 14rem;">Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            :data-state="row.chosen"
                            :class="{
                                'is-changed': changed(row),
                                'is-noted': !changed(row) && row.remark.trim() !== '',
                                'is-blocked': needsReason(row) || needsEvidence(row),
                            }">
                            <td data-label="File">
                                <a :href="row.edit_url" class="ui-link">{{ row.file_no }}</a>
                                <div class="ui-sub">{{ row.received_date }}</div>
                            </td>

                            <td data-label="Vehicle">
                                <span class="ui-lead">{{ row.registration_no || '—' }}</span>
                            </td>

                            <td data-label="Work">
                                <div class="ui-lead">{{ row.work_type }}</div>
                                <div v-if="row.description" class="ui-sub">{{ row.description }}</div>
                            </td>

                            <td data-label="Customer → Vendor">
                                {{ row.customer }}
                                <span class="arrow">&rarr;</span>
                                <span :class="{ 'text-muted': !row.vendor }">{{ row.vendor || 'In-house' }}</span>
                            </td>

                            <td data-label="Charged" class="num">
                                <span class="ui-money ui-money--strong">{{ money(row.customer_amount) }}</span>
                            </td>

                            <td data-label="Status">
                                <select
                                    class="ui-select"
                                    :name="`statuses[${row.id}]`"
                                    v-model="row.chosen"
                                    @change="onStatusChange(row)">
                                    <option
                                        v-for="(label, key) in statuses"
                                        :key="key"
                                        :value="key"
                                        v-show="key !== returnedKey || row.status === returnedKey">
                                        {{ label }}
                                    </option>
                                </select>

                                <!-- Approval has to be evidenced. -->
                                <div v-if="approving(row)" class="row-extra">
                                    <label class="ui-label">Approval screenshot <span class="ui-label__req">*</span></label>
                                    <input
                                        type="file"
                                        class="ui-input"
                                        :name="`screenshots[${row.id}]`"
                                        accept="image/*,application/pdf"
                                        @change="onScreenshot(row, $event)">
                                    <div v-if="row.screenshot_url" class="ui-hint">
                                        <i class="bi bi-paperclip"></i>
                                        <a :href="row.screenshot_url" target="_blank" rel="noopener">screenshot on file</a>
                                        &mdash; choose one only to replace it
                                    </div>
                                </div>

                                <!-- Returning moves the customer's balance. -->
                                <div v-if="returning(row)" class="row-extra">
                                    <label class="ui-label">Refund to customer</label>
                                    <input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        :max="row.customer_amount"
                                        class="ui-input ui-input--amount"
                                        :name="`refunds[${row.id}]`"
                                        v-model="row.refund"
                                        :placeholder="money(row.customer_amount)">
                                    <div class="ui-hint ui-hint--error">
                                        Credits {{ money(row.refund || row.customer_amount) }} back to {{ row.customer }}
                                    </div>
                                </div>

                                <div v-if="changed(row) && cancelling(row)" class="row-extra">
                                    <div class="ui-hint ui-hint--error">Removes this file from both ledgers</div>
                                </div>
                            </td>

                            <td data-label="Remark">
                                <input
                                    type="text"
                                    class="ui-input"
                                    :class="{ 'ui-input--invalid': needsReason(row) }"
                                    :name="`remarks[${row.id}]`"
                                    v-model="row.remark"
                                    maxlength="255"
                                    :placeholder="needsReason(row) ? 'A reason is required' : 'Why, or what is pending'">
                                <div v-if="row.last_remark" class="ui-sub">
                                    <i class="bi bi-clock-history"></i> {{ row.last_remark }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': touched.length && !blocked }">
                <span class="ui-hint" :class="{ 'ui-hint--error': summary.tone === 'error' }">{{ summary.text }}</span>
                <div class="foot-actions">
                    <a :href="resetUrl" class="ui-btn">Reset</a>
                    <button type="submit" class="ui-btn ui-btn--primary" :disabled="!touched.length || blocked">
                        <i class="bi bi-check2-circle"></i> Save Status
                    </button>
                </div>
            </div>
        </template>
    </form>
</template>

<style>
/* A colour down the left edge says what state a row is in before it is read.
   It follows the choice, not the stored value, so a row shows where it is
   heading the moment the dropdown moves. */
.board tbody tr td:first-child {
    border-left: 3px solid transparent;
}

.board tbody tr[data-state="in_office"] td:first-child { border-left-color: var(--n-400); }
.board tbody tr[data-state="paper_pendency"] td:first-child { border-left-color: var(--warn-500); }
.board tbody tr[data-state="file_dispatch"] td:first-child { border-left-color: var(--info-500); }
.board tbody tr[data-state="part_pesi_required"] td:first-child { border-left-color: var(--warn-500); }
.board tbody tr[data-state="under_verification"] td:first-child { border-left-color: var(--brand-500); }
.board tbody tr[data-state="approval_done"] td:first-child { border-left-color: var(--dr-600); }
.board tbody tr[data-state="paper_returned"] td:first-child { border-left-color: var(--ink-900); }
.board tbody tr[data-state="cancelled"] td:first-child { border-left-color: var(--cr-600); }

.board tbody tr.is-changed td { background: var(--warn-050); }
.board tbody tr.is-noted td { background: var(--n-050); }
.board tbody tr.is-blocked td { background: var(--cr-050); }

.board .arrow {
    color: var(--n-300);
    margin: 0 var(--s-1);
}

.row-extra {
    display: flex;
    flex-direction: column;
    gap: var(--s-1);
    margin-top: var(--s-2);
}

.foot-actions {
    display: flex;
    gap: var(--s-2);
}

/* Below the large breakpoint each row becomes a card: eleven columns of ledger
   do not fit a phone, and this screen is a table of controls, which a sideways
   scrollbar makes close to unusable. */
@media (max-width: 991.98px) {
    .board,
    .board tbody,
    .board tr,
    .board td {
        display: block;
        width: 100%;
    }

    .board thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
    }

    .board tbody tr {
        border: 1px solid var(--n-200);
        border-radius: var(--r-md);
        margin-bottom: var(--s-3);
        padding: var(--s-2) var(--s-3);
    }

    .board tbody td {
        border-bottom: 0;
        padding: var(--s-2) 0;
    }

    .board tbody td::before {
        color: var(--n-500);
        content: attr(data-label);
        display: block;
        font-size: var(--t-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        margin-bottom: var(--s-1);
        text-transform: uppercase;
    }

    .board tbody td.num {
        text-align: left;
    }

    .foot-actions {
        flex: 1 1 auto;
    }

    .foot-actions .ui-btn {
        flex: 1 1 auto;
    }
}
</style>
