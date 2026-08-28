<script setup>
/**
 * Every run that collects evaluations, with how much of the room replied.
 *
 * Response rate leads because it is the number that decides whether the
 * averages on the next screen mean anything.
 */
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

defineProps({
    trainings: { type: Array, required: true },
});
</script>

<template>
    <Head title="Evaluations" />

    <AuthenticatedLayout title="Evaluations" current="admin-evaluations">
        <div class="mx-auto max-w-7xl space-y-5">
            <p class="text-sm leading-relaxed text-csc-ink-muted">
                How participants rated the subject matter experts on each run. A response rate is
                submissions received against one per participant per training day.
            </p>

            <AppCard v-if="!trainings.length" :padded="false">
                <AppEmptyState
                    icon="analytics"
                    title="No evaluations yet"
                    description="Assign subject matter experts to a training; participants are asked to evaluate them at the end of each training day."
                />
            </AppCard>

            <div v-else class="overflow-x-auto rounded-xl border border-csc-line bg-white">
                <table class="w-full min-w-3xl text-left text-sm">
                    <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Training</th>
                            <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Experts</th>
                            <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">
                                Submissions
                            </th>
                            <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">
                                Response rate
                            </th>
                            <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-csc-line">
                        <tr v-for="training in trainings" :key="training.id">
                            <td class="px-5 py-3.5">
                                <Link :href="training.url" class="font-medium text-csc-blue hover:underline">
                                    {{ training.title }}
                                </Link>
                                <p class="mt-0.5 text-xs text-csc-ink-subtle">
                                    {{ training.training_code }} · {{ training.starts_at }} ·
                                    {{ training.duration_days }}
                                    day{{ training.duration_days === 1 ? '' : 's' }} ·
                                    <!--
                                        Only worth spelling out where the two
                                        differ, which is exactly where somebody
                                        would otherwise read the denominator as
                                        wrong: a session carried across days is
                                        rated once, at its end.
                                    -->
                                    <template v-if="training.evaluation_days !== training.duration_days">
                                        {{ training.evaluation_days }} evaluated ·
                                    </template>
                                    {{ training.status_label }}
                                </p>
                            </td>
                            <td class="px-5 py-3.5 text-csc-ink-muted">{{ training.experts }}</td>
                            <td class="px-5 py-3.5 text-csc-ink-muted">
                                {{ training.submissions }}
                                <span class="text-xs text-csc-ink-subtle">/ {{ training.possible }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-csc-ink">
                                {{ training.response_rate === null ? '—' : `${training.response_rate}%` }}
                            </td>
                            <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                <Link
                                    :href="training.url"
                                    class="text-xs font-semibold text-csc-blue hover:underline"
                                >
                                    View results
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
