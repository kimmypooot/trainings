<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { GRID, SLOTS, formatCount, niceTicks } from '@/charts';

/**
 * A single series over time, as an area under a line.
 *
 * One series, so there is no legend — the card's title already says what is
 * plotted, and a legend box with one swatch just restates it. Colour is doing
 * no work here beyond looking like the rest of the app, which is exactly why
 * this form is safe: the shape carries the meaning.
 *
 * Drawn in real pixels off a measured container rather than in a scaled
 * viewBox. A viewBox stretched to the container distorts the stroke — a 2px
 * line drawn in a 600-wide box and displayed at 900 comes out 3px horizontally
 * and 2px vertically, which is why scaled charts look subtly wrong. Measuring
 * costs a ResizeObserver and gets honest geometry.
 *
 * Every value is also reachable without the chart: the table toggle below it
 * is the accessible twin, not a nicety. A tooltip is never the only way to
 * read a number.
 */
const props = defineProps({
    /** `[{ label, value }]`, oldest first. */
    rows: { type: Array, required: true },
    /** What one point *is* — used in the tooltip and the table header. */
    valueLabel: { type: String, default: 'Count' },
    /** Formatter for values in the tooltip and the table. */
    format: { type: Function, default: formatCount },
    /*
     * Formatter for the axis ticks, when the full one is too wide for them.
     * Money is the case this exists for: two decimals and a thousands comma
     * belong in the tooltip, but on a 52px axis they collide into a smear.
     */
    tickFormat: { type: Function, default: null },
    /** Plot height in pixels; the axis band is added on top of this. */
    height: { type: Number, default: 190 },
    emptyText: { type: String, default: 'Nothing to show yet.' },
});

const PAD = { top: 14, right: 14, bottom: 26 };
const AXIS_WIDTH = 52;

// SVG ids are global to the document, so two of these on one page would share
// a gradient definition. useId() gives each instance its own.
const uid = useId();

const container = ref(null);
const width = ref(640);
const active = ref(null);
const showTable = ref(false);

let observer = null;

onMounted(() => {
    if (!container.value) return;

    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(240, Math.round(entry.contentRect.width));
    });
    observer.observe(container.value);
    width.value = Math.max(240, Math.round(container.value.clientWidth));
});

onBeforeUnmount(() => observer?.disconnect());

const values = computed(() => props.rows.map((row) => Number(row.value ?? 0)));
const peak = computed(() => Math.max(...values.value, 0));

const ticks = computed(() => niceTicks(peak.value));
const ceiling = computed(() => Math.max(...ticks.value, 1));

const totalHeight = computed(() => props.height + PAD.top + PAD.bottom);
const plotLeft = AXIS_WIDTH;
const plotRight = computed(() => width.value - PAD.right);
const plotWidth = computed(() => Math.max(1, plotRight.value - plotLeft));
const baseline = computed(() => PAD.top + props.height);

// A single point has no span to spread across, so it is pinned to the middle
// rather than to x=0, where it would sit on the y-axis and read as a stray.
function xAt(index) {
    if (props.rows.length <= 1) return plotLeft + plotWidth.value / 2;

    return plotLeft + (index / (props.rows.length - 1)) * plotWidth.value;
}

function yAt(value) {
    return baseline.value - (Number(value ?? 0) / ceiling.value) * props.height;
}

const points = computed(() => props.rows.map((row, index) => ({ x: xAt(index), y: yAt(row.value) })));

const linePath = computed(() =>
    points.value.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x} ${point.y}`).join(' ')
);

const areaPath = computed(() => {
    if (!points.value.length) return '';

    const first = points.value[0];
    const last = points.value[points.value.length - 1];

    return `${linePath.value} L${last.x} ${baseline.value} L${first.x} ${baseline.value} Z`;
});

/*
 * Show every nth x label, where n is whatever keeps them from touching. The
 * alternative — rotating them 45° — buys a little room and costs a lot of
 * legibility, and twelve months at ~58px apiece fits on anything wider than a
 * phone anyway.
 */
const labelStep = computed(() => {
    const fits = Math.max(1, Math.floor(plotWidth.value / 58));

    return Math.ceil(props.rows.length / fits);
});

// The last label is always drawn: a time axis whose right edge is unlabelled
// leaves the reader guessing what "now" is.
function showsLabel(index) {
    return index === props.rows.length - 1 || index % labelStep.value === 0;
}

const activeRow = computed(() => (active.value === null ? null : props.rows[active.value]));

function nearest(event) {
    const box = container.value?.getBoundingClientRect();
    if (!box || props.rows.length === 0) return;

    const x = event.clientX - box.left;
    const ratio = (x - plotLeft) / plotWidth.value;
    const index = Math.round(ratio * Math.max(1, props.rows.length - 1));

    active.value = Math.min(props.rows.length - 1, Math.max(0, index));
}

// Keyboard parity with hover: the same crosshair, driven by arrow keys, so the
// tooltip is not a mouse-only affordance.
function step(delta) {
    const from = active.value ?? (delta > 0 ? -1 : props.rows.length);

    active.value = Math.min(props.rows.length - 1, Math.max(0, from + delta));
}

const summary = computed(
    () =>
        `${props.valueLabel} over ${props.rows.length} periods, ` +
        `from ${props.rows[0]?.label ?? '—'} to ${props.rows[props.rows.length - 1]?.label ?? '—'}. ` +
        `Peak ${props.format(peak.value)}.`
);
</script>

<template>
    <p v-if="!rows.length" class="text-sm text-csc-ink-subtle">{{ emptyText }}</p>

    <div v-else>
        <div
            ref="container"
            class="relative"
            @pointermove="nearest"
            @pointerleave="active = null"
        >
            <svg
                :width="width"
                :height="totalHeight"
                :viewBox="`0 0 ${width} ${totalHeight}`"
                class="block w-full"
                role="img"
                :aria-label="summary"
                tabindex="0"
                @keydown.left.prevent="step(-1)"
                @keydown.right.prevent="step(1)"
                @keydown.esc="active = null"
                @focus="active = rows.length - 1"
                @blur="active = null"
            >
                <defs>
                    <!--
                        The area is a wash, not a block: a saturated fill under
                        the line competes with the line for attention and makes
                        the whole card read loud.
                    -->
                    <linearGradient :id="`trend-wash-${uid}`" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" :stop-color="SLOTS[0]" stop-opacity="0.22" />
                        <stop offset="100%" :stop-color="SLOTS[0]" stop-opacity="0.02" />
                    </linearGradient>
                </defs>

                <!-- Gridlines: solid hairlines, one step off the surface. -->
                <g>
                    <line
                        v-for="tick in ticks"
                        :key="`grid-${tick}`"
                        :x1="plotLeft"
                        :x2="plotRight"
                        :y1="yAt(tick)"
                        :y2="yAt(tick)"
                        :stroke="GRID"
                        stroke-width="1"
                        shape-rendering="crispEdges"
                    />
                    <text
                        v-for="tick in ticks"
                        :key="`tick-${tick}`"
                        :x="plotLeft - 10"
                        :y="yAt(tick) + 4"
                        text-anchor="end"
                        class="fill-csc-ink-subtle text-2xs tabular-nums"
                    >
                        {{ (tickFormat ?? format)(tick) }}
                    </text>
                </g>

                <path :d="areaPath" :fill="`url(#trend-wash-${uid})`" />
                <path
                    :d="linePath"
                    fill="none"
                    :stroke="SLOTS[0]"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />

                <!-- x labels -->
                <text
                    v-for="(row, index) in rows"
                    v-show="showsLabel(index)"
                    :key="`x-${row.label}`"
                    :x="xAt(index)"
                    :y="totalHeight - 8"
                    :text-anchor="index === 0 ? 'start' : index === rows.length - 1 ? 'end' : 'middle'"
                    class="fill-csc-ink-subtle text-2xs"
                >
                    {{ row.label }}
                </text>

                <!-- Crosshair + the point being read. -->
                <template v-if="active !== null">
                    <line
                        :x1="xAt(active)"
                        :x2="xAt(active)"
                        :y1="PAD.top"
                        :y2="baseline"
                        :stroke="SLOTS[0]"
                        stroke-width="1"
                        stroke-opacity="0.35"
                    />
                    <circle
                        :cx="xAt(active)"
                        :cy="yAt(rows[active].value)"
                        r="5"
                        :fill="SLOTS[0]"
                        stroke="#ffffff"
                        stroke-width="2"
                    />
                </template>

                <!--
                    The end dot marks where the series stops, so a flat tail is
                    not mistaken for the chart running off the edge. Hidden
                    while another point is being read, to leave one dot on
                    screen at a time.
                -->
                <circle
                    v-if="active === null"
                    :cx="xAt(rows.length - 1)"
                    :cy="yAt(rows[rows.length - 1].value)"
                    r="5"
                    :fill="SLOTS[0]"
                    stroke="#ffffff"
                    stroke-width="2"
                />
            </svg>

            <div
                v-if="activeRow"
                class="pointer-events-none absolute top-2 z-[--z-popover] -translate-x-1/2 rounded-lg border border-csc-line bg-white px-3 py-2 shadow-lg"
                :style="{
                    left: `${Math.min(Math.max(xAt(active), 70), width - 70)}px`,
                }"
            >
                <p class="text-2xs font-medium text-csc-ink-subtle">{{ activeRow.label }}</p>
                <p class="text-sm font-semibold text-csc-ink tabular-nums">
                    {{ format(activeRow.value) }}
                    <span class="font-normal text-csc-ink-subtle">{{ valueLabel.toLowerCase() }}</span>
                </p>
            </div>
        </div>

        <!--
            The accessible twin. Every value in the chart is here as text, so
            nothing above is gated behind a hover.
        -->
        <div class="mt-3 flex justify-end print:hidden">
            <button
                type="button"
                class="rounded-md px-2 py-1 text-2xs font-medium text-csc-ink-subtle transition-colors duration-150 hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                :aria-expanded="showTable"
                @click="showTable = !showTable"
            >
                {{ showTable ? 'Hide table' : 'View as table' }}
            </button>
        </div>

        <div v-show="showTable" class="mt-1 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-csc-line text-2xs uppercase">
                    <tr>
                        <th scope="col" class="py-2 pr-4 font-semibold text-csc-ink-muted">Period</th>
                        <th scope="col" class="py-2 text-right font-semibold text-csc-ink-muted">
                            {{ valueLabel }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-csc-line">
                    <tr v-for="row in rows" :key="row.label">
                        <td class="py-2 pr-4 text-csc-ink-muted">{{ row.label }}</td>
                        <td class="py-2 text-right font-medium text-csc-ink tabular-nums">
                            {{ format(row.value) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
