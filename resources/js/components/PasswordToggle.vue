<script setup>
/*
 * Show/hide and a caps-lock warning for a password box that Vue does not own.
 *
 * This is the login page, where the cost of a mistake is nobody being able to
 * get in. So the form stays exactly as the server rendered it — real HTML, real
 * names, real CSRF, submitting fine with JavaScript switched off — and this
 * component renders only the button beside the input, reaching the input through
 * the DOM. Mounting replaces an element's contents, so wrapping the input in a
 * component would have deleted the very thing being enhanced.
 *
 * If the bundle never loads, or this throws, the page is a plain password field
 * and login still works.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    // The id of the input to drive.
    target: { type: String, required: true },
});

const shown = ref(false);
const caps = ref(false);
const input = ref(null);

function toggle() {
    if (!input.value) {
        return;
    }

    shown.value = !shown.value;
    input.value.type = shown.value ? 'text' : 'password';

    // Focus goes back where the reader was, at the end of what they typed.
    input.value.focus();
    const end = input.value.value.length;
    input.value.setSelectionRange?.(end, end);
}

function onKey(event) {
    caps.value = event.getModifierState?.('CapsLock') ?? false;
}

function onBlur() {
    caps.value = false;
}

onMounted(() => {
    input.value = document.getElementById(props.target);

    if (!input.value) {
        return;
    }

    input.value.addEventListener('keydown', onKey);
    input.value.addEventListener('keyup', onKey);
    input.value.addEventListener('blur', onBlur);
});

onBeforeUnmount(() => {
    if (!input.value) {
        return;
    }

    input.value.removeEventListener('keydown', onKey);
    input.value.removeEventListener('keyup', onKey);
    input.value.removeEventListener('blur', onBlur);
});
</script>

<template>
    <button
        type="button"
        class="pw-toggle"
        :aria-label="shown ? 'Hide password' : 'Show password'"
        :aria-pressed="shown"
        @click="toggle">
        <i class="bi" :class="shown ? 'bi-eye-slash' : 'bi-eye'"></i>
        {{ shown ? 'Hide' : 'Show' }}
    </button>

    <!-- Caps lock is the reason for a good share of failed logins, and the
         password box shows nothing that would let anyone notice. -->
    <p v-if="caps" class="pw-caps" role="status">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Caps Lock is on
    </p>
</template>

<style>
.pw-toggle {
    background: none;
    border: 0;
    color: #4154f1;
    cursor: pointer;
    font-size: 0.8rem;
    font-weight: 600;
    margin-top: 0.35rem;
    padding: 0.25rem 0;
}

.pw-toggle:hover {
    text-decoration: underline;
}

.pw-caps {
    align-items: center;
    color: #b45309;
    display: flex;
    font-size: 0.8rem;
    gap: 0.35rem;
    margin: 0.35rem 0 0;
}
</style>
