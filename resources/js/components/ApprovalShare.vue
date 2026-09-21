<script setup>
/*
 * Work just approved, and a message for each customer to be told.
 *
 * Shown once, on the page the save came back to, one line per file approved
 * in that save — a board can approve work on several files at once, each for
 * its own customer. Nothing is sent from here: WhatsApp opens with the message
 * filled in and the office presses Send.
 */
import WhatsAppShare from './WhatsAppShare.vue';
import { approvalMessage } from '../customerShare';

const props = defineProps({
    files: { type: Array, required: true },
    todayLabel: { type: String, default: '' },
});
</script>

<template>
    <div class="approval-share">
        <div v-for="file in files" :key="file.id" class="approval-share__row">
            <span class="approval-share__what">
                <i class="bi bi-patch-check"></i>
                Approved: {{ [file.fileNo, file.vehicle].filter(Boolean).join(' · ') }}
                <span class="approval-share__who">— tell {{ file.customer }}?</span>
            </span>
            <WhatsAppShare
                :text="approvalMessage(file, props.todayLabel)"
                :mobile="file.mobile"
                :name="file.customer"
                send-label="Send approval on WhatsApp" />
        </div>
    </div>
</template>

<style>
.approval-share {
    display: grid;
    gap: var(--s-2);
    margin-bottom: var(--s-3);
}

.approval-share__row {
    align-items: center;
    background: var(--dr-050);
    border: 1px solid var(--n-200);
    border-radius: var(--r-md);
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    padding: var(--s-2) var(--s-3);
}

.approval-share__what {
    color: var(--ink-700);
    font-weight: 600;
}

.approval-share__who {
    color: var(--n-500);
    font-weight: 400;
}
</style>
