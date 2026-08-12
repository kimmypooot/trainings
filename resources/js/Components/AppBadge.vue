<script setup>
import { computed } from 'vue';

/**
 * Status pill. Always renders an icon alongside the label — status is never
 * carried by colour alone, so it survives colour blindness and greyscale print.
 */
const props = defineProps({
    status: {
        type: String,
        default: 'neutral',
        validator: (value) =>
            [
                'neutral',
                'pending',
                'approved',
                'waitlisted',
                'rejected',
                'completed',
                'cancelled',
                'processing',
            ].includes(value),
    },
    label: { type: String, default: null },
});

const variants = {
    neutral: { classes: 'bg-csc-blue-tint text-csc-ink', icon: 'M12 8v4m0 4h.01', label: 'Unknown' },
    pending: {
        classes: 'bg-warning-soft text-warning',
        icon: 'M12 7v5l3 2',
        label: 'Pending Approval',
    },
    approved: {
        classes: 'bg-info-soft text-info',
        icon: 'M9 12.5l2 2 4-4',
        label: 'Approved',
    },
    rejected: {
        classes: 'bg-danger-soft text-danger',
        icon: 'M8 8l8 8M16 8l-8 8',
        label: 'Rejected',
    },
    completed: {
        classes: 'bg-success-soft text-success',
        icon: 'M5 12.5l4.5 4.5L19 7.5',
        label: 'Completed',
    },
    cancelled: {
        classes: 'bg-danger-soft text-danger',
        icon: 'M8 8l8 8M16 8l-8 8',
        label: 'Cancelled',
    },
    waitlisted: {
        classes: 'bg-warning-soft text-warning',
        icon: 'M12 7v5l3 2',
        label: 'Waitlisted',
    },
    processing: {
        classes: 'bg-warning-soft text-warning',
        icon: 'M12 7v5l3 2',
        label: 'Processing',
    },
};

const variant = computed(() => variants[props.status] ?? variants.neutral);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
        :class="variant.classes"
    >
        <svg
            class="size-3.5 shrink-0"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.2"
            aria-hidden="true"
        >
            <path :d="variant.icon" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        {{ label ?? variant.label }}
    </span>
</template>
