<script setup>
import AppBadge from '@/Components/AppBadge.vue';
import AppIcon from '@/Components/AppIcon.vue';
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
                <AppIcon name="calendar" size="sm" class="shrink-0 text-csc-blue/60" />
                <span>
                    <span class="font-semibold text-csc-ink">{{ program.starts_at }}</span>
                    <!-- A single-day run stores the same date twice; "Oct 4 – Oct 4" is noise. -->
                    <template v-if="program.ends_at && program.ends_at !== program.starts_at">
                        – <span class="font-semibold text-csc-ink">{{ program.ends_at }}</span>
                    </template>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <AppIcon name="map-pin" size="sm" class="shrink-0 text-csc-blue/60" />
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
                <AppIcon name="users" size="sm" class="shrink-0 text-csc-blue/60" />
                <span v-if="program.status === 'full'">No slots remaining</span>
                <span v-else-if="program.status === 'ongoing'">Currently in session</span>
                <span v-else>{{ slotsLabel(program.slots_remaining) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <!--
                    A price tag, not a currency sign.

                    Each icon in this list names the *kind* of fact its row
                    carries — calendar for the dates, pin for the venue, people
                    for the seats. A currency glyph broke that pattern by
                    restating the row's own content: it was a dollar sign for a
                    long time, which at least disagreed with the ₱ loudly enough
                    to be spotted, and correcting it to a peso only made the
                    duplication tidy ("₱ Fee ₱2,500.00"). The amount already
                    carries its currency, so the icon is free to do the job the
                    other three do.
                -->
                <AppIcon name="tag" size="sm" class="shrink-0 text-csc-blue/60" />
                <span v-if="program.payment_required">
                    Fee <span class="font-semibold text-csc-ink">₱{{ money(program.payment_amount) }}</span>
                </span>
                <span v-else class="font-semibold text-success">Free of charge</span>
            </div>
        </div>

        <span class="mt-auto inline-flex items-center gap-1.5 pt-6 text-sm font-semibold text-csc-blue">
            View details
            <AppIcon
                name="arrow-forward"
                size="sm"
                class="transition-transform duration-200 group-hover:translate-x-1"
            />
        </span>
    </button>
</template>
