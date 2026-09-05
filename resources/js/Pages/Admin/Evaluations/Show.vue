<script setup>
/**
 * One run's evaluation results: an expert-by-expert summary, the same numbers
 * broken down by training day, and the written answers underneath.
 *
 * The comments are deliberately last and complete rather than sampled. Ratings
 * tell the office whether to invite somebody back; the comments are what tell
 * them what to change, and a page that quotes three of forty is a page that
 * gets quoted from selectively.
 */
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppStatTile from '@/Components/AppStatTile.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    training: { type: Object, required: true },
    assignments: { type: Array, required: true },
    results: { type: Object, required: true },
    comments: { type: Array, required: true },
    criteria: { type: Array, required: true },
    scale: { type: Array, required: true },
});

const format = (value) => (value === null || value === undefined ? '—' : value.toFixed(2));

const dayFilter = ref('all');

const days = computed(() => Array.from({ length: props.training.duration_days }, (_, i) => i + 1));

const visibleComments = computed(() =>
    dayFilter.value === 'all'
        ? props.comments
        : props.comments.filter((comment) => comment.day === Number(dayFilter.value))
);

/**
 * "Days 1–3" reads better than "Days 1, 2, 3", and an assignment covering the
 * whole run is the common case that deserves the short label.
 */
const spans = (list) =>
    list.reduce((out, day) => {
        const last = out[out.length - 1];

        if (last && last[1] === day - 1) last[1] = day;
        else out.push([day, day]);

        return out;
    }, []);

const dayList = (list) =>
    spans(list)
        .map(([from, to]) => (from === to ? `${from}` : `${from}–${to}`))
        .join(', ');

const assignedDays = (assignment) => {
    const days = assignment.days ?? [];

    if (!days.length) return 'No days';
    if (days.length === props.training.duration_days) return 'All days';

    return `Days ${dayList(days)}`;
};

/**
 * Where this assignment's feedback lands. Only worth saying when it differs
 * from the days they were present — an expert on one day is rated on that day,
 * and labelling it would be noise.
 */
const ratedOn = (assignment) => {
    const on = assignment.evaluated_on ?? [];

    if (!on.length || on.length === (assignment.days ?? []).length) return null;

    return `rated on day ${dayList(on)}`;
};
</script>

<template>
    <Head :title="`Evaluations — ${training.title}`" />

    <AuthenticatedLayout title="Evaluation Results" current="admin-evaluations">
        <div class="mx-auto max-w-6xl space-y-5">
            <AppCard>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 class="text-lg font-semibold text-csc-ink">{{ training.title }}</h1>
                        <p class="mt-0.5 text-sm text-csc-ink-muted">
                            {{ training.training_code }} · {{ training.starts_at }} ·
                            {{ training.venue }}
                        </p>
                    </div>
                    <AppButton :href="training.roster_url" variant="ghost" size="sm" icon="users">
                        Roster
                    </AppButton>
                </div>
            </AppCard>

            <div class="grid gap-3 sm:grid-cols-3">
                <AppStatTile label="Submissions" :value="results.submissions" icon="clipboard" />
                <AppStatTile label="Participants" :value="results.expected_responses" icon="users" />
                <AppStatTile label="Experts Assigned" :value="assignments.length" icon="user" />
            </div>

            <AppCard title="Panel">
                <ul class="divide-y divide-csc-line">
                    <li
                        v-for="assignment in assignments"
                        :key="assignment.id"
                        class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 first:pt-0 last:pb-0"
                    >
                        <div>
                            <component
                                :is="assignment.url ? Link : 'span'"
                                :href="assignment.url"
                                class="text-sm font-medium"
                                :class="assignment.url ? 'text-csc-blue hover:underline' : 'text-csc-ink'"
                            >
                                {{ assignment.display_name }}
                            </component>
                            <span v-if="assignment.topic" class="ml-2 text-xs text-csc-ink-subtle">
                                {{ assignment.topic }}
                            </span>
                        </div>
                        <span class="text-xs text-csc-ink-subtle">
                            {{ assignedDays(assignment) }}
                            <span v-if="ratedOn(assignment)">· {{ ratedOn(assignment) }}</span>
                        </span>
                    </li>
                </ul>
            </AppCard>

            <AppCard
                title="Ratings"
                subtitle="Mean score per criterion, 1 (strongly disagree) to 5 (strongly agree)."
                :padded="false"
            >
                <AppEmptyState
                    v-if="!results.experts.length"
                    icon="analytics"
                    title="No evaluations submitted yet"
                    description="Participants are asked to evaluate at the end of each training day."
                />
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-3xl text-left text-sm">
                        <thead class="border-y border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">
                                    Expert
                                </th>
                                <th
                                    v-for="criterion in criteria"
                                    :key="criterion.key"
                                    scope="col"
                                    class="px-4 py-3 font-semibold text-csc-ink-muted"
                                    :title="criterion.statement"
                                >
                                    {{ criterion.short }}
                                </th>
                                <th scope="col" class="px-4 py-3 font-semibold text-csc-ink-muted">
                                    Overall
                                </th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">
                                    Responses
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <template v-for="expert in results.experts" :key="expert.expert_id">
                                <tr class="bg-white">
                                    <th scope="row" class="px-5 py-3.5 text-left font-medium text-csc-ink">
                                        {{ expert.name }}
                                        <span
                                            v-if="expert.position"
                                            class="mt-0.5 block text-xs font-normal text-csc-ink-subtle"
                                        >
                                            {{ expert.position }}
                                        </span>
                                    </th>
                                    <td
                                        v-for="criterion in criteria"
                                        :key="criterion.key"
                                        class="px-4 py-3.5 text-csc-ink-muted"
                                    >
                                        {{
                                            format(
                                                expert.days.reduce(
                                                    (sum, day) =>
                                                        sum +
                                                        day.criteria[criterion.key] * day.responses,
                                                    0
                                                ) / expert.responses
                                            )
                                        }}
                                    </td>
                                    <td class="px-4 py-3.5 font-semibold text-csc-ink">
                                        {{ format(expert.average) }}
                                    </td>
                                    <td class="px-5 py-3.5 text-csc-ink-muted">{{ expert.responses }}</td>
                                </tr>
                                <!--
                                    Per-day rows only where the run has more
                                    than one day; on a single-day training they
                                    would repeat the row above verbatim.
                                -->
                                <tr
                                    v-for="day in training.duration_days > 1 ? expert.days : []"
                                    :key="`${expert.expert_id}-${day.day}`"
                                    class="bg-csc-blue-tint/20 text-xs"
                                >
                                    <th scope="row" class="px-5 py-2 pl-10 text-left font-normal text-csc-ink-subtle">
                                        Day {{ day.day }}
                                    </th>
                                    <td
                                        v-for="criterion in criteria"
                                        :key="criterion.key"
                                        class="px-4 py-2 text-csc-ink-subtle"
                                    >
                                        {{ format(day.criteria[criterion.key]) }}
                                    </td>
                                    <td class="px-4 py-2 text-csc-ink-subtle">{{ format(day.average) }}</td>
                                    <td class="px-5 py-2 text-csc-ink-subtle">
                                        {{ day.responses }}
                                        <span v-if="day.response_rate !== null">
                                            ({{ day.response_rate }}%)
                                        </span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p v-if="results.unrated.length" class="px-5 py-3 text-xs text-csc-ink-subtle">
                    No responses yet for
                    {{ results.unrated.map((expert) => expert.name).join(', ') }}.
                </p>
            </AppCard>

            <AppCard title="What participants wrote">
                <template v-if="comments.length">
                    <div class="mb-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                            :class="
                                dayFilter === 'all'
                                    ? 'border-csc-blue bg-csc-blue-tint text-csc-blue'
                                    : 'border-csc-line text-csc-ink-muted hover:bg-csc-blue-tint/50'
                            "
                            @click="dayFilter = 'all'"
                        >
                            All days
                        </button>
                        <button
                            v-for="day in days"
                            :key="day"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                            :class="
                                Number(dayFilter) === day
                                    ? 'border-csc-blue bg-csc-blue-tint text-csc-blue'
                                    : 'border-csc-line text-csc-ink-muted hover:bg-csc-blue-tint/50'
                            "
                            @click="dayFilter = day"
                        >
                            Day {{ day }}
                        </button>
                    </div>

                    <ul class="divide-y divide-csc-line">
                        <li v-for="comment in visibleComments" :key="comment.id" class="py-4 first:pt-0 last:pb-0">
                            <p class="text-xs text-csc-ink-subtle">
                                Day {{ comment.day }} · {{ comment.participant }} ·
                                {{ comment.submitted_at }}
                            </p>

                            <dl class="mt-2 space-y-2 text-sm">
                                <div v-if="comment.learned">
                                    <dt class="text-xs font-semibold text-csc-ink-muted">Learned</dt>
                                    <dd class="leading-relaxed text-csc-ink">{{ comment.learned }}</dd>
                                </div>
                                <div v-if="comment.liked_most">
                                    <dt class="text-xs font-semibold text-csc-ink-muted">Liked most</dt>
                                    <dd class="leading-relaxed text-csc-ink">{{ comment.liked_most }}</dd>
                                </div>
                                <div v-if="comment.needs_improvement">
                                    <dt class="text-xs font-semibold text-csc-ink-muted">
                                        Needs improvement
                                    </dt>
                                    <dd class="leading-relaxed text-csc-ink">
                                        {{ comment.needs_improvement }}
                                    </dd>
                                </div>
                                <div v-if="comment.suggestions">
                                    <dt class="text-xs font-semibold text-csc-ink-muted">Suggestions</dt>
                                    <dd class="leading-relaxed text-csc-ink">{{ comment.suggestions }}</dd>
                                </div>
                            </dl>

                            <ul class="mt-2 space-y-1">
                                <li
                                    v-for="(expert, index) in comment.experts"
                                    :key="index"
                                    class="text-xs text-csc-ink-muted"
                                >
                                    <span class="font-semibold text-csc-ink">{{ expert.name }}</span>
                                    · {{ expert.average.toFixed(2) }}
                                    <span v-if="expert.comments"> — “{{ expert.comments }}”</span>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </template>

                <AppEmptyState
                    v-else
                    compact
                    icon="document"
                    title="No written feedback yet"
                    description="The narrative questions are optional, so ratings can arrive without them."
                />
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
