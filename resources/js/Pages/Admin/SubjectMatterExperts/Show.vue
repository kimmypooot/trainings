<script setup>
/**
 * One expert's record, their assignments, and how participants have rated them.
 *
 * The per-criterion breakdown is the useful half: an expert whose overall 4.2
 * is made of 4.8 on expertise and 3.4 on pacing is a coaching conversation, not
 * a scheduling decision, and an average alone hides that.
 */
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppStat from '@/Components/AppStat.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import { formatDateRange } from '@/dateRange';

defineProps({
    expert: { type: Object, required: true },
    assignments: { type: Array, required: true },
    summary: { type: Object, required: true },
    criteria: { type: Array, required: true },
    scale: { type: Array, required: true },
});

const format = (value) => (value === null || value === undefined ? '—' : value.toFixed(2));
</script>

<template>
    <Head :title="expert.name" />

    <AuthenticatedLayout :title="expert.name" current="admin-smes">
        <div class="mx-auto max-w-5xl space-y-5">
            <AppCard>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 class="text-lg font-semibold text-csc-ink">{{ expert.name }}</h1>
                        <p class="mt-0.5 text-sm text-csc-ink-muted">
                            {{ expert.position || 'Resource person' }}
                            <span v-if="expert.organization"> · {{ expert.organization }}</span>
                        </p>
                        <p v-if="!expert.is_active" class="mt-1 text-xs font-semibold text-danger">
                            Inactive — cannot be assigned to new trainings.
                        </p>
                    </div>
                    <AppButton :href="expert.edit_url" variant="ghost" size="sm" icon="settings">
                        Edit
                    </AppButton>
                </div>

                <dl class="mt-4 grid gap-3 border-t border-csc-line pt-4 text-sm sm:grid-cols-2">
                    <div v-if="expert.email">
                        <dt class="text-xs text-csc-ink-subtle">Email</dt>
                        <dd class="text-csc-ink">{{ expert.email }}</dd>
                    </div>
                    <div v-if="expert.contact_number">
                        <dt class="text-xs text-csc-ink-subtle">Contact</dt>
                        <dd class="text-csc-ink">{{ expert.contact_number }}</dd>
                    </div>
                    <div v-if="expert.expertise" class="sm:col-span-2">
                        <dt class="text-xs text-csc-ink-subtle">Areas of expertise</dt>
                        <dd class="text-csc-ink">{{ expert.expertise }}</dd>
                    </div>
                    <div v-if="expert.bio" class="sm:col-span-2">
                        <dt class="text-xs text-csc-ink-subtle">Biography</dt>
                        <dd class="leading-relaxed text-csc-ink-muted">{{ expert.bio }}</dd>
                    </div>
                    <div v-if="expert.remarks" class="sm:col-span-2">
                        <dt class="text-xs text-csc-ink-subtle">Internal remarks</dt>
                        <dd class="leading-relaxed text-csc-ink-muted">{{ expert.remarks }}</dd>
                    </div>
                </dl>
            </AppCard>

            <div class="grid gap-3 sm:grid-cols-3">
                <AppStat label="Trainings" :value="expert.trainings_count" />
                <AppStat label="Evaluations" :value="summary.responses" />
                <AppStat label="Overall Rating" :value="format(summary.average)" />
            </div>

            <AppCard
                title="Ratings by criterion"
                :subtitle="`Mean of ${summary.responses} evaluation${summary.responses === 1 ? '' : 's'}, on a scale of 1 to 5.`"
            >
                <AppEmptyState
                    v-if="!summary.responses"
                    compact
                    icon="analytics"
                    title="No evaluations yet"
                    description="Ratings appear once participants evaluate a training day this expert delivered."
                />
                <ul v-else class="divide-y divide-csc-line">
                    <li
                        v-for="criterion in criteria"
                        :key="criterion.key"
                        class="py-3 first:pt-0 last:pb-0"
                    >
                        <div class="flex items-baseline justify-between gap-4">
                            <p class="text-sm text-csc-ink">{{ criterion.statement }}</p>
                            <p class="shrink-0 text-sm font-semibold text-csc-ink">
                                {{ format(summary.criteria[criterion.key]) }}
                            </p>
                        </div>
                        <!--
                            The bar is a second encoding of the number beside
                            it, never the only one — it is aria-hidden for that
                            reason.
                        -->
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-csc-blue-tint" aria-hidden="true">
                            <div
                                class="h-full rounded-full bg-csc-blue"
                                :style="{ width: `${(summary.criteria[criterion.key] / 5) * 100}%` }"
                            />
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AppCard title="Trainings delivered">
                <AppEmptyState
                    v-if="!assignments.length"
                    compact
                    icon="calendar"
                    title="Not yet assigned"
                    description="Assign this expert from a training's form."
                />
                <ul v-else class="divide-y divide-csc-line">
                    <li
                        v-for="assignment in assignments"
                        :key="assignment.id"
                        class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-csc-ink">{{ assignment.title }}</p>
                            <p class="mt-0.5 text-xs text-csc-ink-subtle">
                                {{ assignment.training_code }} ·
                                {{ formatDateRange(assignment.starts_at, assignment.ends_at) }} ·
                                {{ assignment.status_label }}
                                <span v-if="assignment.topic"> · {{ assignment.topic }}</span>
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p
                                v-if="summary.trainings.find((row) => row.id === assignment.id)"
                                class="text-sm font-semibold text-csc-ink"
                            >
                                {{
                                    format(
                                        summary.trainings.find((row) => row.id === assignment.id).average
                                    )
                                }}
                                <span class="text-xs font-normal text-csc-ink-subtle">
                                    ({{
                                        summary.trainings.find((row) => row.id === assignment.id)
                                            .responses
                                    }})
                                </span>
                            </p>
                            <Link
                                :href="assignment.results_url"
                                class="text-xs font-semibold text-csc-blue hover:underline"
                            >
                                Results
                            </Link>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
