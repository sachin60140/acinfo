<script setup>
/*
 * The approval document, over the page rather than instead of it.
 *
 * Checking a screenshot is a glance — is this the right vehicle, is the date
 * the one on the file — and it happens while reading a list that is filtered
 * and scrolled to a particular row. Opening the image as a page threw all of
 * that away and left the reader to find their way back to it.
 *
 * Images are shown; a PDF is given the browser's own viewer in a frame, which
 * is the one thing that reliably renders one everywhere.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    // The document to show, or null for closed. One prop, so a parent needs a
    // single ref to drive this.
    src: { type: String, default: null },
    title: { type: String, default: 'Approval screenshot' },
});

const emit = defineEmits(['close']);

const open = computed(() => Boolean(props.src));

const isPdf = computed(() => /\.pdf(\?|#|$)/i.test(props.src ?? ''));

// A document that will not load says so, rather than leaving an empty box that
// reads as the page having broken.
const broken = ref(false);

const closeButton = ref(null);

function close() {
    emit('close');
}

function onKey(event) {
    if (event.key === 'Escape') {
        close();
    }
}

/*
 * The key listener and the scroll lock live only while this is open. Left
 * bound, Escape would close a dialog that is not on screen, and the page
 * behind would stay locked after it went.
 *
 * Tracked rather than assumed: the watcher runs immediately, so a dialog
 * mounted already open is bound too — lazily it was not, and such a dialog had
 * no Escape and no lock. And on the way out only a lock this component set is
 * released, so mounting a closed one cannot free a page something else locked.
 */
let locked = false;

function bind() {
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    locked = true;
}

function unbind() {
    document.removeEventListener('keydown', onKey);

    if (locked) {
        document.body.style.overflow = '';
        locked = false;
    }
}

watch(open, (isOpen) => {
    if (! isOpen) {
        unbind();

        return;
    }

    broken.value = false;
    bind();

    // Focus lands on the way out, so Escape is not the only way back.
    window.requestAnimationFrame(() => closeButton.value?.focus());
}, { immediate: true });

onBeforeUnmount(unbind);
</script>

<template>
    <!-- Teleported to the body: inside a table cell it would be clipped by the
         grid's own overflow, and sit under anything with a stacking context. -->
    <Teleport to="body">
        <div
            v-if="open"
            class="preview"
            role="dialog"
            aria-modal="true"
            :aria-label="title"
            @click.self="close">
            <div class="preview__card">
                <div class="preview__head">
                    <span class="preview__title">{{ title }}</span>
                    <button
                        ref="closeButton"
                        type="button"
                        class="preview__close"
                        aria-label="Close"
                        @click="close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="preview__body">
                    <div v-if="broken" class="preview__missing">
                        <i class="bi bi-exclamation-triangle"></i>
                        <p>This document could not be loaded. It may have been moved or removed from the server.</p>
                    </div>

                    <iframe
                        v-else-if="isPdf"
                        class="preview__frame"
                        :src="src"
                        :title="title"
                        @error="broken = true"></iframe>

                    <img
                        v-else
                        class="preview__image"
                        :src="src"
                        :alt="title"
                        @error="broken = true">
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style>
.preview {
    align-items: center;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(2px);
    display: flex;
    inset: 0;
    justify-content: center;
    padding: var(--s-4, 1rem);
    position: fixed;
    /* Above the loading overlay, which sits at 2000: a document opened while a
       navigation is in flight must not end up behind the spinner. */
    z-index: 2100;
}

.preview__card {
    background: var(--n-000, #fff);
    border-radius: var(--r-lg, 12px);
    box-shadow: 0 18px 48px rgba(1, 41, 112, 0.28);
    display: flex;
    flex-direction: column;
    max-height: 92vh;
    max-width: 62rem;
    overflow: hidden;
    width: 100%;
}

.preview__head {
    align-items: center;
    border-bottom: 1px solid var(--n-200, #e5e9f2);
    display: flex;
    gap: var(--s-3, 0.75rem);
    justify-content: space-between;
    padding: var(--s-3, 0.75rem) var(--s-4, 1rem);
}

.preview__title {
    color: var(--n-900, #0f172a);
    font-weight: 700;
}

.preview__close {
    background: none;
    border: 0;
    border-radius: var(--r-sm, 5px);
    color: var(--n-600, #475569);
    cursor: pointer;
    font-size: 1rem;
    /* The whole point of a close button is that it is easy to hit. */
    height: 2.25rem;
    width: 2.25rem;
}

.preview__close:hover {
    background: var(--n-100, #f1f5f9);
    color: var(--n-900, #0f172a);
}

.preview__close:focus-visible {
    outline: 2px solid var(--brand-500, #4154f1);
    outline-offset: 2px;
}

/*
 * A thumb, not a mouse pointer. The design system sets 44px as the smallest
 * anything interactive may be on touch, and a 36px cross in the corner of a
 * dialog is exactly the control that rule exists for.
 */
@media (pointer: coarse) {
    .preview__close {
        height: var(--tap);
        width: var(--tap);
    }
}

.preview__body {
    align-items: center;
    background: var(--n-050, #f8fafc);
    display: flex;
    justify-content: center;
    min-height: 12rem;
    overflow: auto;
    padding: var(--s-3, 0.75rem);
}

/* Scaled to fit the card rather than the page: a phone screenshot is taller
   than it is wide and would otherwise need scrolling to read at all. */
.preview__image {
    display: block;
    max-height: 78vh;
    max-width: 100%;
    object-fit: contain;
}

.preview__frame {
    border: 0;
    height: 78vh;
    width: 100%;
}

.preview__missing {
    color: var(--n-600, #475569);
    padding: var(--s-6, 1.5rem);
    text-align: center;
}

.preview__missing i {
    color: var(--warn-500, #f59e0b);
    display: block;
    font-size: 1.75rem;
    margin-bottom: var(--s-2, 0.5rem);
}

.preview__missing p {
    margin: 0;
    max-width: 32ch;
}

@media (max-width: 575.98px) {
    .preview {
        padding: 0;
    }

    .preview__card {
        border-radius: 0;
        max-height: 100vh;
        height: 100%;
    }

    .preview__image {
        max-height: none;
    }

    .preview__frame {
        height: 70vh;
    }
}
</style>
