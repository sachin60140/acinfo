<script setup>
/*
 * One file's paper checklist.
 *
 * A line per paper the file's unfinished work needs, each answered Received,
 * Pending or Not needed. Field names are what WorkFileController::papers()
 * validates — papers[<paper_type_id>][state|note|office_note] — and the server
 * checks every line again against the list as it stands when the form arrives.
 *
 * Two notes per line, because they have two readers. The note is shown to the
 * customer ("Buyer has not signed Form 30"); the office note never is.
 */
import { computed, reactive } from 'vue';

const props = defineProps({
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    returnTo: { type: String, default: '' },
    backUrl: { type: String, required: true },
    editUrl: { type: String, default: '' },
    file: { type: Object, required: true },
    lastCheck: { type: Object, default: null },
    states: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
});

const RECEIVED = 'received';
const PENDING = 'pending';
const NOT_NEEDED = 'not_needed';

const rows = reactive(props.lines.map((line) => ({
    ...line,
    state: line.state || '',
    note: line.note || '',
    office_note: line.office_note || '',
    // Notes are shown where they are needed or already say something; a
    // received paper usually needs neither, and fifteen empty boxes hide the list.
    showNotes: Boolean(line.note || line.office_note || (line.state && line.state !== RECEIVED)),
})));

const errorFor = (row, field) => props.errors[`papers.${row.paper_type_id}.${field}`] || '';

const unanswered = computed(() => rows.filter((row) => ! row.state));
const pending = computed(() => rows.filter((row) => row.state === PENDING));

// A required paper marked not needed has to say why — the server refuses it otherwise.
const unexplained = (row) =>
    row.required && row.state === NOT_NEEDED && ! row.note.trim() && ! row.office_note.trim();

const blocked = computed(() => unanswered.value.length > 0 || rows.some(unexplained));

function choose(row, state) {
    row.state = state;

    if (state !== RECEIVED) {
        row.showNotes = true;
    }
}

/*
 * Most folders arrive complete. This answers every line not already answered
 * otherwise — a paper marked pending or not needed on purpose is left alone.
 */
function allReceived() {
    rows.forEach((row) => {
        if (! row.state) {
            row.state = row.required ? RECEIVED : NOT_NEEDED;
        }
    });
}

const summary = computed(() => {
    if (! rows.length) {
        return { tone: 'quiet', text: '' };
    }

    if (unanswered.value.length) {
        const n = unanswered.value.length;

        return { tone: 'error', text: `${n} ${n === 1 ? 'paper is' : 'papers are'} not marked yet.` };
    }

    if (rows.some(unexplained)) {
        return { tone: 'error', text: 'A required paper marked not needed must say why.' };
    }

    if (pending.value.length) {
        return {
            tone: 'warn',
            text: `Pending: ${pending.value.map((row) => row.name).join(', ')}. Those works go to Paper Pendency, and the customer sees the list.`,
        };
    }

    return { tone: 'ready', text: 'All papers in. The file is ready to give to a vendor.' };
});

const STATE_ICONS = { received: 'bi-check2', pending: 'bi-hourglass-split', not_needed: 'bi-dash' };
</script>

<template>
    <div class="ui ui-page pck-page">
        <div class="ui-card">
            <div class="ui-card__body pck-file">
                <div class="pck-file__who">
                    <div class="pck-file__title">
                        <strong>{{ file.file_no }}</strong>
                        <span v-if="file.registration_no" class="pck-plate">{{ file.registration_no }}</span>
                        <span class="ui-badge" :data-state="file.status_key">{{ file.status }}</span>
                    </div>
                    <div v-if="file.description || file.last_remark" class="pck-before">
                        <span v-if="file.description"><strong>Details:</strong> {{ file.description }}</span>
                        <span v-if="file.last_remark"><strong>Last remark:</strong> {{ file.last_remark }}</span>
                    </div>
                    <div class="ui-hint">
                        {{ file.customer || 'No customer' }} · received {{ file.received }}
                        <template v-if="lastCheck"> · last checked {{ lastCheck.on }}<template v-if="lastCheck.by"> by {{ lastCheck.by }}</template></template>
                    </div>
                </div>
                <div class="pck-works">
                    <span v-for="work in file.works" :key="work" class="pck-work">{{ work }}</span>
                </div>
            </div>
        </div>

        <div v-if="! rows.length" class="ui-card">
            <div class="ui-card__body ui-empty">
                <div class="ui-empty__icon"><i class="bi bi-clipboard-check"></i></div>
                <div class="ui-empty__title">Nothing to check</div>
                <div>None of the unfinished work on this file needs papers from the list.</div>
                <a :href="backUrl" class="ui-btn">Back</a>
            </div>
        </div>

        <form v-else class="ui-card" :action="action" method="POST">
            <input type="hidden" name="_token" :value="csrf">
            <input type="hidden" name="return_to" :value="returnTo">

            <div class="ui-card__head pck-head">
                <div>
                    <h2 class="ui-card__title">Papers</h2>
                    <div class="ui-page__sub">
                        Mark each paper. Anything pending puts the work that needs it into Paper Pendency, and the
                        customer sees what is still needed.
                    </div>
                </div>
                <button v-if="unanswered.length" type="button" class="ui-btn ui-btn--sm" @click="allReceived">
                    <i class="bi bi-check2-all"></i> Mark the rest received
                </button>
            </div>

            <ul class="pck-list">
                <li
                    v-for="row in rows"
                    :key="row.paper_type_id"
                    class="pck-line"
                    :class="[`is-${row.state || 'open'}`, { 'is-invalid': errorFor(row, 'state') || unexplained(row) }]">
                    <div class="pck-line__paper">
                        <div class="pck-line__name">
                            <strong>{{ row.name }}</strong>
                            <span v-if="! row.required" class="pck-tag">If applicable</span>
                        </div>
                        <div class="pck-line__for">
                            For <span v-for="work in row.works" :key="work" class="pck-work pck-work--sm">{{ work }}</span>
                            <span v-if="row.state === 'received' && row.received_on" class="ui-hint">· received {{ row.received_on }}</span>
                        </div>
                    </div>

                    <!-- Three answers as one control. Radios, so the keyboard
                         moves between them and a screen reader says which is chosen. -->
                    <div class="pck-choice" role="radiogroup" :aria-label="`${row.name}`">
                        <label
                            v-for="(label, key) in states"
                            :key="key"
                            class="pck-choice__opt"
                            :class="[`pck-choice__opt--${key}`, { 'is-on': row.state === key }]">
                            <input
                                type="radio"
                                :name="`papers[${row.paper_type_id}][state]`"
                                :value="key"
                                :checked="row.state === key"
                                @change="choose(row, key)">
                            <i class="bi" :class="STATE_ICONS[key]"></i>
                            {{ label }}
                        </label>
                    </div>

                    <div class="pck-line__notes" v-show="row.showNotes">
                        <label class="pck-note">
                            <span class="ui-label">Note for the customer</span>
                            <input
                                type="text"
                                class="ui-input"
                                :class="{ 'ui-input--invalid': unexplained(row) }"
                                :name="`papers[${row.paper_type_id}][note]`"
                                v-model="row.note"
                                maxlength="200"
                                :placeholder="row.state === 'pending' ? 'e.g. Buyer has not signed it' : 'Optional'">
                        </label>
                        <label class="pck-note">
                            <span class="ui-label">Office note</span>
                            <input
                                type="text"
                                class="ui-input"
                                :name="`papers[${row.paper_type_id}][office_note]`"
                                v-model="row.office_note"
                                maxlength="200"
                                placeholder="Only the office sees this">
                        </label>
                    </div>

                    <button v-if="! row.showNotes" type="button" class="pck-addnote" @click="row.showNotes = true">
                        <i class="bi bi-plus"></i> Add a note
                    </button>

                    <div v-if="errorFor(row, 'state') || errorFor(row, 'note') || unexplained(row)" class="ui-hint ui-hint--error pck-line__error">
                        {{ errorFor(row, 'state') || errorFor(row, 'note') || 'Required paper: say why it is not needed.' }}
                    </div>
                </li>
            </ul>

            <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': ! blocked }">
                <span class="ui-hint pck-summary" :class="`pck-summary--${summary.tone}`">{{ summary.text }}</span>
                <div class="pck-actions">
                    <a :href="backUrl" class="ui-btn">Cancel</a>
                    <a v-if="editUrl" :href="editUrl" class="ui-btn">Open file</a>
                    <button type="submit" class="ui-btn ui-btn--primary" :disabled="blocked">
                        <i class="bi bi-clipboard-check"></i> Save checklist
                    </button>
                </div>
            </div>
        </form>
    </div>
</template>

<style>
/* Every class carries the pck- prefix: a short unscoped name in a component's
   style block is global. See StylesheetTest. */

.pck-file {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-3) var(--s-4);
    justify-content: space-between;
}

.pck-file__who {
    display: flex;
    flex-direction: column;
    gap: var(--s-1);
    min-width: 0;
}

.pck-file__title {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    font-size: var(--t-lg);
    gap: var(--s-2);
}

/* What was noted before the checklist — often which papers were missing. */
.pck-before {
    color: var(--n-700);
    display: flex;
    flex-wrap: wrap;
    font-size: var(--t-sm);
    gap: 0.1rem var(--s-4);
}

/* The vehicle, in the shape a number plate is read in. */
.pck-plate {
    border: 1px solid var(--n-300);
    border-radius: var(--r-sm);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.06em;
    padding: 0.1rem 0.4rem;
}

.pck-works {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-1);
}

.pck-work {
    background: var(--brand-050);
    border-radius: 999px;
    color: var(--ink-700);
    display: inline-block;
    font-size: var(--t-xs);
    font-weight: 700;
    padding: 0.15rem 0.55rem;
}

.pck-work--sm {
    margin-right: 0.2rem;
    padding: 0.05rem 0.45rem;
}

.pck-head {
    align-items: flex-start;
    flex-wrap: wrap;
    gap: var(--s-3);
}

.pck-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.pck-line {
    align-items: center;
    border-top: 1px solid var(--n-100);
    border-left: 3px solid transparent;
    display: grid;
    gap: var(--s-2) var(--s-4);
    grid-template-columns: minmax(0, 1fr) auto;
    padding: var(--s-3) var(--s-4);
}

.pck-line:first-child {
    border-top: 0;
}

.pck-line.is-pending {
    background: var(--warn-050);
    border-left-color: var(--warn-500);
}

.pck-line.is-received {
    border-left-color: var(--dr-600);
}

.pck-line.is-invalid {
    border-left-color: var(--cr-600);
}

.pck-line__paper {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    min-width: 0;
}

.pck-line__name {
    align-items: baseline;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2);
}

.pck-tag {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 600;
}

.pck-line__for {
    color: var(--n-500);
    font-size: var(--t-xs);
}

.pck-line__notes,
.pck-addnote,
.pck-line__error {
    grid-column: 1 / -1;
}

.pck-line__notes {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-3);
}

.pck-note {
    display: flex;
    flex: 1 1 16rem;
    flex-direction: column;
    gap: 0.2rem;
    margin: 0;
    min-width: 0;
}

.pck-addnote {
    background: none;
    border: 0;
    color: var(--brand-500);
    cursor: pointer;
    font-size: var(--t-xs);
    font-weight: 700;
    justify-self: start;
    padding: 0;
}

/* ---- the three answers ---------------------------------------------------- */

.pck-choice {
    border: 1px solid var(--n-300);
    border-radius: var(--r-md);
    display: inline-flex;
    overflow: hidden;
}

.pck-choice__opt {
    align-items: center;
    background: var(--n-000);
    color: var(--n-600);
    cursor: pointer;
    display: inline-flex;
    font-size: var(--t-sm);
    font-weight: 600;
    gap: 0.3rem;
    margin: 0;
    padding: 0.4rem 0.7rem;
    white-space: nowrap;
}

.pck-choice__opt + .pck-choice__opt {
    border-left: 1px solid var(--n-300);
}

/* The radio itself is hidden but still focusable and still what posts. */
.pck-choice__opt input {
    height: 1px;
    opacity: 0;
    position: absolute;
    width: 1px;
}

.pck-choice__opt:focus-within {
    outline: 2px solid var(--brand-500);
    outline-offset: -2px;
}

.pck-choice__opt:hover {
    background: var(--n-050);
}

.pck-choice__opt--received.is-on {
    background: var(--dr-050);
    color: var(--dr-700);
}

.pck-choice__opt--pending.is-on {
    background: var(--warn-050);
    color: var(--warn-600);
}

.pck-choice__opt--not_needed.is-on {
    background: var(--n-100);
    color: var(--n-700);
}

.pck-summary--error { color: var(--cr-700); font-weight: 600; }
.pck-summary--warn { color: var(--warn-600); font-weight: 600; }
.pck-summary--ready { color: var(--dr-700); font-weight: 600; }

.pck-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2);
}

@media (max-width: 767.98px) {
    /* The answers drop under the paper they answer. */
    .pck-line {
        grid-template-columns: minmax(0, 1fr);
    }

    .pck-choice {
        width: 100%;
    }

    .pck-choice__opt {
        flex: 1 1 0;
        justify-content: center;
        padding-inline: 0.3rem;
    }

    .pck-actions,
    .pck-actions .ui-btn {
        flex: 1 1 auto;
    }
}
</style>
