<script setup>
import AppBadge from '@/Components/AppBadge.vue';
import ProgramStatusPill from '@/Components/ProgramStatusPill.vue';

/**
 * One program in the public catalogue.
 *
 * Shared by the landing page and /programs so a visitor who follows "View all
 * programs" does not meet the same run described two different ways. The shape
 * is PublicCatalogService::card().
 *
 * The whole card is the button — clicking anywhere opens the detail modal the
 * parent owns.
 */
defineProps({
    program: { type: Object, required: true },
});

defineEmits(['open']);

// null capacity means the run has no cap, so there is nothing to count down.
const slotsLabel = (remaining) =>
    remaining === null ? 'Open enrolment' : `${remaining} ${remaining === 1 ? 'slot' : 'slots'} left`;

const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
</script>

<template>
    <button
        type="button"
        class="group relative flex cursor-pointer flex-col overflow-hidden rounded-2xl border border-csc-line bg-white p-7 text-left shadow-sm transition duration-200 hover:-translate-y-1 hover:border-csc-blue/30 hover:shadow-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
        @click="$emit('open', program)"
    >
        <!--
            A solid gradient top bar replaces the old hover-only underline, so
            each card carries its identity even before a visitor hovers.
        -->
        <span
            class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-csc-blue to-csc-blue-deep"
            aria-hidden="true"
        />

        <!--
            Status leads the card. It is the one thing that decides whether the
            rest of the card is actionable, so it sits first.
        -->
        <div class="flex flex-wrap items-center gap-2">
            <ProgramStatusPill :status="program.status" :label="program.status_label" />
            <span class="inline-flex items-center rounded-full bg-csc-blue-tint px-3 py-1 text-xs font-semibold text-csc-blue">
                {{ program.mode }}
            </span>
            <AppBadge v-if="program.is_supervisory" status="supervisory" />
        </div>

        <h3 class="mt-5 text-lg leading-snug font-semibold text-csc-blue">{{ program.title }}</h3>

        <div class="mt-5 space-y-2.5 text-sm text-csc-ink/70">
            <div class="flex items-center gap-2">
                <svg class="size-4 shrink-0 text-csc-blue/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z" />
                </svg>
                <span>
                    <span class="font-semibold text-csc-ink">{{ program.starts_at }}</span>
                    <!-- A single-day run stores the same date twice; "Oct 4 – Oct 4" is noise. -->
                    <template v-if="program.ends_at && program.ends_at !== program.starts_at">
                        – <span class="font-semibold text-csc-ink">{{ program.ends_at }}</span>
                    </template>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <svg class="size-4 shrink-0 text-csc-blue/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11Z" />
                </svg>
                <span>{{ program.venue }}</span>
            </div>
            <!--
                A seat count only means something while seats can still be
                taken; on a full or already-running program it is noise at best
                and contradicts the status pill at worst. The fee is the other
                thing a visitor decides on before opening the modal, so it
                belongs on the card rather than behind a click.
            -->
            <div class="flex items-center gap-2">
                <svg class="size-4 shrink-0 text-csc-blue/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
                <span v-if="program.status === 'full'">No slots remaining</span>
                <span v-else-if="program.status === 'ongoing'">Currently in session</span>
                <span v-else>{{ slotsLabel(program.slots_remaining) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <svg class="size-4 shrink-0 text-csc-blue/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 3v18M16 7.5C16 6 14.2 5 12 5S8 6 8 7.5 9.8 10.5 12 11s4 1.5 4 3-1.8 2.5-4 2.5-4-1-4-2.5" />
                </svg>
                <span v-if="program.payment_required">
                    Fee <span class="font-semibold text-csc-ink">₱{{ money(program.payment_amount) }}</span>
                </span>
                <span v-else class="font-semibold text-success">Free of charge</span>
            </div>
        </div>

        <span class="mt-auto inline-flex items-center gap-1.5 pt-6 text-sm font-semibold text-csc-blue">
            View details
            <svg
                class="size-4 transition-transform duration-200 group-hover:translate-x-1"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M5 12h14M13 5l7 7-7 7" />
            </svg>
        </span>
    </button>
</template>
