<script setup>
/*
 * Set a client's login password.
 *
 * The field names are the ones AuthController::clientpassword() already
 * validates, so the form still posts normally and the server still checks every
 * value. That is what makes it safe to convert a screen on a live ledger: only
 * the rendering moves.
 *
 * Nothing here decides whether a password is acceptable — the server does. But
 * a rejected submission comes back with both boxes empty, because Laravel never
 * flashes a password into the old input, so the whole thing has to be typed
 * again to correct a typo nobody could see. That is why the rules the server
 * applies are shown here while there is still something to correct, and why the
 * two boxes can be read rather than only counted.
 */
import { computed, reactive, ref, watchEffect } from 'vue';

const props = defineProps({
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    cancelUrl: { type: String, required: true },
    clientName: { type: String, default: '' },
    clientMobile: { type: String, default: '' },
    hasPassword: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

/*
 * The lengths AuthController::clientpassword() validates. The markup this
 * replaced carried the 8 as well; if the rule there moves, move it here — the
 * server is what actually enforces either of them.
 */
const MIN = 8;
const MAX = 255;

const form = reactive({
    password: '',
    password_confirmation: '',
});

const reveal = ref(false);

const shortfall = computed(() =>
    form.password === '' ? 0 : Math.max(0, MIN - form.password.length)
);

/*
 * No maxlength attribute to go with this: the attribute would silently drop the
 * characters past the limit and save a password the operator never typed, which
 * is worse than the server saying no.
 */
const tooLong = computed(() => form.password.length > MAX);

const lengthHint = computed(() => {
    if (tooLong.value) {
        return `Too long — ${MAX} characters at most.`;
    }

    if (shortfall.value) {
        return `At least ${MIN} characters — ${shortfall.value} more to go.`;
    }

    return `At least ${MIN} characters.`;
});

const disagrees = computed(
    () => form.password_confirmation !== '' && form.password_confirmation !== form.password
);

/*
 * Someone retyping the same string is mid-word for every keystroke but the
 * last, so the confirmation is only called wrong once it has stopped being the
 * start of the password. A box that turns red on every letter stops being read.
 */
const settling = computed(
    () => disagrees.value && form.password.startsWith(form.password_confirmation)
);

const agreement = computed(() => {
    if (form.password_confirmation === '' || settling.value) {
        return { tone: 'quiet', text: 'Type it again to confirm.' };
    }

    if (!disagrees.value) {
        return { tone: 'ok', text: 'Both entries match.' };
    }

    return { tone: 'error', text: 'The two entries do not match.' };
});

const confirmBox = ref(null);

/*
 * The mismatch is reported through the browser's own validity rather than by
 * disabling the button. This form is already guarded natively — required, and
 * the minimum length — so refusing a mismatch the same way keeps one mechanism
 * instead of two, puts the message on the box it belongs to, and leaves a
 * disabled button from meaning something different here than elsewhere.
 *
 * It is the server's `confirmed` rule that decides; this only saves a round
 * trip that would come back with both boxes cleared.
 */
watchEffect(() => {
    confirmBox.value?.setCustomValidity(disagrees.value ? 'The two passwords do not match.' : '');
});

/*
 * Caps Lock is the usual explanation for a password that was typed twice,
 * agreed with itself, saved, and then did not work at the client's end.
 */
const capsLock = ref(false);

function onKey(event) {
    // Absent on some mobile keyboards, where there is nothing to warn about.
    if (typeof event.getModifierState === 'function') {
        capsLock.value = event.getModifierState('CapsLock');
    }
}
</script>

<template>
    <form class="ui cp" :action="action" method="POST">
        <!-- Rendered here rather than passed as a slot: the component is mounted
             onto a bare element, so there is no server markup to slot in. -->
        <input type="hidden" name="_token" :value="csrf">

        <div class="cp-who">
            <span class="cp-who__icon"><i class="bi bi-person"></i></span>
            <div class="cp-who__id">
                <div class="ui-lead">{{ clientName }}</div>
                <div class="ui-sub">{{ clientMobile || 'No mobile number on record' }}</div>
            </div>
            <span class="ui-badge" :class="hasPassword ? 'ui-badge--neutral' : 'ui-badge--warn'">
                {{ hasPassword ? 'Password set' : 'No password yet' }}
            </span>
        </div>

        <!-- What saving actually does, said before the boxes rather than after:
             one of these two is a client locked out of a working login. -->
        <div class="ui-note" :class="hasPassword ? 'ui-note--warn' : 'ui-note--info'">
            <template v-if="hasPassword">
                This replaces the password the client is signing in with now.
            </template>
            <template v-else>
                The client signs in with the mobile number above and this password, and cannot
                sign in until one is set.
            </template>
        </div>

        <div class="ui-field">
            <div class="cp-labelrow">
                <label class="ui-label" for="password">Password <span class="ui-label__req">*</span></label>
                <!-- One toggle for both boxes: what it is for is reading the two
                     against each other, not either on its own. -->
                <button
                    type="button"
                    class="ui-btn ui-btn--ghost ui-btn--sm"
                    :aria-pressed="reveal"
                    @click="reveal = !reveal">
                    <i :class="reveal ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                    {{ reveal ? 'Hide' : 'Show' }}
                </button>
            </div>
            <input
                id="password"
                type="password"
                name="password"
                class="ui-input"
                :class="{ 'ui-input--invalid': errors.password || tooLong }"
                :type="reveal ? 'text' : 'password'"
                v-model="form.password"
                :minlength="MIN"
                required
                autocomplete="new-password"
                autocapitalize="off"
                spellcheck="false"
                @keydown="onKey"
                @keyup="onKey"
                @blur="capsLock = false">
            <div class="ui-hint" :class="{ 'ui-hint--error': tooLong || shortfall }">{{ lengthHint }}</div>
            <div v-if="errors.password" class="ui-hint ui-hint--error">{{ errors.password }}</div>
        </div>

        <div class="ui-field">
            <label class="ui-label" for="password_confirmation">
                Confirm Password <span class="ui-label__req">*</span>
            </label>
            <!-- Revealed, the box becomes a text field, where a phone keyboard
                 will capitalise the first letter and correct the rest. -->
            <input
                id="password_confirmation"
                ref="confirmBox"
                type="password"
                name="password_confirmation"
                class="ui-input"
                :class="{ 'ui-input--invalid': agreement.tone === 'error' }"
                :type="reveal ? 'text' : 'password'"
                v-model="form.password_confirmation"
                :minlength="MIN"
                required
                autocomplete="new-password"
                autocapitalize="off"
                spellcheck="false"
                @keydown="onKey"
                @keyup="onKey"
                @blur="capsLock = false">
            <div
                class="ui-hint"
                :class="{ 'ui-hint--error': agreement.tone === 'error', 'cp-ok': agreement.tone === 'ok' }"
                aria-live="polite">
                <i v-if="agreement.tone === 'ok'" class="bi bi-check2"></i>{{ agreement.text }}
            </div>
            <div v-if="errors.password_confirmation" class="ui-hint ui-hint--error">
                {{ errors.password_confirmation }}
            </div>
        </div>

        <div v-if="capsLock" class="ui-note ui-note--warn cp-caps">
            <i class="bi bi-capslock"></i> Caps Lock is on.
        </div>

        <div class="cp-foot">
            <a :href="cancelUrl" class="ui-btn">Cancel</a>
            <button type="submit" class="ui-btn ui-btn--primary">
                <i class="bi bi-check2-circle"></i> Update Password
            </button>
        </div>
    </form>
</template>

<style>
/* A stack at every width. There is no table on this screen, so the row-to-card
   rule has nothing to convert — the form is one narrow column on a phone and on
   a desk, and every field keeps its own label. */
.cp {
    display: flex;
    flex-direction: column;
    gap: var(--s-4);
}

/* Whose password this is, kept in front of the boxes: the screen is reached
   from a list of clients, and there is nothing else on it to say which row was
   clicked. */
.cp-who {
    align-items: center;
    background: var(--n-025);
    border: 1px solid var(--n-200);
    border-radius: var(--r-md);
    display: flex;
    gap: var(--s-3);
    padding: var(--s-3);
}

.cp-who__icon {
    align-items: center;
    background: var(--brand-050);
    border-radius: var(--r-pill);
    color: var(--brand-500);
    display: inline-flex;
    flex: 0 0 auto;
    height: 2.25rem;
    justify-content: center;
    width: 2.25rem;
}

.cp-who__id {
    flex: 1 1 auto;
    min-width: 0;
}

.cp-labelrow {
    align-items: center;
    display: flex;
    gap: var(--s-2);
    justify-content: space-between;
}

/* The agreed state, in the same green .ui-note--ok uses for anything settled.
   Nothing on this screen is money, so the debit colour carries no other
   meaning here. */
.cp-ok {
    color: var(--dr-700);
    font-weight: 600;
}

.cp-ok .bi {
    margin-right: var(--s-1);
}

.cp-caps {
    align-items: center;
    display: flex;
    gap: var(--s-2);
}

.cp-foot {
    align-items: center;
    border-top: 1px solid var(--n-200);
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2);
    justify-content: flex-end;
    padding-top: var(--s-4);
}

@media (max-width: 991.98px) {
    .cp-foot .ui-btn {
        flex: 1 1 auto;
    }
}
</style>
