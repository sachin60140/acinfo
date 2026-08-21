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
 * On the 'in hand' view the board lists only work still outstanding. What is
 * finished is stated in the folder heading and left off, because this screen
 * exists to answer "what is still to do".
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
    // Today, from the server: an approval cannot be dated after it, and the
    // browser's own clock is not the one the ledger is kept by.
    today: { type: String, default: '' },
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
            approvedOn: item.approved_on_value,
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

// Approval is evidenced per job, because two approvals days apart arrive with
// a document each. One already on file is enough.
const needsEvidence = (row) => approving(row) && !row.screenshot && !row.has_screenshot;

/*
 * And it is dated, because which day is the whole question when the second
 * approval lands a week after the first. The box is filled with today, which
 * is right far more often than it is wrong, so this only fires if it is
 * cleared — but the date is stored from what is in the box, not assumed.
 */
const needsDate = (row) => approving(row) && !String(row.approvedOn ?? '').trim();

const blocked = computed(() =>
    rows.some((row) => needsReason(row) || needsEvidence(row) || needsDate(row))
);

const touched = computed(() =>
    rows.filter((row) => changed(row) || row.remark.trim() !== '' || row.screenshot)
);

const summary = computed(() => {
    if (blocked.value) {
        const reasons = rows.filter(needsReason).length;
        const evidence = rows.filter(needsEvidence).length;
        const dates = rows.filter(needsDate).length;
        const parts = [];

        if (reasons) parts.push(`${reasons} ${reasons === 1 ? 'needs' : 'need'} a reason`);
        if (evidence) parts.push(`${evidence} ${evidence === 1 ? 'needs' : 'need'} a screenshot`);
        if (dates) parts.push(`${dates} ${dates === 1 ? 'needs' : 'need'} an approval date`);

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
                            <th style="width: 26%;">Work</th>
                            <th class="num" style="width: 7rem;">Charged</th>
                            <th style="width: 30%;">Status</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in rows" :key="row.id">
                            <!-- The folder, above its jobs. Its status is derived
                                 from them, so it is stated and not offered. -->
                            <tr v-if="row.first" class="board__file">
                                <td :colspan="4">
                                    <div class="board__file-line">
                                        <a :href="row.file.edit_url" class="board__no">{{ row.file.file_no }}</a>
                                        <span class="ui-badge" :data-state="row.file.status">{{ row.file.status_label }}</span>
                                        <span v-if="row.file.registration_no" class="board__reg">
                                            {{ row.file.registration_no }}
                                        </span>
                                        <span class="board__meta">{{ row.file.customer }}</span>
                                        <span class="board__meta">&rarr; {{ row.file.vendor || 'In-house' }}</span>
                                        <span class="board__meta board__meta--end">{{ row.file.received_date }}</span>
                                    </div>
                                    <!-- Work that is finished is off the list below,
                                         so the heading is what says it exists. -->
                                    <div v-if="row.file.settled" class="board__done">
                                        {{ row.file.settled }} of {{ row.file.works }} works finished &mdash;
                                        <a :href="row.file.edit_url" class="ui-link">see the file</a>
                                    </div>
                                </td>
                            </tr>

                            <tr
                                :data-state="row.chosen"
                                :class="{
                                    'is-changed': changed(row),
                                    'is-noted': !changed(row) && row.remark.trim() !== '',
                                    'is-blocked': needsReason(row) || needsEvidence(row) || needsDate(row),
                                }">
                                <td data-label="Work">
                                    <div class="board__work">{{ row.work_type || '—' }}</div>
                                    <div v-if="row.approved_on" class="ui-sub">Approved {{ row.approved_on }}</div>
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
                                        <option v-for="(label, key) in row.file.statuses || statuses" :key="key" :value="key">
                                            {{ label }}
                                        </option>
                                    </select>

                                    <!-- An approval happened on a day and came with
                                         a document. Both belong to this job, and
                                         both are asked for beside each other. -->
                                    <div v-if="approving(row)" class="board__extra">
                                        <label class="board__field">
                                            <span class="board__field-label">
                                                Approved on <span class="ui-label__req">*</span>
                                            </span>
                                            <input
                                                type="date"
                                                class="ui-input board__date"
                                                :class="{ 'ui-input--invalid': needsDate(row) }"
                                                :name="`approved_on[${row.id}]`"
                                                v-model="row.approvedOn"
                                                :max="today">
                                        </label>

                                        <label class="board__field">
                                            <span class="board__field-label">
                                                Screenshot
                                                <span v-if="!row.has_screenshot" class="ui-label__req">*</span>
                                                <span v-else class="board__field-opt">&mdash; on file</span>
                                            </span>
                                            <input
                                                type="file"
                                                class="ui-input board__upload"
                                                :name="`screenshots[${row.id}]`"
                                                accept="image/*,application/pdf"
                                                @change="onScreenshot(row, $event)">
                                        </label>

                                        <div v-if="row.screenshot_url" class="ui-hint board__note">
                                            <i class="bi bi-paperclip"></i>
                                            <a :href="row.screenshot_url" class="ui-link">View the one on file</a>
                                            &mdash; choose a file only to replace it
                                        </div>
                                    </div>

                                    <div v-if="changed(row) && cancelling(row)" class="board__extra">
                                        <div class="ui-hint ui-hint--error board__note">
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
                                    <div v-if="row.first && row.file.last_remark" class="ui-sub board__last">
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
.board tbody tr[data-state="paper_returned"] td:first-child { border-left-color: var(--n-700); }
.board tbody tr[data-state="cancelled"] td:first-child { border-left-color: var(--cr-600); }

.board tbody tr.is-changed td { background: var(--warn-050); }
.board tbody tr.is-noted td { background: var(--n-050); }
.board tbody tr.is-blocked td { background: var(--cr-050); }

/* Controls and two-word cells share a row, so everything is topped out rather
   than floating in the middle of whatever the tallest cell turned out to be. */
.board tbody td {
    padding-bottom: var(--s-3);
    padding-top: var(--s-3);
    vertical-align: top;
}

/* ---- The folder heading ------------------------------------------------ */

.board__file td {
    background: var(--brand-050);
    border-top: 2px solid var(--brand-100);
    padding-bottom: var(--s-2);
    padding-top: var(--s-3);
}

.board tbody tr.board__file td:first-child {
    border-left: 3px solid var(--brand-500);
}

.board__file-line {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-3);
}

.board__no {
    color: var(--brand-700);
    font-weight: 700;
    text-decoration: none;
}

.board__no:hover {
    text-decoration: underline;
}

/* The vehicle, in the shape a number plate is read in. */
.board__reg {
    background: var(--n-000);
    border: 1px solid var(--n-300);
    border-radius: var(--r-sm);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.06em;
    padding: 0.1rem 0.4rem;
}

.board__meta {
    color: var(--n-600);
    font-size: var(--t-sm);
}

/* Pushed to the far end so the eye finds the date in the same place on every
   folder, rather than wherever the customer's name happens to stop. */
.board__meta--end {
    margin-left: auto;
}

.board__done {
    color: var(--n-500);
    font-size: var(--t-xs);
    margin-top: var(--s-1);
}

/* ---- A job ------------------------------------------------------------- */

.board__work {
    color: var(--n-900);
    font-weight: 700;
}

/* What an approval needs: the day it happened and the paper it came on. Side
   by side, because they are one act recorded twice. */
.board__extra {
    display: grid;
    gap: var(--s-2) var(--s-3);
    grid-template-columns: minmax(8rem, 1fr) minmax(8rem, 1.1fr);
    margin-top: var(--s-3);
}

.board__field {
    display: flex;
    flex-direction: column;
    gap: var(--s-1);
    min-width: 0;
}

.board__field-label {
    color: var(--n-600);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
}

/* Says the requirement is already met, where an asterisk would otherwise ask
   again for a document that is on file. */
.board__field-opt {
    color: var(--dr-600);
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
}

/* Both native controls set their own metrics and overflow the app's input box
   otherwise — the file one badly, at full size. */
.board__date,
.board__upload {
    font-size: var(--t-xs);
    line-height: 1.5;
    max-width: 100%;
    padding: var(--s-1) var(--s-2);
}

.board__note {
    grid-column: 1 / -1;
}

.board__last {
    margin-top: var(--s-1);
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

    .board__meta--end {
        margin-left: 0;
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

    .board__file td::before {
        content: none;
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

@media (max-width: 575.98px) {
    .board__extra {
        grid-template-columns: 1fr;
    }
}
</style>
