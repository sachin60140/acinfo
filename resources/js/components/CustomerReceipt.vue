<script setup>
/*
 * A receipt for the payment just saved, to send the customer on WhatsApp —
 * or word of a set-off, a discount or a reversal on their account.
 *
 * Shown once, beside the "saved" or "reversed" message — the server decides
 * when, and leaves this out otherwise. Nothing is sent from here: WhatsApp
 * opens with the message filled in and the office presses Send.
 */
import { computed } from 'vue';
import WhatsAppShare from './WhatsAppShare.vue';
import { receiptMessage } from '../customerShare';
import { money } from '../money';

const props = defineProps({
    name: { type: String, required: true },
    mobile: { type: String, default: '' },
    amount: { type: Number, required: true },
    dateLabel: { type: String, default: '' },
    mode: { type: String, default: '' },
    reference: { type: String, default: '' },
    // Today's, signed: positive when they still owe.
    balance: { type: Number, default: 0 },
    todayLabel: { type: String, default: '' },
    // The files it was adjusted against: [{ label, amount }].
    against: { type: Array, default: () => [] },
    // 'payment', 'setoff' for what they owe cleared against what they are
    // owed, 'writeoff' for a discount, or 'reversal' for an entry taken back.
    kind: { type: String, default: 'payment' },
    // The entry a reversal took back, as their statement numbers it.
    entryNo: { type: Number, default: 0 },
});

const text = computed(() => receiptMessage(props));

// Only a payment's is a receipt.
const what = computed(() => (props.kind === 'payment' ? 'receipt' : 'message'));
</script>

<template>
    <div v-if="text" class="customer-receipt">
        <span class="customer-receipt__what">
            <i class="bi bi-receipt"></i>
            <template v-if="kind === 'setoff'">{{ money(amount) }} set off for {{ name }}</template>
            <template v-else-if="kind === 'writeoff'">{{ money(amount) }} written off for {{ name }}</template>
            <template v-else-if="kind === 'reversal'">Entry #{{ entryNo }} reversed for {{ name }}</template>
            <template v-else>{{ money(amount) }} received from {{ name }}</template>
        </span>
        <WhatsAppShare :text="text" :mobile="mobile" :name="name" :send-label="`Send ${what} on WhatsApp`" :copy-label="`Copy ${what}`" />
    </div>
</template>

<style>
.customer-receipt {
    align-items: center;
    background: var(--dr-050);
    border: 1px solid var(--n-200);
    border-radius: var(--r-md);
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    margin-bottom: var(--s-3);
    padding: var(--s-2) var(--s-3);
}

.customer-receipt__what {
    color: var(--ink-700);
    font-weight: 600;
}
</style>
