<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Route-navigation progress bar, replacing Inertia's built-in one.
 *
 * Inertia's default bar is grey, starts instantly, and makes every fast local
 * navigation flash. This one waits 250ms before appearing so only genuinely
 * slow visits announce themselves; once visible it tracks the real download
 * percentage, snaps to full on arrival, and flashes danger if the visit is
 * invalidated.
 */
const show = ref(false);
const width = ref(0);
const colorClass = ref('bg-csc-blue');

/*
 * Whether `width` is a real measurement or just a reassuring animation.
 *
 * The 20→60 ramp on `start` is theatre: nothing has been measured, it only
 * says "something is happening". A percentage from the `progress` event is a
 * genuine fraction of an upload. Only the second is worth announcing, and
 * conflating them is how this bar spent its whole life telling screen readers
 * `aria-valuenow="50"` — a hardcoded literal that never moved, on a bar whose
 * visible width was moving the entire time.
 *
 * An indeterminate progressbar is spelled by *omitting* aria-valuenow, which
 * is exactly what a nav visit of unknown length is.
 */
const determinate = ref(false);

let showTimer = null;
let hideTimer = null;

const clearTimers = () => {
    clearTimeout(showTimer);
    clearTimeout(hideTimer);
};

const start = () => {
    clearTimeout(showTimer);
    determinate.value = false;
    showTimer = setTimeout(() => {
        show.value = true;
        width.value = 20;
        setTimeout(() => {
            width.value = 60;
        }, 100);
    }, 250);
};

const progress = (event) => {
    if (event.detail.progress?.percentage) {
        // A percentage only arrives while something is actually being
        // downloaded, so show the bar immediately rather than waiting out the
        // delay — this navigation has already proven it is slow.
        clearTimeout(showTimer);
        show.value = true;
        determinate.value = true;
        width.value = Math.min(event.detail.progress.percentage, 90);
    }
};

const finish = () => {
    clearTimers();
    width.value = 100;
    hideTimer = setTimeout(() => {
        show.value = false;
        width.value = 0;
        determinate.value = false;
    }, 400);
};

const invalid = () => {
    clearTimers();
    colorClass.value = 'bg-danger';
    width.value = 100;
    hideTimer = setTimeout(() => {
        show.value = false;
        width.value = 0;
        determinate.value = false;
        colorClass.value = 'bg-csc-blue';
    }, 500);
};

router.on('start', start);
router.on('progress', progress);
router.on('finish', finish);
router.on('invalid', invalid);

onBeforeUnmount(() => {
    clearTimers();
    router.off('start', start);
    router.off('progress', progress);
    router.off('finish', finish);
    router.off('invalid', invalid);
});
</script>

<template>
    <div
        v-if="show"
        class="pointer-events-none fixed inset-x-0 top-0 z-(--z-progress) h-1"
        role="progressbar"
        :aria-label="determinate ? 'Upload progress' : 'Loading'"
        :aria-valuenow="determinate ? Math.round(width) : undefined"
        aria-valuemin="0"
        aria-valuemax="100"
    >
        <div
            class="h-full transition-all duration-[400ms] ease-out"
            :class="colorClass"
            :style="{ width: width + '%' }"
        />
    </div>
</template>