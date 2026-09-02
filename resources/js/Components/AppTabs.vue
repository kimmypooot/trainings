<script setup>
import AppIcon from '@/Components/AppIcon.vue';
import { iconNames } from '@/icons';

/**
 * A segmented control for switching between views of the same thing.
 *
 * Extracted from the analytics page, which had three hand-copied copies of the
 * same fifteen lines of tab markup — the page tabs and the revenue/breakdown
 * sub-tabs on both report views. They had already drifted apart in padding by
 * the time this was pulled out.
 *
 * Tabs, not buttons: the control switches which view of one dataset is on
 * screen, so `role="tablist"` and `aria-selected` are the honest markup. It
 * does not manage focus between panels, because in every use here the panel is
 * the rest of the page rather than a labelled region.
 *
 * Hidden in print. A printed report shows the view that was on screen; a strip
 * of tabs the reader cannot press is just ink.
 */
defineProps({
    modelValue: { type: String, required: true },
    /**
     * `[{ key, label, icon?, count? }]` — icons are decorative, the label
     * carries it. `count` renders only in the underline variant.
     */
    tabs: { type: Array, required: true },
    ariaLabel: { type: String, required: true },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md'].includes(value),
    },
    /*
     * `segmented` is the pill strip this was extracted for — a small control
     * inside a card, swapping one figure for another.
     *
     * `underline` is for a page whose whole body changes: a full-width row of
     * folder tabs sitting on a rule, the shape everyone already reads as "these
     * are the sections of this page". The roster needed it because its sections
     * are different *jobs* — marking attendance is not taking a payment — and a
     * pill strip four words wide did not carry that.
     */
    variant: {
        type: String,
        default: 'segmented',
        validator: (value) => ['segmented', 'underline'].includes(value),
    },
});

defineEmits(['update:modelValue']);

const sizes = {
    segmented: {
        sm: 'px-3 py-1.5 text-xs',
        md: 'px-4 py-2 text-sm',
    },
    underline: {
        sm: 'px-3 py-2 text-xs',
        md: 'px-4 py-3 text-sm',
    },
};
</script>

<template>
    <div
        v-if="variant === 'underline'"
        class="-mb-0.5 flex flex-wrap items-end gap-1 border-b-2 border-csc-line print:hidden"
        role="tablist"
        :aria-label="ariaLabel"
    >
        <!--
            The selected tab is drawn as a tab rather than as a highlight: it
            keeps the page's white, carries the border up its own two sides, and
            covers the rule the unselected ones stay under. That overlap is the
            whole reason the shape reads as a folder tab, so the negative
            margins here are load-bearing rather than nudges.
        -->
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            role="tab"
            :aria-selected="modelValue === tab.key"
            class="-mb-0.5 inline-flex items-center gap-1.5 rounded-t-lg border-2 font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-csc-blue"
            :class="[
                sizes.underline[size],
                modelValue === tab.key
                    ? 'border-csc-line border-b-white bg-white text-csc-blue-deep'
                    : 'border-transparent text-csc-ink-muted hover:bg-csc-blue-tint/60 hover:text-csc-blue',
            ]"
            @click="$emit('update:modelValue', tab.key)"
        >
            <AppIcon v-if="tab.icon && iconNames.includes(tab.icon)" :name="tab.icon" size="sm" />
            {{ tab.label }}
            <!--
                The count is a number beside its own label, never a colour on
                its own, so it survives greyscale and a printed screenshot.
            -->
            <span
                v-if="tab.count !== undefined && tab.count !== null"
                class="rounded-full px-1.5 py-0.5 text-2xs font-bold"
                :class="modelValue === tab.key ? 'bg-csc-blue text-white' : 'bg-csc-blue-tint text-csc-ink-muted'"
            >
                {{ tab.count }}
            </span>
        </button>
    </div>

    <div
        v-else
        class="inline-flex flex-wrap gap-1 rounded-xl bg-csc-blue-tint p-1 print:hidden"
        role="tablist"
        :aria-label="ariaLabel"
    >
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            role="tab"
            :aria-selected="modelValue === tab.key"
            class="inline-flex items-center gap-1.5 rounded-lg font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
            :class="[
                sizes.segmented[size],
                modelValue === tab.key
                    ? 'bg-csc-blue text-white shadow-sm'
                    : 'text-csc-ink-muted hover:bg-white/70 hover:text-csc-blue',
            ]"
            @click="$emit('update:modelValue', tab.key)"
        >
            <AppIcon v-if="tab.icon && iconNames.includes(tab.icon)" :name="tab.icon" size="sm" />
            {{ tab.label }}
        </button>
    </div>
</template>
