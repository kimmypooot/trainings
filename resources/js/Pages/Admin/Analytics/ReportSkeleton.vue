<script setup>
import AppSkeleton from '@/Components/AppSkeleton.vue';

/**
 * The shape of an analytics report, while it is still being counted.
 *
 * Every one of these screens is the same silhouette — a row of stat tiles over
 * a chart over a couple of ranked lists — so the placeholder is one component
 * rather than three hand-drawn approximations that drift from the reports they
 * stand in for.
 *
 * Worth being a skeleton rather than a spinner specifically here: an analytics
 * tab is a page people arrive at with a question, and the layout itself answers
 * "is the number I want on this screen?" before any number has loaded.
 */
defineProps({
    /** Stat tiles above the chart. Reports vary between three and four. */
    tiles: { type: Number, default: 4 },
    label: { type: String, default: 'Loading report' },
});
</script>

<template>
    <div class="space-y-5">
        <AppSkeleton variant="stats" :count="tiles" :label="label" />

        <!--
            The chart block. A plain tinted panel rather than fake bars: drawing
            placeholder bars at invented heights reads as data for the moment
            before it is replaced, and a reader who glances away and back has
            been told something false about their own figures.
        -->
        <div class="rounded-xl border border-csc-line bg-white p-4 sm:p-5">
            <div class="animate-pulse space-y-3" aria-hidden="true">
                <div class="h-3 w-32 rounded-full bg-csc-line" />
                <div class="h-48 rounded-lg bg-csc-line/40" />
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div
                v-for="panel in 2"
                :key="panel"
                class="rounded-xl border border-csc-line bg-white p-4 sm:p-5"
            >
                <div class="mb-4 h-3 w-28 animate-pulse rounded-full bg-csc-line" aria-hidden="true" />
                <AppSkeleton variant="list" :count="4" :label="label" />
            </div>
        </div>
    </div>
</template>
