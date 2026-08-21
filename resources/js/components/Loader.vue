<script setup>
/*
 * The application-wide loading indicator.
 *
 * Shown for two things a user would otherwise experience as the page having
 * frozen: a form submission that is still with the server, and a navigation
 * that has not painted yet. It deliberately waits a moment before appearing —
 * a spinner that flashes up for 80ms reads as a glitch, not as progress.
 */
import { onMounted, onBeforeUnmount, ref } from 'vue';

const visible = ref(false);
const message = ref('Working…');

let showTimer = null;

const APPEAR_AFTER_MS = 250;

function show(text) {
    message.value = text || 'Working…';

    if (showTimer) {
        return;
    }

    showTimer = window.setTimeout(() => {
        visible.value = true;
    }, APPEAR_AFTER_MS);
}

function hide() {
    window.clearTimeout(showTimer);
    showTimer = null;
    visible.value = false;
}

function onSubmit(event) {
    const form = event.target;

    // Cancelled by the page itself, so nothing is going anywhere. See the note
    // on the listeners below.
    if (event.defaultPrevented) {
        return;
    }

    // A form that failed the browser's own validation never left the page.
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
        return;
    }

    show(form.dataset.loading);
}

function onNavigate(event) {
    /*
     * A link the page handles itself is not a navigation.
     *
     * The approval screenshot opens in a dialog over the list, so its click is
     * cancelled and nothing unloads — and nothing ever took the overlay back
     * down. Closing the dialog left the spinner sitting over the page.
     */
    if (event.defaultPrevented) {
        return;
    }

    const link = event.target.closest('a');

    if (!link) {
        return;
    }

    const href = link.getAttribute('href');
    const external = link.target === '_blank' || link.hasAttribute('download');
    const inPage = !href || href.startsWith('#') || href.startsWith('javascript:');

    if (external || inPage || event.metaKey || event.ctrlKey || event.shiftKey) {
        return;
    }

    /*
     * Only a real page navigation raises the overlay.
     *
     * tel:, mailto:, sms: and friends hand off to the operating system and
     * leave this page exactly where it was — nothing unloads, so nothing ever
     * takes the overlay back down. Tapping a customer's phone number on the
     * party list locked the screen until it was reloaded.
     *
     * link.protocol is the browser's own resolution of the href, so this holds
     * for relative URLs too.
     */
    if (link.protocol !== 'http:' && link.protocol !== 'https:') {
        return;
    }

    show(link.dataset.loading);
}

onMounted(() => {
    /*
     * Bubble, not capture. In capture these run before the element's own
     * handler, so a click the page is about to cancel still looks like a
     * navigation — which is exactly how the overlay came to be raised for one
     * that never happened.
     */
    document.addEventListener('submit', onSubmit);
    document.addEventListener('click', onNavigate);
    // Coming back via the browser's back button restores the old page from
    // cache with the overlay still up, so it has to be cleared explicitly.
    window.addEventListener('pageshow', hide);
    window.addEventListener('pagehide', hide);

    /*
     * A swapped screen never unloads the page, so pageshow and pagehide do not
     * fire and nothing would ever take the overlay down again. These are the
     * equivalent pair for a navigation that happens inside the page.
     */
    document.addEventListener('acinfo:visit-start', onVisitStart);
    document.addEventListener('acinfo:visit-end', hide);

    window.acinfoLoader = { show, hide };
});

function onVisitStart() {
    show();
}

onBeforeUnmount(() => {
    document.removeEventListener('submit', onSubmit);
    document.removeEventListener('click', onNavigate);
    window.removeEventListener('pageshow', hide);
    window.removeEventListener('pagehide', hide);
    document.removeEventListener('acinfo:visit-start', onVisitStart);
    document.removeEventListener('acinfo:visit-end', hide);
    hide();
});
</script>

<template>
    <Transition name="loader-fade">
        <div v-if="visible" class="app-loader" role="status" aria-live="polite">
            <div class="app-loader__card">
                <svg class="app-loader__spinner" viewBox="0 0 50 50" aria-hidden="true">
                    <circle class="app-loader__track" cx="25" cy="25" r="20" fill="none" stroke-width="5" />
                    <circle class="app-loader__arc" cx="25" cy="25" r="20" fill="none" stroke-width="5" />
                </svg>
                <span class="app-loader__text">{{ message }}</span>
            </div>
        </div>
    </Transition>
</template>

<style>
.app-loader {
    align-items: center;
    background: rgba(15, 23, 42, 0.32);
    backdrop-filter: blur(2px);
    display: flex;
    inset: 0;
    justify-content: center;
    position: fixed;
    z-index: 2000;
}

.app-loader__card {
    align-items: center;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 18px 48px rgba(1, 41, 112, 0.22);
    display: flex;
    gap: 0.85rem;
    padding: 1rem 1.4rem;
}

.app-loader__spinner {
    height: 34px;
    width: 34px;
    animation: app-loader-rotate 1.6s linear infinite;
}

.app-loader__track {
    stroke: #e9eef5;
}

/* The arc grows and shrinks as it turns, so the spinner reads as progress
   rather than as a shape sliding round at a constant rate. */
.app-loader__arc {
    stroke: #4154f1;
    stroke-linecap: round;
    animation: app-loader-dash 1.4s ease-in-out infinite;
}

.app-loader__text {
    color: #012970;
    font-size: 0.92rem;
    font-weight: 700;
}

@keyframes app-loader-rotate {
    to { transform: rotate(360deg); }
}

@keyframes app-loader-dash {
    0%   { stroke-dasharray: 1, 150; stroke-dashoffset: 0; }
    50%  { stroke-dasharray: 90, 150; stroke-dashoffset: -35; }
    100% { stroke-dasharray: 90, 150; stroke-dashoffset: -124; }
}

.loader-fade-enter-active,
.loader-fade-leave-active {
    transition: opacity 0.18s ease;
}

.loader-fade-enter-from,
.loader-fade-leave-to {
    opacity: 0;
}

/* Someone who has asked for less motion gets a still indicator, not a
   flashing one. */
@media (prefers-reduced-motion: reduce) {
    .app-loader__spinner,
    .app-loader__arc {
        animation: none;
    }

    .app-loader__arc {
        stroke-dasharray: 90, 150;
    }
}
</style>
