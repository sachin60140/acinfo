<script setup>
/*
 * Work types: the add/edit form beside the list it feeds.
 *
 * The form posts name, default_rate and is_active — the field names
 * WorkTypeController::index() already validates — so the submission and its
 * checks are untouched. Only the rendering moved, which is what makes it safe
 * to convert a screen on a live ledger.
 *
 * The list was a DataTable, which pulled jQuery onto the page for two things:
 * sorting and search. Both are a few lines each below.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { money } from '../money';

const props = defineProps({
    types: { type: Array, default: () => [] },
    initial: { type: Object, default: () => ({}) },
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    cancelUrl: { type: String, required: true },
    filesUrl: { type: String, required: true },
    editingId: { type: Number, default: null },
});

const editing = computed(() => props.editingId !== null);

/*
 * The form starts from what the server sent, which is the stored row normally
 * and what was typed after a failed submission — a bounced form must not
 * quietly revert a change the user made.
 */
const form = reactive({
    name: props.initial.name ?? '',
    default_rate: props.initial.default_rate ?? '',
    default_vendor_rate: props.initial.default_vendor_rate ?? '',
    is_active: Boolean(props.initial.is_active),
});

/*
 * The name is unique in the database, so a clash comes back as an error on a
 * form that has to be filled in again. Saying so while it is typed is cheaper.
 * Matched case-insensitively, as the column's collation does.
 */
const clash = computed(() => {
    const typed = form.name.trim().toLowerCase();

    if (typed === '') {
        return null;
    }

    return props.types.find(
        (type) => type.id !== props.editingId && type.name.toLowerCase() === typed
    ) ?? null;
});

const nameInput = ref(null);

onMounted(() => {
    // The old markup carried autofocus. That attribute only fires while the
    // page is parsed, and this field is rendered after that.
    nameInput.value?.focus();
});

/* ---- The list ---------------------------------------------------------- */

const PER_PAGE = 25;

const columns = [
    { key: 'id', label: '#' },
    { key: 'name', label: 'Work Type' },
    { key: 'default_rate', label: 'Charge', num: true },
    { key: 'default_vendor_rate', label: 'Vendor Cost', num: true },
    // Works, not files: a folder of several works counts once against each
    // of them, which is what the number is for.
    { key: 'file_count', label: 'Works', num: true },
    { key: 'billed_total', label: 'Billed', num: true },
];

const query = ref('');
const sort = reactive({ key: 'name', dir: 'asc' });
const page = ref(1);

/*
 * Search runs over what the row reads as on screen — the Inactive badge and the
 * grouped figures included — because that is what someone typing into the box
 * is looking at. The raw figures go in too, so 100000 finds 1,00,000.00.
 */
const searchable = computed(() =>
    props.types.map((type) => ({
        type,
        haystack: [
            type.id,
            type.name,
            type.is_active ? '' : 'inactive',
            type.default_rate === null ? '' : `${type.default_rate} ${money(type.default_rate)}`,
            type.file_count,
            `${type.billed_total} ${money(type.billed_total)}`,
        ].join(' ').toLowerCase(),
    }))
);

const matched = computed(() => {
    const terms = query.value.trim().toLowerCase().split(/\s+/).filter(Boolean);

    if (!terms.length) {
        return props.types;
    }

    // Every word has to appear somewhere in the row, in any order.
    return searchable.value
        .filter((row) => terms.every((term) => row.haystack.includes(term)))
        .map((row) => row.type);
});

/**
 * A type with no rate has no figure to compare, so those rows group at one end
 * instead of scattering through the numbers.
 */
function compare(a, b) {
    if (typeof a === 'string' || typeof b === 'string') {
        return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
    }

    const left = a === null ? -Infinity : Number(a);
    const right = b === null ? -Infinity : Number(b);

    if (left === right) {
        return 0;
    }

    return left < right ? -1 : 1;
}

const sorted = computed(() => {
    const factor = sort.dir === 'asc' ? 1 : -1;

    // The sort is stable, so ties keep the order the server sent, which is by name.
    return [...matched.value].sort((a, b) => factor * compare(a[sort.key], b[sort.key]));
});

const pages = computed(() => Math.max(1, Math.ceil(sorted.value.length / PER_PAGE)));

// Clamped rather than corrected: a search that narrows the list past the page
// being read would otherwise leave an empty table behind.
const current = computed(() => Math.min(page.value, pages.value));

const rows = computed(() =>
    sorted.value.slice((current.value - 1) * PER_PAGE, current.value * PER_PAGE)
);

watch([query, () => sort.key, () => sort.dir], () => {
    page.value = 1;
});

const info = computed(() => {
    const total = props.types.length;
    const found = sorted.value.length;
    const noun = found === 1 ? 'work type' : 'work types';

    if (found > PER_PAGE) {
        const from = (current.value - 1) * PER_PAGE + 1;
        const to = Math.min(from + PER_PAGE - 1, found);

        return `${from}–${to} of ${found} ${noun}` + (found < total ? `, ${total} in all` : '');
    }

    return found < total ? `${found} of ${total} ${noun}` : `${total} ${noun}`;
});

function sortBy(key) {
    if (sort.key === key) {
        sort.dir = sort.dir === 'asc' ? 'desc' : 'asc';

        return;
    }

    // A column always opens ascending, as the table has always done.
    sort.key = key;
    sort.dir = 'asc';
}

const ariaSort = (key) => {
    if (sort.key !== key) {
        return 'none';
    }

    return sort.dir === 'asc' ? 'ascending' : 'descending';
};

const sortIcon = (key) => {
    if (sort.key !== key) {
        return 'bi-arrow-down-up';
    }

    return sort.dir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill';
};
</script>

<template>
    <div class="ui wt">
        <div class="wt-grid">
            <section class="ui-card">
                <div class="ui-card__head">
                    <h2 class="ui-card__title">{{ editing ? 'Edit' : 'Add' }} Work Type</h2>
                </div>

                <form class="ui-card__body wt-form" :action="action" method="POST">
                    <!-- Rendered here rather than passed as a slot: the component
                         mounts onto a bare element, so there is no server markup
                         to slot in. -->
                    <input type="hidden" name="_token" :value="csrf">

                    <div class="ui-field">
                        <label class="ui-label" for="wt-name">
                            Work Type <span class="ui-label__req">*</span>
                        </label>
                        <div class="wt-group">
                            <span class="wt-group__addon"><i class="bi bi-briefcase"></i></span>
                            <input
                                id="wt-name"
                                ref="nameInput"
                                type="text"
                                class="ui-input"
                                :class="{ 'ui-input--invalid': clash }"
                                name="name"
                                v-model="form.name"
                                placeholder="e.g. RC Transfer"
                                required>
                        </div>
                        <div v-if="clash" class="ui-hint ui-hint--error">
                            &ldquo;{{ clash.name }}&rdquo; already exists &mdash;
                            <a :href="clash.edit_url" class="ui-link">edit that one</a> instead.
                        </div>
                    </div>

                    <div class="ui-field">
                        <label class="ui-label" for="wt-rate">Default Charge</label>
                        <div class="wt-group">
                            <span class="wt-group__addon">INR</span>
                            <input
                                id="wt-rate"
                                type="number"
                                min="0"
                                step="0.01"
                                class="ui-input ui-input--amount"
                                name="default_rate"
                                v-model="form.default_rate"
                                placeholder="0.00">
                        </div>
                        <div class="ui-hint">
                            Pre-fills what the customer is charged. Leave blank for work quoted per job.
                        </div>
                    </div>

                    <div class="ui-field">
                        <label class="ui-label" for="wt-vendor-rate">Default Vendor Cost</label>
                        <div class="wt-group">
                            <span class="wt-group__addon">INR</span>
                            <input
                                id="wt-vendor-rate"
                                type="number"
                                min="0"
                                step="0.01"
                                class="ui-input ui-input--amount"
                                name="default_vendor_rate"
                                v-model="form.default_vendor_rate"
                                placeholder="0.00">
                        </div>
                        <div class="ui-hint">
                            Pre-fills what the vendor is paid, when work of this type
                            goes out. Leave blank if the rate is agreed each time.
                        </div>
                    </div>

                    <!-- Only on an existing type: a new one is always active, and
                         the controller does not read the field when adding. -->
                    <div v-if="editing" class="ui-field">
                        <label class="wt-switch">
                            <input type="checkbox" name="is_active" value="1" v-model="form.is_active">
                            <span class="wt-switch__track"></span>
                            <span class="wt-switch__text">Active</span>
                        </label>
                        <div class="ui-hint">
                            Inactive types stay on existing files but cannot be chosen for new ones.
                        </div>
                    </div>

                    <div class="wt-form__actions">
                        <a v-if="editing" :href="cancelUrl" class="ui-btn">Cancel</a>
                        <button type="submit" class="ui-btn ui-btn--primary">
                            <i class="bi bi-check2-circle"></i> {{ editing ? 'Update' : 'Add' }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="ui-card">
                <div class="ui-card__head">
                    <h2 class="ui-card__title">All Work Types</h2>

                    <div class="wt-tools">
                        <input
                            type="search"
                            class="ui-input wt-search"
                            v-model="query"
                            placeholder="Search"
                            aria-label="Search work types">
                        <a :href="filesUrl" class="ui-btn ui-btn--sm">
                            <i class="bi bi-folder2-open"></i> Work Files
                        </a>
                    </div>
                </div>

                <div v-if="!types.length" class="ui-empty">
                    <div class="ui-empty__icon"><i class="bi bi-briefcase"></i></div>
                    <div class="ui-empty__title">No work types yet</div>
                    <div>Add the first one on the left.</div>
                </div>

                <div v-else-if="!sorted.length" class="ui-empty">
                    <div class="ui-empty__icon"><i class="bi bi-search"></i></div>
                    <div class="ui-empty__title">Nothing matches &ldquo;{{ query.trim() }}&rdquo;</div>
                    <div>
                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="query = ''">
                            Clear the search
                        </button>
                    </div>
                </div>

                <template v-else>
                    <!-- The header row is the sort control, and it is clipped on a
                         phone, so the same controls are repeated here. -->
                    <div class="wt-sort-small">
                        <label class="ui-label" for="wt-sort">Sort by</label>
                        <select id="wt-sort" class="ui-select" v-model="sort.key">
                            <option v-for="col in columns" :key="col.key" :value="col.key">
                                {{ col.label }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="ui-btn ui-btn--icon"
                            :aria-label="sort.dir === 'asc' ? 'Sorted ascending' : 'Sorted descending'"
                            @click="sort.dir = sort.dir === 'asc' ? 'desc' : 'asc'">
                            <i class="bi" :class="sort.dir === 'asc' ? 'bi-sort-down-alt' : 'bi-sort-down'"></i>
                        </button>
                    </div>

                    <div class="ui-table-wrap">
                        <table class="ui-table wt-table">
                            <thead>
                                <tr>
                                    <th
                                        v-for="col in columns"
                                        :key="col.key"
                                        :class="{ num: col.num }"
                                        :aria-sort="ariaSort(col.key)">
                                        <button
                                            type="button"
                                            class="wt-sort"
                                            :class="{ 'is-on': sort.key === col.key }"
                                            @click="sortBy(col.key)">
                                            {{ col.label }}
                                            <i class="bi" :class="sortIcon(col.key)"></i>
                                        </button>
                                    </th>
                                    <th class="wt-action">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="type in rows"
                                    :key="type.id"
                                    :class="{ 'is-editing': type.id === editingId, 'is-off': !type.is_active }">
                                    <td data-label="#">{{ type.id }}</td>

                                    <td data-label="Work Type">
                                        <span class="ui-lead">{{ type.name }}</span>
                                        <span v-if="!type.is_active" class="ui-badge ui-badge--neutral">Inactive</span>
                                    </td>

                                    <td data-label="Default Rate" class="num">
                                        <span v-if="type.default_rate === null" class="ui-money ui-money--nil">&mdash;</span>
                                        <span v-else class="ui-money">{{ money(type.default_rate) }}</span>
                                    </td>

                                    <td data-label="Works" class="num">
                                        <span class="ui-money" :class="{ 'ui-money--nil': !type.file_count }">
                                            {{ type.file_count }}
                                        </span>
                                    </td>

                                    <td data-label="Billed" class="num">
                                        <span class="ui-money" :class="{ 'ui-money--nil': !type.billed_total }">
                                            {{ money(type.billed_total) }}
                                        </span>
                                    </td>

                                    <td data-label="Action" class="wt-action">
                                        <a :href="type.edit_url" class="ui-link">Edit</a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="ui-card__foot">
                        <span class="ui-hint">{{ info }}</span>

                        <div v-if="pages > 1" class="wt-pager">
                            <button
                                type="button"
                                class="ui-btn ui-btn--sm"
                                :disabled="current === 1"
                                @click="page = current - 1">
                                <i class="bi bi-chevron-left"></i> Prev
                            </button>
                            <span class="ui-hint">Page {{ current }} of {{ pages }}</span>
                            <button
                                type="button"
                                class="ui-btn ui-btn--sm"
                                :disabled="current === pages"
                                @click="page = current + 1">
                                Next <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </template>
            </section>
        </div>
    </div>
</template>

<style>
/* The form is a short, fixed set of fields; the list wants the rest of the
   room. They stack in source order on anything narrower than a laptop. */
.wt-grid {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: minmax(0, 1fr);
}

@media (min-width: 992px) {
    .wt-grid {
        align-items: start;
        grid-template-columns: minmax(17rem, 1fr) minmax(0, 2fr);
    }
}

.wt-form {
    display: flex;
    flex-direction: column;
    gap: var(--s-4);
}

.wt-form__actions {
    display: flex;
    gap: var(--s-2);
    justify-content: flex-end;
}

/* A leading addon keeps the unit on screen while the figure is typed. */
.wt-group {
    display: flex;
}

.wt-group__addon {
    align-items: center;
    background: var(--n-050);
    border: 1px solid var(--n-300);
    border-radius: var(--r-sm) 0 0 var(--r-sm);
    border-right: 0;
    color: var(--n-500);
    display: flex;
    font-size: var(--t-sm);
    justify-content: center;
    min-width: 2.75rem;
    padding: 0 var(--s-2);
}

.wt-group .ui-input {
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
}

.wt-switch {
    align-items: center;
    cursor: pointer;
    display: inline-flex;
    gap: var(--s-2);
}

.wt-switch input {
    opacity: 0;
    pointer-events: none;
    position: absolute;
}

.wt-switch__track {
    background: var(--n-300);
    border-radius: var(--r-pill);
    flex: 0 0 auto;
    height: 22px;
    position: relative;
    transition: background .12s ease;
    width: 40px;
}

.wt-switch__track::after {
    background: var(--n-000);
    border-radius: 50%;
    content: "";
    height: 16px;
    left: 3px;
    position: absolute;
    top: 3px;
    transition: transform .12s ease;
    width: 16px;
}

.wt-switch input:checked + .wt-switch__track {
    background: var(--brand-500);
}

.wt-switch input:checked + .wt-switch__track::after {
    transform: translateX(18px);
}

/* The input itself is invisible, so focus has to show on the track. */
.wt-switch input:focus-visible + .wt-switch__track {
    box-shadow: var(--ring);
}

.wt-switch__text {
    color: var(--n-700);
    font-weight: 600;
}

.wt-tools {
    align-items: center;
    display: flex;
    gap: var(--s-2);
}

/* Held at .wt strength so it outranks the shared .ui-input width whichever way
   the two stylesheets land in the bundle. */
.wt .wt-search {
    min-width: 11rem;
    width: auto;
}

/* The heading is the sort control. It carries the header's own type so the
   column still reads as a heading rather than as a button. */
.wt-sort {
    align-items: center;
    background: none;
    border: 0;
    color: inherit;
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    gap: var(--s-1);
    letter-spacing: inherit;
    padding: 0;
    text-transform: inherit;
}

.wt-sort:hover {
    color: var(--n-700);
}

.wt-sort.is-on {
    color: var(--ink-800);
}

.wt-sort i {
    font-size: 0.9em;
    opacity: 0.3;
}

.wt-sort.is-on i {
    opacity: 1;
}

.wt-sort-small {
    align-items: center;
    display: none;
    gap: var(--s-2);
    padding: var(--s-3) var(--s-5) 0;
}

.wt .wt-table th.wt-action,
.wt .wt-table td.wt-action {
    text-align: right;
}

/* Vue drops the whitespace between the name and the badge, so the gap between
   them has to be a margin. */
.wt-table .ui-badge {
    margin-left: var(--s-2);
}

/* The row the form beside it is editing. */
.wt-table tbody tr.is-editing td {
    background: var(--brand-050);
}

/* Retired types stay legible but stop competing with the ones in use. */
.wt-table tbody tr.is-off .ui-lead {
    color: var(--n-500);
}

.wt-pager {
    align-items: center;
    display: flex;
    gap: var(--s-2);
}

/* Below the large breakpoint each row becomes a card. Six columns of figures do
   not fit a phone, and a sideways scrollbar makes a list you are scanning close
   to unusable. */
@media (max-width: 991.98px) {
    .wt-table,
    .wt-table tbody,
    .wt-table tr,
    .wt-table td {
        display: block;
        width: 100%;
    }

    .wt-table thead {
        clip: rect(0 0 0 0);
        height: 1px;
        overflow: hidden;
        position: absolute;
        width: 1px;
    }

    .wt-table tbody tr {
        border: 1px solid var(--n-200);
        border-radius: var(--r-md);
        margin-bottom: var(--s-3);
        padding: var(--s-2) var(--s-3);
    }

    .wt-table tbody td {
        border-bottom: 0;
        padding: var(--s-2) 0;
    }

    .wt-table tbody td::before {
        color: var(--n-500);
        content: attr(data-label);
        display: block;
        font-size: var(--t-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        margin-bottom: var(--s-1);
        text-transform: uppercase;
    }

    .wt .wt-table tbody td.num,
    .wt .wt-table tbody td.wt-action {
        text-align: left;
    }

    /* Scoped to this screen: the wrapper is a shared class, and the cards need
       a margin the flush desktop table does not. */
    .wt .ui-table-wrap {
        padding: 0 var(--s-3);
    }

    .wt-sort-small {
        display: flex;
        padding: var(--s-3) var(--s-3) 0;
    }

    .wt-sort-small .ui-select {
        width: auto;
    }

    .wt-tools {
        flex: 1 1 100%;
    }

    .wt-search {
        flex: 1 1 auto;
        min-width: 0;
    }
}
</style>
