<script setup>
import { computed, ref } from 'vue';
import { categorical, foldTail, formatCount, percent, sumRows } from '@/charts';
import { useChartMount } from '@/useChartMount';

/**
 * Part-to-whole, with the headline number in the hole.
 *
 * A donut is only honest at a glance — it cannot be used to compare two
 * similar slices, and it stops working entirely past a handful of segments. So
 * this one folds anything beyond six categories into "Other" rather than
 * growing more colours, and it always prints the counts and shares in the
 * legend beside it. The ring is the shape of the split; the legend is where
 * the values actually get read.
 *
 * The centre is not decoration: it is the one number the card is about (an
 * attendance rate, a total), so the ring has something to be a breakdown *of*.
 */
const props = defineProps({
    /** `[{ label, count }]`, largest first. */
    rows: { type: Array, required: true },
    /** The figure in the hole. Falls back to the summed total. */
    centerValue: { type: [String, Number], default: null },
    centerLabel: { type: String, default: 'Total' },
    emptyText: { type: String, default: 'Nothing to show yet.' },
});

const RADIUS = 56;
const THICKNESS = 18;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

// The 2px surface gap that separates touching segments, expressed as arc
// length. White doing the separating — never a stroke drawn round each slice,
// which adds ink that is not data.
const GAP = 2;

const active = ref(null);

const shown = computed(() => foldTail(props.rows.filter((row) => Number(row.count) > 0), 6));
const total = computed(() => sumRows(shown.value));

const segments = computed(() => {
    let offset = 0;

    return shown.value.map((row, index) => {
        const share = Number(row.count) / (total.value || 1);
        const length = Math.max(0, share * CIRCUMFERENCE - GAP);
        const segment = {
            ...row,
            color: categorical(index),
            share,
            dash: `${length} ${CIRCUMFERENCE - length}`,
            offset: -offset,
            index,
        };

        offset += share * CIRCUMFERENCE;

        return segment;
    });
});

/*
 * Each arc traces out from its own start point rather than the whole ring
 * sweeping from twelve o'clock: the offsets stay fixed and only the drawn
 * length grows, so a segment ends where it always was and never crosses over a
 * neighbour on its way there.
 */
const mounted = useChartMount();

const heroValue = computed(() => props.centerValue ?? formatCount(total.value));

const summary = computed(
    () =>
        `${props.centerLabel}: ${heroValue.value}. ` +
        segments.value.map((row) => `${row.label} ${formatCount(row.count)}`).join(', ')
);
</script>

<template>
    <p v-if="!shown.length" class="text-sm text-csc-ink-subtle">{{ emptyText }}</p>

    <div v-else class="flex flex-col items-center gap-6 sm:flex-row sm:items-center">
        <div class="relative shrink-0">
            <svg
                width="150"
                height="150"
                viewBox="0 0 150 150"
                role="img"
                :aria-label="summary"
                class="block -rotate-90"
            >
                <!--
                    The track. It shows the ring's full extent even when one
                    segment rounds to nothing, so a near-total category does
                    not read as a complete circle.
                -->
                <circle
                    cx="75"
                    cy="75"
                    :r="RADIUS"
                    fill="none"
                    stroke="var(--color-csc-blue-tint)"
                    :stroke-width="THICKNESS"
                />

                <circle
                    v-for="segment in segments"
                    :key="segment.label"
                    cx="75"
                    cy="75"
                    :r="RADIUS"
                    fill="none"
                    :stroke="segment.color"
                    :stroke-width="active === null || active === segment.index ? THICKNESS : THICKNESS - 4"
                    :stroke-dasharray="mounted ? segment.dash : `0 ${CIRCUMFERENCE}`"
                    :stroke-dashoffset="segment.offset"
                    :opacity="active === null || active === segment.index ? 1 : 0.45"
                    class="donut-segment"
                />
            </svg>

            <!--
                The hero figure sits in the hole rather than above the chart, so
                the ring reads as its breakdown. Proportional figures, not
                tabular: at this size tabular digits make a short number look
                gappy, and nothing here has to align with a column.
            -->
            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-bold text-csc-blue">{{ heroValue }}</span>
                <span class="mt-0.5 max-w-[6.5rem] text-center text-2xs text-csc-ink-subtle">
                    {{ centerLabel }}
                </span>
            </div>
        </div>

        <!--
            The legend carries every value as text. That is what lets the ring
            use the lighter palette slots at all — no figure here is reachable
            only by looking at a colour.
        -->
        <ul class="w-full space-y-1">
            <li
                v-for="segment in segments"
                :key="segment.label"
                class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 transition-colors duration-150"
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
