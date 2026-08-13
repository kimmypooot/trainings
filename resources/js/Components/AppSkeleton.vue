<script setup>
/**
 * Structure-preserving placeholder. Preferred over spinners so a slow
 * connection shows the shape of what is arriving rather than a blank void.
 *
 * The shapes are hidden from assistive tech — a screen reader gains nothing
 * from six grey rectangles — and the wrapper carries a polite live region
 * instead, so the wait is announced once and the arrival is not a silent swap.
 */
defineProps({
    variant: {
        type: String,
        default: 'text', // text | list | stats
        validator: (value) => ['text', 'list', 'stats'].includes(value),
    },
    /** Lines for `text`, rows for `list`, tiles for `stats`. */
    count: { type: Number, default: 3 },
    label: { type: String, default: 'Loading' },
});
</script>

<template>
    <div role="status" :aria-label="label">
        <!-- Paragraph-ish runs of text -->
        <div v-if="variant === 'text'" class="animate-pulse space-y-3" aria-hidden="true">
            <div
                v-for="line in count"
                :key="line"
                class="h-3 rounded-full bg-csc-line"
                :style="{ width: line === count ? '60%' : '100%' }"
            />
        </div>

        <!-- Rows of a divided list: title, meta line, trailing control -->
        <ul v-else-if="variant === 'list'" class="animate-pulse divide-y divide-csc-line" aria-hidden="true">
            <li v-for="row in count" :key="row" class="flex items-center justify-between gap-3 py-3.5">
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-3.5 w-2/5 rounded-full bg-csc-line" />
                    <div class="h-2.5 w-3/5 rounded-full bg-csc-line/70" />
                </div>
                <div class="h-6 w-20 shrink-0 rounded-full bg-csc-line" />
            </li>
        </ul>

        <!-- The stat-tile row -->
        <div v-else class="grid animate-pulse grid-cols-2 gap-3 lg:grid-cols-4" aria-hidden="true">
            <div
                v-for="tile in count"
                :key="tile"
                class="space-y-2 rounded-xl border border-csc-line bg-white p-4 sm:p-5"
            >
                <div class="h-7 w-12 rounded-lg bg-csc-line" />
                <div class="h-2.5 w-20 rounded-full bg-csc-line/70" />
            </div>
        </div>

        <span class="sr-only">{{ label }}…</span>
    </div>
</template>
