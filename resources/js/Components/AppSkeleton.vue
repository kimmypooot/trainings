<script setup>
// Structure-preserving placeholder. Preferred over spinners so a slow
// connection still shows the shape of the page rather than a blank void.
defineProps({
    lines: { type: Number, default: 3 },
    class: { type: String, default: '' },
});
</script>

<template>
    <div class="animate-pulse space-y-3" aria-hidden="true">
        <div
            v-for="line in lines"
            :key="line"
            class="h-3 rounded-full bg-csc-line"
            :style="{ width: line === lines ? '60%' : '100%' }"
        />
    </div>
</template>
