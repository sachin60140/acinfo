<script setup>
/*
 * The work status board.
 *
 * A row is a job, not a folder. Papers for a transfer and a hypothecation
 * addition are one file with two jobs, and the RTO approves them separately —
 * often days apart. The folder's own status is shown but never chosen: it is
 * derived from the jobs beneath it, and reads "Partly Approved" while some are
 * through and some are not.
 *
 * Field names are the ones WorkFileController::status() already validates, so
 * the form still posts normally and the server still checks every value. That
 * is what makes it safe to change this screen on a live ledger: the rendering
 * moves, the money logic does not.
 */
import { computed, reactive } from 'vue';
import { money } from '../money';

const props = defineProps({
    files: { type: Array, default: () => [] },
    statuses: { type: Object, default: () => ({}) },
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    resetUrl: { type: String, required: true },
    approvedKey: { type: String, required: true },
    cancelledKey: { type: String, required: true },
});

/*
 * One working row per job, flattened out of the files, so the form is a flat
 * list of what is being changed — while each row still knows the folder it
 * belongs to, for the heading above it.
 */
const rows = reactive(
    props.files.flatMap((file) =>
        file.items.map((item, index) => ({
            ...item,
            file,
            // Only the first job of a file draws the heading.
            first: index === 0,
            count: file.items.length,
            chosen: item.status,
            remark: '',
            screenshot: null,
        }))
    )
);

const changed = (row) => row.chosen !== row.status;
const approving = (row) => row.chosen === props.approvedKey;
const cancelling = (row) => row.chosen === props.cancelledKey;

/*
 * Cancelling strikes work off the folder and takes its charge off the
 * customer's statement, so the server refuses it without a reason. Saying so
 * here beats bouncing the whole board back.
 */
const needsReason = (row) => changed(row) && cancelling(row) && row.remark.trim() === '';

// Approval is evidenced per job now, because two approvals days apart arrive
// with a document each.
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

        if (reasons) parts.push(`${reasons} ${reasons === 1 ? 'needs' : 'need'} a reason`);
        if (evidence) parts.push(`${evidence} ${evidence === 1 ? 'needs' : 'need'} a screenshot`);

        return { tone: 'error', text: parts.join(', ') + '.' };
    }

    if (!touched.value.length) {
        return { tone: 'quiet', text: 'No changes yet.' };
    }

    const moved = rows.filter(changed).length;
    const noted = rows.filter((r) => !changed(r) && r.remark.trim() !== '').length;
    const done = rows.filter((r) => changed(r) && approving(r)).length;
    const voids = rows.filter((r) => changed(r) && cancelling(r)).length;

    const parts = [];
    if (moved) parts.push(`${moved} ${moved === 1 ? 'work' : 'works'} will move`);
    if (done) parts.push(`${done} approved`);
    if (voids) parts.push(`${voids} cancelled`);
    if (noted) parts.push(`${noted} ${noted === 1 ? 'remark' : 'remarks'} added`);

    return { tone: 'ready', text: parts.join(', ') + '.' };
});

function onStatusChange(row) {
    if (!approving(row)) {
        row.screenshot = null;
    }
}

function onScreenshot(row, event) {
    row.screenshot = event.target.files[0] ?? null;
}
</script>

<template>
    <form class="ui" :action="action" method="POST" enctype="multipart/form-data">
        <!-- Rendered here rather than passed as a slot: the component is mounted
             onto a bare element, so there is no server markup to slot in. -->
        <input type="hidden" name="_token" :value="csrf">

        <div v-if="!rows.length" class="ui-empty">
            <div class="ui-empty__icon"><i class="bi bi-inbox"></i></div>
            <div class="ui-empty__title">Nothing here</div>
            <div>No work matches this view.</div>
        </div>

        <template v-else>
            <div class="ui-table-wrap">
                <table class="ui-table board">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Work</th>
                            <th class="num">Charged</th>
                            <th style="min-width: 12rem;">Status</th>
                            <th style="min-width: 14rem;">Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in rows" :key="row.id">
                            <!-- The folder, above its jobs. Its status is derived
                                 from them, so it is stated and not offered. -->
                            <tr v-if="row.first" class="board__file">
                                <td :colspan="5">
                                    <a :href="row.file.edit_url" class="ui-link">{{ row.file.file_no }}</a>
                                    <span class="board__meta">{{ row.file.received_date }}</span>
                                    <span v-if="row.file.registration_no" class="board__meta">{{ row.file.registration_no }}</span>
                                    <span class="board__meta">{{ row.file.customer }}</span>
                                    <span class="board__meta">&rarr; {{ row.file.vendor || 'In-house' }}</span>
                                    <span class="ui-badge" :data-state="row.file.status">{{ row.file.status_label }}</span>
                                    <span v-if="row.count > 1" class="board__meta">{{ row.count }} works</span>
                                </td>
                            </tr>

                            <tr
                                :data-state="row.chosen"
                                :class="{
                                    'is-changed': changed(row),
                                    'is-noted': !changed(row) && row.remark.trim() !== '',
                                    'is-blocked': needsReason(row) || needsEvidence(row),
                                }">
                                <td data-label="File" class="board__spacer"></td>

                                <td data-label="Work">
                                    <div class="ui-lead">{{ row.work_type || '—' }}</div>
                                    <div v-if="row.approved_on" class="ui-sub">Approved {{ row.approved_on }}</div>
                                </td>

                                <td data-label="Charged" class="num">
                                    <span class="ui-money ui-money--strong">{{ money(row.customer_amount) }}</span>
                                </td>

                                <td data-label="Status">
                                    <!-- The folder says what its jobs may be set
                                         to: one holding several works does not offer
                                         to send half an envelope home. -->
                                    <select
                                        class="ui-select"
                                        :name="`statuses[${row.id}]`"
                                        v-model="row.chosen"
                                        @change="onStatusChange(row)">
                                        <option v-for="(label, key) in row.file.statuses || statuses" :key="key" :value="key">
                                            {{ label }}
                                        </option>
                                    </select>

                                    <!-- Approval has to be evidenced, per job. -->
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

                                    <div v-if="changed(row) && cancelling(row)" class="row-extra">
                                        <div class="ui-hint ui-hint--error">
                                            Takes this work off the file, and its charge off the statement
                                        </div>
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
                                    <div v-if="row.first && row.file.last_remark" class="ui-sub">
                                        <i class="bi bi-clock-history"></i> {{ row.file.last_remark }}
                                    </div>
                                </td>
                            </tr>
                        </template>
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
/* A colour down the left edge says what state a job is in before it is read.
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
.board tbody tr[data-state="cancelled"] td:first-child { border-left-color: var(--cr-600); }

.board tbody tr.is-changed td { background: var(--warn-050); }
.board tbody tr.is-noted td { background: var(--n-050); }
.board tbody tr.is-blocked td { background: var(--cr-050); }

/* The folder heading, so its jobs read as belonging to it. */
.board__file td {
    background: var(--brand-050);
    border-top: 1px solid var(--n-200);
    font-weight: 600;
    padding-top: var(--s-3);
}

.board__meta {
    color: var(--n-500);
    font-weight: 400;
    margin-left: var(--s-3);
}

.board__file .ui-badge {
    margin-left: var(--s-3);
}

/* Indents a job under its folder without needing a column of its own. */
.board__spacer {
    width: 2rem;
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

/* Below the large breakpoint each row becomes a card: a table of controls is
   close to unusable behind a sideways scrollbar. */
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

    .board tbody tr.board__file {
        border: 0;
        margin-bottom: 0;
    }

    .board__spacer {
        display: none;
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
