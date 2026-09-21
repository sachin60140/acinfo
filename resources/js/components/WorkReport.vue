<script setup>
/*
 * The work report, and the one thing a reader wants to do to a row of it.
 *
 * The grid is the same grid every other listing uses and is left alone; this
 * only listens for the row somebody asked about and opens the dialog over it.
 * Everything the grid is handed is named below and passed straight through, so
 * a column added to the report tomorrow needs nothing here — though a new grid
 * prop does, and VueMountTest is what says so rather than a silently dropped
 * feature.
 */
import { computed, ref } from 'vue';
import DataGrid from './DataGrid.vue';
import { vendorFilesMessage } from '../vendorShare';
import { copyText, whatsappNumber, whatsappUrl } from '../whatsapp';
import WorkUpdateDialog from './WorkUpdateDialog.vue';

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    // Bands of columns the reader can turn on; see DataGrid.
    groups: { type: Array, default: () => [] },
    title: { type: String, default: 'Export' },
    groupBy: { type: String, default: '' },
    groupLabel: { type: String, default: '' },
    totals: { type: Object, default: () => ({}) },
    perPage: { type: Number, default: 50 },
    sortable: { type: Boolean, default: true },
    sortedBy: { type: String, default: '' },
    sortedDesc: { type: Boolean, default: false },
    rowClass: { type: String, default: '' },
    emptyText: { type: String, default: 'Nothing to show.' },
    lead: { type: Array, default: () => [] },
    tail: { type: Array, default: () => [] },

    /*
     * Which report this is. Only the vendor-wise one offers to send its list:
     * a customer is never told a file went to a vendor, and a list of dispatch
     * dates and days out would tell them exactly that.
     */
    partyType: { type: String, default: '' },
    todayLabel: { type: String, default: '' },

    // What the dialog needs, and nothing the grid cares about.
    action: { type: String, default: '' },
    csrf: { type: String, default: '' },
    returnTo: { type: String, default: '' },
    jobStatuses: { type: Object, default: () => ({}) },
    approvedKey: { type: String, default: 'approval_done' },
    pendencyKey: { type: String, default: 'paper_pendency' },
    cancelledKey: { type: String, default: 'cancelled' },
    reasonKeys: { type: Array, default: () => [] },
    today: { type: String, default: '' },
});

// Split once, so the grid is handed its own props and not the dialog's.
const gridProps = computed(() => ({
    columns: props.columns,
    rows: props.rows,
    groups: props.groups,
    title: props.title,
    groupBy: props.groupBy,
    groupLabel: props.groupLabel,
    totals: props.totals,
    perPage: props.perPage,
    sortable: props.sortable,
    sortedBy: props.sortedBy,
    sortedDesc: props.sortedDesc,
    rowClass: props.rowClass,
    emptyText: props.emptyText,
    lead: props.lead,
    tail: props.tail,
}));

const editing = ref(null);

/*
 * Sending a vendor the files they are holding.
 *
 * One band is one vendor, so the buttons sit on the band's own heading and
 * send exactly the rows under it — which the grid has already narrowed by any
 * search typed above it. The vendor's number rides on those rows rather than
 * being a prop of its own, because a report can hold a dozen vendors at once.
 */
const canShare = computed(() => props.partyType === 'vendor');

const vendorOf = (band) => band.rows?.[0] ?? {};

const bandMessage = (band) => vendorFilesMessage(
    vendorOf(band).party_name || band.label,
    band.rows ?? [],
    props.todayLabel
);

const bandNumber = (band) => whatsappNumber(vendorOf(band).party_mobile);

const copiedBand = ref(null);

async function copyBand(band) {
    if (await copyText(bandMessage(band))) {
        copiedBand.value = band.label;

        setTimeout(() => {
            if (copiedBand.value === band.label) {
                copiedBand.value = null;
            }
        }, 2500);
    }
}

function sendBand(band) {
    window.open(whatsappUrl(bandNumber(band), bandMessage(band)), '_blank', 'noopener');
}

/* Said before anything is clicked, so nobody finds out where it went afterwards. */
const sendTitle = (band) => {
    const number = bandNumber(band);

    return number
        ? `Opens a chat with ${vendorOf(band).party_name} on +91 ${number.slice(2, 7)} ${number.slice(7)}. Nothing is sent until you press Send.`
        : `${vendorOf(band).party_name || 'This vendor'} has no mobile number WhatsApp can use — you will choose the chat yourself.`;
};

/*
 * A row with no works has nothing to move. It should not have offered a button
 * at all, and opening an empty dialog over the report would be a worse way of
 * saying so than the disabled cell the server sends.
 */
function onAction(row) {
    if (! row?.items?.length) {
        return;
    }

    editing.value = row;
}
</script>

<template>
    <div>
        <DataGrid v-bind="gridProps" @action="onAction">
            <template #band="{ band }">
                <span class="wr-band">
                    <span class="wr-band__label">{{ band.label }}</span>

                    <!-- type="button" so nothing around this table can ever be
                         submitted by a click meant for WhatsApp. -->
                    <span v-if="canShare && band.rows && band.rows.length" class="wr-band__share">
                        <button type="button" class="ui-btn ui-btn--sm" @click="copyBand(band)">
                            <i class="bi" :class="copiedBand === band.label ? 'bi-check2' : 'bi-clipboard'"></i>
                            {{ copiedBand === band.label ? 'Copied' : 'Copy list' }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--sm wr-band__wa"
                            :title="sendTitle(band)"
                            @click="sendBand(band)">
                            <i class="bi bi-whatsapp"></i>
                            Send on WhatsApp
                        </button>
                    </span>
                </span>
            </template>
        </DataGrid>

        <WorkUpdateDialog
            :file="editing"
            :action="action"
            :csrf="csrf"
            :return-to="returnTo"
            :statuses="jobStatuses"
            :approved-key="approvedKey"
            :pendency-key="pendencyKey"
            :cancelled-key="cancelledKey"
            :reason-keys="reasonKeys"
            :today="today"
            @close="editing = null" />
    </div>
</template>

<style>
/* The ways to send a vendor their list sit right beside their name, on the band
   that already says whose files these are.

   Beside it, not at the far end. This report is wider than the screen and
   scrolls sideways in its own box, and a band's heading spans the whole table —
   so pushed to its right edge, Send on WhatsApp landed past what was visible
   and could only be reached by scrolling the table first. */
.wr-band {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    justify-content: flex-start;
}

.wr-band__share {
    display: inline-flex;
    flex-wrap: wrap;
    gap: var(--s-2);
}

/* WhatsApp's own green, the same as on Paper Audit. */
.wr-band__wa {
    background: #25d366;
    border-color: #1ebe5b;
    color: #fff;
}

.wr-band__wa:hover {
    background: #1ebe5b;
    color: #fff;
}
</style>
