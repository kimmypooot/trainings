<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    variant: {
        type: String,
        default: 'primary', // primary | accent | ghost
        validator: (value) => ['primary', 'accent', 'ghost'].includes(value),
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
    // Use on blue backgrounds, where the filled-blue primary would disappear.
    onDark: { type: Boolean, default: false },
});

const base =
    'relative inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60';

const sizes = {
    sm: 'px-4 py-2 text-sm',
    md: 'px-5 py-2.5 text-sm',
    lg: 'px-7 py-3.5 text-base',
};

const variants = computed(() => ({
    primary: props.onDark
        ? 'bg-white text-csc-blue hover:bg-csc-blue-tint focus-visible:outline-white'
        : 'bg-csc-blue text-white hover:bg-csc-red-ink focus-visible:outline-csc-red',
    accent: 'bg-csc-red-ink text-white hover:bg-csc-blue focus-visible:outline-csc-blue',
    ghost: props.onDark
        ? 'border border-white/60 text-white hover:border-white hover:bg-white/10 focus-visible:outline-white'
        : 'border border-csc-blue/30 text-csc-blue hover:border-csc-blue hover:bg-csc-blue-tint focus-visible:outline-csc-blue',
}));

const classes = computed(() => [
    base,
    sizes[props.size],
    variants.value[props.variant],
    props.block ? 'w-full' : '',
]);

const isDisabled = computed(() => props.disabled || props.loading);
</script>

<template>
    <Link v-if="href && !isDisabled" :href="href" :class="classes">
        <slot />
    </Link>

    <button v-else :type="type" :class="classes" :disabled="isDisabled">
        <svg
            v-if="loading"
            class="size-4 animate-spin"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.3" stroke-width="3" />
            <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <slot />
    </button>
</template>
