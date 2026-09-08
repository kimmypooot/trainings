<script setup>
import { computed, ref, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import AppButton from '@/Components/AppButton.vue';

/**
 * Lets a participant choose which part of their photo becomes the avatar,
 * before it ever leaves the browser.
 *
 * AvatarImageService has always centre-cropped to a square on the server —
 * right for a portrait shot square-on, and wrong for the phone photo where
 * the face sits left of centre, or the group photo somebody meant to crop to
 * just themselves. The server had no way to ask which part mattered; this
 * does, and by the time a file reaches ProfilePhotoController it is already
 * the square the participant chose, not the square the middle of the frame
 * happened to land on.
 *
 * The frame is round on screen because the avatar is round everywhere it is
 * shown (AppAvatar draws a circle) — but the file produced is a square, the
 * same shape AvatarImageService has always stored, so nothing server-side
 * has to change to accept it. Panning and zooming happen in CSS transforms
 * against a fixed viewport; the crop itself happens once, on confirm, by
 * drawing the visible rectangle of the *natural* image onto an output
 * canvas — the on-screen frame is never the source of truth for pixels.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The file just picked. Read once per open; swapping it while open is not supported. */
    file: { type: File, default: null },
});

const emit = defineEmits(['cropped', 'close']);

/** CSS pixels of the (square) viewport the participant drags the photo inside. */
const FRAME = 256;

/** How far past "fills the frame" a participant may zoom in. */
const MAX_ZOOM = 3;

/** Longest edge of the file this produces — matches AvatarImageService::SIZE, so the server's own resize is a no-op on the common case rather than a second downscale. */
const OUTPUT_SIZE = 512;

const objectUrl = ref(null);
const imageEl = ref(null);
const naturalWidth = ref(0);
const naturalHeight = ref(0);
const ready = ref(false);

const zoom = ref(1);
const panX = ref(0);
const panY = ref(0);

/** The scale that makes the image exactly cover the frame at zoom 1 — "cover" fit, the same rule the server's centre-crop used to apply on its own. */
const baseScale = computed(() => {
    if (!naturalWidth.value || !naturalHeight.value) return 1;

    return FRAME / Math.min(naturalWidth.value, naturalHeight.value);
});

const scale = computed(() => baseScale.value * zoom.value);
const displayWidth = computed(() => naturalWidth.value * scale.value);
const displayHeight = computed(() => naturalHeight.value * scale.value);

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

/** Keeps the visible image covering the frame — never showing the white space past its own edge. */
const clampPan = () => {
    panX.value = clamp(panX.value, FRAME - displayWidth.value, 0);
    panY.value = clamp(panY.value, FRAME - displayHeight.value, 0);
};

const centerPan = () => {
    panX.value = (FRAME - displayWidth.value) / 2;
    panY.value = (FRAME - displayHeight.value) / 2;
};

// Zooming re-centres on whatever point was under the middle of the frame
// before the change, rather than the image's own centre — so zooming in on
// a photo already panned toward a face keeps that face in view instead of
// snapping back to the middle of the original photo.
watch(zoom, (nextZoom, previousZoom) => {
    const previousScale = baseScale.value * previousZoom;
    const focusX = (FRAME / 2 - panX.value) / previousScale;
    const focusY = (FRAME / 2 - panY.value) / previousScale;
    const nextScale = baseScale.value * nextZoom;

    panX.value = FRAME / 2 - focusX * nextScale;
    panY.value = FRAME / 2 - focusY * nextScale;
    clampPan();
});

const releaseUrl = () => {
    if (objectUrl.value) URL.revokeObjectURL(objectUrl.value);
    objectUrl.value = null;
};

watch(
    () => props.file,
    (file) => {
        releaseUrl();
        ready.value = false;
        zoom.value = 1;

        if (file) objectUrl.value = URL.createObjectURL(file);
    },
    { immediate: true }
);

const onImageLoad = () => {
    naturalWidth.value = imageEl.value.naturalWidth;
    naturalHeight.value = imageEl.value.naturalHeight;
    centerPan();
    ready.value = true;
};

// Pointer events cover mouse and touch alike, which is the input that
// matters most here — this is a phone camera photo as often as not.
let dragging = false;
let startX = 0;
let startY = 0;
let startPanX = 0;
let startPanY = 0;

const startDrag = (event) => {
    dragging = true;
    startX = event.clientX;
    startY = event.clientY;
    startPanX = panX.value;
    startPanY = panY.value;
    event.currentTarget.setPointerCapture?.(event.pointerId);
};

const moveDrag = (event) => {
    if (!dragging) return;
    panX.value = startPanX + (event.clientX - startX);
    panY.value = startPanY + (event.clientY - startY);
    clampPan();
};

const endDrag = () => {
    dragging = false;
};

/**
 * Draws the rectangle currently inside the frame — in the natural image's
 * own pixels, not the on-screen CSS ones — onto a fresh canvas, and hands
 * the result back as a file ready to post.
 */
const confirm = () => {
    const sourceX = -panX.value / scale.value;
    const sourceY = -panY.value / scale.value;
    const sourceSize = FRAME / scale.value;

    const canvas = document.createElement('canvas');
    canvas.width = OUTPUT_SIZE;
    canvas.height = OUTPUT_SIZE;

    canvas
        .getContext('2d')
        .drawImage(imageEl.value, sourceX, sourceY, sourceSize, sourceSize, 0, 0, OUTPUT_SIZE, OUTPUT_SIZE);

    canvas.toBlob(
        (blob) => {
            if (!blob) return;
            emit('cropped', new File([blob], 'avatar.jpg', { type: 'image/jpeg' }));
        },
        'image/jpeg',
        0.9
    );
};

const close = () => emit('close');
</script>

<template>
    <AppModal
        :open="open"
        title="Position your photo"
        subtitle="Drag to reposition, and zoom to fill the frame. It is shown as a circle everywhere in TIMS."
        size="sm"
        @close="close"
    >
        <div class="flex flex-col items-center">
            <div
                class="relative touch-none overflow-hidden rounded-lg bg-csc-ink/5 select-none"
                :style="{ width: `${FRAME}px`, height: `${FRAME}px` }"
                @pointerdown="startDrag"
                @pointermove="moveDrag"
                @pointerup="endDrag"
                @pointercancel="endDrag"
            >
                <img
                    v-if="objectUrl"
                    ref="imageEl"
                    :src="objectUrl"
                    alt=""
                    draggable="false"
                    class="absolute top-0 left-0 max-w-none cursor-move"
                    :style="{
                        width: `${displayWidth}px`,
                        height: `${displayHeight}px`,
                        transform: `translate(${panX}px, ${panY}px)`,
                        opacity: ready ? 1 : 0,
                    }"
                    @load="onImageLoad"
                />

                <!--
                    A dimmed ring outside the circle the avatar is actually
                    shown in — decoration, but the decoration is the whole
                    point of a preview: it answers "what will this look
                    like" without the participant having to imagine the
                    corners being cut off.
                -->
                <div
                    class="pointer-events-none absolute inset-0 rounded-full"
                    style="box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.55)"
                    aria-hidden="true"
                />
            </div>

            <div class="mt-5 w-full max-w-64">
                <label class="flex items-center justify-between text-xs font-medium text-csc-ink-subtle">
                    Zoom
                </label>
                <input
                    v-model.number="zoom"
                    type="range"
                    min="1"
                    :max="MAX_ZOOM"
                    step="0.01"
                    class="mt-1 w-full accent-csc-blue"
                    :disabled="!ready"
                    aria-label="Zoom the photo"
                />
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <AppButton variant="ghost" @click="close">Cancel</AppButton>
                <AppButton :disabled="!ready" icon="check" @click="confirm">Use This Photo</AppButton>
            </div>
        </template>
    </AppModal>
</template>
