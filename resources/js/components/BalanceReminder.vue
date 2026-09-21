<script setup>
/*
 * A reminder of what a customer owes, from their statement.
 *
 * Offered only while they owe something: the server leaves this out for a
 * customer who is settled or in credit, and the message itself refuses to be
 * written about nothing.
 */
import { computed } from 'vue';
import WhatsAppShare from './WhatsAppShare.vue';
import { balanceMessage } from '../customerShare';
import { money } from '../money';

const props = defineProps({
    name: { type: String, required: true },
    mobile: { type: String, default: '' },
    balance: { type: Number, required: true },
    todayLabel: { type: String, default: '' },
});

const text = computed(() => balanceMessage(props.name, props.balance, props.todayLabel));
</script>

<template>
    <div v-if="text" class="balance-reminder">
        <span class="balance-reminder__what">
            <i class="bi bi-bell"></i>
            {{ money(balance) }} owing
        </span>
        <WhatsAppShare :text="text" :mobile="mobile" :name="name" send-label="Send balance reminder" />
    </div>
</template>

<style>
.balance-reminder {
    align-items: center;
    background: var(--warn-050);
    border: 1px solid var(--n-200);
    border-radius: var(--r-md);
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2) var(--s-4);
    margin-bottom: var(--s-3);
    padding: var(--s-2) var(--s-3);
}

.balance-reminder__what {
    color: var(--ink-700);
    font-weight: 600;
}
</style>
