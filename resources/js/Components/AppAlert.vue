<script setup>
import { computed } from 'vue';

const props = defineProps({
    tone: {
        type: String,
        default: 'info',
        validator: (value) => ['info', 'success', 'warning', 'danger'].includes(value),
    },
    title: { type: String, default: null },
});

const tones = {
    info: {
        classes: 'border-info/20 bg-info-soft text-info',
        icon: 'M12 16v-5m0-3.5v.5',
    },
    success: {
        classes: 'border-success/20 bg-success-soft text-success',
        icon: 'M5 12.5l4.5 4.5L19 7.5',
    },
    warning: {
        classes: 'border-warning/25 bg-warning-soft text-warning',
        icon: 'M12 8v5m0 3.5v.5',
    },
    danger: {
        classes: 'border-danger/25 bg-danger-soft text-danger',
        icon: 'M12 8v5m0 3.5v.5',
    },
};

const variant = computed(() => tones[props.tone]);
</script>

<template>
    <div
        class="flex items-start gap-3 rounded-xl border px-4 py-3.5"
        :class="variant.classes"
        :role="tone === 'danger' ? 'alert' : 'status'"
    >
        <svg
            class="mt-0.5 size-5 shrink-0"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
        >
            <circle v-if="tone !== 'success'" cx="12" cy="12" r="9" />
            <path :d="variant.icon" stroke-linecap="round" stroke-linejoin="round" />
        </svg>

        <div class="min-w-0 flex-1">
            <p v-if="title" class="text-sm font-semibold">{{ title }}</p>
            <div class="text-sm leading-relaxed" :class="title ? 'mt-1' : ''">
                <slot />
            </div>
        </div>

        <div v-if="$slots.action" class="shrink-0">
            <slot name="action" />
        </div>
    </div>
</template>
