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
import FilePreview from './FilePreview.vue';

/*
 * The approval document being looked at, or null. Held here so the link
 * only has to say which one; the dialog itself is shared.
 */
const preview = ref(null);

/*
 * A second line that links somewhere.
 *
 * A document — an approval screenshot — opens over the list, because checking
 * one is a glance and the reader is part way down a page that is filtered and
 * scrolled. Anything else is a page and is followed normally: opened in the
 * dialog it would land in a frame with none of its own navigation, which is
 * how a party statement came to be a viewer saying it could not load.
 */
function onSubLink(event, column, row) {
    if (! column.subPreview) {
        return;
    }

    event.preventDefault();

    preview.value = { src: row[column.subLinkTo], title: row[column.sub] };
}

import {
    cellText,
    exportColumns as exportableColumns,
    exportHeader,
    exportRows as exportRowsOf,
    exportValues as exportValuesOf,
    fileName,
    isNumeric,
    toClipboard,
    toCsv,
} from '../exports';

/*
 * A row the reader wants to do something to.
 *
 * The grid stays a table and knows nothing about what the something is: it
 * says which row was asked about and leaves the screen around it to decide.
 * That keeps the dialog, its form and its rules out of a component that thirty
 * other screens render through.
 */
const emit = defineEmits(['action']);

const props = defineProps({
    /*
     * { key, label, type, sortable, exportable, searchable, hidden, width,
     *   sub, note, subLinkTo, class, linkTo, newTab, sortBy, exportOnly }
     *
     * type: text | money | balance | count | badge | link
     * sortBy   sort this column on another field's value — a date shown as
     *          dd-mm-yyyy sorts by day of the month unless pointed at the ISO one
     * sortDesc  the first click sorts this column downwards. For a date whose
     *          question is "what happened lately": ascending would open on the
     *          oldest row, which nobody asked for
     * note     a second quiet line of plain text, above sub — for a cell that
     *          has something to say as well as something to open
     * subPreview  the sub link is a document rather than a page, so it opens over
     *          the list instead of replacing it. Only for something a viewer can
     *          show: a page opened this way lands in a frame with none of its own
     *          navigation, which is how a party statement came to be unreadable
     * exportOnly  kept out of the table but written to every export. For detail
     *          a spreadsheet can sort and filter and a screen has no room for:
     *          the works on a file, which are summarised in a cell here and
     *          wanted a column each in Excel
     * newTab   open the link in a new tab. Only for somewhere outside the
     *          application: within it, a second tab loses the filters and the
     *          place in the list the reader was working in
     * searchable: false  keep a fixed label like "Edit" out of the search text,
     *          or every row matches the word
     */
    columns: { type: Array, required: true },
    // Per-row extra classes: { rowKeyField: 'class-name' } applied by row[key].
    rowClass: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    title: { type: String, default: 'Export' },

    // Column key to band rows by, for reports that group by party.
    groupBy: { type: String, default: '' },
    // Where the group's heading text comes from, if not the grouping key itself.
    groupLabel: { type: String, default: '' },

    // Columns to total, per group and overall: { columnKey: 'sum' }
    totals: { type: Object, default: () => ({}) },

    /*
     * Rows that frame the data rather than belong to it: a statement's opening
     * balance above and its closing below.
     *
     * Kept out of the search, the sort, the paging and the totals, because none
     * of those mean anything applied to them — an opening balance that can be
     * filtered away, or sorted into the middle, stops being an opening balance.
     * They go into every export, which is the whole point: the figure the
     * Balance column counts up from was drawn outside the table, so a printed
     * statement began mid-air with no figure to explain its first line.
     */
    lead: { type: Array, default: () => [] },
    tail: { type: Array, default: () => [] },

    perPage: { type: Number, default: 50 },
    searchable: { type: Boolean, default: true },

    /*
     * The column the rows already arrive sorted by, and which way.
     *
     * Without this the grid shows no sort indicator on a list the server has
     * already ordered, and the first click on that column sorts ascending —
     * which, on a list sorted by amount descending, reads as the sort inverting
     * itself for no reason. Naming the existing order here makes the first click
     * do the one thing the reader expects: reverse it.
     */
    sortedBy: { type: String, default: '' },
    sortedDesc: { type: Boolean, default: false },
    // Statements carry a running balance accumulated in the order the server
    // sent, so re-sorting would detach each figure from its row.
    sortable: { type: Boolean, default: true },
    exportable: { type: Boolean, default: true },
    emptyText: { type: String, default: 'Nothing to show.' },

    /*
     * Bands of columns the reader can turn on, as [{ key, label, on }].
     *
     * A column naming a band is drawn only while that band is open; a column
     * naming none is always drawn. That is the whole rule, and it is what keeps
     * a long list readable without hiding anything permanently: the handful of
     * columns that say which file this is and where it has got to are always
     * there, and the rest — what it cost, who has it, what the margin was — is
     * one click away when somebody is asking that question.
     *
     * Exports are untouched. Every column the screen knows about goes into the
     * spreadsheet whether or not it is drawn, which is exactly what makes it
     * safe for the default to be short.
     */
    groups: { type: Array, default: () => [] },
});

const query = ref('');
const sortKey = ref(props.sortedBy);
const sortAsc = ref(!props.sortedDesc);
const page = ref(1);

/*
 * Until the reader clicks a heading, the rows stand in the order the server
 * sent. sortedBy only names that order so the indicator is right and the first
 * click reverses rather than re-sorts; re-sorting here would risk breaking ties
 * differently from the query that produced the order.
 */
const reordered = ref(false);

/*
 * Which bands are open, remembered per screen in this browser.
 *
 * Read through a try, because storage throws rather than coming back empty in a
 * private window — and a table that will not draw is a far worse answer than a
 * table showing its usual columns.
 */
const groupStore = `acinfo.grid.${props.title}.groups`;

function remembered() {
    try {
        const saved = JSON.parse(localStorage.getItem(groupStore) || 'null');

        if (Array.isArray(saved)) {
            // Only bands this screen still offers: a column band that has since
            // been renamed or dropped should not keep a seat in memory.
            return saved.filter((key) => props.groups.some((band) => band.key === key));
        }
    } catch {
        // Nothing remembered about this screen. The defaults stand.
    }

    return null;
}

const open = ref(remembered() ?? props.groups.filter((band) => band.on).map((band) => band.key));

const isOpen = (key) => open.value.includes(key);

function toggleGroup(key) {
    open.value = isOpen(key) ? open.value.filter((one) => one !== key) : [...open.value, key];

    try {
        localStorage.setItem(groupStore, JSON.stringify(open.value));
    } catch {
        // It will not be remembered past this page. It still works on it.
    }
}

// What the table draws. Hidden columns are internal; export-only ones are
// real data that this screen has no room for; the rest depend on their band.
const shown = computed(() => props.columns.filter(
    (c) => ! c.hidden && ! c.exportOnly && (! c.group || isOpen(c.group))
));

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
            .filter((column) => column.searchable !== false)
            .map((column) => textOf(row, column))
            .join(' ')
            .toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
});

const sorted = computed(() => {
    if (!sortKey.value || !reordered.value) {
        return filtered.value;
    }

    const column = props.columns.find((c) => c.key === sortKey.value);
    const numeric = column && ['money', 'balance', 'count'].includes(column.type);
    const direction = sortAsc.value ? 1 : -1;

    /*
     * A column may sort on a different field than it shows. Dates are the reason:
     * they are printed dd-mm-yyyy, and comparing that as text sorts by day of the
     * month, so March the 2nd of any year lands above December the 1st. Point the
     * column at the ISO date and the order is chronological again.
     */
    const on = column?.sortBy || sortKey.value;

    // A copy: sorting the computed source in place would mutate the prop.
    return [...filtered.value].sort((a, b) => {
        const left = a[on];
        const right = b[on];

        if (numeric) {
            return ((Number(left) || 0) - (Number(right) || 0)) * direction;
        }

        const l = String(left ?? '');
        const r = String(right ?? '');

        /*
         * A blank is "not yet", not "before everything". A file with no
         * dispatch date has not gone out, and putting those at the top of an
         * oldest-first sort buries the thing the sort was for — so they sit at
         * the end whichever way the column is pointing.
         */
        if (l === '' || r === '') {
            return l === r ? 0 : (l === '' ? 1 : -1);
        }

        return l.localeCompare(r, 'en-IN', { numeric: true }) * direction;
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

/**
 * A total is written the way its column is written.
 *
 * Totalling a balance column through money() put "-1,200.00" in the footer under
 * a column of cells reading "1,200.00 Cr" — the same figure, in two conventions,
 * one of which the rest of the app never uses.
 */
function total(column, value) {
    if (column.type === 'balance') {
        return balance(value);
    }

    // A count is a number of things. Through money() nine works totalled to
    // "9.00", under a column of cells reading 3, 2, 2 and 2 — and nobody has
    // ever done nine-hundredths of a transfer.
    if (column.type === 'count') {
        return String(Math.round(Number(value) || 0));
    }

    return money(value);
}

function totalClass(column, value) {
    return column.type === 'balance' ? `ui-money ui-money--${side(value)}` : 'ui-money';
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
        // Most columns open upwards: A before B, 1 before 2. A date column that
        // asks "what lately" opens the other way — see sortDesc above.
        sortAsc.value = ! column.sortDesc;
    }

    reordered.value = true;
}

/* ---- exports -------------------------------------------------------------
 * Everything exports the filtered set in its current order, not the visible
 * page. Exporting page 1 of 8 when the reader has searched for a party is the
 * kind of quiet wrongness that gets found in a meeting.
 */

const money2 = { money, balance };

const exportColumns = computed(() => exportableColumns(props.columns));

/*
 * What every export is built from: the framing rows around the filtered set, in
 * the order they are read. Written once so Copy, CSV, Excel, PDF and Print
 * cannot disagree about whether a statement carries its opening balance.
 */
const exportable = computed(() => [...props.lead, ...sorted.value, ...props.tail]);

function exportRows() {
    return exportRowsOf(props.columns, exportable.value, money2);
}

function exportValues() {
    return exportValuesOf(props.columns, exportable.value, money2);
}

const exportHead = computed(() => exportHeader(props.columns));

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
    link.download = fileName(props.title, extension);
    link.click();

    URL.revokeObjectURL(url);
}

function exportCsv() {
    download(new Blob([toCsv(props.columns, exportable.value, money2)], { type: 'text/csv;charset=utf-8' }), 'csv');
}

async function copy() {
    const text = toClipboard(props.columns, exportable.value, money2);

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
        const sheet = XLSX.utils.aoa_to_sheet([exportHead.value, ...exportValues()]);

        sheet['!cols'] = exportColumns.value.map((column) => ({
            wch: Math.min(40, Math.max(12, column.label.length + 4)),
        }));

        /*
         * Two decimals with thousands separators, applied to the figures as a
         * cell format rather than baked into the text — so they read the way the
         * screen reads while staying numbers underneath.
         */
        const range = XLSX.utils.decode_range(sheet['!ref']);

        exportColumns.value.forEach((column, index) => {
            if (!['money', 'balance'].includes(column.type)) {
                return;
            }

            for (let row = 1; row <= range.e.r; row++) {
                const cell = sheet[XLSX.utils.encode_cell({ c: index, r: row })];

                if (cell && cell.t === 'n') {
                    cell.z = '#,##0.00';
                }
            }
        });

        const book = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(book, sheet, 'Sheet1');
        XLSX.writeFile(book, fileName(props.title, 'xlsx'));
    } catch (error) {
        // Same reasoning as the PDF catch: a mistake in this function is not a
        // network problem, and saying so sends the reader to the wrong place.
        console.error('Excel export failed', error);
        flash('Could not build the spreadsheet');
    } finally {
        busy.value = '';
    }
}

async function exportPdf() {
    busy.value = 'pdf';

    try {
        await load('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.10/pdfmake.min.js');
        await load('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.10/vfs_fonts.js');

        const numeric = exportColumns.value.map(isNumeric);

        // pdfMake, with the capital: its UMD build names the global that way, and
        // window.pdfmake was undefined, so this threw a TypeError the catch below
        // then reported as the library failing to load.
        window.pdfMake
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
            .download(fileName(props.title, 'pdf'));
    } catch (error) {
        // Said "unavailable offline" for every failure including a mistake in this
        // function, which is how a typo looked like a network problem. The console
        // gets the real error; the message no longer claims to know the reason.
        console.error('PDF export failed', error);
        flash('Could not build the PDF');
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
    const numeric = exportColumns.value.map(isNumeric);

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

/**
 * Nothing and zero are different facts, and a figures column has to keep them
 * apart. A file with no vendor has no cost; printing 0.00 there states that a
 * vendor charged nothing, which is a different claim and a false one. So null
 * leaves the cell empty — on screen and in every export, which is the part that
 * matters: the same cell reading blank here, 0.00 there and 0 in the
 * spreadsheet is worse than any one of them.
 */
function blank(value) {
    return value === null || value === undefined || value === '';
}

function display(row, column) {
    const raw = row[column.key];

    if (column.type === 'money' || column.type === 'balance') {
        return blank(raw) ? '' : (column.type === 'money' ? money(raw) : balance(raw));
    }

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

            <!-- What else this table can say, offered rather than shown. Named
                 for the question each answers, not for the columns inside. -->
            <div v-if="groups.length" class="grid__groups">
                <button
                    v-for="band in groups"
                    :key="band.key"
                    type="button"
                    class="grid__group"
                    :class="{ 'is-on': isOpen(band.key) }"
                    :aria-pressed="isOpen(band.key)"
                    @click="toggleGroup(band.key)">
                    <i class="bi" :class="isOpen(band.key) ? 'bi-check2' : 'bi-plus'"></i>
                    {{ band.label }}
                </button>
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

        <!-- The framing rows count as something to show. A period with no
             entries but a real opening balance is not an empty statement, and
             saying "Nothing here" over a brought-forward figure reads as a
             balance of nothing. -->
        <div v-if="!sorted.length && !lead.length && !tail.length" class="ui-empty">
            <div class="ui-empty__icon"><i class="bi bi-inbox"></i></div>
            <div class="ui-empty__title">Nothing here</div>
            <div>{{ query ? 'Nothing matches that search.' : emptyText }}</div>
        </div>

        <div v-else class="ui-table-wrap">
            <!--
                A grid of many columns is given room to be itself and allowed
                to scroll inside its own wrapper, rather than being squeezed
                into the page width. Squeezed, every column takes what is left
                after the fixed ones: a customer called "Kuwy Technology
                Service Pvt Ltd" wrapped onto four lines and made a row four
                lines tall, and the figures beside it drifted away from the row
                they belonged to.
            -->
            <table class="ui-table grid__table" :class="{ 'grid__table--wide': shown.length >= 9 }">
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

                <!-- Above the body and outside the banding: the opening balance
                     belongs to the statement, not to any group within it. -->
                <tbody v-if="lead.length">
                    <tr v-for="(row, i) in lead" :key="`lead-${i}`" class="grid__edge">
                        <td
                            v-for="column in shown"
                            :key="column.key"
                            :data-label="column.label"
                            :class="[isNum(column) ? 'num' : '', column.class]">
                            <!-- The same branches the entries get, so a framing row
                                 does not quietly lose a badge's pill or a link's anchor
                                 the moment a statement gains such a column. An action
                                 is deliberately absent: a brought-forward balance is
                                 not a row anything is done to. -->
                            <a
                                v-if="column.type === 'link' && row[column.linkTo]"
                                :href="row[column.linkTo]"
                                class="ui-link"
                                :target="column.newTab ? '_blank' : null"
                                :rel="column.newTab ? 'noopener' : null">
                                {{ display(row, column) }}
                            </a>
                            <span v-else-if="column.type === 'badge' && display(row, column)" class="ui-badge" :data-state="row[column.key + '_key']">
                                {{ display(row, column) }}
                            </span>
                            <span v-else-if="column.type === 'money' || column.type === 'balance'" :class="moneyClass(row, column)">
                                {{ display(row, column) }}
                            </span>
                            <template v-else>{{ display(row, column) }}</template>
                        </td>
                    </tr>
                </tbody>

                <template v-for="band in banded" :key="band.key">
                    <tbody>
                        <!-- A band heading spans every column, so the row still has
                             the cell count the header promises. -->
                        <tr v-if="groupBy" class="grid__band">
                            <!-- A slot, defaulting to the label, so a screen that wants
                                 to put something on a band's heading can without every
                                 other screen changing: WorkReport sends a vendor their
                                 list from here. -->
                            <td :colspan="shown.length">
                                <slot name="band" :band="band">{{ band.label }}</slot>
                            </td>
                        </tr>

                        <tr v-for="(row, i) in band.rows" :key="row.id ?? i" :class="rowClass ? row[rowClass] : ''">
                            <td
                                v-for="column in shown"
                                :key="column.key"
                                :data-label="column.label"
                                :class="[isNum(column) ? 'num' : '', column.class]">
                                <a
                                    v-if="column.type === 'link' && row[column.linkTo]"
                                    :href="row[column.linkTo]"
                                    class="ui-link"
                                    :target="column.newTab ? '_blank' : null"
                                    :rel="column.newTab ? 'noopener' : null">
                                    {{ display(row, column) }}
                                </a>
                                <span v-else-if="column.type === 'badge'" class="ui-badge" :data-state="row[column.key + '_key']">
                                    {{ display(row, column) }}
                                </span>
                                <!-- A button and not a link: it does something to
                                     this row rather than going somewhere, and a
                                     link that goes nowhere cannot be middle-clicked,
                                     bookmarked or opened in a tab the way its
                                     appearance promises. -->
                                <button
                                    v-else-if="column.type === 'action'"
                                    type="button"
                                    class="ui-btn ui-btn--sm"
                                    @click="emit('action', row, column)">
                                    <i v-if="column.icon" class="bi" :class="column.icon"></i>
                                    {{ display(row, column) || column.label }}
                                </button>
                                <span v-else :class="moneyClass(row, column)">{{ display(row, column) }}</span>

                                <!-- What else the cell has to say. The note is a
                                     statement, the sub may be a link: an attachment
                                     is worth naming and worth opening. -->
                                <div v-if="column.note && row[column.note]" class="ui-sub">
                                    {{ row[column.note] }}
                                </div>

                                <div v-if="column.sub && row[column.sub]" class="ui-sub">
                                    <!-- A document opens over the list, not instead
                                         of it: checking a screenshot is a glance, and
                                         the reader is part way down a filtered page.
                                         Anything else is a page, and is followed. -->
                                    <a
                                        v-if="column.subLinkTo && row[column.subLinkTo]"
                                        :href="row[column.subLinkTo]"
                                        class="ui-link"
                                        @click="onSubLink($event, column, row)">
                                        {{ row[column.sub] }}
                                    </a>
                                    <template v-else>{{ row[column.sub] }}</template>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="groupBy && hasTotals" class="grid__subtotal">
                            <td
                                v-for="(column, i) in shown"
                                :key="column.key"
                                :class="isNum(column) ? 'num' : ''"
                                :data-label="column.label">
                                <span v-if="i === 0">Total</span>
                                <span v-else-if="totals[column.key] !== undefined"
                                    :class="[totalClass(column, band.totals[column.key]), 'ui-money--strong']">
                                    {{ total(column, band.totals[column.key]) }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </template>

                <!-- And the closing below it, before the column totals. -->
                <tbody v-if="tail.length">
                    <tr v-for="(row, i) in tail" :key="`tail-${i}`" class="grid__edge">
                        <td
                            v-for="column in shown"
                            :key="column.key"
                            :data-label="column.label"
                            :class="[isNum(column) ? 'num' : '', column.class]">
                            <!-- The same branches the entries get, so a framing row
                                 does not quietly lose a badge's pill or a link's anchor
                                 the moment a statement gains such a column. An action
                                 is deliberately absent: a brought-forward balance is
                                 not a row anything is done to. -->
                            <a
                                v-if="column.type === 'link' && row[column.linkTo]"
                                :href="row[column.linkTo]"
                                class="ui-link"
                                :target="column.newTab ? '_blank' : null"
                                :rel="column.newTab ? 'noopener' : null">
                                {{ display(row, column) }}
                            </a>
                            <span v-else-if="column.type === 'badge' && display(row, column)" class="ui-badge" :data-state="row[column.key + '_key']">
                                {{ display(row, column) }}
                            </span>
                            <span v-else-if="column.type === 'money' || column.type === 'balance'" :class="moneyClass(row, column)">
                                {{ display(row, column) }}
                            </span>
                            <template v-else>{{ display(row, column) }}</template>
                        </td>
                    </tr>
                </tbody>

                <tfoot v-if="hasTotals">
                    <tr>
                        <td
                            v-for="(column, i) in shown"
                            :key="column.key"
                            :class="isNum(column) ? 'num' : ''"
                            :data-label="column.label">
                            <span v-if="i === 0">{{ query ? 'Total (filtered)' : 'Total' }}</span>
                            <span v-else-if="totals[column.key] !== undefined"
                                :class="[totalClass(column, grandTotals[column.key]), 'ui-money--strong']">
                                {{ total(column, grandTotals[column.key]) }}
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

        <FilePreview :src="preview?.src" :title="preview?.title" @close="preview = null" />
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

/* Offered quietly: these are a way to ask the table a further question, not the
   controls the screen is about. Open ones are filled in so the state of the
   table is readable at a glance from across a desk. */
.grid__groups {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2);
}

.grid__group {
    align-items: center;
    background: var(--n-000);
    border: 1px solid var(--n-300);
    border-radius: 999px;
    color: var(--n-600);
    cursor: pointer;
    display: inline-flex;
    font-size: var(--t-xs);
    font-weight: 600;
    gap: 0.25rem;
    padding: 0.2rem 0.6rem;
    white-space: nowrap;
}

.grid__group:hover {
    border-color: var(--brand-400);
    color: var(--brand-600);
}

.grid__group.is-on {
    background: var(--brand-050);
    border-color: var(--brand-500);
    color: var(--brand-700);
}

.grid__group:focus-visible {
    outline: 2px solid var(--brand-500);
    outline-offset: 2px;
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

/* Not uppercased: a band heading carries the party's ledger balance, and
   uppercasing turns "1,200.00 Cr" into "1,200.00 CR", which is not how a
   balance is written. Weight and colour separate it from the rows well enough. */
.grid__band td {
    background: var(--brand-050);
    color: var(--brand-700);
    font-size: var(--t-sm);
    font-weight: 700;
    letter-spacing: 0.01em;
}

/* A file that is closed — returned or cancelled — is still worth seeing but is
   no longer in play, so it recedes rather than competing with live work. */
.grid__table tbody tr.is-closed td {
    color: var(--n-400);
}

.grid__table tbody tr.is-closed .ui-money {
    color: var(--n-400);
}

.grid__subtotal td {
    background: var(--n-050);
    border-top: 1px solid var(--n-200);
    font-weight: 600;
}

/* The framing rows. Set apart from the entries without being shouted: they are
   context for the column beside them, not a finding. */
.grid__table tr.grid__edge td {
    background: var(--n-050, #f8fafc);
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

/*
 * Only above the width where the table is still a table. Below it every row
 * becomes a card, where a minimum width would do nothing but bring back the
 * sideways scroll the cards exist to avoid.
 */
@media (min-width: 992px) {
    .grid__table--wide {
        min-width: 72rem;
    }

    /* A name is a name. Wrapping "Kuwy Technology Service Pvt Ltd" is fine;
       wrapping it after every word because the column is 90px is not. */
    .grid__table--wide td,
    .grid__table--wide th {
        min-width: 5rem;
    }
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
