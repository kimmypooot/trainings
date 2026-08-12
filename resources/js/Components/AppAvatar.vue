<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, default: '' },
    src: { type: String, default: null },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
});

const sizes = { sm: 'size-8 text-xs', md: 'size-10 text-sm', lg: 'size-14 text-lg' };

const initials = computed(() => {
    const parts = (props.name ?? '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';

    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
});
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-csc-blue font-semibold text-white"
        :class="sizes[size]"
    >
        <img v-if="src" :src="src" alt="" class="size-full object-cover" />
        <template v-else>{{ initials }}</template>
    </span>
</template>
