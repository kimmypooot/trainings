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
                'verified',
                // The refund pipeline. It gets its own entries rather than
                // being mapped onto pending/approved because the stage name is
                // the whole point of the badge on that screen.
                'for_review',
                'forwarded_to_msd',
                'for_release',
                'refunded',
                // The agency-request correspondence.
                'under_review',
                'requirements_sent',
                'confirmed',
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
    verified: {
        classes: 'bg-success-soft text-success',
        icon: 'M5 12.5l4.5 4.5L19 7.5',
        label: 'Verified',
    },
    // Refund stages. The three in-flight ones share the warning treatment —
    // they are all "still waiting", and distinguishing them by colour would
    // imply a severity difference that is not there. The label carries the
    // detail, which is the rule everywhere else in this map too.
    for_review: {
        classes: 'bg-warning-soft text-warning',
        icon: 'M12 7v5l3 2',
        label: 'For Review',
    },
    forwarded_to_msd: {
        classes: 'bg-warning-soft text-warning',
        icon: 'M5 12h12m-4-4l4 4-4 4',
        label: 'Forwarded to MSD',
    },
    for_release: {
        classes: 'bg-info-soft text-info',
        icon: 'M9 12.5l2 2 4-4',
        label: 'For Release',
    },
    refunded: {
        classes: 'bg-success-soft text-success',
        icon: 'M5 12.5l4.5 4.5L19 7.5',
        label: 'Refunded',
    },
    // Agency-request stages. Same reasoning as the refund pipeline: the ones
    // still in flight share the warning treatment because they are all "still
    // waiting", and the label carries which kind of waiting it is.
    under_review: {
        classes: 'bg-warning-soft text-warning',
        icon: 'M12 7v5l3 2',
        label: 'Under HRD Review',
    },
    requirements_sent: {
        classes: 'bg-info-soft text-info',
        icon: 'M5 12h12m-4-4l4 4-4 4',
        label: 'Requirements Sent',
    },
    confirmed: {
        classes: 'bg-info-soft text-info',
        icon: 'M9 12.5l2 2 4-4',
        label: 'Confirmed',
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
