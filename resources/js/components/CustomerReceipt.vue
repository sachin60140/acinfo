<script setup>
/*
 * A receipt for the payment just saved, to send the customer on WhatsApp.
 *
 * Shown once, beside the "saved" message, after a customer credit paid in
 * money — the server decides that and leaves this out otherwise. Nothing is
 * sent from here: WhatsApp opens with the receipt filled in and the office
 * presses Send.
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
});

const text = computed(() => receiptMessage(props));
</script>

<template>
    <div v-if="text" class="customer-receipt">
        <span class="customer-receipt__what">
            <i class="bi bi-receipt"></i>
            {{ money(amount) }} received from {{ name }}
        </span>
        <WhatsAppShare :text="text" :mobile="mobile" :name="name" send-label="Send receipt on WhatsApp" copy-label="Copy receipt" />
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
