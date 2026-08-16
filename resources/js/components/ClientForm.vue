<script setup>
/*
 * Add a client to the ledger.
 *
 * The field names are the ones AuthController::client() already validates, so
 * the form still posts normally and the server still checks every value. That
 * is what makes it safe to convert a screen on a live ledger: only the
 * rendering moves.
 *
 * Two of the fields are not description, they are credentials: a client signs
 * in with their mobile number and the password set here — UserController looks
 * them up by mobile and nothing else. The number is unique in the table, and
 * the one person who would spot a typo in either is the one it shuts out. So
 * both are given the checks the server cannot do until the form has already
 * been sent.
 */
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    indexUrl: { type: String, required: true },
    values: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
});

/*
 * What the page loaded with, and so what Reset puts back — a rejected
 * submission's own values included. The browser's own reset would empty the
 * form instead: Vue writes a field's value as a property rather than as an
 * attribute, so every box here has an empty default to fall back to.
 *
 * Neither password is among them. The server never sends one back and this
 * form keeps none, so the two boxes always start empty.
 */
const initial = {
    name: props.values.name ?? '',
    mobile_number: props.values.mobile_number ?? '',
    address: props.values.address ?? '',
    password: '',
    password_confirmation: '',
};

const form = reactive({ ...initial });

/*
 * The number is ten digits, so anything else is dropped as it is typed.
 *
 * The element's own value is corrected alongside the model: a rejected
 * character leaves the model unchanged, Vue has nothing to re-render, and the
 * character would otherwise sit in the box. This was a number input before,
 * where the ten-digit limit written on it never applied and a stray scroll of
 * the wheel over the field quietly changed the number.
 */
function onDigits(event) {
    const digits = event.target.value.replace(/\D/g, '').slice(0, 10);

    form.mobile_number = digits;
    event.target.value = digits;
}

const complete = computed(() => form.mobile_number.length === 10);

/*
 * Shown rather than masked on request. The operator is setting a password for
 * somebody else and then has to pass it on, so being able to read back what
 * was typed is the difference between a client who can sign in and a support
 * call. The confirmation box stays masked — it has nothing to prove otherwise.
 */
const revealed = ref(false);

/*
 * Caps lock survives the form being submitted and the password being written
 * down; nothing later in the process would reveal it.
 */
const capsLock = ref(false);

function onKey(event) {
    capsLock.value = event.getModifierState?.('CapsLock') ?? false;
}

/*
 * The one rule the browser cannot enforce on its own. Required and the
 * eight-character minimum are left to it, and the server checks all three
 * again regardless — this only saves the round trip.
 */
const mismatch = computed(
    () => form.password_confirmation !== '' && form.password !== form.password_confirmation
);

const matched = computed(
    () => form.password !== '' && form.password === form.password_confirmation
);

const tooShort = computed(() => form.password !== '' && form.password.length < 8);

const touched = computed(() => Object.keys(initial).some((field) => form[field] !== initial[field]));

const hint = computed(() => {
    if (mismatch.value) {
        return 'The two passwords do not match.';
    }

    if (tooShort.value) {
        return 'The password needs at least eight characters.';
    }

    if (!touched.value) {
        return 'Nothing entered yet.';
    }

    return 'The client signs in with the mobile number and password above.';
});

const nameField = ref(null);

function onReset() {
    Object.assign(form, initial);
    revealed.value = false;
    capsLock.value = false;
    nameField.value?.focus();
}

onMounted(() => {
    // The autofocus attribute is only reliable for markup the parser saw, and
    // this form is inserted after that. It matters on a re-render too: the name
    // is where you start whether the page is fresh or has just bounced back.
    nameField.value?.focus();
});
</script>

<template>
    <form class="ui client-form ui-card" :action="action" method="POST" @reset.prevent="onReset">
        <!-- Rendered here rather than passed as a slot: the component is mounted
             onto a bare element, so there is no server markup to slot in. -->
        <input type="hidden" name="_token" :value="csrf">

        <div class="ui-card__head">
            <h2 class="ui-card__title">Client Details</h2>
            <a :href="indexUrl" class="ui-btn ui-btn--sm">
                <i class="bi bi-list-ul"></i> All Clients
            </a>
        </div>

        <div class="ui-card__body cf-body">
            <div class="cf-grid">
                <div class="ui-field">
                    <label class="ui-label" for="name">Name <span class="ui-label__req">*</span></label>
                    <div class="cf-affix">
                        <span class="cf-affix__tag"><i class="bi bi-person"></i></span>
                        <input
                            id="name"
                            ref="nameField"
                            type="text"
                            name="name"
                            class="ui-input"
                            :class="{ 'ui-input--invalid': errors.name }"
                            v-model="form.name"
                            autofocus
                            required>
                    </div>
                    <div v-if="errors.name" class="ui-hint ui-hint--error">{{ errors.name }}</div>
                </div>

                <div class="ui-field">
                    <label class="ui-label" for="mobile_number">
                        Mobile Number <span class="ui-label__req">*</span>
                    </label>
                    <div class="cf-affix">
                        <span class="cf-affix__tag">+91</span>
                        <input
                            id="mobile_number"
                            type="text"
                            name="mobile_number"
                            class="ui-input"
                            :class="{ 'ui-input--invalid': errors.mobile_number }"
                            :value="form.mobile_number"
                            @input="onDigits"
                            inputmode="numeric"
                            pattern="\d{10}"
                            maxlength="10"
                            placeholder="10 digit number"
                            required>
                    </div>
                    <div class="ui-hint">
                        Also the client's username, so it has to be one no other client is on.
                    </div>
                    <div v-if="errors.mobile_number" class="ui-hint ui-hint--error">
                        {{ errors.mobile_number }}
                    </div>
                </div>

                <div class="ui-field cf-grid__wide">
                    <label class="ui-label" for="address">Address <span class="ui-label__req">*</span></label>
                    <div class="cf-affix">
                        <span class="cf-affix__tag"><i class="bi bi-geo-alt"></i></span>
                        <input
                            id="address"
                            type="text"
                            name="address"
                            class="ui-input"
                            :class="{ 'ui-input--invalid': errors.address }"
                            v-model="form.address"
                            placeholder="Shop / street, city"
                            required>
                    </div>
                    <div v-if="errors.address" class="ui-hint ui-hint--error">{{ errors.address }}</div>
                </div>
            </div>

            <hr class="cf-rule">

            <div>
                <h3 class="cf-section__title">Sign-In</h3>
                <div class="ui-hint">
                    What the client uses to open their own statement. It can be changed later
                    from the client list without touching anything else on this form.
                </div>
            </div>

            <div class="cf-grid">
                <div class="ui-field">
                    <label class="ui-label" for="password">Password <span class="ui-label__req">*</span></label>
                    <div class="cf-affix cf-affix--secret">
                        <span class="cf-affix__tag"><i class="bi bi-key"></i></span>
                        <input
                            id="password"
                            :type="revealed ? 'text' : 'password'"
                            name="password"
                            class="ui-input"
                            :class="{ 'ui-input--invalid': errors.password }"
                            v-model="form.password"
                            @keyup="onKey"
                            @keydown="onKey"
                            minlength="8"
                            maxlength="255"
                            autocomplete="new-password"
                            required>
                        <!-- Not a submit: a button inside a form defaults to one,
                             and this one only changes how the box is drawn. -->
                        <button
                            type="button"
                            class="cf-eye"
                            :aria-pressed="revealed"
                            :aria-label="revealed ? 'Hide password' : 'Show password'"
                            @click="revealed = !revealed">
                            <i :class="revealed ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                        </button>
                    </div>
                    <div class="ui-hint">At least eight characters.</div>
                    <div v-if="capsLock" class="ui-hint ui-hint--error">Caps lock is on.</div>
                    <div v-if="errors.password" class="ui-hint ui-hint--error">{{ errors.password }}</div>
                </div>

                <div class="ui-field">
                    <label class="ui-label" for="password_confirmation">
                        Confirm Password <span class="ui-label__req">*</span>
                    </label>
                    <div class="cf-affix">
                        <span class="cf-affix__tag"><i class="bi bi-key-fill"></i></span>
                        <input
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            class="ui-input"
                            :class="{ 'ui-input--invalid': mismatch }"
                            v-model="form.password_confirmation"
                            @keyup="onKey"
                            @keydown="onKey"
                            minlength="8"
                            maxlength="255"
                            autocomplete="new-password"
                            required>
                    </div>
                    <div v-if="mismatch" class="ui-hint ui-hint--error">The two passwords do not match.</div>
                    <div v-else-if="matched" class="cf-match"><i class="bi bi-check2"></i> Both match.</div>
                </div>
            </div>

            <!-- What the two credentials add up to, said plainly, while there is
                 still a form to correct them on. -->
            <div v-if="complete" class="ui-note ui-note--info">
                {{ form.name || 'This client' }} will sign in with
                <span class="cf-num">{{ form.mobile_number }}</span>
                and the password above.
            </div>
        </div>

        <div class="ui-card__foot" :class="{ 'ui-card__foot--dirty': touched && !mismatch }">
            <span class="ui-hint" :class="{ 'ui-hint--error': mismatch || tooShort }">{{ hint }}</span>
            <div class="cf-actions">
                <button type="reset" class="ui-btn">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </button>
                <button type="submit" class="ui-btn ui-btn--primary" :disabled="mismatch">
                    <i class="bi bi-check2-circle"></i> Save Client
                </button>
            </div>
        </div>
    </form>
</template>

<style>
/* Every rule hangs off the component's own root: these class names are plain
   enough that another screen could reasonably want them, and a converted screen
   must not reach outside itself. */

.client-form .cf-body {
    display: flex;
    flex-direction: column;
    gap: var(--s-5);
}

/* Two columns at desk width, one on a phone. There is no table on this screen,
   so the row-to-card rule has nothing to convert — the same job is done by the
   grid collapsing and every field keeping its own label. */
.client-form .cf-grid {
    display: grid;
    gap: var(--s-4);
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.client-form .cf-grid__wide {
    grid-column: 1 / -1;
}

/* A leading tag on a field: the +91 nobody types, the icon that says which of
   the two password boxes this is. */
.client-form .cf-affix {
    display: flex;
    min-width: 0;
}

.client-form .cf-affix__tag {
    align-items: center;
    background: var(--n-050);
    border: 1px solid var(--n-300);
    border-radius: var(--r-sm) 0 0 var(--r-sm);
    border-right: 0;
    color: var(--n-500);
    display: inline-flex;
    flex: 0 0 auto;
    font-size: var(--t-sm);
    font-weight: 600;
    justify-content: center;
    min-width: 2.6rem;
    padding: 0 var(--s-2);
}

.client-form .cf-affix .ui-input {
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
    min-width: 0;
}

/* The password box has the reveal button after it, so it keeps square corners
   on both sides and the button carries the right-hand radius. */
.client-form .cf-affix--secret .ui-input {
    border-radius: 0;
}

.client-form .cf-eye {
    align-items: center;
    background: var(--n-050);
    border: 1px solid var(--n-300);
    border-left: 0;
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
    color: var(--n-500);
    cursor: pointer;
    display: inline-flex;
    flex: 0 0 auto;
    justify-content: center;
    min-width: 2.6rem;
    padding: 0 var(--s-2);
}

.client-form .cf-eye:hover {
    background: var(--n-100);
    color: var(--ink-800);
}

.client-form .cf-rule {
    border: 0;
    border-top: 1px solid var(--n-200);
    margin: 0;
}

.client-form .cf-section__title {
    color: var(--ink-800);
    font-size: var(--t-md);
    font-weight: 700;
    margin: 0 0 var(--s-1);
}

.client-form .cf-match {
    color: var(--dr-700);
    font-size: var(--t-xs);
    font-weight: 600;
}

/* The number is read back digit by digit, so it is set like the figures are. */
.client-form .cf-num {
    font-family: var(--font-num);
    font-variant-numeric: tabular-nums;
    font-weight: 700;
}

.client-form .cf-actions {
    display: flex;
    gap: var(--s-2);
}

@media (max-width: 991.98px) {
    .client-form .cf-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .client-form .cf-actions {
        flex: 1 1 auto;
    }

    .client-form .cf-actions .ui-btn {
        flex: 1 1 auto;
    }
}

@media (pointer: coarse) {
    .client-form .cf-eye {
        min-height: var(--tap);
    }
}
</style>
