<script setup>
import AppBadge from '@/Components/AppBadge.vue';

/**
 * A participant's proof of supervisory function, as it appears on the roster.
 *
 * The other half of the table/card split — see RosterActions for the why. The
 * cards were showing the badge, the link and the two review buttons but not the
 * reviewer's name and date, which is the part that answers "has someone already
 * looked at this?" and stops two staff verifying the same document twice.
 *
 * `row` stacks under a table cell and caps the remarks so one long note cannot
 * widen the column; `card` lays the controls out in a wrapping line.
 */
defineProps({
    document: { type: Object, default: null },
    name: { type: String, required: true },
    layout: {
        type: String,
        default: 'row',
        validator: (value) => ['row', 'card'].includes(value),
    },
});

defineEmits(['decide']);

const link = 'rounded text-xs font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue';
</script>

<template>
    <template v-if="document">
        <div class="flex flex-wrap items-center gap-2">
            <AppBadge :status="`document_${document.status}`" />
            <a
                v-if="document.download_url"
                :href="document.download_url"
                class="shrink-0 rounded text-xs font-medium text-csc-blue underline underline-offset-2 hover:text-csc-blue-deep"
            >
                <span class="sr-only">View proof of supervisory function for {{ name }}</span>
                <span aria-hidden="true">View</span>
            </a>

            <!--
                On a card the review buttons join the badge's line; in a table
                cell they drop below it, where the column is narrow.
            -->
            <template v-if="document.can_review && layout === 'card'">
                <button type="button" :class="[link, 'text-success']" @click="$emit('decide', 'verified')">
                    Verify
                </button>
                <button type="button" :class="[link, 'text-danger']" @click="$emit('decide', 'rejected')">
                    Reject
                </button>
            </template>
        </div>

        <div v-if="document.can_review && layout === 'row'" class="mt-1.5 flex gap-2">
            <button type="button" :class="[link, 'text-success']" @click="$emit('decide', 'verified')">
                Verify
            </button>
            <span class="text-csc-line">|</span>
            <button type="button" :class="[link, 'text-danger']" @click="$emit('decide', 'rejected')">
                Reject
            </button>
        </div>

        <p
            v-if="document.remarks"
            class="mt-1 text-xs text-csc-ink-subtle"
            :class="layout === 'row' ? 'max-w-48' : ''"
        >
            {{ document.remarks }}
        </p>
        <p v-else-if="document.reviewed_by" class="mt-1 text-2xs text-csc-ink-subtle">
            {{ document.reviewed_by }}
            <template v-if="document.reviewed_at"> · {{ document.reviewed_at }}</template>
        </p>
    </template>

    <span v-else-if="layout === 'row'" class="text-xs text-csc-ink-subtle">—</span>
</template>
