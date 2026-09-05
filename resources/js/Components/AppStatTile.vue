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
    /*
     * Movement against the period before, as `{ direction, text, caption }` —
     * already formatted, because only the caller knows whether the figure is
     * pesos, a count or a rate.
     *
     * `direction` is coloured on the assumption that up is good, which is true
     * of every figure this is used for. A metric where a rise is *bad* — a
     * backlog, a rejection count — must not be given one of these: it would
     * paint a growing problem green. Show it as a plain caption instead.
     */
    delta: {
        type: Object,
        default: null,
        validator: (value) => value === null || ['up', 'down', 'flat'].includes(value.direction),
    },
});

const deltaSkin = {
    up: { chip: 'bg-success-soft text-success', icon: 'trend-up' },
    down: { chip: 'bg-danger-soft text-danger', icon: 'trend-down' },
    // Flat is not news, so it is told in the muted voice rather than a colour.
    flat: { chip: 'bg-csc-blue-tint text-csc-ink-muted', icon: null },
};

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

        <!--
            The icon sits beside the figure from `sm` up and above it below.

            Not a taste call, and the numbers are measured rather than guessed.
            The dashboard runs these two per row on a phone (deliberately — see
            Dashboard.vue), so at a 323px viewport a tile is 132px wide, 100px
            of it content, and the icon chip with its gap takes half: "₱2,050"
            needs 83px and was given 50, then clipped by the card's own
            `overflow-hidden`. It rendered as "₱2,05" — not a smaller number, a
            wrong one, on a revenue tile. Captions in the same column broke to
            one word per line. Stacking gives the figure the tile's full width
            where width is scarce and changes nothing where it is not.
        -->
        <div class="flex flex-col-reverse items-start gap-2 sm:flex-row sm:justify-between sm:gap-3">
            <!--
                `w-full` as well as `min-w-0`: stacked, a flex child sizes to
                its content, so without it the column would be as wide as the
                longest caption and overflow the card again rather than wrap.
            -->
            <div class="w-full min-w-0">
                <!--
                    Proportional figures, deliberately: tabular-nums gives every
                    digit the width of a zero, which makes a short number look
                    gappy at display size. Tabular is for columns that align.
                -->
                <p class="text-2xl font-bold sm:text-3xl" :class="skin.value">{{ value }}</p>
                <p class="mt-1 text-xs font-medium text-csc-ink-muted sm:text-sm">{{ label }}</p>

                <!--
                    The comparison sits under the label rather than beside the
                    number: it is the second thing read, and putting it on the
                    headline row makes two figures compete for the same glance.
                -->
                <div v-if="delta" class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-2xs font-semibold"
                        :class="deltaSkin[delta.direction].chip"
                    >
                        <AppIcon
                            v-if="deltaSkin[delta.direction].icon"
                            :name="deltaSkin[delta.direction].icon"
                            size="sm"
                        />
                        {{ delta.text }}
                    </span>
                    <span v-if="delta.caption" class="text-2xs text-csc-ink-subtle">{{ delta.caption }}</span>
                </div>

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
