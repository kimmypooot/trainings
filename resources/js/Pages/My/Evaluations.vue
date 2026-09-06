<script setup>
/**
 * What the participant still owes an evaluation on, and what they have already
 * filed — one row per training day.
 *
 * Days that are closed are shown with the reason rather than hidden, because
 * "why can't I evaluate day 2 yet" is otherwise a phone call to the office.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppStatTile from '@/Components/AppStatTile.vue';

const props = defineProps({
    trainings: { type: Array, required: true },
    pending: { type: Number, default: 0 },
});

const heading = computed(() =>
    props.pending === 0
        ? 'Nothing is waiting on you.'
        : `${props.pending} session${props.pending === 1 ? '' : 's'} still to evaluate.`
);

/*
 * The two figures the prose above already carries, given a shape the eye can
 * find without reading a paragraph.
 *
 * Counted here rather than asked of the server: every day of every training is
 * already in `trainings`, so a prop for this would be a second source for a
 * number the page can see, and the two would eventually disagree.
 *
 * "Submitted" is not the complement of "still to evaluate" — a day that has not
 * opened yet, and one whose sessions all carry over into a later day, are
 * neither — so both are counted rather than one being derived from the other.
 */
const days = computed(() => props.trainings.flatMap((training) => training.days));

const submitted = computed(() => days.value.filter((day) => day.submitted).length);
</script>

<template>
    <Head title="Session Evaluations" />

    <AuthenticatedLayout title="Session Evaluations" current="evaluations">
        <div class="mx-auto max-w-7xl space-y-5">
            <p class="text-sm leading-relaxed text-csc-ink-muted">
                {{ heading }} At the end of each training day you are asked to rate the subject
                matter experts who delivered it. Evaluations stay open after the day ends, so a
                session missed here can still be answered later.
            </p>

            <!--
                Capped, because a tile row divides the frame by however many
                tiles it has. The dashboard runs four across the 7xl frame and
                Payments three, giving tiles of roughly 300-390px; two tiles
                left unbounded in the same frame are ~590px each — the same
                component, twice the size, one screen apart. The cap keeps a
                summary figure looking like the summary figures everywhere else
                rather than like a pair of banners.
            -->
            <div v-if="days.length" class="grid grid-cols-2 gap-3 sm:max-w-3xl">
                <AppStatTile
                    label="Still to Evaluate"
                    :value="pending"
                    icon="clipboard"
                    :tone="pending > 0 ? 'warning' : 'brand'"
                    :caption="pending > 0 ? 'Open now, and staying open' : 'Nothing waiting on you'"
                />
                <AppStatTile
                    label="Submitted"
                    :value="submitted"
                    icon="check-circle"
                    :tone="submitted > 0 ? 'success' : 'brand'"
                    caption="Forms you have filed"
                />
            </div>

            <AppCard v-if="!trainings.length" :padded="false">
                <AppEmptyState
                    icon="clipboard"
                    title="No sessions to evaluate"
                    description="Once a training day you are registered for has taken place, its evaluation form appears here."
                >
                    <template #action>
                        <AppButton href="/my/registrations" variant="ghost">My Registrations</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <AppCard
                v-for="training in trainings"
                :key="training.registration_id"
                :title="training.title"
                :subtitle="`${training.training_code} · ${training.venue}`"
            >
                <ul class="divide-y divide-csc-line">
                    <li
                        v-for="day in training.days"
                        :key="day.day"
                        class="flex flex-col gap-3 py-3.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-csc-ink">
                                Day {{ day.day }}
                                <span class="font-normal text-csc-ink-muted">· {{ day.date }}</span>
                            </p>
                            <p
                                v-if="day.experts.length || day.continuing.length"
                                class="mt-0.5 text-xs text-csc-ink-subtle"
                            >
                                {{ [...day.experts, ...day.continuing].join(' · ') }}
                            </p>
                            <p
                                v-if="!day.open && !day.submitted"
                                class="mt-0.5 text-xs text-csc-ink-subtle"
                            >
                                {{ day.reason }}
                            </p>
                            <p v-if="day.submitted" class="mt-0.5 text-xs text-success">
                                <AppIcon name="check" class="inline size-3.5" aria-hidden="true" />
                                Submitted {{ day.submitted_at }}
                            </p>
                        </div>

                        <div class="shrink-0">
                            <AppButton
                                v-if="day.open && !day.submitted"
                                :href="day.url"
                                size="sm"
                                icon="clipboard"
                            >
                                Evaluate
                            </AppButton>
                            <!--
                                Ghost rather than a bare text link. It sat in
                                the same slot as the Evaluate button and was a
                                third of its height, so on a phone the row a
                                participant had already answered was the one
                                with nothing pressable in it.
                            -->
                            <AppButton
                                v-else-if="day.submitted"
                                :href="day.url"
                                size="sm"
                                variant="ghost"
                                icon="pencil"
                            >
                                View or Amend
                            </AppButton>
                            <!--
                                A day whose sessions all carry over never opens
                                — it is not waiting, it is folded into a later
                                day — so "Not yet open" would be a small lie the
                                participant would keep coming back to check.
                            -->
                            <span v-else class="text-xs text-csc-ink-subtle">
                                {{ !day.experts.length && day.continuing.length ? 'Continues' : 'Not yet open' }}
                            </span>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
