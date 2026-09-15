<script setup>
/*
 * Moving one file along, over the report rather than instead of it.
 *
 * The question a report raises is nearly always about one row — this file has
 * been at Paper Pendency for a fortnight, what happened — and answering it used
 * to mean leaving a report that was filtered to one customer and a date range,
 * finding the file on the status board, and setting all of that up again to get
 * back.
 *
 * It posts to WorkFileController::status() with the field names that screen
 * already uses, as a real multipart form. Nothing about the rules is restated
 * here: the screenshot an approval needs, the date it needs, the reason a
 * cancellation needs, are all still the server's to insist on, and this screen
 * cannot drift from the board because neither decides anything.
 *
 * Statuses belong to works, not to folders. A file of three works is three
 * rows, because a transfer can be approved on Tuesday and the hypothecation
 * addition still be pending on Friday.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    // The row being updated, or null for closed.
    file: { type: Object, default: null },
    action: { type: String, required: true },
    csrf: { type: String, required: true },
    // Where the server should send the reader back to: this report, as it was.
    returnTo: { type: String, default: '' },
    // { key: label } — what a work may be moved to, from the model.
    statuses: { type: Object, default: () => ({}) },
    approvedKey: { type: String, default: 'approval_done' },
    // The states the server refuses without a reason.
    reasonKeys: { type: Array, default: () => ['cancelled', 'paper_returned'] },
    today: { type: String, default: '' },
});

const emit = defineEmits(['close']);

const open = computed(() => Boolean(props.file));

const works = computed(() => props.file?.items ?? []);

/*
 * What the form holds, rebuilt whenever a different file is opened. Keyed by
 * work id, which is what the server reads.
 */
const form = ref({});

function reset() {
    form.value = Object.fromEntries(
        works.value.map((work) => [work.id, {
            status: work.status,
            was: work.status,
            remark: '',
            approved_on: work.approved_on_iso || props.today,
            hasShot: Boolean(work.has_screenshot),
        }])
    );
}

const moved = (work) => form.value[work.id] && form.value[work.id].status !== form.value[work.id].was;

const approving = (work) => form.value[work.id]?.status === props.approvedKey;

const needsReason = (work) =>
    moved(work)
    && props.reasonKeys.includes(form.value[work.id].status)
    && form.value[work.id].remark.trim() === '';

// Only for a work being approved now that has no document already on file.
const needsShot = (work) => approving(work) && moved(work) && ! form.value[work.id].hasShot;

const touched = computed(() =>
    works.value.filter((work) => moved(work) || form.value[work.id]?.remark.trim() !== '')
);

const blocked = computed(() => works.value.some(needsReason));

/*
 * Said before the button is pressed rather than after the round trip. The
 * server refuses these too — this only saves the reader losing what they typed
 * to a rule they could have been told about while typing it.
 */
const summary = computed(() => {
    if (! touched.value.length) {
        return { tone: 'quiet', text: 'Nothing changed yet.' };
    }

    const missing = works.value.filter(needsReason).length;

    if (missing) {
        return {
            tone: 'error',
            text: `${missing === 1 ? 'One work needs' : `${missing} works need`} a reason before this can be saved.`,
        };
    }

    const parts = [];
    const movingCount = works.value.filter(moved).length;
    const noted = touched.value.length - movingCount;

    if (movingCount) parts.push(`${movingCount} ${movingCount === 1 ? 'work' : 'works'} will move`);
    if (noted > 0) parts.push(`${noted} ${noted === 1 ? 'remark' : 'remarks'} added`);

    return { tone: 'ready', text: parts.join(', ') + '.' };
});

const dialog = ref(null);
const firstField = ref(null);

function close() {
    emit('close');
}

function onKey(event) {
    if (event.key === 'Escape') {
        close();
    }
}

/*
 * The page behind a dialog must not scroll under it, and must get its scrolling
 * back afterwards — including when the dialog is closed by the row's screen
 * going away rather than by the button.
 */
let locked = false;

function lock(on) {
    if (on === locked) {
        return;
    }

    document.body.style.overflow = on ? 'hidden' : '';
    locked = on;
}

watch(open, async (isOpen) => {
    lock(isOpen);

    if (isOpen) {
        reset();
        document.addEventListener('keydown', onKey);
        await nextTick();
        firstField.value?.focus();
    } else {
        document.removeEventListener('keydown', onKey);
    }
}, { immediate: true });

onBeforeUnmount(() => {
    lock(false);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="wu" @click.self="close">
            <div class="wu__panel" role="dialog" aria-modal="true" aria-labelledby="wu-title" ref="dialog">
                <form :action="action" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="_token" :value="csrf">
                    <!-- So the server comes back to the report the reader was
                         reading, filtered and scrolled as they left it. -->
                    <input type="hidden" name="return_to" :value="returnTo">

                    <div class="wu__head">
                        <div>
                            <h5 class="wu__title" id="wu-title">
                                {{ file.file_no }}
                                <span v-if="file.registration_no" class="wu__reg">{{ file.registration_no }}</span>
                            </h5>
                            <div class="ui-hint">
                                {{ file.party_name }}<template v-if="file.received"> &middot; received {{ file.received }}</template>
                            </div>
                        </div>
                        <button type="button" class="wu__x" @click="close" aria-label="Close">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <div class="wu__body">
                        <div v-for="(work, i) in works" :key="work.id" class="wu__work">
                            <div class="wu__workhead">
                                <strong>{{ work.work_type }}</strong>
                                <span class="ui-badge" :data-state="work.status">{{ work.status_label }}</span>
                            </div>

                            <div class="wu__fields">
                                <div class="ui-field">
                                    <label class="ui-label" :for="`wu-status-${work.id}`">Status</label>
                                    <select
                                        :id="`wu-status-${work.id}`"
                                        class="ui-select"
                                        :name="`statuses[${work.id}]`"
                                        v-model="form[work.id].status"
                                        :ref="i === 0 ? (el) => (firstField = el) : undefined">
                                        <option v-for="(label, key) in statuses" :key="key" :value="key">
                                            {{ label }}
                                        </option>
                                    </select>
                                </div>

                                <div class="ui-field wu__remark">
                                    <label class="ui-label" :for="`wu-remark-${work.id}`">
                                        Remark
                                        <span v-if="needsReason(work)" class="ui-label__req">*</span>
                                    </label>
                                    <input
                                        :id="`wu-remark-${work.id}`"
                                        type="text"
                                        class="ui-input"
                                        :class="{ 'ui-input--invalid': needsReason(work) }"
                                        :name="`remarks[${work.id}]`"
                                        v-model="form[work.id].remark"
                                        maxlength="255"
                                        placeholder="Added to this file's history">
                                    <div v-if="needsReason(work)" class="ui-hint ui-hint--error">
                                        Say why before saving &mdash; it goes on the file's history.
                                    </div>
                                </div>
                            </div>

                            <!-- An approval is a thing that happened on a day, with
                                 a document to show for it. Both are asked for here
                                 rather than refused after the save. -->
                            <div v-if="approving(work) && moved(work)" class="wu__fields wu__approval">
                                <div class="ui-field">
                                    <label class="ui-label" :for="`wu-date-${work.id}`">Approved on</label>
                                    <input
                                        :id="`wu-date-${work.id}`"
                                        type="date"
                                        class="ui-input"
                                        :name="`approved_on[${work.id}]`"
                                        v-model="form[work.id].approved_on"
                                        :max="today">
                                </div>

                                <div class="ui-field">
                                    <label class="ui-label" :for="`wu-shot-${work.id}`">
                                        Screenshot
                                        <span v-if="needsShot(work)" class="ui-label__req">*</span>
                                    </label>
                                    <input
                                        :id="`wu-shot-${work.id}`"
                                        type="file"
                                        class="ui-input"
                                        :name="`screenshots[${work.id}]`"
                                        accept=".jpg,.jpeg,.png,.webp,.pdf">
                                    <div class="ui-hint">
                                        <template v-if="form[work.id].hasShot">
                                            One is already on file &mdash; attach another only to replace it.
                                        </template>
                                        <template v-else>
                                            Approval Done needs one.
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wu__foot">
                        <span class="ui-hint" :class="{ 'ui-hint--error': summary.tone === 'error' }">
                            {{ summary.text }}
                        </span>
                        <div class="wu__actions">
                            <button type="button" class="ui-btn" @click="close">Cancel</button>
                            <button
                                type="submit"
                                class="ui-btn ui-btn--primary"
                                :disabled="! touched.length || blocked">
                                <i class="bi bi-check2-circle"></i> Save
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>

<style>
.wu {
    align-items: flex-start;
    background: rgb(15 23 42 / 55%);
    display: flex;
    inset: 0;
    justify-content: center;
    overflow-y: auto;
    padding: var(--s-4);
    position: fixed;
    z-index: 1060;
}

.wu__panel {
    background: var(--n-000);
    border-radius: var(--r-lg);
    box-shadow: 0 20px 60px rgb(15 23 42 / 35%);
    margin: auto;
    max-width: 44rem;
    width: 100%;
}

.wu__head {
    align-items: flex-start;
    border-bottom: 1px solid var(--n-200);
    display: flex;
    gap: var(--s-3);
    justify-content: space-between;
    padding: var(--s-4);
}

.wu__title {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    font-size: var(--t-lg);
    gap: var(--s-2);
    margin: 0;
}

.wu__reg {
    background: var(--n-100);
    border-radius: var(--r-sm);
    font-family: var(--font-num);
    font-size: var(--t-sm);
    padding: 0.1rem 0.4rem;
}

.wu__x {
    background: none;
    border: 0;
    color: var(--n-500);
    cursor: pointer;
    font-size: var(--t-md);
    line-height: 1;
    padding: var(--s-1);
}

.wu__x:hover {
    color: var(--ink-900);
}

.wu__body {
    display: flex;
    flex-direction: column;
    gap: var(--s-3);
    max-height: 60vh;
    overflow-y: auto;
    padding: var(--s-4);
}

.wu__work {
    border: 1px solid var(--n-200);
    border-radius: var(--r-md);
    padding: var(--s-3);
}

.wu__workhead {
    align-items: center;
    display: flex;
    gap: var(--s-2);
    justify-content: space-between;
    margin-bottom: var(--s-2);
}

.wu__fields {
    display: grid;
    gap: var(--s-3);
    grid-template-columns: minmax(10rem, 1fr) minmax(12rem, 2fr);
}

.wu__approval {
    border-top: 1px dashed var(--n-200);
    margin-top: var(--s-3);
    padding-top: var(--s-3);
}

.wu__foot {
    align-items: center;
    border-top: 1px solid var(--n-200);
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-3);
    justify-content: space-between;
    padding: var(--s-4);
}

.wu__actions {
    display: flex;
    gap: var(--s-2);
}

@media (max-width: 575.98px) {
    .wu__fields {
        grid-template-columns: 1fr;
    }

    .wu__foot,
    .wu__actions {
        width: 100%;
    }

    .wu__actions .ui-btn {
        flex: 1 1 auto;
    }
}
</style>
