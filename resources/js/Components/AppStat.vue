<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * A count and its label.
 *
 * Renders as a link when it has somewhere to go, a button when it opens
 * something in place, and a plain figure when the number is the whole story —
 * so a stat never advertises an interaction it does not have.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], required: true },
    href: { type: String, default: null },
    /** Set for a stat that opens a dialog rather than navigating. */
    action: { type: Boolean, default: false },
});

const interactive = computed(() => Boolean(props.href) || props.action);

const tag = computed(() => {
    if (props.href) return Link;

    return props.action ? 'button' : 'div';
});
</script>

<template>
    <component
        :is="tag"
        :href="href ?? undefined"
        :type="action && !href ? 'button' : undefined"
        class="block rounded-xl border border-csc-line bg-white p-4 sm:p-5"
        :class="
            interactive
                ? 'w-full text-left transition-colors duration-150 hover:border-csc-blue/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue'
                : ''
        "
    >
        <span class="block text-2xl font-bold text-csc-blue sm:text-3xl">{{ value }}</span>
        <span class="mt-1 block text-xs font-medium text-csc-ink-subtle sm:text-sm">{{ label }}</span>
    </component>
</template>
