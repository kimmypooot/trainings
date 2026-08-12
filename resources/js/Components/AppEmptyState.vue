<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    icon: {
        type: String,
        default: 'M4 6h16M4 12h16M4 18h10',
    },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
        <span class="inline-flex size-14 items-center justify-center rounded-full bg-csc-blue-tint">
            <svg
                class="size-7 text-csc-blue"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                aria-hidden="true"
            >
                <path :d="icon" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>

        <h3 class="mt-5 text-base font-semibold text-csc-blue">{{ title }}</h3>
        <p v-if="description" class="mt-2 max-w-sm text-sm leading-relaxed text-csc-ink/65">
            {{ description }}
        </p>

        <div v-if="$slots.action" class="mt-6">
            <slot name="action" />
        </div>
    </div>
</template>
