<script setup>
import { computed } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';
import { iconNames } from '@/icons';

/**
 * A headline figure with its label, an icon chip, and an optional sparkline.
 *
 * The right form when the data is one number. A one-bar bar chart is the most
 * common way a dashboard wastes a card; this is what to reach for instead.
 *
 * Distinct from AppStat, which is the app-wide stat used across the admin
 * shell and knows how to be a link or a button. This one is for the reports
 * page: it carries a tone, an icon, and the trend behind the number, and it
 * never navigates.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    /** Small print under the value — units, scope, a caveat. */
    caption: { type: String, default: null },
    icon: {
        type: String,
        default: null,
        validator: (value) => value === null || iconNames.includes(value),
    },
    /*
     * Semantic tone, not brand colour. Inside the signed-in app a red tile
     * means "something is wrong", never "this is CSC" — see the palette notes
     * in app.css.
     */
    tone: {
        type: String,
        default: 'brand',
        validator: (value) => ['brand', 'success', 'warning', 'danger'].includes(value),
    },
    /** Bare numbers, oldest first. Drawn as a 100%-wide sparkline. */
    spark: { type: Array, default: null },
});

const tones = {
    brand: {
        chip: 'bg-csc-blue-tint text-csc-blue',
        value: 'text-csc-blue',
        rule: 'from-csc-blue',
        stroke: 'var(--color-chart-1)',
    },
    success: {
        chip: 'bg-success-soft text-success',
        value: 'text-success',
        rule: 'from-success',
        stroke: 'var(--color-success)',
    },
    warning: {
        chip: 'bg-warning-soft text-warning',
        value: 'text-warning',
        rule: 'from-warning',
        stroke: 'var(--color-warning)',
    },
    danger: {
        chip: 'bg-danger-soft text-danger',
        value: 'text-danger',
        rule: 'from-danger',
        stroke: 'var(--color-danger)',
    },
};

const skin = computed(() => tones[props.tone]);

/*
 * The sparkline. No axis, no labels, no tooltip — it is texture behind the
 * number, showing only the shape of how it got here. Anyone who needs the
 * values reads the full chart further down the page.
 */
const sparkPath = computed(() => {
    const points = (props.spark ?? []).map(Number).filter((n) => Number.isFinite(n));

    if (points.length < 2) return null;

    const peak = Math.max(...points);
    const floor = Math.min(...points);
    const span = peak - floor || 1;

    return points
        .map((point, index) => {
            const x = (index / (points.length - 1)) * 100;
            // 2px of headroom top and bottom so the stroke is not clipped by
            // the viewBox at the series' own maximum and minimum.
            const y = 22 - ((point - floor) / span) * 20;

            return `${index === 0 ? 'M' : 'L'}${x.toFixed(2)} ${y.toFixed(2)}`;
        })
        .join(' ');
});
</script>

<template>
    <div
        class="relative overflow-hidden rounded-xl border border-csc-line bg-white p-4 transition-shadow duration-150 hover:shadow-sm sm:p-5"
    >
        <!-- A hairline of tone across the top edge — the accent, kept small. -->
        <span
            class="absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r to-transparent"
            :class="skin.rule"
            aria-hidden="true"
        />

        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <!--
                    Proportional figures, deliberately: tabular-nums gives every
                    digit the width of a zero, which makes a short number look
                    gappy at display size. Tabular is for columns that align.
                -->
                <p class="text-2xl font-bold sm:text-3xl" :class="skin.value">{{ value }}</p>
                <p class="mt-1 text-xs font-medium text-csc-ink-muted sm:text-sm">{{ label }}</p>
                <p v-if="caption" class="mt-0.5 text-2xs text-csc-ink-subtle">{{ caption }}</p>
            </div>

            <span
                v-if="icon"
                class="grid size-9 shrink-0 place-items-center rounded-lg"
                :class="skin.chip"
                aria-hidden="true"
            >
                <AppIcon :name="icon" size="sm" />
            </span>
        </div>

        <svg
            v-if="sparkPath"
            class="mt-3 block h-6 w-full"
            viewBox="0 0 100 24"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <path
                :d="sparkPath"
                fill="none"
                :stroke="skin.stroke"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
            />
        </svg>
    </div>
</template>
