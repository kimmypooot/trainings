<script setup>
import { computed } from 'vue';

/**
 * Where a program sits in its public registration lifecycle.
 *
 * Deliberately not AppBadge: that component's status list is the signed-in
 * app's internal one (registration, refund, and delivery pipelines), and these
 * six describe something different — a *program*, not a participant's slot in
 * it. Folding them in would mix two vocabularies in one component for the sake
 * of reuse alone.
 *
 * Like AppBadge, though, each carries an icon as well as a colour, so the
 * status survives colour blindness, greyscale, and print.
 *
 * The keys mirror PublicCatalogService::registrationState().
 */
const props = defineProps({
    status: { type: String, required: true },
    label: { type: String, required: true },
});

const styles = {
    open: {
        classes: 'bg-success-soft text-success',
        icon: 'M5 12.5l4.5 4.5L19 7.5',
    },
    'closing-soon': {
        classes: 'bg-warning-soft text-warning',
        icon: 'M12 7v5l3 2M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18Z',
    },
    opening: {
        classes: 'bg-csc-blue-tint text-csc-blue',
        icon: 'M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z',
    },
    ongoing: {
        classes: 'bg-info-soft text-info',
        icon: 'M10 8l6 4-6 4V8ZM12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18Z',
    },
    full: {
        classes: 'bg-csc-line/60 text-csc-ink/80',
        icon: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8ZM22 21v-2a4 4 0 0 0-3-3.87',
    },
    closed: {
        classes: 'bg-csc-line/60 text-csc-ink/80',
        icon: 'M7 11V8a5 5 0 0 1 10 0v3M5 11h14v10H5V11Z',
    },
};

// An unrecognised key falls back to the neutral-blue treatment rather than
// rendering an unstyled pill, so a new server-side status degrades quietly.
const style = computed(() => styles[props.status] ?? styles.opening);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
        :class="style.classes"
    >
        <svg
            class="size-3.5"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path :d="style.icon" />
        </svg>
        {{ label }}
    </span>
</template>
