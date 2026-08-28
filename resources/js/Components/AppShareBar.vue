<script setup>
import { computed, ref } from 'vue';
import { categorical, foldTail, formatCount, percent, sumRows } from '@/charts';

/**
 * One horizontal bar, split into its parts.
 *
 * The right form for a small part-to-whole where the categories are few and
 * the question is "what is the mix" — sex, funding source, PWD status. It
 * reads in one glance, stacks tidily in a grid of six, and unlike a row of
 * separate bars it makes the *shares* the subject rather than the counts.
 *
 * Keep it to a handful of segments. Past six the slivers stop being
 * distinguishable and `foldTail` collapses the rest into "Other"; if the tail
 * is the interesting part, that data wants AppBarList instead.
 */
const props = defineProps({
    /** `[{ label, count }]`, largest first. */
    rows: { type: Array, required: true },
    emptyText: { type: String, default: 'Nothing to show yet.' },
});

// The 2px surface gap between touching segments. Written as a border rather
// than a margin so it survives flex rounding at odd container widths.
const active = ref(null);

const shown = computed(() => foldTail(props.rows.filter((row) => Number(row.count) > 0), 6));
const total = computed(() => sumRows(shown.value));

const segments = computed(() =>
    shown.value.map((row, index) => ({
        ...row,
        color: categorical(index),
        share: Number(row.count) / (total.value || 1),
        index,
    }))
);

const summary = computed(() =>
    segments.value
        .map((row) => `${row.label} ${formatCount(row.count)} (${percent(row.count, total.value)})`)
        .join(', ')
);
</script>

<template>
    <p v-if="!shown.length" class="text-sm text-csc-ink-subtle">{{ emptyText }}</p>

    <div v-else>
        <!--
            The bar itself is decorative: every number in it is printed in the
            legend below, so it is hidden from assistive tech rather than
            announced as a row of empty elements. The summary rides on the
            legend list instead.
        -->
        <div
            class="flex h-3 w-full gap-[2px] overflow-hidden rounded-full bg-csc-blue-tint"
            aria-hidden="true"
        >
            <span
                v-for="segment in segments"
                :key="segment.label"
                class="h-full first:rounded-l-full last:rounded-r-full transition-opacity duration-150"
                :style="{ width: `${segment.share * 100}%`, backgroundColor: segment.color }"
                :class="active !== null && active !== segment.index ? 'opacity-40' : ''"
            />
        </div>

        <ul class="mt-3 space-y-1" :aria-label="summary">
            <li
                v-for="segment in segments"
                :key="segment.label"
                class="flex items-center gap-2.5 rounded-md px-1.5 py-1 transition-colors duration-150"
                :class="active === segment.index ? 'bg-csc-blue-tint/60' : ''"
                @mouseenter="active = segment.index"
                @mouseleave="active = null"
            >
                <span
                    class="size-2.5 shrink-0 rounded-full"
                    :style="{ backgroundColor: segment.color }"
                    aria-hidden="true"
                />
                <span class="min-w-0 flex-1 truncate text-sm text-csc-ink-muted" :title="segment.label">
                    {{ segment.label }}
                </span>
                <span class="text-sm font-semibold text-csc-ink tabular-nums">
                    {{ formatCount(segment.count) }}
                </span>
                <span class="w-12 text-right text-2xs text-csc-ink-subtle tabular-nums">
                    {{ percent(segment.count, total) }}
                </span>
            </li>
        </ul>
    </div>
</template>
