<script setup>
/*
 * Handing approved papers back to the customer.
 *
 * Field names match what WorkFileController::handOver() validates — files[],
 * handed_over_on, collected_by, remark — so the form still posts normally and
 * the server still decides.
 *
 * No money moves here, which is the whole difference from Return to Customer:
 * approved work is finished work the customer pays for in full, and giving back
 * what it was done on is a delivery rather than a refund. So there is no amount
 * on a row and no reason demanded — only which files, when, and who took them.
 */
import { computed, ref } from 'vue';

const props = defineProps({
    files: { type: Array, default: () => [] },
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    cancelUrl: { type: String, required: true },
    handedOverOn: { type: String, default: '' },
    today: { type: String, default: '' },
    oldCollectedBy: { type: String, default: '' },
    oldRemark: { type: String, default: '' },
    oldFiles: { type: Array, default: () => [] },
    search: { type: String, default: '' },
});

// Restored from old input, so a bounced batch comes back with the same files ticked.
const picked = ref(props.oldFiles.map(Number));
const collectedBy = ref(props.oldCollectedBy ?? '');
const remark = ref(props.oldRemark ?? '');

const isPicked = (file) => picked.value.includes(file.id);

/*
 * Narrowing the list.
 *
 * Papers are collected a customer at a time, and this list is every approved
 * file still in the office — so the search is how the counter finds today's.
 *
 * Rows are hidden with v-show and never removed. They carry the ticks: taken
 * out of the page they would take their checkboxes with them, and a file ticked
 * before the search was typed would silently not be handed over. The footer
 * says how many are ticked but out of sight.
 */
const query = ref(props.search ?? '');

const terms = computed(() => query.value.trim().toLowerCase().split(/\s+/).filter(Boolean));

const haystack = (file) => [
    file.file_no,
    file.registration_no,
    file.customer,
    file.work_type,
    file.description,
    file.received_date,
    file.approved_on,
].filter(Boolean).join(' ').toLowerCase();

// Every word must appear somewhere on the row, so a second word narrows —
// "car4sales br06" is that customer's vehicles in that district.
const matches = (file) => terms.value.every((term) => haystack(file).includes(term));

const shown = computed(() => props.files.filter(matches));

const hiddenPicked = computed(() => props.files.filter((file) => isPicked(file) && ! matches(file)).length);

/*
 * Select all means all of what is on screen. With a search typed, ticking
 * files nobody can see is handing over papers nobody meant to; unticking
 * leaves the hidden ones alone for the mirror of that reason.
 */
const allPicked = computed({
    get: () => shown.value.length > 0 && shown.value.every(isPicked),
    set: (on) => {
        const ids = shown.value.map((file) => file.id);

        picked.value = on
            ? [...new Set([...picked.value, ...ids])]
            : picked.value.filter((id) => ! ids.includes(id));
    },
});

const somePicked = computed(() => picked.value.length > 0);
const partlyPicked = computed(() => shown.value.some(isPicked) && ! allPicked.value);

const summary = computed(() => {
    if (! somePicked.value) {
        return { tone: 'quiet', text: 'Tick the files whose papers are going back.' };
    }

    const count = picked.value.length;
    const parts = [`${count} ${count === 1 ? 'file' : 'files'} handed over. No balance changes.`];

    if (hiddenPicked.value) {
        parts.push(`${hiddenPicked.value} of them ${hiddenPicked.value === 1 ? 'is' : 'are'} hidden by the search.`);
    }

    return { tone: 'ready', text: parts.join(' ') };
});

/*
 * dd-mm-yyyy for the box, Y-m-d for the server — the pair
 * partials/_datefield.blade.php renders. See CustomerReturn.vue for why the
 * field is v-once: a re-render would put the page-load default back over the
 * date the operator picked, and the wrong date would still validate.
 */
const stamp = String(props.handedOverOn).match(/^(\d{4})-(\d{2})-(\d{2})$/);
const displayDate = stamp ? `${stamp[3]}-${stamp[2]}-${stamp[1]}` : '';
</script>

<template>
    <div v-if="! files.length" class="ui ui-empty">
        <div class="ui-empty__icon"><i class="bi bi-send-check"></i></div>
        <div class="ui-empty__title">Nothing to hand over</div>
        <div>Every approved file's papers have already gone back.</div>
    </div>

    <form v-else class="ui ui-page hov-page" :action="action" method="POST">
        <input type="hidden" name="_token" :value="csrf">

        <div class="ui-card">
            <div class="ui-card__head">
                <h2 class="ui-card__title">Handed Over</h2>
            </div>

            <div class="ui-card__body hov-when">
                <div class="ui-field hov-when__date" v-once>
                    <label class="ui-label" for="handed_over_on_display">
                        On <span class="ui-label__req">*</span>
                    </label>
                    <input
                        type="text"
                        id="handed_over_on_display"
                        class="ui-input js-datefield"
                        :value="displayDate"
                        data-target="handed_over_on"
                        :data-max="today"
                        placeholder="dd-mm-yyyy"
                        inputmode="numeric"
                        maxlength="10"
                        autocomplete="off"
                        required>
                    <input type="hidden" id="handed_over_on" name="handed_over_on" :value="handedOverOn">
                </div>

                <div class="ui-field hov-when__who">
                    <label class="ui-label" for="collected_by">Collected by</label>
                    <input
                        type="text"
                        id="collected_by"
                        name="collected_by"
                        class="ui-input"
                        v-model="collectedBy"
                        maxlength="120"
                        placeholder="e.g. Rakesh, driver">
                    <div class="ui-hint">Optional. Kept for the office; the customer does not see it.</div>
                </div>

                <div class="ui-field hov-when__remark">
                    <label class="ui-label" for="remark">Remark</label>
                    <input
                        type="text"
                        id="remark"
                        name="remark"
                        class="ui-input"
                        v-model="remark"
                        maxlength="200"
                        placeholder="e.g. RC and NOC handed over">
                    <!-- Said plainly, because the other field on this card is
                         the opposite. -->
                    <div class="ui-hint">Optional. The customer sees this on their file's history.</div>
                </div>
            </div>
        </div>

        <div class="ui-card">
            <div class="ui-card__head hov-head">
                <div>
                    <h2 class="ui-card__title">Approved, Papers Still Here</h2>
                    <div class="ui-page__sub">
                        Every work on these files is approved. Handing the papers over changes no balance and
                        no status &mdash; it records that they have gone back.
                    </div>
                </div>

                <div class="hov-tools">
                    <div class="hov-search">
                        <i class="bi bi-search"></i>
                        <!-- No name: it narrows what is on screen, never what is sent. -->
                        <input
                            type="search"
                            class="ui-input"
                            v-model="query"
                            placeholder="Search file, vehicle or customer"
                            aria-label="Search approved files">
                    </div>

                    <label class="hov-all">
                        <input
                            type="checkbox"
                            class="hov-check"
                            v-model="allPicked"
                            :indeterminate="partlyPicked"
                            :disabled="! shown.length">
                        Select all
                    </label>
                </div>
            </div>

            <div class="ui-table-wrap">
                <table class="ui-table hov-table">
                    <thead>
                        <tr>
                            <th class="hov-tick">Hand Over</th>
                            <th>File No.</th>
                            <th>Vehicle</th>
                            <th>Customer</th>
                            <th>Work</th>
                            <th>Details</th>
                            <th>Received</th>
                            <th>Approved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="file in files"
                            v-show="matches(file)"
                            :key="file.id"
                            :class="{ 'is-picked': isPicked(file) }">
                            <td data-label="Hand Over" class="hov-tick">
                                <input
                                    type="checkbox"
                                    class="hov-check"
                                    name="files[]"
                                    :value="file.id"
                                    v-model="picked"
                                    :aria-label="`Hand over the papers for ${file.file_no}`">
                            </td>
                            <td data-label="File No."><span class="ui-lead">{{ file.file_no }}</span></td>
                            <td data-label="Vehicle">{{ file.registration_no || '—' }}</td>
                            <td data-label="Customer">{{ file.customer || '—' }}</td>
                            <td data-label="Work">{{ file.work_type || '—' }}</td>
                            <td data-label="Details">{{ file.description || '—' }}</td>
                            <td data-label="Received">{{ file.received_date }}</td>
                            <td data-label="Approved">{{ file.approved_on || '—' }}</td>
                        </tr>

                        <tr v-if="! shown.length">
                            <td colspan="8" class="hov-none">No approved files match that search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': somePicked }">
                <span class="ui-hint">{{ summary.text }}</span>
                <div class="hov-actions">
                    <a :href="cancelUrl" class="ui-btn">Cancel</a>
                    <button type="submit" class="ui-btn ui-btn--primary" :disabled="! somePicked">
                        <i class="bi bi-send-check"></i> Hand Over Papers
                    </button>
                </div>
            </div>
        </div>
    </form>
</template>

<style>
/*
 * Every class here carries the hov- prefix, and there is no bare .hov. A short
 * unscoped name in a component's style block is global, and .cr in
 * CustomerReturn.vue is what took every credit cell in the application out of
 * its table. See StylesheetTest.
 */
.hov-when {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: 12rem minmax(0, 1fr) minmax(0, 1.4fr);
}

.hov-head {
    align-items: flex-start;
    flex-wrap: wrap;
    gap: var(--s-3);
}

/* The search and Select all share the right of the heading, and wrap under it
   on a phone rather than squeezing the box to nothing. */
.hov-tools {
    align-items: center;
    display: flex;
    flex: 1 1 22rem;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-3);
    justify-content: flex-end;
}

.hov-search {
    align-items: center;
    display: flex;
    flex: 1 1 16rem;
    gap: var(--s-2);
    max-width: 24rem;
}

.hov-search i {
    color: var(--n-400);
}

.hov-search .ui-input {
    flex: 1 1 auto;
    min-width: 0;
}

.hov-all {
    align-items: center;
    color: var(--n-600);
    cursor: pointer;
    display: inline-flex;
    font-size: var(--t-sm);
    font-weight: 600;
    gap: var(--s-2);
    white-space: nowrap;
}

.hov-check {
    height: 1.05rem;
    width: 1.05rem;
}

.hov-table .hov-tick {
    text-align: center;
    width: 5.5rem;
}

.hov-table tbody tr.is-picked td {
    background: var(--brand-050);
}

.hov-none {
    color: var(--n-500);
    padding: var(--s-5);
    text-align: center;
}

.hov-actions {
    display: flex;
    gap: var(--s-2);
}

/* Below the large breakpoint each file is a card with its headings beside the
   values, the same as Return to Customer, rather than eight columns scrolling
   sideways off a phone. v-show still hides a card: it writes an inline
   display, which wins over the block below. */
@media (max-width: 991.98px) {
    .hov-when {
        grid-template-columns: minmax(0, 1fr);
    }

    .hov-table,
    .hov-table tbody,
    .hov-table tr,
    .hov-table td {
        display: block;
        width: 100%;
    }

    .hov-table thead {
        clip: rect(0 0 0 0);
        height: 1px;
        overflow: hidden;
        position: absolute;
        width: 1px;
    }

    .hov-table tbody tr {
        border: 1px solid var(--n-200);
        border-radius: var(--r-md);
        margin-bottom: var(--s-3);
        padding: var(--s-2) var(--s-3);
    }

    /* Each heading beside its value rather than above it: a card is then half
       the height, and the counter scrolls through a day's worth of them. */
    .hov-table tbody td {
        align-items: baseline;
        border-bottom: 0;
        display: flex;
        gap: var(--s-3);
        padding: 0.2rem 0;
    }

    .hov-table tbody td::before {
        color: var(--n-500);
        content: attr(data-label);
        flex: 0 0 6.5rem;
        font-size: var(--t-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .hov-table tbody td.hov-none::before {
        content: none;
    }

    .hov-table .hov-tick {
        text-align: left;
        width: auto;
    }
}

@media (max-width: 575.98px) {
    .hov-actions,
    .hov-actions .ui-btn {
        flex: 1 1 auto;
    }
}
</style>
