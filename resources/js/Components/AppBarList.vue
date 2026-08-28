<script setup>
import { computed, ref } from 'vue';
import { categorical, foldTail, formatCount, ordinal, percent, sumRows } from '@/charts';

/**
 * A labelled horizontal bar list — the workhorse chart of the reports page.
 *
 * Extracted from Analytics.vue, where the same twelve lines of markup were
 * repeated per breakdown. With the demographic cuts added there are nine of
 * them, and nine hand-copied bar lists drift apart the first time one is
 * tweaked.
 *
 * Bars are widths against the largest value in the set, not against a total:
 * these are counts of different things, and a shared denominator would make
 * every small category an invisible sliver. The *share* of the total is not
 * lost — it is printed beside the count, where it can be read exactly instead
 * of estimated off a bar.
 *
 * Colour here is doing one of two jobs, and picking the wrong one is the
 * mistake this component exists to prevent:
 *
 *   tone="brand"   (default) — unordered categories: agencies, sectors,
 *                  offices. Every bar is the same colour, because the bar
 *                  *length* already carries the value and there is nothing
 *                  left for hue to say. Colouring these darker-where-bigger
 *                  double-encodes the length and wastes the identity channel.
 *
 *   tone="ordinal" — ordered categories: age bands, position levels. Shuffling
 *                  the rows would destroy the meaning, so the one-hue ramp
 *                  carries the order and the reader sees the sequence without
 *                  reading the labels.
 *
 *   tone="series"  — the rare case where the rows are genuinely separate
 *                  identities that recur across charts. Capped at six by the
 *                  palette; the tail folds into "Other".
 */
const props = defineProps({
    rows: { type: Array, required: true },
    /** Width of the label column. Longer labels (agency names) need more. */
    labelWidth: { type: String, default: '8rem' },
    emptyText: { type: String, default: 'Nothing to show yet.' },
    tone: {
        type: String,
        default: 'brand',
        validator: (value) => ['brand', 'ordinal', 'series'].includes(value),
    },
    /** Formatter for the value at the bar's tip — money lists override this. */
    format: { type: Function, default: formatCount },
    /** Share of the set's total, beside the count. Off for money trends. */
    showPercent: { type: Boolean, default: true },
    /** Fold everything past this many rows into one "Other". */
    limit: { type: Number, default: null },
});

const active = ref(null);

const shown = computed(() => (props.limit ? foldTail(props.rows, props.limit) : props.rows));

// Never divide by zero on an empty or all-zero set.
const peak = computed(() => Math.max(1, ...shown.value.map((row) => Number(row.count) || 0)));
const total = computed(() => sumRows(shown.value));

function colorFor(index) {
    if (props.tone === 'ordinal') return ordinal(index, shown.value.length);
    if (props.tone === 'series') return categorical(index);

    return categorical(0);
}

/*
 * A row with a real but tiny value should still show a mark — at 0.3% of the
 * peak the bar rounds away to nothing and reads identically to zero, which is
 * the one thing it must not do. A true zero stays at zero.
 */
function widthFor(count) {
    const value = Number(count) || 0;

    if (value <= 0) return 0;

    return Math.max(1.5, (value / peak.value) * 100);
}
</script>

<template>
    <p v-if="!shown.length" class="text-sm text-csc-ink-subtle">{{ emptyText }}</p>

    <ul v-else class="space-y-0.5">
        <li
            v-for="(row, index) in shown"
            :key="row.label"
            class="grid items-center gap-3 rounded-md px-1.5 py-1.5 transition-colors duration-150"
            :class="active === index ? 'bg-csc-blue-tint/60' : ''"
            :style="{
                gridTemplateColumns: `${labelWidth} 1fr auto${showPercent ? ' 3rem' : ''}`,
            }"
            @mouseenter="active = index"
            @mouseleave="active = null"
        >
            <span class="truncate text-xs text-csc-ink-subtle" :title="row.label">{{ row.label }}</span>

            <!--
                The bar is decorative: the label and the value either side of it
                already carry the meaning, so it is hidden from assistive tech
                rather than announced as an empty element.

                Rounded at the data end and square at the baseline, so every bar
                starts on the same line and the eye can compare lengths without
                the cap adding phantom width to the short ones.
            -->
            <span class="h-2.5 rounded-[4px] bg-csc-blue-tint" aria-hidden="true">
                <span
                    class="block h-full rounded-r-[4px] transition-[width,opacity] duration-300 ease-out"
                    :style="{ width: `${widthFor(row.count)}%`, backgroundColor: colorFor(index) }"
                    :class="active !== null && active !== index ? 'opacity-60' : ''"
                />
            </span>

            <span class="text-right text-xs font-semibold text-csc-ink tabular-nums">
                {{ format(row.count) }}
            </span>

            <span
                v-if="showPercent"
                class="text-right text-2xs text-csc-ink-subtle tabular-nums"
            >
                {{ percent(row.count, total) }}
            </span>
        </li>
    </ul>
</template>
