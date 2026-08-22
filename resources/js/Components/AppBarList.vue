<script setup>
import { computed } from 'vue';

/**
 * A labelled horizontal bar list — the one chart form this app uses.
 *
 * Extracted from Analytics.vue, where the same twelve lines of markup were
 * repeated per breakdown. With the demographic cuts added there are nine of
 * them, and nine hand-copied bar lists drift apart the first time one is
 * tweaked.
 *
 * Bars are widths against the largest value in the set, not against a total:
 * these are counts of different things, and a shared denominator would make
 * every small category an invisible sliver.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    /** Width of the label column. Longer labels (agency names) need more. */
    labelWidth: { type: String, default: '8rem' },
    emptyText: { type: String, default: 'Nothing to show yet.' },
});

// Never divide by zero on an empty or all-zero set.
const peak = computed(() => Math.max(1, ...props.rows.map((row) => row.count)));
</script>

<template>
    <p v-if="!rows.length" class="text-sm text-csc-ink-subtle">{{ emptyText }}</p>

    <ul v-else class="space-y-2">
        <li
            v-for="row in rows"
            :key="row.label"
            class="grid items-center gap-3"
            :style="{ gridTemplateColumns: `${labelWidth} 1fr 2.5rem` }"
        >
            <span class="truncate text-xs text-csc-ink-subtle" :title="row.label">{{ row.label }}</span>
            <!--
                The bar is decorative: the label and the count either side of it
                already carry the value, so it is hidden from assistive tech
                rather than announced as an empty element.
            -->
            <span class="h-2.5 rounded-full bg-csc-blue-tint" aria-hidden="true">
                <span
                    class="block h-full rounded-full bg-csc-blue"
                    :style="{ width: `${(row.count / peak) * 100}%` }"
                />
            </span>
            <span class="text-right text-xs font-medium text-csc-ink">{{ row.count }}</span>
        </li>
    </ul>
</template>
