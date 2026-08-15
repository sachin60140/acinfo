<script setup>
/*
 * The table every listing and statement screen renders through.
 *
 * It replaces DataTables plus its Buttons stack. That stack pulled roughly a
 * megabyte of jQuery, JSZip and pdfmake from a CDN into every page that showed
 * a table, whether or not anyone exported anything — and it was the source of
 * the "Incorrect column count" failures, because it counts <td> per row and has
 * no notion of a merged cell. Sorting, searching and paging are cheap to do
 * directly; the export libraries are not, so they are fetched on the first
 * click and never before.
 *
 * Cells are described by data rather than by slots. A component mounted onto a
 * bare element has no server markup to slot in, so each column carries a type
 * and the grid decides how to draw it. That also means one definition of how a
 * figure looks, on screen and in every export.
 */
import { computed, ref } from 'vue';
import { balance, money, side } from '../money';

const props = defineProps({
    // { key, label, type, align, sortable, exportable, hidden, width }
    // type: text | money | balance | date | badge | link | sub | count
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    title: { type: String, default: 'Export' },

    // Column key to band rows by, for reports that group by party.
    groupBy: { type: String, default: '' },
    // Where the group's heading text comes from, if not the grouping key itself.
    groupLabel: { type: String, default: '' },

    // Columns to total, per group and overall: { columnKey: 'sum' }
    totals: { type: Object, default: () => ({}) },

    perPage: { type: Number, default: 50 },
    searchable: { type: Boolean, default: true },
    // Statements carry a running balance accumulated in the order the server
    // sent, so re-sorting would detach each figure from its row.
    sortable: { type: Boolean, default: true },
    exportable: { type: Boolean, default: true },
    emptyText: { type: String, default: 'Nothing to show.' },
});

const query = ref('');
const sortKey = ref('');
const sortAsc = ref(true);
const page = ref(1);

const shown = computed(() => props.columns.filter((c) => !c.hidden));

/* Searching runs over what a column exports, not what it displays, so a search
   matches what the reader can actually see rather than an internal id. */
function textOf(row, column) {
    const raw = row[column.key];

    if (raw === null || raw === undefined || raw === '') {
        return '';
    }

    if (column.type === 'money') return money(raw);
    if (column.type === 'balance') return balance(raw);

    return String(raw);
}

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (!needle) {
        return props.rows;
    }

    /* Every space-separated word must match somewhere in the row, so "sharma
       pending" narrows rather than widening the way an OR would. */
    const words = needle.split(/\s+/);

    return props.rows.filter((row) => {
        const haystack = props.columns
            .map((column) => textOf(row, column))
            .join(' ')
            .toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
});

const sorted = computed(() => {
    if (!sortKey.value) {
        return filtered.value;
    }

    const column = props.columns.find((c) => c.key === sortKey.value);
    const numeric = column && ['money', 'balance', 'count'].includes(column.type);
    const direction = sortAsc.value ? 1 : -1;

    // A copy: sorting the computed source in place would mutate the prop.
    return [...filtered.value].sort((a, b) => {
        const left = a[sortKey.value];
        const right = b[sortKey.value];

        if (numeric) {
            return ((Number(left) || 0) - (Number(right) || 0)) * direction;
        }

        return String(left ?? '').localeCompare(String(right ?? ''), 'en-IN', { numeric: true }) * direction;
    });
});

const pageCount = computed(() => Math.max(1, Math.ceil(sorted.value.length / props.perPage)));

const paged = computed(() => {
    // A filter that shortens the list can strand the reader past the last page.
    const current = Math.min(page.value, pageCount.value);
    const start = (current - 1) * props.perPage;

    return sorted.value.slice(start, start + props.perPage);
});

/*
 * Grouped rows are banded rather than nested. A heading row inside the body
 * would be a row with fewer cells than the header, which is exactly the shape
 * that used to break the table — so the band is drawn as a full-width cell that
 * spans every column, and the totals sit on their own spanning row.
 */
const banded = computed(() => {
    if (!props.groupBy) {
        return [{ key: '', label: '', rows: paged.value, totals: null }];
    }

    const bands = new Map();

    for (const row of paged.value) {
        const key = String(row[props.groupBy] ?? '');

        if (!bands.has(key)) {
            bands.set(key, {
                key,
                label: String(row[props.groupLabel || props.groupBy] ?? ''),
                rows: [],
            });
        }

        bands.get(key).rows.push(row);
    }

    return [...bands.values()].map((band) => ({ ...band, totals: sum(band.rows) }));
});

function sum(rows) {
    const out = {};

    for (const key of Object.keys(props.totals)) {
        out[key] = rows.reduce((carry, row) => carry + (Number(row[key]) || 0), 0);
    }

    return out;
}

const grandTotals = computed(() => sum(sorted.value));
const hasTotals = computed(() => Object.keys(props.totals).length > 0);

function toggleSort(column) {
    if (!props.sortable || column.sortable === false) {
        return;
    }

    if (sortKey.value === column.key) {
        sortAsc.value = !sortAsc.value;
    } else {
        sortKey.value = column.key;
        sortAsc.value = true;
    }
}

/* ---- exports -------------------------------------------------------------
 * Everything exports the filtered set in its current order, not the visible
 * page. Exporting page 1 of 8 when the reader has searched for a party is the
 * kind of quiet wrongness that gets found in a meeting.
 */

const exportColumns = computed(() => props.columns.filter((c) => c.exportable !== false && !c.hidden));

function exportRows() {
    return sorted.value.map((row) => exportColumns.value.map((column) => textOf(row, column)));
}

const exportHead = computed(() => exportColumns.value.map((c) => c.label));

const busy = ref('');

/**
 * Fetches a script once and resolves when it has run. The export libraries are
 * large and most visits never export, so they are not in the bundle and not in
 * the page — they arrive when someone asks for a file.
 */
const loaded = new Map();

function load(src) {
    if (loaded.has(src)) {
        return loaded.get(src);
    }

    const pending = new Promise((resolve, reject) => {
        const tag = document.createElement('script');
        tag.src = src;
        tag.onload = resolve;
        tag.onerror = () => reject(new Error(`Could not load ${src}`));
        document.head.appendChild(tag);
    });

    loaded.set(src, pending);

    return pending;
}

function download(blob, extension) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = `${props.title.replace(/[^\w-]+/g, '-')}.${extension}`;
    link.click();

    URL.revokeObjectURL(url);
}

function csvCell(value) {
    // A figure like 1,200.00 carries a comma, so quoting is not optional here.
    return `"${String(value).replace(/"/g, '""')}"`;
}

function toCsv() {
    return [exportHead.value, ...exportRows()]
        .map((row) => row.map(csvCell).join(','))
        .join('\r\n');
}

function exportCsv() {
    // The BOM is what makes Excel read it as UTF-8 rather than mangling ₹.
    download(new Blob(['﻿' + toCsv()], { type: 'text/csv;charset=utf-8' }), 'csv');
}

async function copy() {
    const text = [exportHead.value, ...exportRows()].map((row) => row.join('\t')).join('\n');

    try {
        await navigator.clipboard.writeText(text);
        flash('Copied');
    } catch {
        flash('Could not copy');
    }
}

const note = ref('');
let noteTimer = null;

function flash(text) {
    note.value = text;
    clearTimeout(noteTimer);
    noteTimer = setTimeout(() => (note.value = ''), 2000);
}

/**
 * A real .xlsx rather than a CSV renamed, so column widths and the header row
 * survive and figures arrive as figures.
 */
async function exportExcel() {
    busy.value = 'excel';

    try {
        await load('https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js');

        const XLSX = window.XLSX;
        const sheet = XLSX.utils.aoa_to_sheet([exportHead.value, ...exportRows()]);

        sheet['!cols'] = exportColumns.value.map((column) => ({
            wch: Math.min(40, Math.max(12, column.label.length + 4)),
        }));

        const book = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(book, sheet, 'Sheet1');
        XLSX.writeFile(book, `${props.title.replace(/[^\w-]+/g, '-')}.xlsx`);
    } catch (error) {
        flash('Excel export unavailable offline');
    } finally {
        busy.value = '';
    }
}

async function exportPdf() {
    busy.value = 'pdf';

    try {
        await load('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.10/pdfmake.min.js');
        await load('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.10/vfs_fonts.js');

        const numeric = exportColumns.value.map((c) => ['money', 'balance', 'count'].includes(c.type));

        window.pdfmake
            .createPdf({
                pageOrientation: exportColumns.value.length > 6 ? 'landscape' : 'portrait',
                pageMargins: [20, 30, 20, 30],
                content: [
                    { text: props.title, style: 'title' },
                    {
                        table: {
                            headerRows: 1,
                            widths: exportColumns.value.map(() => 'auto'),
                            body: [
                                exportHead.value.map((label) => ({ text: label, style: 'head' })),
                                ...exportRows().map((row) =>
                                    row.map((cell, i) => ({
                                        text: cell,
                                        alignment: numeric[i] ? 'right' : 'left',
                                        fontSize: 8,
                                    }))
                                ),
                            ],
                        },
                        layout: 'lightHorizontalLines',
                    },
                ],
                styles: {
                    title: { fontSize: 14, bold: true, margin: [0, 0, 0, 10] },
                    head: { fontSize: 9, bold: true, fillColor: '#eef2ff' },
                },
            })
            .download(`${props.title.replace(/[^\w-]+/g, '-')}.pdf`);
    } catch (error) {
        flash('PDF export unavailable offline');
    } finally {
        busy.value = '';
    }
}

/*
 * Printing opens a clean document rather than styling the page away. The page
 * carries a sidebar, a header and a filter bar that no one wants on paper, and
 * hiding all of it with print CSS has to be redone every time the layout moves.
 */
function print() {
    const numeric = exportColumns.value.map((c) => ['money', 'balance', 'count'].includes(c.type));

    const table = `
        <table>
            <thead><tr>${exportHead.value.map((h) => `<th>${escape(h)}</th>`).join('')}</tr></thead>
            <tbody>${exportRows()
                .map(
                    (row) =>
                        `<tr>${row
                            .map((cell, i) => `<td class="${numeric[i] ? 'num' : ''}">${escape(cell)}</td>`)
                            .join('')}</tr>`
                )
                .join('')}</tbody>
        </table>`;

    const win = window.open('', '_blank');

    if (!win) {
        flash('Allow pop-ups to print');

        return;
    }

    win.document.write(`<!doctype html><html><head><title>${escape(props.title)}</title><style>
        body { font: 12px/1.5 system-ui, sans-serif; margin: 24px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 16px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #eef2ff; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        @page { margin: 12mm; }
    </style></head><body><h1>${escape(props.title)}</h1>${table}</body></html>`);

    win.document.close();
    win.focus();
    win.print();
}

function escape(value) {
    return String(value).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
}

/* ---- display ----------------------------------------------------------- */

function display(row, column) {
    const raw = row[column.key];

    if (column.type === 'money') return money(raw);
    if (column.type === 'balance') return balance(raw);

    return raw ?? '—';
}

function moneyClass(row, column) {
    if (column.type === 'balance') return `ui-money ui-money--${side(row[column.key])}`;
    if (column.type === 'money') return Number(row[column.key]) ? 'ui-money' : 'ui-money ui-money--nil';

    return '';
}

const isNum = (column) => ['money', 'balance', 'count'].includes(column.type);
</script>

<template>
    <div class="grid">
        <div class="grid__bar">
            <div v-if="searchable" class="grid__search">
                <i class="bi bi-search"></i>
                <input
                    v-model="query"
                    type="search"
                    class="ui-input"
                    placeholder="Search"
                    aria-label="Search this table"
                    @input="page = 1">
            </div>

            <div class="grid__count">
                <span v-if="query">{{ sorted.length }} of {{ rows.length }}</span>
                <span v-else>{{ rows.length }} {{ rows.length === 1 ? 'row' : 'rows' }}</span>
                <span v-if="note" class="grid__note">{{ note }}</span>
            </div>

            <div v-if="exportable && rows.length" class="grid__tools">
                <button type="button" class="ui-btn ui-btn--sm" @click="copy">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
                <button type="button" class="ui-btn ui-btn--sm" @click="exportCsv">
                    <i class="bi bi-filetype-csv"></i> CSV
                </button>
                <button type="button" class="ui-btn ui-btn--sm" :disabled="busy === 'excel'" @click="exportExcel">
                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    {{ busy === 'excel' ? 'Building…' : 'Excel' }}
                </button>
                <button type="button" class="ui-btn ui-btn--sm" :disabled="busy === 'pdf'" @click="exportPdf">
                    <i class="bi bi-file-earmark-pdf"></i>
                    {{ busy === 'pdf' ? 'Building…' : 'PDF' }}
                </button>
                <button type="button" class="ui-btn ui-btn--sm" @click="print">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>

        <div v-if="!sorted.length" class="ui-empty">
            <div class="ui-empty__icon"><i class="bi bi-inbox"></i></div>
            <div class="ui-empty__title">Nothing here</div>
            <div>{{ query ? 'Nothing matches that search.' : emptyText }}</div>
        </div>

        <div v-else class="ui-table-wrap">
            <table class="ui-table grid__table">
                <thead>
                    <tr>
                        <th
                            v-for="column in shown"
                            :key="column.key"
                            :class="{ num: isNum(column), sortable: sortable && column.sortable !== false }"
                            :style="column.width ? `min-width:${column.width}` : ''"
                            :aria-sort="sortKey === column.key ? (sortAsc ? 'ascending' : 'descending') : 'none'"
                            @click="toggleSort(column)">
                            {{ column.label }}
                            <i
                                v-if="sortKey === column.key"
                                class="bi"
                                :class="sortAsc ? 'bi-caret-up-fill' : 'bi-caret-down-fill'"></i>
                        </th>
                    </tr>
                </thead>

                <template v-for="band in banded" :key="band.key">
                    <tbody>
                        <!-- A band heading spans every column, so the row still has
                             the cell count the header promises. -->
                        <tr v-if="groupBy" class="grid__band">
                            <td :colspan="shown.length">{{ band.label }}</td>
                        </tr>

                        <tr v-for="(row, i) in band.rows" :key="row.id ?? i">
                            <td
                                v-for="column in shown"
                                :key="column.key"
                                :data-label="column.label"
                                :class="[isNum(column) ? 'num' : '', column.class]">
                                <a v-if="column.type === 'link' && row[column.linkTo]" :href="row[column.linkTo]" class="ui-link">
                                    {{ display(row, column) }}
                                </a>
                                <span v-else-if="column.type === 'badge'" class="ui-badge" :data-state="row[column.key + '_key']">
                                    {{ display(row, column) }}
                                </span>
                                <span v-else :class="moneyClass(row, column)">{{ display(row, column) }}</span>

                                <div v-if="column.sub && row[column.sub]" class="ui-sub">{{ row[column.sub] }}</div>
                            </td>
                        </tr>

                        <tr v-if="groupBy && hasTotals" class="grid__subtotal">
                            <td
                                v-for="(column, i) in shown"
                                :key="column.key"
                                :class="isNum(column) ? 'num' : ''"
                                :data-label="column.label">
                                <span v-if="i === 0">Total</span>
                                <span v-else-if="totals[column.key] !== undefined" class="ui-money ui-money--strong">
                                    {{ money(band.totals[column.key]) }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </template>

                <tfoot v-if="hasTotals">
                    <tr>
                        <td
                            v-for="(column, i) in shown"
                            :key="column.key"
                            :class="isNum(column) ? 'num' : ''"
                            :data-label="column.label">
                            <span v-if="i === 0">{{ query ? 'Total (filtered)' : 'Total' }}</span>
                            <span v-else-if="totals[column.key] !== undefined" class="ui-money ui-money--strong">
                                {{ money(grandTotals[column.key]) }}
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div v-if="pageCount > 1" class="grid__pages">
            <button type="button" class="ui-btn ui-btn--sm" :disabled="page <= 1" @click="page--">
                <i class="bi bi-chevron-left"></i> Previous
            </button>
            <span class="ui-hint">Page {{ Math.min(page, pageCount) }} of {{ pageCount }}</span>
            <button type="button" class="ui-btn ui-btn--sm" :disabled="page >= pageCount" @click="page++">
                Next <i class="bi bi-chevron-right"></i>
            </button>
        </div>
    </div>
</template>

<style>
.grid__bar {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-3);
    justify-content: space-between;
    padding: var(--s-3) 0;
}

.grid__search {
    align-items: center;
    display: flex;
    flex: 0 1 20rem;
    gap: var(--s-2);
    position: relative;
}

.grid__search .bi {
    color: var(--n-400);
    left: var(--s-3);
    position: absolute;
}

.grid__search .ui-input {
    padding-left: var(--s-8);
}

.grid__count {
    color: var(--n-500);
    font-size: var(--t-sm);
    margin-right: auto;
}

.grid__note {
    color: var(--dr-600);
    font-weight: 600;
    margin-left: var(--s-2);
}

.grid__tools {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2);
}

.grid__table th.sortable {
    cursor: pointer;
    user-select: none;
}

.grid__table th.sortable:hover {
    color: var(--brand-600);
}

.grid__band td {
    background: var(--brand-050);
    color: var(--brand-700);
    font-size: var(--t-xs);
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.grid__subtotal td {
    background: var(--n-050);
    border-top: 1px solid var(--n-200);
    font-weight: 600;
}

.grid__table tfoot td {
    background: var(--n-100);
    border-top: 2px solid var(--n-300);
    font-weight: 700;
}

.grid__pages {
    align-items: center;
    display: flex;
    gap: var(--s-3);
    justify-content: center;
    padding: var(--s-3) 0;
}

@media (max-width: 991.98px) {
    .grid__table,
    .grid__table tbody,
    .grid__table tr,
    .grid__table td {
        display: block;
        width: 100%;
    }

    .grid__table thead,
    .grid__table tfoot {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
    }

    .grid__table tbody tr {
        border: 1px solid var(--n-200);
        border-radius: var(--r-md);
        margin-bottom: var(--s-3);
        padding: var(--s-2) var(--s-3);
    }

    .grid__table tbody td {
        border-bottom: 0;
        display: flex;
        gap: var(--s-3);
        justify-content: space-between;
        padding: var(--s-1) 0;
        text-align: right;
    }

    .grid__table tbody td::before {
        color: var(--n-500);
        content: attr(data-label);
        font-size: var(--t-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        text-align: left;
        text-transform: uppercase;
    }

    .grid__table tbody tr.grid__band td,
    .grid__table tbody tr.grid__band td::before {
        content: none;
        display: block;
        text-align: left;
    }

    .grid__tools {
        width: 100%;
    }

    .grid__tools .ui-btn {
        flex: 1 1 auto;
    }
}
</style>
