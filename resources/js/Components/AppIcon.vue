<script setup>
import { computed } from 'vue';
import { icons } from '@/icons';

/**
 * The only place icon SVG attributes are written down.
 *
 * Decorative by default: an icon next to a text label is noise to a screen
 * reader. Pass `label` for the rare standalone icon that carries the meaning
 * on its own, and it becomes an image with an accessible name instead.
 */
const props = defineProps({
    name: {
        type: String,
        required: true,
        validator: (value) => value in icons,
    },
    /*
     * Sizing is a prop rather than a passed-in class: a `size-6` arriving by
     * fallthrough would not reliably beat a `size-5` default, because Tailwind
     * resolves that collision by stylesheet order rather than by which class
     * was written last.
     */
    size: {
        type: String,
        default: 'md', // sm | md | lg
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
    label: { type: String, default: null },
});

const sizes = { sm: 'size-4', md: 'size-5', lg: 'size-6' };

const path = computed(() => icons[props.name]);
</script>

<template>
    <svg
        :class="sizes[size]"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        :aria-hidden="label ? undefined : true"
        :role="label ? 'img' : undefined"
        :aria-label="label ?? undefined"
    >
        <path :d="path" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</template>
