<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The three-pulsing-dot wait cue used on brand overlays.
 *
 * Runs its own cycle so callers don't each need their own
 * setInterval/clearInterval bookkeeping just to animate three dots. The
 * colours come from the design tokens via CSS variables — never hex — so the
 * blue/red beat reads as branding on the (branded) surfaces it sits on.
 */
const active = ref(0);
let timer = null;

onMounted(() => {
    timer = setInterval(() => {
        active.value = (active.value + 1) % 3;
    }, 400);
});

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div class="mt-6 flex justify-center gap-1.5">
        <span
            v-for="i in 3"
            :key="i"
            class="size-2 rounded-full transition-all duration-300"
            :style="{
                backgroundColor:
                    active === i - 1 ? 'var(--color-csc-red)' : 'var(--color-csc-blue)',
                transform: active === i - 1 ? 'scale(1.25)' : 'scale(1)',
            }"
        />
    </div>
</template>