<script setup>
/*
 * Taking papers in over the counter.
 *
 * One card per file. A file is one envelope for one vehicle, and it may be for
 * several works at once — a transfer and a hypothecation addition together —
 * each charged separately, so each is a line inside the card rather than a work
 * type invented to name the pair.
 *
 * The inputs keep the exact names the controller already validates
 * (rows[i][registration_no], rows[i][works][j][amount] and so on), so the form
 * still posts normally and the server still checks every field. That is what
 * makes it safe to redraw a screen on a live application: the layout moves, the
 * money logic does not.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    workTypes: { type: Array, default: () => [] },
    historyUrl: { type: String, required: true },
    // Where to ask what this customer has been charged before.
    ratesUrl: { type: String, default: '' },
    cancelUrl: { type: String, default: '' },
    oldRows: { type: Array, default: () => [] },
});

/*
 * One work on the papers: a type and what the customer is charged for it.
 */
function blankWork() {
    return { work_type_id: '', amount: '' };
}

function blankRow() {
    return {
        registration_no: '',
        description: '',
        works: [blankWork()],
        // Lookup state, never submitted.
        history: null,
        looking: false,
        failed: false,
        open: true,
    };
}

const rows = reactive(
    props.oldRows.length
        ? props.oldRows.map((row) => ({
            ...blankRow(),
            ...row,
            // A bounced form comes back with the works as they were typed.
            works: (row.works ?? []).length ? row.works.map((w) => ({ ...blankWork(), ...w })) : [blankWork()],
        }))
        : [blankRow()]
);

const money = (value) =>
    (Number(value) || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

// What one file comes to, so a card holding three works says so itself rather
// than leaving the operator to add them up against the batch total.
const fileTotal = (row) => row.works.reduce((sum, work) => sum + (Number(work.amount) || 0), 0);

const total = computed(() => rows.reduce((sum, row) => sum + fileTotal(row), 0));

const workCount = computed(() => rows.reduce((count, row) => count + row.works.length, 0));

/*
 * What is not filled in yet. Said in the footer rather than discovered on
 * submit: at a counter with a customer waiting, a form that bounces is a form
 * that has to be read again from the top.
 */
const unpriced = computed(() =>
    rows.reduce(
        (count, row) => count + row.works.filter((w) => ! w.work_type_id || String(w.amount).trim() === '').length,
        0
    )
);

const summary = computed(() => {
    const files = `${rows.length} ${rows.length === 1 ? 'file' : 'files'}`;
    const works = `${workCount.value} ${workCount.value === 1 ? 'work' : 'works'}`;

    if (unpriced.value) {
        return {
            tone: 'wait',
            text: `${files}, ${works} — ${unpriced.value} still ${unpriced.value === 1 ? 'needs' : 'need'} a work type and a price.`,
        };
    }

    return { tone: 'ready', text: `${files}, ${works}, all priced.` };
});

/*
 * What this line may still be set to.
 *
 * One vehicle has one transfer and one hypothecation addition — a file for the
 * same work twice is a mistyped file, not a bigger one. So a work already
 * chosen elsewhere on this card is not offered again, and the line's own choice
 * stays in its list or it would have nothing selected to show.
 */
function worksLeftFor(row, index) {
    const taken = new Set(
        row.works
            .filter((_, i) => i !== index)
            .map((work) => String(work.work_type_id))
            .filter(Boolean)
    );

    return props.workTypes.filter((type) => ! taken.has(String(type.id)));
}

// Every work is on the card already, so another line would have nothing to
// offer. Said on the button rather than found out by pressing it.
const allWorksUsed = (row) => row.works.length >= props.workTypes.length;

function addWork(row) {
    if (! allWorksUsed(row)) {
        row.works.push(blankWork());
    }
}

function removeWork(row, index) {
    // Never the last one: a file with no work on it is not a file.
    if (row.works.length > 1) {
        row.works.splice(index, 1);
    }
}

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
function onWorkTypeChange(work) {
    const type = props.workTypes.find((t) => String(t.id) === String(work.work_type_id));

    if (type && type.default_rate && ! work.amount) {
        work.amount = Number(type.default_rate).toFixed(2);
    }
}

/*
 * What this customer has paid before, work by work.
 *
 * The mirror of the vendor screen's rate history, on the other side of the
 * counter: a price is agreed with the customer in front of you, and the
 * question is what they paid last time for the same job. Their own history
 * rather than everybody's — a price is settled between two people, and what
 * another customer paid is not what this one expects.
 *
 * Fetched rather than sent with the screen, because the customer is chosen
 * after it loads. The panel above announces the choice; this listens, the same
 * way it listens for the running total in the other direction.
 */
const customerRates = ref([]);

const ratesFor = computed(() =>
    Object.fromEntries(customerRates.value.map((one) => [one.work_type_id, one.rates]))
);

const pastRates = (work) => (work.work_type_id ? ratesFor.value[work.work_type_id] ?? [] : []);

// One open at a time, keyed by the row and the line it belongs to.
const showingRates = ref(null);

function toggleRates(rowIndex, workIndex) {
    const key = `${rowIndex}-${workIndex}`;

    showingRates.value = showingRates.value === key ? null : key;
}

const isShowingRates = (rowIndex, workIndex) => showingRates.value === `${rowIndex}-${workIndex}`;

async function loadCustomerRates(customerId) {
    if (! customerId || ! props.ratesUrl) {
        customerRates.value = [];

        return;
    }

    try {
        const response = await fetch(`${props.ratesUrl}?customer_id=${encodeURIComponent(customerId)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (! response.ok) {
            throw new Error(`Lookup failed: ${response.status}`);
        }

        customerRates.value = (await response.json()).works ?? [];
    } catch (error) {
        // A price can still be agreed without it, so a failed lookup is quiet.
        customerRates.value = [];
    }
}

function onCustomerChosen(event) {
    loadCustomerRates(event.detail);
}

onMounted(() => document.addEventListener('receive-customer', onCustomerChosen));
onBeforeUnmount(() => document.removeEventListener('receive-customer', onCustomerChosen));

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

        if (! response.ok) {
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

watch(total, (value) => {
    // The panel above this one shows where the customer's balance lands. It
    // listens for the running total rather than reaching into this markup.
    document.dispatchEvent(new CustomEvent('receive-total', { detail: value }));
});
</script>

<template>
    <div class="ui rcv">
        <div class="ui-card">
            <div class="ui-card__head">
                <div>
                    <h2 class="ui-card__title">Files Received</h2>
                    <div class="ui-hint">
                        One card is one file: one envelope, one vehicle, one line on the
                        customer's statement. Papers for several works go on the same card,
                        priced one by one.
                    </div>
                </div>
                <button type="button" class="ui-btn ui-btn--sm" @click="addRow">
                    <i class="bi bi-plus-lg"></i> Add File
                </button>
            </div>

            <div class="ui-card__body rcv-body">
                <article v-for="(row, index) in rows" :key="index" class="rcv-file">
                    <header class="rcv-file__head">
                        <span class="rcv-file__no">File {{ index + 1 }}</span>

                        <!-- What this one file comes to. Blank until there is
                             something to say, so an untouched card is quiet. -->
                        <span v-if="fileTotal(row)" class="rcv-file__sum">
                            <span class="rcv-file__sum-label">Charging</span>
                            <span class="ui-money ui-money--dr ui-money--strong">{{ money(fileTotal(row)) }}</span>
                        </span>

                        <button
                            type="button"
                            class="rcv-file__remove"
                            :disabled="rows.length === 1"
                            :title="rows.length === 1 ? 'The last file cannot be removed' : 'Remove this file'"
                            @click="removeRow(index)">
                            <i class="bi bi-trash3"></i> Remove
                        </button>
                    </header>

                    <div class="rcv-file__body">
                        <!-- What the papers are for, and whose they are. One grid,
                             so every label and box on the card lines up. -->
                        <div class="rcv-about">
                            <div class="ui-field">
                                <label class="ui-label">Registration No.</label>
                                <div class="rcv-affix">
                                    <span class="rcv-affix__tag"><i class="bi bi-car-front"></i></span>
                                    <input
                                        type="text"
                                        class="ui-input rcv-reg"
                                        :name="`rows[${index}][registration_no]`"
                                        v-model="row.registration_no"
                                        @input="onRegInput(row)"
                                        maxlength="20"
                                        autocomplete="off"
                                        placeholder="BR01AB1234">
                                    <span v-if="row.looking" class="rcv-affix__busy" aria-label="Checking earlier files">
                                        <span class="rcv-dot"></span><span class="rcv-dot"></span><span class="rcv-dot"></span>
                                    </span>
                                </div>
                                <div class="ui-hint">Earlier files for this vehicle are looked up as you type.</div>
                            </div>

                            <div class="ui-field">
                                <label class="ui-label">File Details</label>
                                <input
                                    type="text"
                                    class="ui-input"
                                    :name="`rows[${index}][description]`"
                                    v-model="row.description"
                                    maxlength="255"
                                    placeholder="Party name, reference — anything that identifies these papers">
                            </div>
                        </div>

                        <div class="rcv-works">
                            <div class="rcv-works__head">
                                <span class="ui-label">Work &amp; Amount <span class="ui-label__req">*</span></span>
                                <span class="ui-hint">Each work is charged, approved and priced with the vendor on its own.</span>
                            </div>

                            <!-- One line per work: what it is, what it costs the
                                 customer, and the way to take it off again. -->
                            <template v-for="(work, wi) in row.works" :key="wi">
                            <div class="rcv-work">
                                <select
                                    class="ui-select"
                                    :name="`rows[${index}][works][${wi}][work_type_id]`"
                                    v-model="work.work_type_id"
                                    @change="onWorkTypeChange(work)"
                                    required>
                                    <option value="">Select work</option>
                                    <option v-for="type in worksLeftFor(row, wi)" :key="type.id" :value="type.id">
                                        {{ type.name }}<template v-if="type.default_rate"> — {{ money(type.default_rate) }}</template>
                                    </option>
                                </select>

                                <div class="rcv-work__price">
                                <div class="rcv-affix rcv-work__amount">
                                    <span class="rcv-affix__tag">INR</span>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        class="ui-input ui-input--amount"
                                        :name="`rows[${index}][works][${wi}][amount]`"
                                        v-model="work.amount"
                                        placeholder="0.00"
                                        required>
                                </div>

                                <!-- What this customer paid for this work before. -->
                                <button
                                    v-if="pastRates(work).length"
                                    type="button"
                                    class="rcv-past__open"
                                    title="What this customer has paid for this work before"
                                    @click="toggleRates(index, wi)">
                                    <i class="bi bi-clock-history"></i>
                                    last {{ money(pastRates(work)[0].amount) }}
                                </button>
                                </div>

                                <button
                                    type="button"
                                    class="rcv-work__remove"
                                    :disabled="row.works.length === 1"
                                    :title="row.works.length === 1 ? 'A file is for at least one work' : 'Remove this work'"
                                    @click="removeWork(row, wi)">
                                    <i class="bi bi-x-lg"></i>
                                    <span class="rcv-sr">Remove this work</span>
                                </button>
                            </div>

                            <!-- What this customer paid for this work before. -->
                            <div v-if="isShowingRates(index, wi)" class="rcv-past">
                                <div class="rcv-past__head">
                                    <strong>{{ pastRates(work)[0].work_type }}</strong> &mdash; the last
                                    {{ pastRates(work).length }}
                                    {{ pastRates(work).length === 1 ? 'time' : 'times' }} this customer was charged
                                </div>

                                <div class="ui-table-wrap">
                                    <table class="ui-table rcv-past__table">
                                        <thead>
                                            <tr>
                                                <th>Received</th>
                                                <th>File No.</th>
                                                <th>Vehicle</th>
                                                <th>Status</th>
                                                <th class="num">Charged</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(past, i) in pastRates(work)" :key="i">
                                                <td data-label="Received">{{ past.received_date }}</td>
                                                <td data-label="File No."><span class="ui-lead">{{ past.file_no }}</span></td>
                                                <td data-label="Vehicle">{{ past.registration_no || '—' }}</td>
                                                <td data-label="Status">{{ past.status }}</td>
                                                <td data-label="Charged" class="num">
                                                    <span class="ui-money ui-money--dr">{{ money(past.amount) }}</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            </template>

                            <button
                                type="button"
                                class="ui-btn ui-btn--sm rcv-works__add"
                                :disabled="allWorksUsed(row)"
                                :title="allWorksUsed(row) ? 'Every work is already on this file' : 'Add another work to this file'"
                                @click="addWork(row)">
                                <i class="bi bi-plus-lg"></i> Add another work
                            </button>
                        </div>

                        <!-- What this vehicle has been charged before. -->
                        <div v-if="row.failed" class="rcv-past rcv-past--warn">
                            <i class="bi bi-exclamation-triangle"></i>
                            Could not check this registration number. The file can still be saved.
                        </div>

                        <div v-else-if="row.history && row.history.count === 0" class="rcv-past rcv-past--new">
                            <i class="bi bi-patch-check"></i>
                            No earlier files against <strong>{{ row.history.registration_no }}</strong> — this is its first.
                        </div>

                        <div v-else-if="row.history" class="rcv-past">
                            <button type="button" class="rcv-past__head" @click="row.open = ! row.open">
                                <i class="bi" :class="row.open ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                                <strong>{{ row.history.count }}</strong>
                                earlier {{ row.history.count === 1 ? 'file' : 'files' }} against
                                <strong>{{ row.history.registration_no }}</strong>
                                <span class="rcv-past__hint">— check the work and the price before booking</span>
                            </button>

                            <div v-if="row.open" class="ui-table-wrap">
                                <table class="ui-table rcv-past__table">
                                    <thead>
                                        <tr>
                                            <th>File No.</th>
                                            <th>Received</th>
                                            <th>Type of Work</th>
                                            <th>Customer</th>
                                            <th>Status</th>
                                            <th class="num">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="past in row.history.files" :key="past.id">
                                            <td data-label="File No."><span class="ui-lead">{{ past.file_no }}</span></td>
                                            <td data-label="Received">{{ past.received_date }}</td>
                                            <td data-label="Type of Work">{{ past.work_type }}</td>
                                            <td data-label="Customer">{{ past.customer }}</td>
                                            <td data-label="Status">
                                                <span class="badge" :class="past.status_badge">{{ past.status_label }}</span>
                                            </td>
                                            <td class="num" data-label="Amount">
                                                <span v-if="past.was_returned" class="ui-money ui-money--void">{{ past.charged }}</span>
                                                <span class="ui-money">{{ past.net }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </article>

                <!-- Below the cards rather than only above them: after filling one
                     in, the next file is added from where the eye already is. -->
                <button type="button" class="rcv-add" @click="addRow">
                    <i class="bi bi-plus-lg"></i> Add another file
                </button>
            </div>

            <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': total > 0 && ! unpriced }">
                <div class="rcv-foot">
                    <span class="ui-hint" :class="{ 'rcv-foot__wait': summary.tone === 'wait' }">{{ summary.text }}</span>
                    <span class="rcv-foot__total">
                        <span class="rcv-foot__label">Total debit to customer</span>
                        <span class="ui-money ui-money--dr rcv-foot__value">{{ money(total) }}</span>
                    </span>
                </div>

                <div class="rcv-actions">
                    <a v-if="cancelUrl" :href="cancelUrl" class="ui-btn">Cancel</a>
                    <button type="submit" class="ui-btn ui-btn--primary">
                        <i class="bi bi-check2-circle"></i> Receive Files
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
.rcv-body {
    display: flex;
    flex-direction: column;
    gap: var(--s-4);
}

/* ---- One file --------------------------------------------------------- */

.rcv-file {
    border: 1px solid var(--n-200);
    border-radius: var(--r-lg);
    overflow: hidden;
}

.rcv-file__head {
    align-items: center;
    background: var(--n-050);
    border-bottom: 1px solid var(--n-200);
    display: flex;
    gap: var(--s-3);
    padding: var(--s-2) var(--s-3);
}

.rcv-file__no {
    background: var(--brand-050);
    border: 1px solid var(--brand-100);
    border-radius: var(--r-pill);
    color: var(--brand-700);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.04em;
    padding: 0.15rem 0.6rem;
    text-transform: uppercase;
}

/* Pushed to the right of the number and left of the remove button, so the
   figure lands in the same place on every card. */
.rcv-file__sum {
    align-items: baseline;
    display: flex;
    gap: var(--s-2);
    margin-left: auto;
}

.rcv-file__sum-label {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

/*
 * Removing a file takes a charge off the batch, so it says what it does rather
 * than being an unlabelled cross the operator has to try.
 */
.rcv-file__remove {
    align-items: center;
    background: none;
    border: 1px solid transparent;
    border-radius: var(--r-sm);
    color: var(--cr-600);
    cursor: pointer;
    display: inline-flex;
    font-size: var(--t-xs);
    font-weight: 600;
    gap: var(--s-1);
    margin-left: auto;
    padding: 0.25rem 0.5rem;
}

/* When there is a figure beside it, that already took the free space. */
.rcv-file__sum + .rcv-file__remove {
    margin-left: 0;
}

.rcv-file__remove:hover:not(:disabled) {
    background: var(--cr-050);
    border-color: var(--cr-600);
}

.rcv-file__remove:disabled {
    color: var(--n-400);
    cursor: not-allowed;
}

.rcv-file__remove:focus-visible {
    outline: 2px solid var(--cr-600);
    outline-offset: 1px;
}

.rcv-file__body {
    display: flex;
    flex-direction: column;
    gap: var(--s-4);
    padding: var(--s-4);
}

/* ---- What the papers are ---------------------------------------------- */

.rcv-about {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: minmax(12rem, 18rem) 1fr;
}

/* A number plate is read as a block of characters, so it is set as one. */
.rcv-reg {
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

/* A tag fixed to an input, sharing its border so the two read as one control. */
.rcv-affix {
    display: flex;
    min-width: 0;
}

.rcv-affix__tag {
    align-items: center;
    background: var(--n-100);
    border: 1px solid var(--n-300);
    border-radius: var(--r-sm) 0 0 var(--r-sm);
    border-right: 0;
    color: var(--n-600);
    display: flex;
    flex: 0 0 auto;
    font-size: var(--t-xs);
    font-weight: 700;
    padding: 0 var(--s-3);
}

.rcv-affix .ui-input,
.rcv-affix .ui-select {
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
    flex: 1 1 auto;
    min-width: 0;
}

.rcv-affix__busy {
    align-items: center;
    display: flex;
    gap: 3px;
    margin-left: calc(var(--s-6) * -1);
    padding-right: var(--s-3);
    pointer-events: none;
}

/* Three dots that rise in sequence while the lookup is in flight. */
.rcv-dot {
    animation: rcv-bounce 1.1s ease-in-out infinite;
    background: var(--brand-500);
    border-radius: 50%;
    display: inline-block;
    height: 5px;
    width: 5px;
}

.rcv-dot:nth-child(2) { animation-delay: 0.15s; }
.rcv-dot:nth-child(3) { animation-delay: 0.3s; }

@keyframes rcv-bounce {
    0%, 80%, 100% { opacity: 0.35; transform: translateY(0); }
    40% { opacity: 1; transform: translateY(-3px); }
}

@media (prefers-reduced-motion: reduce) {
    .rcv-dot {
        animation: none;
        opacity: 0.6;
    }
}

/* ---- The works on it --------------------------------------------------- */

.rcv-works {
    background: var(--n-025);
    border: 1px solid var(--n-200);
    border-radius: var(--r-md);
    display: flex;
    flex-direction: column;
    gap: var(--s-2);
    padding: var(--s-3);
}

.rcv-works__head {
    align-items: baseline;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-1) var(--s-3);
}

.rcv-works__head .ui-label {
    margin: 0;
}

/*
 * A work, its price, and the way to take it off — one line, on a grid rather
 * than a flex row, so the selects and the amounts line up down the card
 * however many there are.
 */
.rcv-work {
    align-items: center;
    display: grid;
    gap: var(--s-2);
    align-items: start;
    grid-template-columns: minmax(0, 1fr) 10.5rem 2.25rem;
}

.rcv-work__remove {
    align-items: center;
    background: none;
    border: 1px solid var(--n-200);
    border-radius: var(--r-sm);
    color: var(--n-500);
    cursor: pointer;
    display: inline-flex;
    height: 2.25rem;
    justify-content: center;
    width: 2.25rem;
}

.rcv-work__remove:hover:not(:disabled) {
    background: var(--cr-050);
    border-color: var(--cr-600);
    color: var(--cr-600);
}

.rcv-work__remove:disabled {
    color: var(--n-300);
    cursor: not-allowed;
}

.rcv-work__remove:focus-visible {
    outline: 2px solid var(--brand-500);
    outline-offset: 1px;
}

/*
 * Both of these are small on a mouse and too small on a thumb. Removing a work
 * or a whole file takes a charge off the batch, so they are the last controls
 * that should be easy to miss and hit by accident in turn.
 */
@media (pointer: coarse) {
    .rcv-work__remove {
        height: var(--tap);
        width: var(--tap);
    }

    .rcv-work {
        grid-template-columns: minmax(0, 1fr) 10.5rem var(--tap);
    }

    .rcv-file__remove,
    .rcv-past__head {
        min-height: var(--tap);
    }
}

.rcv-works__add {
    align-self: flex-start;
}

/* Named for a screen reader, where an icon on its own says nothing. */
.rcv-sr {
    clip: rect(0 0 0 0);
    height: 1px;
    overflow: hidden;
    position: absolute;
    width: 1px;
}

/* What this customer paid for this work before: a quiet line on the work it is
   about, and a panel when it is asked for. */
/* The price and, under it, what this customer paid last time. One cell, so the
   row keeps the three columns it is laid out on. */
.rcv-work__price {
    display: flex;
    flex-direction: column;
    gap: var(--s-1);
    min-width: 0;
}

.rcv-work__price .rcv-affix {
    width: 100%;
}

.rcv-past__open {
    background: none;
    border: 0;
    color: var(--brand-600);
    cursor: pointer;
    font-size: var(--t-xs);
    padding: 0 var(--s-1);
    white-space: nowrap;
}

.rcv-past__open:hover {
    text-decoration: underline;
}

.rcv-past__open:focus-visible {
    border-radius: var(--r-sm);
    outline: 2px solid var(--brand-500);
    outline-offset: 2px;
}

@media (pointer: coarse) {
    .rcv-past__open {
        min-height: var(--tap);
    }
}

/* ---- What this vehicle has been charged before ------------------------- */

.rcv-past {
    background: var(--brand-050);
    border-left: 3px solid var(--brand-500);
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
    font-size: var(--t-sm);
    padding: var(--s-2) var(--s-3);
}

.rcv-past--new {
    background: var(--dr-050);
    border-left-color: var(--dr-600);
    color: var(--dr-700);
}

.rcv-past--warn {
    background: var(--warn-050);
    border-left-color: var(--warn-500);
    color: var(--warn-600);
}

.rcv-past__head {
    background: none;
    border: 0;
    color: var(--ink-800);
    cursor: pointer;
    font: inherit;
    padding: 0;
    text-align: left;
    width: 100%;
}

.rcv-past__head:focus-visible {
    outline: 2px solid var(--brand-500);
    outline-offset: 2px;
}

.rcv-past__hint {
    color: var(--n-500);
    font-weight: 400;
}

.rcv-past__table {
    font-size: var(--t-xs);
    margin-top: var(--s-2);
}

/* ---- Adding, and the footer -------------------------------------------- */

/*
 * A dashed outline rather than a solid button: this adds an empty card to be
 * filled in, and should not compete with the one that saves the batch.
 */
.rcv-add {
    background: none;
    border: 1px dashed var(--n-300);
    border-radius: var(--r-md);
    color: var(--brand-600);
    cursor: pointer;
    font-weight: 600;
    min-height: var(--tap);
    padding: var(--s-3);
    width: 100%;
}

.rcv-add:hover {
    background: var(--brand-050);
    border-color: var(--brand-400);
}

.rcv-add:focus-visible {
    outline: 2px solid var(--brand-500);
    outline-offset: 2px;
}

.rcv-foot {
    align-items: baseline;
    display: flex;
    flex: 1 1 auto;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-5);
    justify-content: space-between;
}

.rcv-foot__wait {
    color: var(--warn-600);
}

.rcv-foot__total {
    align-items: baseline;
    display: flex;
    gap: var(--s-3);
}

.rcv-foot__label {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.rcv-foot__value {
    font-size: var(--t-xl);
    font-weight: 700;
}

.rcv-actions {
    display: flex;
    gap: var(--s-2);
}

@media (max-width: 767.98px) {
    .rcv-about {
        grid-template-columns: 1fr;
    }

    /* The amount drops under the work rather than being squeezed beside it,
       and the remove button keeps its own corner. */
    .rcv-work {
        grid-template-columns: minmax(0, 1fr) minmax(2.25rem, auto);
    }

    .rcv-work__amount {
        grid-column: 1;
    }

    .rcv-work__remove {
        grid-column: 2;
        grid-row: 1 / span 2;
        height: 100%;
    }

    .rcv-actions {
        flex: 1 1 auto;
    }

    .rcv-actions .ui-btn {
        flex: 1 1 auto;
    }

    /* This table exists so someone can check what was charged for this vehicle
       last time before pricing it again. Scrolling it sideways puts Amount —
       the one figure it is here for — off the edge of the screen, so on a phone
       each earlier file becomes a card instead. */
    .rcv-past__table,
    .rcv-past__table tbody,
    .rcv-past__table tr,
    .rcv-past__table td {
        display: block;
        width: 100%;
    }

    .rcv-past__table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
    }

    .rcv-past__table tbody tr {
        border: 1px solid var(--n-200);
        border-radius: var(--r-md);
        margin-bottom: var(--s-2);
        padding: var(--s-2) var(--s-3);
    }

    .rcv-past__table tbody td {
        border: 0;
        display: flex;
        gap: var(--s-3);
        justify-content: space-between;
        padding: 2px 0;
        text-align: right;
    }

    .rcv-past__table tbody td::before {
        color: var(--n-500);
        content: attr(data-label);
        font-size: var(--t-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        text-align: left;
        text-transform: uppercase;
    }
}
</style>
