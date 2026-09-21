<script setup>
/*
 * A card that can be folded away, and says what it is holding while it is shut.
 *
 * For the parts of a long form that most visits never touch. The file form put
 * its fields, its works, its expenses and its documents in one card and asked
 * the operator to scroll past all four to reach the one they came for; folded,
 * the three they did not come for cost a line each and say how much is inside.
 *
 * Two rules make it safe to fold a form up.
 *
 * The body is hidden with v-show and never v-if. These sections carry inputs
 * that post: removed from the DOM they would take their values with them, and a
 * price typed before something was folded would silently stop being saved.
 *
 * And a section holding an error opens itself, whatever anyone folded. A form
 * that will not say why it refused to save is worse than a long one.
 */
import { computed, ref, watch } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    // A line under the title, always shown: what this section is for.
    hint: { type: String, default: '' },
    /*
     * What the section says about itself while it is shut — "3 PDFs", "2
     * expenses · ₹450". A fold that hides the fact there is anything inside is
     * how a document nobody knew about stays unnoticed for a year.
     */
    summary: { type: String, default: '' },
    // Open unless this browser remembers otherwise.
    open: { type: Boolean, default: true },
    // Where to remember it. Left blank, the fold lasts only this visit.
    remember: { type: String, default: '' },
    /* Something inside went wrong, or needs answering. Opens and stays open. */
    forceOpen: { type: Boolean, default: false },
});

/*
 * Read through a try: storage throws rather than coming back empty in a private
 * window, and a section that will not draw is a far worse answer than one that
 * opens when it was last left shut.
 */
function remembered() {
    if (! props.remember) {
        return null;
    }

    try {
        const saved = localStorage.getItem(`acinfo.section.${props.remember}`);

        if (saved === 'open' || saved === 'shut') {
            return saved === 'open';
        }
    } catch {
        // Nothing remembered. The default stands.
    }

    return null;
}

const chosen = ref(remembered() ?? props.open);

const isOpen = computed(() => props.forceOpen || chosen.value);

function toggle() {
    // An error is holding it open. Folding it away would hide the error.
    if (props.forceOpen) {
        return;
    }

    chosen.value = ! chosen.value;

    if (! props.remember) {
        return;
    }

    try {
        localStorage.setItem(`acinfo.section.${props.remember}`, chosen.value ? 'open' : 'shut');
    } catch {
        // It will not be remembered past this visit. It still works on it.
    }
}

/*
 * Opened by something else on the page — a button that says where the thing
 * it points at is. For this visit only: what the reader chose to fold stays
 * their choice the next time.
 */
function show() {
    chosen.value = true;
}

defineExpose({ show });

/*
 * A section forced open by an error stays open once the error is answered,
 * rather than folding itself away under the reader's hands.
 */
watch(() => props.forceOpen, (now) => {
    if (now) {
        chosen.value = true;
    }
});
</script>

<template>
    <section class="ui-card sect" :class="{ 'is-shut': ! isOpen }">
        <div class="ui-card__head sect__head">
            <button
                type="button"
                class="sect__toggle"
                :aria-expanded="isOpen"
                :disabled="forceOpen"
                @click="toggle">
                <i class="bi sect__chev" :class="isOpen ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                <span class="sect__title">{{ title }}</span>
                <!-- Said only while it is shut: open, the section speaks for itself. -->
                <span v-if="! isOpen && summary" class="sect__summary">{{ summary }}</span>
            </button>

            <div class="sect__aside">
                <slot name="aside"></slot>
            </div>
        </div>

        <div v-show="isOpen" class="ui-card__body">
            <div v-if="hint" class="ui-hint sect__hint">{{ hint }}</div>
            <slot></slot>
        </div>
    </section>
</template>

<style>
.sect__head {
    gap: var(--s-3);
}

/* The whole heading is the control, so the target is a heading's width rather
   than a chevron's. A fold nobody can hit is a fold nobody uses. */
.sect__toggle {
    align-items: baseline;
    background: none;
    border: 0;
    cursor: pointer;
    display: flex;
    flex: 1 1 auto;
    gap: var(--s-2);
    min-width: 0;
    padding: 0;
    text-align: left;
}

.sect__toggle[disabled] {
    cursor: default;
}

.sect__chev {
    color: var(--n-500);
    font-size: var(--t-sm);
}

.sect__title {
    color: var(--ink-800);
    font-size: var(--t-md);
    font-weight: 700;
}

/* What is inside, for a reader who is not opening it. Quiet, and on one line:
   it is a label for a closed drawer, not a second heading. */
.sect__summary {
    color: var(--n-500);
    font-size: var(--t-sm);
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sect__summary::before {
    content: '·';
    margin-right: var(--s-2);
}

.sect__aside {
    align-items: center;
    display: flex;
    flex: none;
    gap: var(--s-2);
}

.sect__hint {
    margin-bottom: var(--s-3);
}

.sect__toggle:focus-visible {
    border-radius: var(--r-sm);
    outline: 2px solid var(--brand-500);
    outline-offset: 3px;
}

/* A shut card is a line, so the head's own padding is all the height it has. */
.sect.is-shut .ui-card__head {
    border-bottom: 0;
}
</style>
