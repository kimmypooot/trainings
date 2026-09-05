<script setup>
/**
 * Every run that collects evaluations, with how much of the room replied.
 *
 * Response rate leads because it is the number that decides whether the
 * averages on the next screen mean anything.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppStatTile from '@/Components/AppStatTile.vue';

const props = defineProps({
    trainings: { type: Array, required: true },
});

/*
 * The rows say how each run did; this says how the programme is doing.
 *
 * The overall rate is submissions over forms asked for across every run, not
 * the mean of the per-run rates — a two-person run at 100% would otherwise
 * count for as much as a two-hundred-person run at 40%, and the headline would
 * flatter the office exactly when it should not.
 *
 * Derived on the client because the page already holds every row; this list is
 * not paginated. If it grows a paginator these move to the controller.
 */
const summary = computed(() => {
    const submissions = props.trainings.reduce((total, run) => total + run.submissions, 0);
    const possible = props.trainings.reduce((total, run) => total + run.possible, 0);

    return {
        runs: props.trainings.length,
        submissions,
        possible,
        rate: possible > 0 ? Math.round((submissions / possible) * 100) : null,
        // Runs where nobody has replied at all. Distinct from a low rate: a
        // zero usually means the day codes were never handed out, which is a
        // fixable thing rather than a verdict on the session.
        silent: props.trainings.filter((run) => run.submissions === 0).length,
    };
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

            <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStatTile label="Runs Evaluated" :value="summary.runs" icon="calendar" />
                <AppStatTile label="Submissions" :value="summary.submissions" icon="clipboard" />
                <!--
                    The bands are about whether the averages on the next screen
                    can be trusted, which is what this page exists to tell you:
                    below half the room, a run's rating is a handful of opinions.
                -->
                <AppStatTile
                    label="Response Rate"
                    :value="summary.rate === null ? '—' : `${summary.rate}%`"
                    icon="analytics"
                    :caption="`${summary.submissions} of ${summary.possible} forms asked for`"
                    :tone="
                        summary.rate === null
                            ? 'brand'
                            : summary.rate >= 70
                              ? 'success'
                              : summary.rate >= 50
                                ? 'warning'
                                : 'danger'
                    "
                />
                <AppStatTile
                    label="No Responses"
                    :value="summary.silent"
                    icon="warning"
                    :tone="summary.silent > 0 ? 'warning' : 'success'"
                    :caption="summary.silent > 0 ? 'Check the day codes were shared' : 'Every run has replies'"
                />
            </div>

            <div v-if="trainings.length" class="overflow-x-auto rounded-xl border border-csc-line bg-white">
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
