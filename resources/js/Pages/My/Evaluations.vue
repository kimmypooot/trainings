<script setup>
/**
 * What the participant still owes an evaluation on, and what they have already
 * filed — one row per training day.
 *
 * Days that are closed are shown with the reason rather than hidden, because
 * "why can't I evaluate day 2 yet" is otherwise a phone call to the office.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    trainings: { type: Array, required: true },
    pending: { type: Number, default: 0 },
});

const heading = computed(() =>
    props.pending === 0
        ? 'Nothing is waiting on you.'
        : `${props.pending} session${props.pending === 1 ? '' : 's'} still to evaluate.`
);
</script>

<template>
    <Head title="Session Evaluations" />

    <AuthenticatedLayout title="Session Evaluations" current="evaluations">
        <div class="mx-auto max-w-5xl space-y-5">
            <p class="text-sm leading-relaxed text-csc-ink-muted">
                {{ heading }} At the end of each training day you are asked to rate the subject
                matter experts who delivered it. Evaluations stay open after the day ends, so a
                session missed here can still be answered later.
            </p>

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
                            <Link
                                v-else-if="day.submitted"
                                :href="day.url"
                                class="text-xs font-semibold text-csc-blue hover:underline"
                            >
                                View or amend
                            </Link>
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
