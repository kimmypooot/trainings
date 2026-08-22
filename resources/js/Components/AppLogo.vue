<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'dark', // 'dark' = for light backgrounds, 'light' = for blue backgrounds
        validator: (value) => ['light', 'dark'].includes(value),
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
    wordmark: {
        type: Boolean,
        default: true,
    },
});

const light = computed(() => props.variant === 'light');

const markSize = computed(() => ({ sm: 'h-8', md: 'h-10', lg: 'h-14' })[props.size]);
const titleSize = computed(() => ({ sm: 'text-sm', md: 'text-base', lg: 'text-xl' })[props.size]);
const subSize = computed(() => ({ sm: 'text-[10px]', md: 'text-2xs', lg: 'text-xs' })[props.size]);
</script>

<template>
    <span class="inline-flex items-center gap-3">
        <img
            src="/images/csc-logo-256.png"
            alt=""
            :class="markSize"
            class="w-auto shrink-0 object-contain"
            aria-hidden="true"
        />

        <span v-if="wordmark" class="flex flex-col leading-none">
            <span
                :class="[titleSize, light ? 'text-white' : 'text-csc-blue']"
                class="font-semibold tracking-tight"
            >
                CSC <span class="font-normal">TIMS</span>
            </span>
            <span
                :class="[subSize, light ? 'text-white/70' : 'text-csc-ink-subtle']"
                class="mt-1 font-medium tracking-wide uppercase"
            >
                Civil Service Commission
            </span>
        </span>
    </span>
</template>
