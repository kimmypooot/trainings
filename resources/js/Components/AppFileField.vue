<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

/**
 * A labelled file input.
 *
 * AppInput cannot cover this: it binds a value, and a file input is the one
 * control a page is not allowed to set the value of. So the markup was being
 * hand-rolled at every upload — the payment proof, the refund proof, the
 * supervisory document — and the agency-request screens need seven more. Nine
 * copies of the same label/input/hint/error block is how the error styling on
 * one of them quietly stops matching the rest.
 *
 * Emits the File itself rather than the event, so callers write
 * `@change="form.proof = $event"` instead of reaching into the DOM.
 *
 * Shows a preview once a file is chosen — a thumbnail for an image, a name
 * and size for anything else — so the person uploading a proof of payment can
 * see what a reviewer is about to see and catch a blurry photo or the wrong
 * screenshot before submitting, rather than after it comes back rejected.
 */
const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    accept: { type: String, default: null },
    /** Shown under the thumbnail, for a proof that must specifically be legible. */
    previewHint: { type: String, default: null },
    /*
     * Inertia's `form.progress` — an axios progress event with `.percentage`,
     * or null when nothing is in flight. Pass it on any form posting with
     * `forceFormData: true`.
     *
     * Every upload in this app happens inside a modal, and until this existed
     * the only feedback a participant got for sending a 4MB photo of a receipt
     * over mobile data was a spinner on the submit button — a control that
     * looks identical at 5% and at 95%. The global AppProgressBar does know the
     * real percentage, but it is a hairline at the top of the viewport, far
     * from the dialog holding the reader's attention. This is that same number,
     * put where the file itself is.
     */
    progress: { type: Object, default: null },
});

const emit = defineEmits(['change']);

const inputEl = ref(null);
const selected = ref(null);
const previewUrl = ref(null);

const revokePreview = () => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
};

const readableSize = (bytes) => {
    const units = ['B', 'KB', 'MB'];
    let size = bytes;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }

    return `${size.toFixed(size >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
};

// Named for what it is, not just an icon — a PDF proof needs to be legible
// too, and "document" alone next to a filename does not say that as clearly
// as naming the type does.
const fileKindLabel = computed(() => {
    if (!selected.value || previewUrl.value) return null;

    return selected.value.type === 'application/pdf' ? 'PDF document' : 'File';
});

// Rounded once here so the bar's width, the spoken value and the printed
// caption can never disagree by a percent.
const percent = computed(() =>
    props.progress ? Math.min(100, Math.round(props.progress.percentage ?? 0)) : null
);

const onChange = (event) => {
    const file = event.target.files[0] ?? null;

    revokePreview();
    selected.value = file;
    if (file?.type.startsWith('image/')) {
        previewUrl.value = URL.createObjectURL(file);
    }

    emit('change', file);
};

const clear = () => {
    revokePreview();
    selected.value = null;
    if (inputEl.value) {
        inputEl.value.value = '';
    }
    emit('change', null);
};

onBeforeUnmount(revokePreview);
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-medium text-csc-ink">
            {{ label }}
            <span v-if="required" class="text-csc-red-ink" aria-hidden="true">*</span>
        </label>

        <input
            :id="id"
            ref="inputEl"
            type="file"
            :accept="accept"
            :required="required"
            :disabled="percent !== null"
            class="w-full rounded-lg border bg-white px-4 py-2.5 text-sm text-csc-ink transition-colors duration-150 file:mr-3 file:rounded file:border-0 file:bg-csc-blue-tint file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-csc-blue"
            :class="
                error
                    ? 'border-csc-red-ink focus:outline-csc-red-ink'
                    : 'border-csc-line hover:border-csc-blue/40 focus:border-csc-blue focus:outline-csc-blue'
            "
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="hint ? `${id}-hint` : undefined"
            @change="onChange"
        />

        <p v-if="hint" :id="`${id}-hint`" class="mt-1.5 text-xs text-csc-ink-subtle">{{ hint }}</p>
        <p v-if="error" class="mt-1.5 text-xs font-medium text-csc-red-ink">{{ error }}</p>

        <!-- The self-check: what was actually picked, before it is submitted. -->
        <div v-if="selected" class="mt-3 flex items-start gap-3 rounded-lg border border-csc-line bg-csc-mist/30 p-3">
            <img
                v-if="previewUrl"
                :src="previewUrl"
                alt="Preview of the selected file"
                class="h-20 w-20 shrink-0 rounded-md border border-csc-line object-cover"
            />
            <div v-else class="flex size-20 shrink-0 items-center justify-center rounded-md border border-csc-line bg-white">
                <AppIcon name="document" class="size-8 text-csc-ink-subtle" aria-hidden="true" />
            </div>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-csc-ink">{{ selected.name }}</p>
                <p class="text-xs text-csc-ink-subtle">
                    {{ readableSize(selected.size) }}<template v-if="fileKindLabel"> · {{ fileKindLabel }}</template>
                </p>
                <!--
                    Mid-upload the hint and the swap-file control both go: one
                    is advice that can no longer be acted on, the other is an
                    offer to change a file that is already halfway to the
                    server. The bar takes their place rather than sitting under
                    them, so the card never grows and shoves the submit button
                    down at the exact moment it is being pressed.
                -->
                <template v-if="percent === null">
                    <p v-if="previewHint" class="mt-1 text-xs text-csc-ink-muted">{{ previewHint }}</p>
                    <button
                        type="button"
                        class="mt-1.5 rounded text-xs font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        @click="clear"
                    >
                        Remove and choose another file
                    </button>
                </template>

                <div v-else class="mt-2">
                    <div
                        class="h-1.5 overflow-hidden rounded-full bg-csc-blue-tint"
                        role="progressbar"
                        aria-label="Upload progress"
                        :aria-valuenow="percent"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full bg-csc-blue transition-[width] duration-200 ease-out"
                            :style="{ width: `${percent}%` }"
                        />
                    </div>
                    <!--
                        aria-hidden: the progressbar above already speaks the
                        number, and a live region repeating it every percent
                        would talk over everything else on the page.
                    -->
                    <p class="mt-1 text-xs text-csc-ink-muted" aria-hidden="true">
                        {{ percent < 100 ? `Uploading… ${percent}%` : 'Upload complete — saving…' }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
