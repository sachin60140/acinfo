<script setup>
/*
 * Step 2: papers checked.
 *
 * Two lists. Files received whose papers have not been checked, each opening
 * its checklist. And every paper still pending, across every file — which is
 * what the counter works from when a customer walks in with the missing
 * papers: search their name or vehicle, tick what they brought, Mark received.
 *
 * Field names are what WorkFileController::paperAudit() validates —
 * received[] and received_on — and the server only changes lines that are
 * still pending when the form arrives.
 */
import { computed, ref } from 'vue';
import { copyText, pendingMessage, whatsappNumber, whatsappUrl } from '../paperShare';

const props = defineProps({
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    today: { type: String, default: '' },
    search: { type: String, default: '' },
    toCheck: { type: Array, default: () => [] },
    pending: { type: Array, default: () => [] },
    // Files in Paper Pendency this screen can do nothing for, and why.
    stuck: { type: Array, default: () => [] },
});

const query = ref(props.search ?? '');
const picked = ref([]);

const terms = computed(() => query.value.trim().toLowerCase().split(/\s+/).filter(Boolean));

const haystack = (row) => [
    row.file_no,
    row.registration_no,
    row.customer,
    row.paper,
    row.work_type,
    ...(row.works || []),
    row.description,
    row.last_remark,
    row.why,
].filter(Boolean).join(' ').toLowerCase();

// Every word must appear somewhere on the row, so "car4sales form 30" is that
// customer's missing Form 30s and nothing else.
const matches = (row) => terms.value.every((term) => haystack(row).includes(term));

const shownCheck = computed(() => props.toCheck.filter(matches));
const shownPending = computed(() => props.pending.filter(matches));
const shownStuck = computed(() => props.stuck.filter(matches));

const isPicked = (row) => picked.value.includes(row.id);

/*
 * Sending the list to the customer.
 *
 * What is shown, not what is ticked. The ticks on this card mean "received" and
 * feed Mark received, so a list sent by ticking would leave six papers ticked
 * for sending, and the next Mark received — pressed for the two that actually
 * arrived — would mark all eight. Searching a vehicle or a customer narrows the
 * list to their file in one step, which is the step the office takes anyway.
 */
const shownCustomers = computed(() => {
    const byId = new Map();

    for (const row of shownPending.value) {
        byId.set(row.customer_id ?? row.customer, row);
    }

    return byId;
});

// The one customer these papers belong to, or null when the list spans several.
const oneCustomer = computed(() => (shownCustomers.value.size === 1 ? [...shownCustomers.value.values()][0] : null));

const shareNumber = computed(() => (oneCustomer.value ? whatsappNumber(oneCustomer.value.customer_mobile) : null));

const shareText = computed(() => pendingMessage(shownPending.value));

/* "+91 98352 30000", so the office can see where it is going before it goes. */
const shownNumber = computed(() => (shareNumber.value
    ? `+91 ${shareNumber.value.slice(2, 7)} ${shareNumber.value.slice(7)}`
    : ''));

const shareHint = computed(() => {
    if (! oneCustomer.value) {
        return `These papers are for ${shownCustomers.value.size} customers. Search one of them to send it straight to them — or send it and choose the chat.`;
    }

    return shareNumber.value
        ? `Opens a chat with ${oneCustomer.value.customer} on ${shownNumber.value}. Nothing is sent until you press Send in WhatsApp.`
        : `${oneCustomer.value.customer} has no mobile number WhatsApp can use, so you will choose the chat yourself.`;
});

const copied = ref(false);

async function copyList() {
    copied.value = await copyText(shareText.value);

    if (copied.value) {
        setTimeout(() => {
            copied.value = false;
        }, 2500);
    }
}

function sendOnWhatsApp() {
    window.open(whatsappUrl(shareNumber.value, shareText.value), '_blank', 'noopener');
}

/*
 * Rows the search hides stay on the form, ticks and all — hidden with v-show,
 * never removed — so a paper ticked before the search was typed is still
 * marked received. The footer says how many that is.
 */
const hiddenPicked = computed(() => props.pending.filter((row) => isPicked(row) && ! matches(row)).length);

// Select all means all of what is on screen, in both directions.
const allPicked = computed({
    get: () => shownPending.value.length > 0 && shownPending.value.every(isPicked),
    set: (on) => {
        const ids = shownPending.value.map((row) => row.id);

        picked.value = on
            ? [...new Set([...picked.value, ...ids])]
            : picked.value.filter((id) => ! ids.includes(id));
    },
});

const partlyPicked = computed(() => shownPending.value.some(isPicked) && ! allPicked.value);

const summary = computed(() => {
    if (! picked.value.length) {
        return 'Tick the papers the customer has brought in.';
    }

    const files = new Set(props.pending.filter(isPicked).map((row) => row.file_id)).size;
    const n = picked.value.length;
    let text = `${n} ${n === 1 ? 'paper' : 'papers'} on ${files} ${files === 1 ? 'file' : 'files'} marked received.`;

    if (hiddenPicked.value) {
        text += ` ${hiddenPicked.value} of them ${hiddenPicked.value === 1 ? 'is' : 'are'} hidden by the search.`;
    }

    return text;
});

// dd-mm-yyyy for the box, Y-m-d for the server; see HandOver.vue for why v-once.
const stamp = String(props.today).match(/^(\d{4})-(\d{2})-(\d{2})$/);
const displayDate = stamp ? `${stamp[3]}-${stamp[2]}-${stamp[1]}` : '';
</script>

<template>
    <div class="ui ui-page pau-page">
        <div class="pau-search">
            <i class="bi bi-search"></i>
            <!-- No name: it narrows what is on screen, never what is sent. -->
            <input
                type="search"
                class="ui-input"
                v-model="query"
                placeholder="Search customer, vehicle, file or paper"
                aria-label="Search files and papers">
        </div>

        <!-- ---- Files still to be checked --------------------------------- -->
        <div class="ui-card">
            <div class="ui-card__head">
                <div>
                    <h2 class="ui-card__title">
                        Papers to check
                        <span class="pau-count">{{ toCheck.length }}</span>
                    </h2>
                    <div class="ui-page__sub">
                        Received and not yet checked. A file cannot be given to a vendor until its papers are.
                    </div>
                </div>
            </div>

            <div v-if="! toCheck.length" class="ui-card__body pau-none">
                Every received file has had its papers checked.
            </div>

            <div v-else class="ui-table-wrap">
                <table class="ui-table pau-table">
                    <thead>
                        <tr>
                            <th>File No.</th>
                            <th>Vehicle</th>
                            <th>Customer</th>
                            <th>Work</th>
                            <th>Received</th>
                            <th class="pau-go"><span class="visually-hidden">Checklist</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="file in toCheck" v-show="matches(file)" :key="file.id">
                            <td data-label="File No."><span class="ui-lead">{{ file.file_no }}</span></td>
                            <td data-label="Vehicle">{{ file.registration_no || '—' }}</td>
                            <td data-label="Customer">{{ file.customer || '—' }}</td>
                            <td data-label="Work">
                                {{ file.work_type || '—' }}
                                <!-- What was noted before the checklist existed: often the
                                     only record of which papers were missing. -->
                                <div v-if="file.description" class="ui-sub">{{ file.description }}</div>
                                <div v-if="file.last_remark" class="ui-sub">Last remark: {{ file.last_remark }}</div>
                            </td>
                            <td data-label="Received">{{ file.received_date }}</td>
                            <td data-label="" class="pau-go">
                                <a :href="file.papers_url" class="ui-btn ui-btn--sm ui-btn--primary">
                                    <i class="bi bi-clipboard-check"></i> Check papers
                                </a>
                            </td>
                        </tr>
                        <tr v-if="! shownCheck.length">
                            <td colspan="6" class="pau-none">No file to check matches that search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---- Papers still pending ------------------------------------------ -->
        <form class="ui-card" :action="action" method="POST">
            <input type="hidden" name="_token" :value="csrf">

            <div class="ui-card__head pau-head">
                <div>
                    <h2 class="ui-card__title">
                        Papers pending
                        <span class="pau-count pau-count--warn">{{ pending.length }}</span>
                    </h2>
                    <div class="ui-page__sub">
                        What customers still have to bring in. When they do, tick it here — the work leaves Paper
                        Pendency once nothing else is holding it.
                    </div>
                </div>

                <div v-if="pending.length" class="pau-tools">
                    <div class="ui-field pau-date" v-once>
                        <label class="ui-label" for="received_on_display">Received on</label>
                        <input
                            type="text"
                            id="received_on_display"
                            class="ui-input js-datefield"
                            :value="displayDate"
                            data-target="received_on"
                            :data-max="today"
                            placeholder="dd-mm-yyyy"
                            inputmode="numeric"
                            maxlength="10"
                            autocomplete="off"
                            required>
                        <input type="hidden" id="received_on" name="received_on" :value="today">
                    </div>

                    <label class="pau-all">
                        <input
                            type="checkbox"
                            class="pau-check"
                            v-model="allPicked"
                            :indeterminate="partlyPicked"
                            :disabled="! shownPending.length">
                        Select all
                    </label>
                </div>
            </div>

            <!--
                The list, for the customer.

                type="button" on both, and it matters: this card is the Mark
                received form, so a button left to its default would submit it
                and mark every ticked paper received on the way to WhatsApp.
            -->
            <div v-if="shownPending.length" class="pau-share">
                <div class="pau-share__row">
                    <span class="pau-share__what">
                        <i class="bi bi-send"></i>
                        Send {{ shownPending.length === 1 ? 'this paper' : `these ${shownPending.length} papers` }}
                        <template v-if="oneCustomer"> to {{ oneCustomer.customer }}</template>
                    </span>

                    <div class="pau-share__actions">
                        <button type="button" class="ui-btn ui-btn--sm" @click="copyList">
                            <i class="bi" :class="copied ? 'bi-check2' : 'bi-clipboard'"></i>
                            {{ copied ? 'Copied' : 'Copy list' }}
                        </button>
                        <button type="button" class="ui-btn ui-btn--sm pau-share__wa" @click="sendOnWhatsApp">
                            <i class="bi bi-whatsapp"></i>
                            Send on WhatsApp
                        </button>
                    </div>
                </div>

                <p class="ui-hint pau-share__hint">{{ shareHint }}</p>
            </div>

            <div v-if="! pending.length" class="ui-card__body pau-none">
                No papers are pending.
            </div>

            <template v-else>
                <div class="ui-table-wrap">
                    <table class="ui-table pau-table">
                        <thead>
                            <tr>
                                <th class="pau-tick">Received</th>
                                <th>Paper</th>
                                <th>File No.</th>
                                <th>Vehicle</th>
                                <th>Customer</th>
                                <th>For</th>
                                <th>Note</th>
                                <th>Pending since</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in pending"
                                v-show="matches(row)"
                                :key="row.id"
                                :class="{ 'is-picked': isPicked(row) }">
                                <td data-label="Received" class="pau-tick">
                                    <input
                                        type="checkbox"
                                        class="pau-check"
                                        name="received[]"
                                        :value="row.id"
                                        v-model="picked"
                                        :aria-label="`${row.paper} received for ${row.file_no}`">
                                </td>
                                <td data-label="Paper"><strong>{{ row.paper }}</strong></td>
                                <td data-label="File No.">
                                    <a :href="row.papers_url" class="ui-link">{{ row.file_no }}</a>
                                </td>
                                <td data-label="Vehicle">{{ row.registration_no || '—' }}</td>
                                <td data-label="Customer">{{ row.customer || '—' }}</td>
                                <td data-label="For">{{ row.works.join(', ') || '—' }}</td>
                                <td data-label="Note">
                                    <span v-if="row.note">{{ row.note }}</span>
                                    <div v-if="row.office_note" class="ui-sub">Office: {{ row.office_note }}</div>
                                    <span v-if="! row.note && ! row.office_note">—</span>
                                </td>
                                <td data-label="Pending since">{{ row.since }}</td>
                            </tr>
                            <tr v-if="! shownPending.length">
                                <td colspan="8" class="pau-none">No pending paper matches that search.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': picked.length }">
                    <span class="ui-hint">{{ summary }}</span>
                    <button type="submit" class="ui-btn ui-btn--primary" :disabled="! picked.length">
                        <i class="bi bi-check2-all"></i> Mark received
                    </button>
                </div>
            </template>
        </form>

        <!-- ---- Stuck in Paper Pendency ---------------------------------------- -->
        <!--
            Outside the form: there is nothing here to tick. These files are in
            Paper Pendency and this screen cannot help them — named because
            otherwise they appear on no screen at all, which is how one came to
            sit there for weeks.
        -->
        <div v-if="stuck.length" class="ui-card pau-stuck">
            <div class="ui-card__head">
                <div>
                    <h2 class="ui-card__title">
                        Stuck in Paper Pendency
                        <span class="pau-count pau-count--warn">{{ stuck.length }}</span>
                    </h2>
                    <p class="ui-hint">
                        Waiting on papers, with no checklist behind them. Give the work a paper list,
                        or move the file on from the status board.
                    </p>
                </div>
            </div>

            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Vehicle</th>
                            <th>Customer</th>
                            <th>Work</th>
                            <th>Received</th>
                            <th>Why</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="file in stuck" v-show="matches(file)" :key="file.id">
                            <td data-label="File"><strong>{{ file.file_no }}</strong></td>
                            <td data-label="Vehicle">{{ file.registration_no || '—' }}</td>
                            <td data-label="Customer">{{ file.customer || '—' }}</td>
                            <td data-label="Work">{{ file.work_type || '—' }}</td>
                            <td data-label="Received">{{ file.received_date }}</td>
                            <td data-label="Why" class="pau-why">{{ file.why }}</td>
                            <td data-label="">
                                <a :href="file.work_type_url" class="ui-btn ui-btn--sm">Paper lists</a>
                                <a :href="file.board_url" class="ui-btn ui-btn--sm">Status board</a>
                            </td>
                        </tr>
                        <tr v-if="! shownStuck.length">
                            <td colspan="7" class="pau-none">No stuck file matches that search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<style>
/* A bar of its own between the heading and the table, so sending the list reads
   as a separate thing from receiving papers — which is what the rest of this
   card is for. */
.pau-share {
    background: var(--n-025);
    border-bottom: 1px solid var(--n-100);
    padding: var(--s-3) var(--s-5);
}

.pau-share__row {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-3);
    justify-content: space-between;
}

.pau-share__what {
    align-items: center;
    color: var(--ink-800);
    display: inline-flex;
    font-weight: 600;
    gap: var(--s-2);
}

.pau-share__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2);
}

/* WhatsApp's own green, so the button is recognisable before it is read. */
.pau-share__wa {
    background: #25d366;
    border-color: #1ebe5b;
    color: #fff;
}

.pau-share__wa:hover {
    background: #1ebe5b;
    color: #fff;
}

.pau-share__hint {
    margin: var(--s-1) 0 0;
}
/* Every class carries the pau- prefix: a short unscoped name in a component's
   style block is global. See StylesheetTest. */

/* The last card on the page: nothing to do here, only something to explain. */
.pau-stuck {
    border-color: var(--warn-500);
    margin-top: var(--s-4);
}

.pau-why {
    color: var(--n-700);
    font-size: var(--t-sm);
}

.pau-search {
    align-items: center;
    display: flex;
    gap: var(--s-2);
    max-width: 30rem;
}

.pau-search i {
    color: var(--n-400);
}

.pau-search .ui-input {
    flex: 1 1 auto;
    min-width: 0;
}

.pau-count {
    background: var(--n-100);
    border-radius: 999px;
    color: var(--n-600);
    display: inline-block;
    font-size: var(--t-xs);
    font-weight: 700;
    margin-left: var(--s-1);
    padding: 0.1rem 0.5rem;
    vertical-align: middle;
}

.pau-count--warn {
    background: var(--warn-050);
    color: var(--warn-600);
}

.pau-head {
    align-items: flex-start;
    flex-wrap: wrap;
    gap: var(--s-3);
}

.pau-tools {
    align-items: flex-end;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
}

.pau-date {
    width: 10rem;
}

.pau-all {
    align-items: center;
    color: var(--n-600);
    cursor: pointer;
    display: inline-flex;
    font-size: var(--t-sm);
    font-weight: 600;
    gap: var(--s-2);
    padding-bottom: 0.45rem;
    white-space: nowrap;
}

.pau-check {
    height: 1.05rem;
    width: 1.05rem;
}

.pau-table .pau-tick {
    text-align: center;
    width: 5.5rem;
}

.pau-table .pau-go {
    text-align: right;
    white-space: nowrap;
}

.pau-table tbody tr.is-picked td {
    background: var(--brand-050);
}

.pau-none {
    color: var(--n-500);
    padding: var(--s-5);
    text-align: center;
}

/* Below the large breakpoint each row is a card with its headings beside the
   values. v-show still hides a card: it writes an inline display, which wins
   over the block below. */
@media (max-width: 991.98px) {
    .pau-table,
    .pau-table tbody,
    .pau-table tr,
    .pau-table td {
        display: block;
        width: 100%;
    }

    .pau-table thead {
        clip: rect(0 0 0 0);
        height: 1px;
        overflow: hidden;
        position: absolute;
        width: 1px;
    }

    .pau-table tbody tr {
        border: 1px solid var(--n-200);
        border-radius: var(--r-md);
        margin-bottom: var(--s-3);
        padding: var(--s-2) var(--s-3);
    }

    .pau-table tbody td {
        align-items: baseline;
        border-bottom: 0;
        display: flex;
        gap: var(--s-3);
        padding: 0.2rem 0;
        text-align: left;
    }

    .pau-table tbody td::before {
        color: var(--n-500);
        content: attr(data-label);
        flex: 0 0 6.5rem;
        font-size: var(--t-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .pau-table tbody td.pau-none::before {
        content: none;
    }

    .pau-table .pau-tick,
    .pau-table .pau-go {
        width: auto;
    }

    .pau-date {
        width: 100%;
    }
}
</style>
