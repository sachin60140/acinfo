<script setup>
/*
 * Copy a message, or open WhatsApp with it filled in.
 *
 * The office presses Send itself, every time: this opens a chat and fills the
 * message in, and nothing leaves until a person has read it. Said on the button
 * before it is clicked, along with whose number the chat opens on.
 */
import { computed, ref } from 'vue';
import { copyText, whatsappNumber, whatsappUrl } from '../whatsapp';

const props = defineProps({
    text: { type: String, default: '' },
    mobile: { type: String, default: '' },
    // Whose chat it is, for the button's tooltip.
    name: { type: String, default: '' },
    sendLabel: { type: String, default: 'Send on WhatsApp' },
    copyLabel: { type: String, default: 'Copy message' },
});

const number = computed(() => whatsappNumber(props.mobile));

const sendTitle = computed(() => (number.value
    ? `Opens a chat with ${props.name || 'them'} on +91 ${number.value.slice(2, 7)} ${number.value.slice(7)}. Nothing is sent until you press Send.`
    : `${props.name || 'They'} ${props.name ? 'has' : 'have'} no mobile number WhatsApp can use — you will choose the chat yourself.`));

const copied = ref(false);

async function copy() {
    if (await copyText(props.text)) {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2500);
    }
}

function send() {
    window.open(whatsappUrl(number.value, props.text), '_blank', 'noopener');
}
</script>

<template>
    <!-- type="button" so nothing around these can ever be submitted by a click
         meant for WhatsApp. -->
    <span v-if="text" class="wa-share">
        <button type="button" class="ui-btn ui-btn--sm" @click="copy">
            <i class="bi" :class="copied ? 'bi-check2' : 'bi-clipboard'"></i>
            {{ copied ? 'Copied' : copyLabel }}
        </button>
        <button type="button" class="ui-btn ui-btn--sm wa-share__send" :title="sendTitle" @click="send">
            <i class="bi bi-whatsapp"></i>
            {{ sendLabel }}
        </button>
    </span>
</template>

<style>
.wa-share {
    display: inline-flex;
    flex-wrap: wrap;
    gap: var(--s-2);
}

/* WhatsApp's own green, the same as on Paper Audit and the Work Report. */
.wa-share__send {
    background: #25d366;
    border-color: #1ebe5b;
    color: #fff;
}

.wa-share__send:hover {
    background: #1ebe5b;
    color: #fff;
}
</style>
