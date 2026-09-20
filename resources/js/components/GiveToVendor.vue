<script setup>
/*
 * Handing a batch of work to one vendor.
 *
 * Field names are the ones WorkFileController::assign() already validates —
 * vendor_id, vendor_date, files[], jobs[], amounts[<job id>] and remark — so the
 * form still posts normally and the server still checks every value. That is
 * what makes it safe to convert a screen on a live ledger: only the rendering
 * moves, the money logic does not.
 *
 * The screen's job is one handover at a counter: tick the work going out, agree
 * a price on each, and see before saving what the vendor is being credited in
 * total and where that leaves their balance.
 *
 * The tick is per job, not per folder. Papers for a transfer and a hypothecation
 * addition are one file, two works and two costs, and they need not go to the
 * same person — one agent is quick with transfers, another has the bank
 * contact. The folder's own box above them is a shorthand for all of their
 * ticks at once, and a folder half of which has already gone comes back to this
 * screen for the other half.
 *
 * jobs[] is all or nothing on the server: posted at all, it is read as the whole
 * list of work leaving, for every folder in the batch. So every ticked job posts
 * its own id — never some folders by job and others whole.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { balance, money, side } from '../money';

const props = defineProps({
    files: { type: Array, default: () => [] },
    vendors: { type: Array, default: () => [] },
    action: { type: String, required: true },
    // Where the same ticked work goes when it is staying here instead.
    keepUrl: { type: String, default: '' },
    csrf: { type: String, required: true },
    cancelUrl: { type: String, required: true },
    // Repopulation after a failed validation, so nothing typed is lost.
    vendorId: { type: [Number, String], default: '' },
    vendorDate: { type: String, default: '' },
    vendorDateDisplay: { type: String, default: '' },
    remark: { type: String, default: '' },
    pickedFiles: { type: Array, default: () => [] },
    // And the work ticked inside them, so a bounced batch does not come back
    // having quietly widened a handover the operator had narrowed.
    pickedJobs: { type: Array, default: () => [] },
    // The last few rates agreed for each work, keyed by work type.
    rateHistory: { type: Array, default: () => [] },
    oldAmounts: { type: Object, default: () => ({}) },
    // Reasons given for sending a file out before its papers are complete.
    oldOverrides: { type: Object, default: () => ({}) },
});

/*
 * Step 3 follows step 2: work goes out once its papers are checked and
 * complete. Work whose papers are not can still be ticked — the RTO sometimes
 * takes a paper later — but then the folder needs a reason, which the office
 * keeps.
 *
 * Asked of the work, not of the folder, because the server asks it that way
 * too. A folder whose transfer is ready and whose hypothecation addition is
 * still waiting on a form lets the transfer go without a word.
 */
const jobReady = (item) => ! item.papers || item.papers === 'ready';

// A folder with no work lines of its own carries the answer itself.
const ready = (file) => ! file.papers || file.papers === 'ready';

const reasons = reactive(
    Object.fromEntries(props.files.map((file) => [file.id, props.oldOverrides[file.id] ?? '']))
);

const chosen = ref(props.vendorId);
const remarkText = ref(props.remark);

/*
 * The ticked folders, bound straight to the checkboxes. An unticked box posts
 * nothing of its own, and its amounts are disabled below, so a rate typed
 * against work that is not going out cannot reach the vendor's ledger.
 */
const picked = ref(props.pickedFiles.map(Number));

/*
 * The ticked work, which is what actually goes out.
 *
 * Kept beside the folder ticks rather than derived from them: the server reads
 * jobs[] as the entire list of work leaving, across every folder in the batch,
 * so a folder ticked with nothing under it would hand over nothing at all.
 * onFileToggle and onJobToggle are what hold the two in step.
 */
const pickedWork = ref(props.pickedJobs.map(Number));

// Keyed on the job, because that is what carries a rate.
const amounts = reactive(
    Object.fromEntries(
        props.files.flatMap((file) =>
            file.items.map((item) => [item.id, props.oldAmounts[item.id] ?? ''])
        )
    )
);

const jobs = (file) => file.items ?? [];

/*
 * The work on this folder that is still here to be given away. Work already
 * with a vendor, and work that is finished, is shown but cannot be ticked:
 * hiding it would make a half-empty folder look like a whole one.
 */
const hereJobs = (file) => jobs(file).filter((item) => item.state === 'here');

const isPicked = (file) => picked.value.includes(file.id);

const isJobPicked = (item) => pickedWork.value.includes(item.id);

/*
 * Some of a folder's work going and some staying. Drawn on the folder's own box
 * as the half-ticked state, so a part handover is visible from the row without
 * reading down the works.
 */
const partlyPicked = (file) => {
    const here = hereJobs(file);

    return here.length > 0 && here.some(isJobPicked) && ! here.every(isJobPicked);
};

/*
 * Ticking work fills in what that kind of work usually costs.
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
function fillRate(item) {
    if (item.vendor_rate && String(amounts[item.id]).trim() === '') {
        amounts[item.id] = Number(item.vendor_rate).toFixed(2);
    }
}

function takeJob(item) {
    if (! isJobPicked(item)) {
        pickedWork.value.push(item.id);
    }

    fillRate(item);
}

function dropJobs(file) {
    const ids = jobs(file).map((item) => item.id);

    pickedWork.value = pickedWork.value.filter((id) => ! ids.includes(id));
}

/* The folder's box is a shorthand for every tick under it. */
function onFileToggle(file) {
    if (isPicked(file)) {
        hereJobs(file).forEach(takeJob);
    } else {
        dropJobs(file);
    }
}

/*
 * And the folder follows its work. Ticking one work on an untouched folder
 * brings the folder with it — files[] has to name the folder or the server
 * never looks inside it — and unticking the last one lets the folder go, so a
 * folder with nothing under it is never posted as a handover of nothing.
 */
function onJobToggle(file, item) {
    if (isJobPicked(item)) {
        fillRate(item);

        if (! isPicked(file)) {
            picked.value.push(file.id);
        }

        return;
    }

    if (! hereJobs(file).some(isJobPicked)) {
        picked.value = picked.value.filter((id) => id !== file.id);
    }
}

/*
 * What this work has been paid before.
 *
 * The question at the counter is always the same — what did we pay for a
 * transfer last time, and to whom — and the answer used to be on another
 * screen behind a filter. Every vendor's is here, not only the one being
 * chosen: comparing across them is half the reason to ask.
 */
/*
 * Built once from the list the screen was sent, keyed by the work and the
 * office together — a map keyed on ids alone would be a prop whose very
 * shape changed with the data.
 */
const ratesFor = computed(() =>
    Object.fromEntries(props.rateHistory.map((one) => [`${one.work_type_id}|${one.rto}`, one.rates]))
);

/*
 * The first four characters of a registration number are the office the
 * papers go through — BR06, BR01 — and what a transfer costs at one is not
 * what it costs at another. A rate from the wrong office is worse than no
 * rate: it reads as an answer.
 */
const rtoOf = (file) => (file.registration_no || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 4);

const pastRates = (file, work) => ratesFor.value[`${work.work_type_id}|${rtoOf(file)}`] ?? [];

// One open at a time, keyed by the work line it belongs to.
const showingRates = ref(null);

function toggleRates(work) {
    showingRates.value = showingRates.value === work.id ? null : work.id;
}

/*
 * Narrowing the list.
 *
 * The list is everything waiting to go out, which on a busy week is longer than
 * the screen, and the handover at the counter is for one vehicle or one
 * customer at a time.
 *
 * Rows are hidden with v-show and never v-if. These rows carry the ticks and
 * the agreed rates: removed from the DOM they would take their inputs with
 * them, and a file ticked before the search was typed would silently stop being
 * handed over. So they stay on the form, and the footer says how many of them
 * the search is covering up.
 */
const search = ref('');

// Whoever already holds half of a folder is worth searching on: the other half
// is usually being sent after it.
const haystack = (file) => [
    file.file_no,
    file.registration_no,
    file.description,
    file.customer,
    file.received_date,
    ...jobs(file).map((item) => item.work_type),
    ...jobs(file).map((item) => item.vendor),
].filter(Boolean).join(' ').toLowerCase();

const terms = computed(() =>
    search.value.trim().toLowerCase().split(/\s+/).filter(Boolean)
);

// Every term has to appear somewhere on the row, so a second word narrows
// rather than widens — "car4sales hpa" is that customer's hypothecation work.
const matches = (file) => {
    if (! terms.value.length) {
        return true;
    }

    const hay = haystack(file);

    return terms.value.every((term) => hay.includes(term));
};

const shown = computed(() => props.files.filter(matches));

/*
 * Ticked, and hidden by the search. Still on the form and still going out,
 * which is the right behaviour and the surprising one — so it is said here
 * rather than discovered in the success message afterwards.
 */
const hiddenPicked = computed(() =>
    props.files.filter((file) => isPicked(file) && ! matches(file)).length
);

/*
 * Select all means all of what is on screen.
 *
 * With a search typed, ticking every file in the list would hand over ones the
 * operator cannot see and did not mean — and this is the screen that credits a
 * vendor. Unticking leaves the hidden ones alone for the mirror of that reason:
 * quietly dropping a file somebody ticked earlier is as wrong as quietly adding
 * one. The footer says how many are ticked but out of sight.
 */
// Select all takes the work that is ready. Work whose papers are not is ticked
// on purpose, one at a time, with a reason — never swept in by a header box.
const sweepJobs = (file) => hereJobs(file).filter(jobReady);

const sweepFiles = computed(() =>
    shown.value.filter((file) => (jobs(file).length ? sweepJobs(file).length > 0 : ready(file)))
);

const allPicked = computed({
    get: () => sweepFiles.value.length > 0 && sweepFiles.value.every(
        (file) => isPicked(file) && sweepJobs(file).every(isJobPicked)
    ),
    set: (on) => {
        if (on) {
            sweepFiles.value.forEach((file) => {
                sweepJobs(file).forEach(takeJob);

                if (! isPicked(file)) {
                    picked.value.push(file.id);
                }
            });

            return;
        }

        const ids = shown.value.map((file) => file.id);

        picked.value = picked.value.filter((id) => ! ids.includes(id));
        shown.value.forEach(dropJobs);
    },
});

/*
 * The work actually leaving, which is what everything below counts.
 *
 * Read off the ticked folders as well as the ticked work, so a stray job id
 * left behind by a folder being unticked can never reach a total.
 */
const goingJobs = computed(() =>
    props.files.filter(isPicked).flatMap((file) => hereJobs(file).filter(isJobPicked))
);

/*
 * A reason is owed for the work that is going, not for everything in the
 * folder. The server narrows the same question to the same work, and the two
 * have to agree — asked of the folder, this box would demand an explanation
 * the server never asked for, which is the kind of refusal people learn to type
 * anything into.
 */
const notReadyGoing = (file) => (jobs(file).length
    ? hereJobs(file).filter(isJobPicked).some((item) => ! jobReady(item))
    : ! ready(file));

const needsReason = (file) =>
    isPicked(file) && notReadyGoing(file) && String(reasons[file.id] ?? '').trim() === '';

const unexplained = computed(() => props.files.filter(needsReason).length);

const total = computed(() =>
    goingJobs.value.reduce((sum, item) => sum + (Number(amounts[item.id]) || 0), 0)
);

const blanks = computed(
    () => goingJobs.value.filter((item) => String(amounts[item.id]).trim() === '').length
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

/*
 * How many folders have some of their work going but not all of it. Said out
 * loud because it is the one thing about this screen somebody can get wrong
 * without noticing: the folder is ticked, the row looks handed over, and a work
 * inside it is staying on the desk.
 */
const partFolders = computed(() =>
    props.files.filter((file) => isPicked(file) && partlyPicked(file)).length
);

const summary = computed(() => {
    if (! picked.value.length) {
        return terms.value.length && ! shown.value.length
            ? 'No files match that search.'
            : 'Nothing ticked yet.';
    }

    const count = picked.value.length;
    const parts = [`${count} ${count === 1 ? 'file' : 'files'} going out`];

    const works = goingJobs.value.length;

    if (works !== count) {
        parts.push(`${works} ${works === 1 ? 'work' : 'works'}`);
    }

    if (partFolders.value) {
        const many = partFolders.value === 1 ? 'file is' : 'files are';

        parts.push(`${partFolders.value} ${many} going in part — the rest of it stays here`);
    }

    if (blanks.value) {
        parts.push(`${blanks.value} without a price yet`);
    }

    if (unexplained.value) {
        parts.push(`${unexplained.value} ${unexplained.value === 1 ? 'needs' : 'need'} a reason to go without complete papers`);
    }

    if (hiddenPicked.value) {
        const many = hiddenPicked.value === 1 ? 'it is' : 'they are';

        parts.push(`${hiddenPicked.value} not shown by the search — ${many} still going out`);
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
                        Tick the work you are handing over &mdash; a whole folder from the box on its
                        left, or one work at a time. Leave an amount blank if the rate is not agreed
                        yet &mdash; nothing is posted to the vendor until it is filled in.
                    </div>
                </div>

                <div class="give-tools">
                    <div class="give-search">
                        <i class="bi bi-search"></i>
                        <!-- No name, so it posts nothing. It narrows what is on
                             screen and never what is sent. -->
                        <input
                            type="search"
                            class="ui-input"
                            v-model="search"
                            placeholder="Search file, vehicle, customer or work"
                            aria-label="Search files waiting to go out">
                    </div>

                    <label class="give-all">
                        <input type="checkbox" class="give-check" v-model="allPicked">
                        Select all
                    </label>
                </div>
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
                            <template v-for="file in files" :key="file.id">
                            <tr v-show="matches(file)" :class="{ 'is-picked': isPicked(file) }">
                                <td data-label="Give out" class="give-pick">
                                    <!-- Half-ticked while some of the folder's work is going and
                                         some is staying, so a part handover is legible from the
                                         row without reading down the works. -->
                                    <input
                                        type="checkbox"
                                        class="give-check"
                                        name="files[]"
                                        :value="file.id"
                                        v-model="picked"
                                        :indeterminate="partlyPicked(file)"
                                        :aria-label="`Give out everything still here on ${file.file_no}`"
                                        @change="onFileToggle(file)">
                                </td>

                                <td data-label="File No.">
                                    <span class="ui-lead">{{ file.file_no }}</span>
                                    <!-- Why this one is not ready, and where to fix it. -->
                                    <div v-if="! ready(file)" class="give-papers" :class="`give-papers--${file.papers}`">
                                        <a :href="file.papers_url" class="ui-link">{{ file.papers_note }}</a>
                                    </div>
                                    <!-- Asked only once work that is not ready is actually
                                         going out, and posted only then — the server asks for a
                                         reason about the work leaving, not about the folder. -->
                                    <input
                                        v-if="isPicked(file) && notReadyGoing(file)"
                                        type="text"
                                        class="ui-input give-reason"
                                        :class="{ 'ui-input--invalid': needsReason(file) }"
                                        :name="`overrides[${file.id}]`"
                                        v-model="reasons[file.id]"
                                        maxlength="200"
                                        placeholder="Reason to send anyway"
                                        :aria-label="`Reason to send ${file.file_no} before its papers are complete`">
                                </td>

                                <td data-label="Vehicle">
                                    <span v-if="file.registration_no" class="file-plate">{{ file.registration_no }}</span>
                                    <span v-else class="ui-money--nil">&mdash;</span>
                                </td>

                                <td data-label="Received">{{ file.received_date }}</td>

                                <!-- The three columns below stack one line per job
                                     and stay in step, so a folder with two works reads
                                     across: this work, charged this, costing that. -->
                                <!-- One line per work, and the line is where its tick lives: the
                                     three columns below stack in step, so a folder with two works
                                     reads across — this work, charged this, costing that. -->
                                <td data-label="Work Type">
                                    <div v-for="item in jobs(file)" :key="item.id" class="give-job">
                                        <label v-if="item.state === 'here'" class="give-work">
                                            <input
                                                type="checkbox"
                                                class="give-check give-check--work"
                                                name="jobs[]"
                                                :value="item.id"
                                                v-model="pickedWork"
                                                :aria-label="`Give out ${item.work_type || 'this work'} on ${file.file_no}`"
                                                @change="onJobToggle(file, item)">
                                            <span class="give-work__name">{{ item.work_type || '&mdash;' }}</span>

                                            <!-- Its own papers, beside its own tick, where a
                                                 folder holds more than one work and the line under
                                                 its number cannot say which of them is held up.
                                                 The full sentence is in the tooltip; the badge has
                                                 to fit on the line or the columns stop lining up. -->
                                            <span
                                                v-if="! jobReady(item) && jobs(file).length > 1"
                                                class="give-work__papers"
                                                :class="`give-papers--${item.papers}`"
                                                :title="item.papers === 'to_check'
                                                    ? 'Papers not checked yet'
                                                    : `Papers pending: ${item.papers_pending}`">
                                                <i class="bi bi-exclamation-triangle-fill"></i>
                                                {{ item.papers === 'to_check' ? 'not checked' : item.papers_pending }}
                                            </span>
                                        </label>

                                        <!-- Work that is not going anywhere from here: already
                                             with somebody, or finished with. Shown rather than
                                             left out, or a half-empty folder would read as a
                                             whole one and nobody could see who has the rest. -->
                                        <span v-else class="give-work give-work--fixed">
                                            <span class="give-work__name">{{ item.work_type || '&mdash;' }}</span>
                                            <span v-if="item.state === 'out'" class="give-work__with">
                                                with {{ item.vendor || 'a vendor' }}<template v-if="item.vendor_date"> since {{ item.vendor_date }}</template>
                                            </span>
                                            <!-- Ours. Shown rather than dropped, so the row says why
                                                 this work has no tick instead of simply lacking one;
                                                 letting go of it again is done on the edit screen. -->
                                            <span v-else-if="item.state === 'kept'" class="give-work__with give-work__with--kept">
                                                <i class="bi bi-house-check"></i>
                                                kept in-house<template v-if="item.kept_on"> since {{ item.kept_on }}</template>
                                            </span>
                                            <span v-else class="give-work__with">{{ item.status_label }}</span>
                                        </span>
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
                                        <template v-if="item.state === 'here'">
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                class="ui-input ui-input--amount"
                                                :name="`amounts[${item.id}]`"
                                                v-model="amounts[item.id]"
                                                :disabled="! isJobPicked(item)"
                                                :aria-label="`Vendor amount for ${item.work_type || 'this work'} on ${file.file_no}`"
                                                placeholder="0.00">

                                            <!-- What this work was paid before, at the
                                                 moment its rate is being agreed. -->
                                            <button
                                                v-if="pastRates(file, item).length"
                                                type="button"
                                                class="give-past__open"
                                                title="What this work has been paid before"
                                                @click="toggleRates(item)">
                                                <i class="bi bi-clock-history"></i>
                                                last {{ money(pastRates(file, item)[0].amount) }} at {{ rtoOf(file) }}
                                            </button>
                                            <span v-else class="give-past__none">
                                                no earlier rate<template v-if="rtoOf(file)"> at {{ rtoOf(file) }}</template>
                                            </span>
                                        </template>

                                        <!-- Already agreed with whoever has it. Shown, not
                                             editable: changing what another vendor is owed is a
                                             correction, and it belongs on the edit screen where
                                             the effect on their balance is visible. -->
                                        <span v-else-if="item.state === 'out'" class="ui-money ui-money--cr">
                                            {{ item.vendor_amount === null ? '&mdash;' : money(item.vendor_amount) }}
                                        </span>
                                        <span v-else class="ui-money--nil">&mdash;</span>
                                    </div>
                                </td>
                            </tr>

                            <!-- The five most recent, on a row of their own: a
                                 table of eight columns cannot hold them. -->
                            <tr v-for="item in jobs(file)" :key="`past-${item.id}`" v-show="showingRates === item.id && matches(file)">
                                <td :colspan="9" class="give-past">
                                    <div class="give-past__head">
                                        <strong>{{ item.work_type }}</strong> at
                                        <strong>{{ rtoOf(file) }}</strong> &mdash; the last
                                        {{ pastRates(file, item).length }}
                                        {{ pastRates(file, item).length === 1 ? 'rate' : 'rates' }} agreed
                                    </div>

                                    <table class="give-past__table">
                                        <thead>
                                            <tr>
                                                <th>Given On</th>
                                                <th>File No.</th>
                                                <th>Vehicle</th>
                                                <th>Vendor</th>
                                                <th class="num">Charged</th>
                                                <th class="num">Paid</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(past, i) in pastRates(file, item)" :key="i">
                                                <td data-label="Given On">{{ past.vendor_date || '—' }}</td>
                                                <td data-label="File No.">{{ past.file_no }}</td>
                                                <td data-label="Vehicle">{{ past.registration_no || '—' }}</td>
                                                <td data-label="Vendor">{{ past.vendor }}</td>
                                                <td data-label="Charged" class="num">{{ money(past.charged) }}</td>
                                                <td data-label="Paid" class="num">
                                                    <span class="ui-money ui-money--cr">{{ money(past.amount) }}</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            </template>
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

                    <!--
                        The same ticks, posted somewhere else.

                        formaction sends this button's submit to keepInHouse()
                        instead, and formnovalidate is what lets it go without a
                        vendor: the select above is required because giving work
                        away needs somebody to give it to, and keeping it does
                        not. Nothing about the money moves either way.
                    -->
                    <button
                        v-if="keepUrl && goingJobs.length"
                        type="submit"
                        class="ui-btn"
                        :formaction="keepUrl"
                        formnovalidate
                        title="We are doing this work here, so stop offering it to vendors">
                        <i class="bi bi-house-check"></i>
                        Keep {{ goingJobs.length === 1 ? 'it' : 'them' }} in-house
                    </button>

                    <button type="submit" class="ui-btn ui-btn--primary" :disabled="unexplained > 0">
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

/* The search and Select all share the right of the heading, and wrap under it
   on a phone rather than squeezing the box to nothing. */
.give-tools {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-3);
    justify-content: flex-end;
}

/* A file whose papers are not ready: said under its number, in the colour the
   rest of the application uses for Paper Pendency. */
.give-papers {
    font-size: var(--t-xs);
    font-weight: 600;
    margin-top: 0.15rem;
}

.give-papers--pending .ui-link {
    color: var(--warn-600);
}

.give-papers--to_check .ui-link {
    color: var(--n-600);
}

.give-reason {
    font-size: var(--t-sm);
    margin-top: var(--s-1);
    min-width: 11rem;
}

.give-search {
    align-items: center;
    display: flex;
    flex: 1 1 18rem;
    gap: var(--s-2);
    max-width: 26rem;
}

.give-search i {
    color: var(--n-400);
}

.give-search .ui-input {
    flex: 1 1 auto;
    min-width: 0;
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

/* What this work was paid before: a quiet line under the box where its rate is
   being typed, and a panel when it is asked for. */
.give-past__open {
    background: none;
    border: 0;
    color: var(--brand-600);
    cursor: pointer;
    display: block;
    font-size: var(--t-xs);
    margin-top: var(--s-1);
    padding: 0;
    text-align: right;
    white-space: nowrap;
    width: 100%;
}

.give-past__open:hover {
    text-decoration: underline;
}

.give-past__open:focus-visible {
    border-radius: var(--r-sm);
    outline: 2px solid var(--brand-500);
    outline-offset: 2px;
}

.give-past__none {
    color: var(--n-400);
    display: block;
    font-size: var(--t-xs);
    margin-top: var(--s-1);
    text-align: right;
}

.give-past {
    background: var(--n-050);
    border-left: 3px solid var(--brand-500);
}

.give-past__head {
    color: var(--n-600);
    font-size: var(--t-sm);
    margin-bottom: var(--s-2);
}

.give-past__table {
    font-size: var(--t-xs);
    width: 100%;
}

.give-past__table th {
    color: var(--n-500);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.04em;
    padding: var(--s-1) var(--s-2);
    text-align: left;
    text-transform: uppercase;
}

.give-past__table td {
    border-top: 1px solid var(--n-200);
    padding: var(--s-1) var(--s-2);
}

.give-past__table .num {
    text-align: right;
}

@media (pointer: coarse) {
    .give-past__open {
        min-height: var(--tap);
    }
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

/* A work and its tick, on one line.
   One line and not two: the Work Type, Charged and Vendor Amount columns stack
   a block per work and stay in step by having the same number of them at the
   same height. A note wrapped onto a second line here would push this column's
   works out of step with their own charges. */
.give-work {
    align-items: center;
    display: flex;
    gap: var(--s-2);
    min-width: 0;
}

label.give-work {
    cursor: pointer;
}

.give-work__name {
    white-space: nowrap;
}

.give-check--work {
    flex: none;
    height: 0.95rem;
    width: 0.95rem;
}

/* Work that is not going anywhere from this screen: who has it, or what became
   of it. Quiet, because it is context and not an instruction. */
.give-work--fixed .give-work__name {
    color: var(--n-500);
}

.give-work__with {
    color: var(--n-500);
    font-size: var(--t-xs);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* What this work is still waiting for, said where its rate is being agreed.
   Truncated rather than wrapped, with the whole of it in the tooltip. */
/* Ours, in the colour the rest of the application uses for something settled
   rather than something pending. */
.give-work__with--kept {
    color: var(--brand-600);
    font-weight: 600;
}

.give-work__papers {
    align-items: center;
    display: inline-flex;
    font-size: var(--t-xs);
    font-weight: 600;
    gap: 0.2rem;
    max-width: 10rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.give-work__papers.give-papers--pending {
    color: var(--warn-600);
}

.give-work__papers.give-papers--to_check {
    color: var(--n-600);
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
