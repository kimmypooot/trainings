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
    /** `[{ key, label, icon? }]` — icons are decorative, the label carries it. */
    tabs: { type: Array, required: true },
    ariaLabel: { type: String, required: true },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md'].includes(value),
    },
});

defineEmits(['update:modelValue']);

const sizes = {
    sm: 'px-3 py-1.5 text-xs',
    md: 'px-4 py-2 text-sm',
};
</script>

<template>
    <div
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
                sizes[size],
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
